<?php

declare(strict_types=1);

/*
 * ⚠ EVERY BOUND BELOW IS WRITTEN AT ITS PRODUCTION VALUE AND MULTIPLIED BY `RATELIMIT_SCALE`,
 * WHICH DEFAULTS TO 1 — SO PRODUCTION IS EXACTLY WHAT THE NUMBERS SAY.
 *
 * The scale exists for ONE caller: the browser suite's own stack. Measured 2026-11-02, after
 * three red CI runs and two red local ones all blamed on a sixty-second timeout:
 * `pnpm run gate:e2e` drives ~390 cases from a single IP, of which roughly 66 are staff page
 * loads, and each load calls `/v1/auth/me` (bucket `auth-session`, **60 per 60 s per IP**) and
 * `/v1/tenant`. The suite therefore crosses that window partway through and the API answers
 * **429** — correctly. The client maps 429 to its `server` kind, so the shell renders *"Something
 * went wrong on our side. Nothing you did caused it."*, which is both false and unreadable as a
 * diagnosis; `browser/support/e2e.ts` now probes the API when a staff page reaches the error
 * state, and that probe is what finally said `429 Too many requests` out loud.
 *
 * ⚠ THE LIMITER IS NOT WEAKENED AND IS STILL PROVEN. `tests/Feature/Http/RateLimitTest.php`
 * drives these buckets at scale 1 — `R-063`'s clause that *a limit is proven by being driven
 * and not by being configured* — and `RateLimitScaleTest` pins the default AT 1 so an override
 * cannot quietly become the production value. What the scale changes is which thing the browser
 * suite measures: the application, rather than a bound written for real traffic and then
 * exceeded by a synthetic burst from one address.
 *
 * ⚠ AND THE CLIENT'S 429 HANDLING IS A SEPARATE, REAL DEFECT, recorded rather than fixed here:
 * `§5` `R-224`. A throttled staff screen should say *wait a moment*, not *our side is broken*,
 * and that needs a state in `§12`'s table with copy in both launch languages — a product-copy
 * decision, which is 10b's (it builds the polled board, where a 429 is most likely).
 */

/*
|--------------------------------------------------------------------------
| RATELIMIT_SCALE is IGNORED outside local and testing — R-234
|--------------------------------------------------------------------------
|
| ⚠ WHAT THIS CLOSES. Every bucket below is built through `$scale`, and nothing confined it
| to a test environment — so ONE `.env` line in production relaxed the login bound by the
| same factor as the polling one. At the **20** the browser stack sets, `auth-login` became
| **200/min/IP** in front of a bcrypt, `auth-2fa` **100/min/IP** in front of a six-digit
| code, and `devices-issue` **200/min/IP** in front of a credential mint. Accepted 2026-11-03
| from Task 10's scoped `§11` security review; fixed at Task 12, its owner.
|
| ⚠ IGNORED RATHER THAN CLAMPED, WHICH IS THE ROW'S OWN RECOMMENDATION AND THE REASON FOR IT:
| *"a clamp invites a judgement about how much relaxation is acceptable in production and the
| answer is none."* A clamp would also have to pick a ceiling per bucket, and the bucket that
| matters most (`auth-2fa` at 5) has the least room.
|
| ⚠ `env('APP_ENV')` AND NOT `app()->environment()`. This file is evaluated by the config
| loader BEFORE the application's environment is resolved from it, so `app()->environment()`
| here reads a default rather than the value in force — the row's suggested form, and wrong
| in this position. `env()` is also what every other key in this directory uses, and `env()`
| inside `config/` is the one place larastan permits it (the cached-config caveat
| `TestCase::refuseHalfIsolatedRunner()` records applies to code OUTSIDE config).
|
| ⚠ AND A CACHED CONFIG CARRIES THE DECISION, NOT THE KNOB, which is the safe direction: a
| config cached under `local` and shipped would carry the SCALED numbers, so the clause is
| asserted against a real `APP_ENV=production` process rather than only in this file.
| `RateLimitScaleTest` drives a child process with `RATELIMIT_SCALE=20` and
| `APP_ENV=production` and reads the bounds back out of it.
|
| The default stays 1, so nothing changes for anyone who never sets it.
*/
$scaleEnvironments = ['local', 'testing'];

