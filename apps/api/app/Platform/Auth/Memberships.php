<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Membership;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * "A user's own memberships" — §4 › Tenancy's second named cross-tenant path, read through
 * §2.8's one mechanism.
 *
 * ================================================================================
 * WHY IT CANNOT BE WIDENED — the thing QRPAGER-12 §10.3(c) has to show
 * ================================================================================
 *
 * ⚠ TWO OF THESE FOUR POINTS USED TO BE FALSE, and the correction is kept visible because
 * the false versions were the stronger claims. They said this class exposed "exactly one
 * public read" asserted by a `MembershipScopeTest`, and that it had "one call site". It has
 * THREE public reads (`for`, `in`, `sole`) and FIVE call sites, and `MembershipScopeTest`
 * has never existed. Found by Task 5's QA review. The security property was never the count
 * -- it is that every caller passes a SERVER-DERIVED id -- so what was missing was the
 * mechanism that keeps that true, and `MembershipCallSiteTest` is now it.
 *
 * 1. **Every public read takes a user id, and it is always the authenticated user's.**
 *    `for()`, `in()` and `sole()` each take the id the guard produced. None takes a filter,
 *    a pattern, a list, or a tenant the caller chose. `MembershipCallSiteTest` pins the set
 *    of public methods, so a fourth cannot be added without the test naming it.
 * 2. **Every call site passes a server-derived id, and the set of call sites is pinned.**
 *    There are **seven call lines in FOUR files**: `Http/AuthController` (2),
 *    `AuthenticatedTenantResolver` (3), `ActiveTenant` (1), `RequestPrincipal` (1). Each
 *    passes a guard identifier or a credential verification's answer, never request input.
 *
 *    ⚠ THIS COUNT HAS NOW BEEN WRONG TWICE. It said "one call site", then "seven lines in
 *    five files" — and the sentence announcing the second correction carried the third error,
 *    while the enumeration right beside it listed four files all along. Caught by the second
 *    `§11` review of the fix diff, by counting. Four files, seven lines, and
 *    `MembershipCallSiteTest` pins both. `MembershipCallSiteTest` greps the tree and
 *    fails on a sixth, because "the user id comes from the guard" is a property of the call
 *    site and not of this file.
 * 3. **The SQL names its columns and calls `public.user_memberships(?)`** — a function with one
 *    exact-match uuid parameter, compared with `=` inside its body. No input shape THIS
 *    CLASS can send returns a second user's memberships.
 *
 *    ⚠ AND THAT IS A PROPERTY OF THIS FILE, NOT OF THE FUNCTION, which is the correction the
 *    `§11` review of the fix diff forced. The sentence used to end "No input shape returns a
 *    second user's memberships", and the function is `SECURITY DEFINER` with the tenant
 *    bypass on — so any role that can read `users` can join over it. Measured as
 *    `qrpager_app`: `select count(*) from tenant_users` -> **0** (RLS, no scope), while
 *    `select count(*) from users u, public.user_memberships(u.id) m` -> **18 rows across all
 *    14 tenants**, matching the privileged ground truth exactly.
 *
 *    **So RLS is NOT a backstop for reads through this function**, and `§10.3(d)`'s "even if
 *    the policy check is wrong" does not cover them. The bound is the PHP one in point 2,
 *    pinned by `MembershipCallSiteTest`, and `MembershipEnumerationTest` pins the absence of
 *    the database-level bound as a measured fact rather than leaving it to be rediscovered.
 *    `R-108` owns the question of whether that is acceptable.
 * 4. **The function is `RETURNS TABLE` with FIVE named columns** (§2.8 rule 1), so nothing
 *    else from `tenant_users` or `tenants` can come back even if this class asked for it.
 *
 *    ⚠ **FOUR UNTIL 10b, FIVE SINCE — `tenant_name`, on Muhammad's `R-222` ruling.** Both of
 *    these numbered points said something false after that migration and `§11` SECURITY L-6
 *    found them: this one still said *four*, and point 3 still said *`select *`* while the
 *    reader below had just been changed to name its columns explicitly, with a comment saying
 *    *never `select *`*. Two statements in the file's own stated BOUND, contradicting the
 *    code beneath them. The file's history is why it matters: its point 2 carries the note
 *    *"THIS COUNT HAS NOW BEEN WRONG TWICE"*, and this is the third count in it to go stale.
 *    `MembershipFunctionGrantTest` now pins the column set against the catalogue, so the
 *    number here has a test behind it rather than a reader's attention.
 * 5. **`EXECUTE` is granted to `qrpager_app` alone**, after `REVOKE ALL … FROM PUBLIC`.
 *
 * ================================================================================
 * CACHING, under Octane — QRPAGER-12 §10.5
 * ================================================================================
 *
 * **Request-scoped, and nothing else.** This object is bound `scoped()`, which Octane
 * flushes at the request boundary by contract, so the memoised set below cannot outlive
 * the request that filled it. The alternative — a cache keyed by user id — was rejected:
 * it buys one indexed query (`tenant_users` carries `INDEX (user_id)`, §2.5) and pays for
 * it with a membership set that can be stale across a revocation, on a worker that stays
 * alive between requests. A stale membership is a cross-tenant read.
 *
 * **`scoped()` and not `singleton()`, and the reason is measured in this repo rather than
 * assumed.** `AppServiceProvider` records it: a class bound `singleton()` resolved as
 * three *different* objects across three real Octane requests, because nothing warmed it,
 * so each request resolved it inside `clone $this->app`. A binding that happens to be
 * discarded is isolation by accident, and the next warm-up changes it.
 *
 * It is memoised **per user id** rather than in a bare property, so that a request which
 * legitimately authenticates twice (a login that then resolves a tenant) cannot read the
 * first user's set for the second user.
 */