$scale = in_array((string) env('APP_ENV', 'production'), $scaleEnvironments, true)
    ? max(1, (int) env('RATELIMIT_SCALE', 1))
    : 1;

/**
 * One bucket half, scaled.
 *
 * A closure used only while this file is EVALUATED, so nothing uncacheable reaches the returned
 * array and `php artisan config:cache` is unaffected.
 *
 * @return array{attempts: int, seconds: int}
 */
$limit = static fn (int $attempts, int $seconds): array => [
    'attempts' => $attempts * $scale,
    'seconds' => $seconds,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Per-IP and per-tenant request limits
    |--------------------------------------------------------------------------
    |
    | Both, because they answer different questions. The per-IP limit is what stops one
    | client flooding the service; the per-TENANT limit is what stops one tenant's
    | traffic -- however many clients it comes from -- consuming the capacity of every
    | other tenant on the task (§4 › Realtime, D5). A limiter keyed only by IP is no
    | protection at all behind a NAT or a mobile carrier.
    |
    | Starting points, not measurements: Task 14 sets them from load results, which is
    | the same note config/shedding.php carries and for the same reason (C6).
    |
    */

    'buckets' => [

        'api' => [
            'per_ip' => $limit(120, 60),
            'per_tenant' => $limit(600, 60),
        ],

        /*
        | THE ADMIN PANEL, added at Task 6 to close security H-2.
        |
        | Every /v1 route carried a bucket, the auth surface carried one, and even the
        | unknown-path fallback carried `unknown` -- the panel's four routes and Livewire's
        | update endpoint carried NONE. Measured at `263bb16`: 25 anonymous GETs of
        | /platform each answered 401 in ~3.6 ms, issued ZERO Postgres statements, and
        | minted ONE Redis key apiece (`dbsize` +25), because `StartSession` persists a
        | session to carry a CSRF token before `Authenticate` refuses. Each key lives
        | 7200 s. Redis is the locked store for session, cache AND queue (§1), so an
        | unmetered loop on a credential-free path fills the store the customer pager
        | path, the outbox and `markReady` all depend on -- which is `R-027`'s shape
        | arriving through a new door.
        |
        | PER IP ONLY, and that is `R-063`'s argument rather than an oversight: the
        | refusal happens before any credential resolves, so there is no tenant to key on
        | -- and a platform admin belongs to no tenant even after one does. §4's "per IP
        | and per tenant" has no tenant dimension available on this surface at all, which
        | is why the row recording it names the per-session half instead.
        |
        | 240/min is deliberately generous for a human clicking through an admin panel:
        | one page load is one document plus several Livewire round trips, and a table
        | search types a request per keystroke. It is three orders of magnitude below what
        | a script needs to matter, which is the property that counts here.
        */
        'panel' => [
            'per_ip' => $limit(240, 60),
            // ⚠ A PER-TENANT HALF, ADDED AT TASK 7, and the comment this replaces said
            // there was "no tenant dimension available on this surface at all". That was
            // true of the PLATFORM panel, whose users hold no membership. It is false of
            // `/studio`, where every user holds exactly one and `TenantResolver` answers
            // on every request -- so §4 › Edge & ops' "per IP AND per tenant" was being
            // half-met on a surface that could meet it. Found by the §11 security review
            // on 2026-10-28 (R-123 widened).
            //
            // 600 and not 240: this bucket meters Livewire's update endpoint, so an
            // ordinary editing session is many requests, and a tenant's whole staff may
            // share one. It is well above a person typing and well below what a script
            // needs to make the upload and publish paths expensive.
            //
            // ⚠ WHICH STACK EACH HALF RUNS ON, because the first version of this comment
            // claimed the tenant half covered `/studio`'s own routes and it does not.
            // `RateLimit:panel` is deliberately the FIRST middleware on the panel stack,
            // before StartSession (Task 6's security H-2 -- an anonymous 401 must not
            // mint a Redis session key), so `$request->user()` cannot resolve there and
            // the resolver answers null: on the page routes only the per-IP half counts.
            // The per-tenant half bites on `POST /livewire-<hash>/update`, because the
            // same bucket is APPENDED to the `web` group -- and that is the surface the
            // bound is for, since every upload, publish and domain poll arrives over it.
            // R-143, found by the §11 review of the fix diff.
            //
            // The platform panel is unaffected: a platform admin resolves to no tenant,
            // so only the per-IP half applies there, which is what it had before.
            'per_tenant' => $limit(600, 60),
        ],

        /*
        | Creating a pager session is the one route in Task 3 that takes a body and the
        | one that does real work per call -- a CSPRNG draw, an insert and an audit row --
        | so it carries its own limit on top of the api group's. Sized well above a busy
        | counter's real rate (a session per order) and well below what a script could
        | use to fill `outbox` and `audit_log`, neither of which any grant in §2.7
        | deletes. Per tenant as well as per IP, because the per-IP half is no protection
        | at all when a tenant's staff are behind one NAT.
        |
        | The four transition routes stay on the api bucket: they act on a session that
        | already exists, and the renotify cap in config/pager.php is what bounds the
        | only one of them that can write repeatedly.
        */
        'pager-create' => [
            'per_ip' => $limit(60, 60),
            'per_tenant' => $limit(240, 60),
        ],

        /*
        | THE CUSTOMER SURFACE. Three buckets, and NONE of them has a per-tenant half --
        | which is not an omission, it is the clause R-063 was written around: a token
        | carries no tenant until it resolves, so there is nothing to key a per-tenant
        | limit on before the lookup, and resolving one in order to rate-limit the request
        | that asks for it is work an unauthenticated caller can make this process do.
        | These routes are the only ones in the project where the per-IP half stands
        | alone, and that is why each one is tighter than `api` rather than looser.
        |
        | Per-IP behind a NAT is weak and is still the only key available here. What
        | carries the rest of the weight is that guessing cannot succeed at all: 128 bits
        | behind a sha256 with one exact-match probe. The risk on this surface is resource
        | consumption and unobservable enumeration, not credential compromise, so the job
        | of these numbers is to bound the first and the counter in
        | TokenResolutionFailures is what answers the second.
        */

        /*
        | Opening a session is the expensive customer call -- the only one that reaches
        | Postgres -- so it carries the tightest bucket in the project. 30/minute is far
        | above a real customer (one scan, plus a re-open when the live status lapses) and
        | far below what an enumeration needs to be worth running. This is R-063's "its
        | own bucket, tighter than `api` and keyed per IP", and it is proven to refuse by
        | being driven rather than by being read.
        */
        /*
        | Issuing a KDS device token is a credential-minting write, and the tightest
        | per-tenant bucket in the project for that reason: a legitimate restaurant adds a
        | screen occasionally, not ten times a minute, and a loop here would fill
        | personal_access_tokens -- a GLOBAL table with no RLS behind it (§2.4), so the
        | rows are shared with every other tenant's.
        |
        | Per-IP AND per-tenant, which the customer surface's buckets cannot be: by the
        | time this route runs the tenant is resolved, so §4 › Edge & ops's "per IP and per
        | tenant" applies in full rather than in half.
        */
        'devices-issue' => [
            'per_ip' => $limit(10, 60),
            'per_tenant' => $limit(20, 60),
        ],

        /*
        | THE AUTH SURFACE. Per-IP only, and for the same reason the customer token route
        | is (R-063): there is no tenant until the credential resolves, so the per-tenant
        | half cannot apply before the lookup -- and resolving one in order to rate-limit
        | the request that asks for it is work an unauthenticated caller can make this
        | process do.
        |
        | The tightest bucket in the project. A password check is a bcrypt verification,
        | which is deliberately expensive, so an unbounded loop here is a CPU denial of
        | service as well as a guessing attempt. 10/minute is far above a real person
        | (one attempt, maybe three on a bad day) and far below what guessing needs.
        */
        'auth-login' => [
            'per_ip' => $limit(10, 60),
        ],

        /*
        | THE SECOND FACTOR, and the tightest bucket in the project. Six digits is a
        | million possibilities inside a thirty-second window, so an unbounded loop here is
        | a real attack rather than a theoretical one. 5/minute leaves room for a mistyped
        | code and a retry and nothing else.
        */
        'auth-2fa' => [
            'per_ip' => $limit(5, 60),
        ],

        /*
        | THE PASSKEY CEREMONIES, and the reason they are not on `auth-login`.
        |
        | `QRPAGER-12 §10.4(d)` named this bucket and the four passkey routes carried
        | `auth-login` instead -- a claim the `§11` reviews falsified by reading the live
        | router rather than the ticket. The claim was the thing that was wrong, and the
        | bucket is the better of the two ways to make it true.
        |
        | Its own allowance, because the work is not the same work. A WebAuthn ceremony is
        | an elliptic-curve signature check and a session write -- real, but nothing like a
        | bcrypt at 12 rounds -- and sharing `auth-login`'s 10/minute meant that issuing
        | options, posting a credential and retrying a failed ceremony (three requests for
        | ONE legitimate sign-in attempt) ate a third of the password budget for the same
        | IP. An office behind one NAT address would have exhausted it.
        |
        | Still per-IP only and not per-tenant, for `R-063`'s reason: a passkey login names
        | no account and resolves no tenant until after the ceremony, so there is no tenant
        | to key on, and resolving one in order to rate-limit the request that asks for it
        | is work an unauthenticated caller can make this process do.
        */
        'auth-passkey' => [
            'per_ip' => $limit(30, 60),
        ],

        /*
        | WHOEVER IS ASKING, AND ENDING A SESSION.
        |
        | `/v1/auth/me` and `/v1/auth/logout` carried NO limiter at all -- the `§11` reviews
        | measured 40 consecutive requests to each with zero 429s, and `me` costs one
        | indexed Postgres query plus a session read every time, so an unauthenticated loop
        | with a forged bearer header was unmetered database work. `routes/auth.php` claimed
        | they were covered by "the group default" and the `auth-surface` group has none, by
        | its own deliberate decision.
        |
        | Loose on purpose. These two are the routes a legitimate client calls most: a
        | dashboard asks `me` on every page load and a reconnecting KDS asks it again. The
        | point of the bucket is that the cost is BOUNDED, not that it is small -- and a
        | logout that can be exhausted leaves somebody unable to end their own session,
        | which is why it is not on `auth-login`'s 10.
        */
        'auth-session' => [
            'per_ip' => $limit(60, 60),
        ],

        /*
        | THE AUTH SURFACE'S COARSE BUCKET, AND IT IS A BACKSTOP RATHER THAN A POLICY.
        |
        | ⚠ WHY IT HAD TO EXIST, measured at the `§11` review of Task 5's fix diff (SEC H-1).
        | The five buckets above are named PER ROUTE, and Laravel runs GROUP middleware
        | before ROUTE middleware. The `auth-surface` group carries the session stack,
        | `ValidateCsrfToken`, `ResolvePrincipal` and `RefuseDeviceToken` — so every refusal
        | those four produce happened BEFORE any bucket was consulted, and was therefore
        | never counted. Driven, 100 requests per form:
        |
        |   GET  /v1/auth/me with a valid device token  -> 100 x 403, ZERO 429s,
        |                                                  each costing a Redis session
        |                                                  read/write and one
        |                                                  personal_access_tokens SELECT
        |   GET  /v1/auth/me with `Bearer zzz|q`        -> 100 x 422, ZERO 429s, each
        |                                                  costing a FAILED Postgres
        |                                                  statement -- and needing no
        |                                                  credential at all
        |   POST /v1/auth/login with no CSRF token      -> 100 x 419, ZERO 429s, each
        |                                                  creating a new Redis session key
        |                                                  with a 120-minute TTL
        |
        | The last one is the attack: an unauthenticated caller fills the session store, and
        | Redis is also the limiter's own backend — `RateLimit` fails OPEN when it cannot
        | reach Redis, so filling it removes every bucket in the project (`R-027`).
        |
        | SO THIS BUCKET RUNS FIRST IN THE GROUP, before the session is even started. That
        | is the ordering `bootstrap/app.php` already documents for the `api` group — "the
        | limiter runs FIRST so an unauthenticated flood costs the limiter and not the
        | resolver" — applied to the surface that was missing it.
        |
        | GENEROUS ON PURPOSE, and it double-counts with the per-route buckets. Both are
        | deliberate: this one bounds the paths the per-route buckets cannot see, while the
        | tight ones (a bcrypt at 10/min, a six-digit code at 5/min) stay the policy. The
        | `api` group does exactly this — `RateLimit` with no argument at the front, plus
        | `pager-create` on the route — so the shape is the project's own and not new here.
        |
        | Per-IP only, for `R-063`'s reason and one more: it runs before the session starts,
        | so there is no tenant to key on even in principle.
        */
        'auth-surface' => [
            'per_ip' => $limit(120, 60),
        ],

        'pager-token' => [
            'per_ip' => $limit(30, 60),
        ],

        /*
        | Polling is the high-frequency one and it is also the cheapest: two Redis reads,
        | no Postgres, no writes. A waiting device polls every ~7s, so ~9/minute; 240
        | leaves room for a table of customers behind one NAT and still bounds a loop to
        | something that costs us two Redis GETs apiece. It is deliberately NOT generous
        | enough to be a free status oracle: an attacker with a stolen token learns only
        | that one session's state, which they are already entitled to.
        */
        'pager-poll' => [
            'per_ip' => $limit(240, 60),
        ],

        /*
        | Registering a device is a write, and the one customer route that inserts a row.
        | A browser calls it once per subscription and again when its keys rotate, so
        | 20/minute is far above the real rate; the row count is separately bounded by
        | UNIQUE (pager_session_id, endpoint), which turns a loop re-sending the same
        | endpoint into one row however many times it is called.
        */
        'pager-subscribe' => [
            'per_ip' => $limit(20, 60),
        ],

        /*
        | Unknown routes get their own, much tighter bucket. A 404 costs almost nothing
        | to serve and everything to scan with, so path enumeration is the traffic this
        | one exists for -- and the gate names it: "the rate limiter covers unknown
        | routes (404s)". There is no per-tenant limit here because an unknown path
        | carries no tenant: it is refused before anything resolves one.
        */
        'unknown' => [
            'per_ip' => $limit(30, 60),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Posture when Redis is unavailable
    |--------------------------------------------------------------------------
    |
    | FAIL OPEN, approved as B7, and it is code rather than a property: measured in
    | phase 1, with the port pointed at a dead socket, RateLimiter::attempt() and
    | tooManyAttempts() both THREW RedisException. So "the API keeps serving with Redis
    | down" is false by default and has to be written.
    |
    | Open rather than closed, because the alternative is that losing Redis takes the
    | whole API down -- and the gate's own clause is "Redis is taken down and the API
    | keeps serving". The residual is real and is written down rather than waved at: an
    | attacker who can take Redis down has removed rate limiting. It goes to PLAN.md §5
    | with owner Task 12, which owns the SAST/DAST and dependency pass.
    |
    | Anything carrying TENANT DATA fails closed instead, which is why readiness answers
    | 503 when it cannot read its key rather than guessing: a wrong answer about one
    | tenant's data is worse than no answer at all. This value governs the limiter only.
    |
    */

    'fail_open' => true,

];