final class Memberships
{
    /** @var array<string, list<Membership>> */
    private array $memoised = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Does this user hold ANY membership, anywhere? **NOT MEMOISED, AND THAT IS THE POINT.**
     *
     * ================================================================================
     * ⚠ `R-244`: WHY A FOURTH READER EXISTS WHEN `for()` ANSWERS THE SAME QUESTION
     * ================================================================================
     *
     * `UserAttachment::refusalFor()` implements `§4 › Security`'s consent clause — *an address
     * already belonging to an account that holds ANY membership is refused rather than
     * attached* — and it was asking `for($userId) === []`. That gave **two different answers
     * to one question depending on container lifetime**, which Muhammad ruled is the defect
     * rather than the status it produced: *"`refusalFor()` ANSWERING DIFFERENTLY ON AND OFF
     * THE REQUEST PATH IS THE DEFECT. Make it one path, and write down in the code why it ever
     * diverged — an unexplained divergence you preserve is a bug you have agreed to keep."*
     *
     * **WHY IT DIVERGED, measured 2026-11-07 with a trace inside `refusalFor()` across two
     * real `POST /v1/members` requests in one test:**
     *
     *     request 1   memberships=0  raw=0  app.tenant_id=''  role=qrpager_app  conn=pgsql
     *     request 2   memberships=0  raw=1  app.tenant_id=''  role=qrpager_app  conn=pgsql
     *     outside     memberships=0  raw=1  app.tenant_id=''  role=qrpager_app  conn=pgsql
     *
     * `raw` is `select … from public.user_memberships(?)` issued on the SAME connection in the
     * SAME call. On request 2 the function returns **1 row** and `for()` returns **none**. Not
     * RLS, not the tenant scope, not the role, not the connection — **the memo below**, filled
     * with `[]` during request 1 by the consent check itself, and still warm because this
     * class is `scoped()` and the test harness runs one container per test (`R-102`).
     *
     * So in PRODUCTION the second attach is refused (fresh reader, 1 row) and in the SUITE it
     * answered 201. `MembersTest`'s *"idempotent on a second add"* case asserted the harness's
     * answer, not the application's, for three days.
     *
     * ================================================================================
     * WHY THE MEMO IS RIGHT FOR `for()` AND WRONG HERE
     * ================================================================================
     *
     * `for()` memoises because it answers *"which tenants may THIS CALLER act in"* — asked
     * several times per request about the authenticated principal, and stable for a request by
     * design (`QRPAGER-12 §10.5`). This reader asks *"does some OTHER account already hold a
     * membership"*, about an id that arrived in a request body, **and the answer can change
     * inside one request**: the attach two statements later is exactly such a change. A memo
     * over that is not a cache, it is a stale authorisation decision.
     *
     * ⚠ IT ADDS NO CROSS-TENANT PATH. Same function, same connection, same `§4 › Tenancy`
     * named path (*"a user's own memberships"*) that `for()` uses; the list stays at FOUR. It
     * reads LESS than `for()` does — `exists`, never a tenant id, a slug or a name — so the
     * caller cannot disclose another tenant's identity even by accident, which is the property
     * `MemberConsentTest` asserts from the outside.
     *
     * ⚠ AND IT IS `limit 1` RATHER THAN A COUNT, because the question is existence and a count
     * over another user's memberships is a number this caller has no use for.
     */
    public function holdsAnyMembership(string $userId): bool
    {
        // Shape-checked first, exactly as `for()` does and for its reason: the value becomes a
        // tenant scope and a Redis namespace downstream, so a non-uuid means a guard produced
        // something impossible and the honest answer is "no memberships" rather than a query.
        if (! Str::isUuid($userId)) {
            return false;
        }

        $row = $this->connection->selectOne(
            'select 1 as present from public.user_memberships(?) limit 1',
            [$userId],
        );

        return $row !== null;
    }

    /**
     * Every membership this user holds. Cross-tenant by design, bounded by the five
     * properties in the class docblock.
     *
     * @return list<Membership>
     */
    public function for(string $userId): array
    {
        // Shape-checked before it reaches the database, like every other identifier in
        // this project: the value becomes a tenant scope and a Redis namespace
        // downstream, and `§4 › Realtime` makes that a thing to validate rather than
        // trust. A non-uuid here means a guard produced something impossible, so the
        // honest answer is "no memberships" rather than a query.
        if (! Str::isUuid($userId)) {
            return [];
        }

        if (array_key_exists($userId, $this->memoised)) {
            return $this->memoised[$userId];
        }

        // An explicit column list over the function's own RETURNS TABLE, never `select *`:
        // the function gained `tenant_name` at 10b (`R-222`), and a star here would have
        // silently started reading whatever a later migration adds to it. §2.8 rule 1 is
        // about the function's surface; this is the same discipline on the caller's side.
        $rows = $this->connection->select(
            'select tenant_id, role, tenant_status, tenant_slug, tenant_name, expires_at
               from public.user_memberships(?)',
            [$userId],
        );

        $memberships = [];

        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->tenant_id, $row->role, $row->tenant_status)) {
                continue;
            }

            $role = TenantRole::tryFrom((string) $row->role);
            $status = TenantStatus::tryFrom((string) $row->tenant_status);

            // A value Postgres holds and PHP does not know is a contract drift, and the
            // safe direction is to drop the row rather than to guess: a membership whose
            // role cannot be mapped grants nothing. §2.3's generated-enum check is what
            // stops this arising; this is the belt.
            if ($role === null || $status === null) {
                continue;
            }

            $memberships[] = new Membership(
                tenantId: (string) $row->tenant_id,
                role: $role,
                tenantStatus: $status,
                tenantSlug: (string) ($row->tenant_slug ?? ''),
                // Null-coalesced like the slug beside it, and for the same reason: the
                // column is NOT NULL in `tenants`, so an absent value here means the
                // function and this reader have drifted -- and an empty string makes the
                // chooser fall back to the slug rather than render the word "null".
                tenantName: (string) ($row->tenant_name ?? ''),
                // QRPAGER-22. Non-null means a platform-support membership; the function has
                // already dropped any whose expiry has passed, so this is always live.
                expiresAt: isset($row->expires_at) ? (string) $row->expires_at : null,
            );
        }

        return $this->memoised[$userId] = $memberships;
    }

    /**
     * The membership this user holds in exactly this tenant, or null.
     *
     * An exact match over the set from `for()`, in PHP — NOT a second query with the
     * tenant id in it. That is deliberate: a `where tenant_id = ?` variant would be a
     * second entry point taking a caller-supplied tenant, which is the widening property 1
     * exists to prevent. The set is at most a handful of rows.
     */
    public function in(string $userId, string $tenantId): ?Membership
    {
        foreach ($this->for($userId) as $membership) {
            if (hash_equals($membership->tenantId, $tenantId)) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * The one membership this user holds, or null when they hold none or several.
     *
     * "Several" answers null rather than picking the first: a request that does not say
     * which tenant it means, from a user who belongs to more than one, has no correct
     * answer, and choosing one silently would make the first tenant in an unordered result
     * set the default. The client names the tenant instead.
     */
    public function sole(string $userId): ?Membership
    {
        $all = $this->for($userId);

        return count($all) === 1 ? $all[0] : null;
    }
}
