<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Http\ProblemType;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RefuseDeviceToken;
use App\Platform\Http\Middleware\ResolvePrincipal;
use App\Platform\Http\RequestLimiter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Three gate clauses in one file:
 *
 *   "the rate limiter covers unknown routes (404s), or the gap is written into §5 with
 *    the control that covers it"
 *   "Redis is taken down and the API keeps serving, with the rate limiter's
 *    fail-open/fail-closed posture stated and tested"
 *   and §4 › Realtime's per-IP AND per-tenant limit, keyed by tenant id (D5).
 *
 * Against REAL Redis, not the array store. The suite runs with CACHE_STORE=array
 * (phpunit.xml), so a limiter on the default store would be tested against an
 * in-process array -- and the one behaviour that matters here, what happens when the
 * store is unreachable, belongs to Redis. Testing it against a fake would prove the
 * fake. Same reasoning as ReadinessStore's.
 */

beforeEach(function (): void {
    // Each case gets its own IP and tenant, so buckets never carry between tests -- and
    // real Redis keeps them for a minute.
    $this->tenant = $this->actingForTenant();

    // A COUNTER, AND THEN THE BUCKETS ARE CLEARED. Both halves are load-bearing and
    // both were learned here rather than reasoned about:
    //
    //   random_int(1, 254) per test: this file now makes ~26 requests, so by the
    //   birthday bound a collision is likely rather than rare -- and the bucket lives
    //   in REAL Redis for a minute, so a collision makes one test refuse another test's
    //   FIRST request. Two unrelated cases failed with 429 the moment the verb dataset
    //   below was added.
    //
    //   A counter alone is worse, not better: it restarts at 1 every run, so a second
    //   run inside the same minute reuses every address and NINE cases failed
    //   deterministically. Measured, immediately after swapping random for the counter.
    //
    // So the address is deterministic -- a failure reproduces -- and the state this
    // file depends on is REMOVED rather than hoped to be absent. That is the "correct
    // on a clean build, wrong on a machine carrying earlier state" shape §4a records
    // four times on this branch; the only reliable answer to it is to own the state.
    static $nth = 0;
    $this->ip = '203.0.113.'.(++$nth);

    // Bucket names from config, so this cannot drift from the limiter's own list.
    $limiter = app(RequestLimiter::class);

    foreach (array_keys((array) config('ratelimit.buckets')) as $bucket) {
        $limiter->clear("ratelimit:{$bucket}:ip:".sha1($this->ip));
        $limiter->clear("ratelimit:{$bucket}:tenant:{$this->tenant}");
    }

    Route::middleware('api')->prefix('v1')->get('limit-probe', fn () => response()->json(['ok' => true]));
});

/**
 * Take Redis away for real, from every layer that holds a handle to it.
 *
 * Changing the port is not enough and that is the whole lesson: the CACHE manager keeps
 * the resolved `redis` store, and that store keeps the connection it was built with. So
 * the port change applied to nothing, the limiter never threw, and the fail-open case
 * passed with Redis perfectly healthy -- caught only because it also asserts the warning
 * that fail-open logs. A test that passes for the wrong reason is worse than one that
 * fails.
 */
function takeRedisAway(): void
{
    // BOTH connections. config/cache.php's redis store uses the `cache` connection
    // (REDIS_CACHE_CONNECTION, default 'cache'), not `default` -- so pointing only
    // `default` at a dead port leaves the limiter's own connection perfectly healthy.
    // That is the second half of why the fail-open case was passing with Redis up.
    config([
        'database.redis.default.port' => 1,
        'database.redis.cache.port' => 1,
    ]);

    Cache::forgetDriver('redis');
    app()->forgetInstance('redis');
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance(RequestLimiter::class);
}

it('lets a request through and refuses the one past the per-IP limit', function (): void {
    $limit = (int) config('ratelimit.buckets.api.per_ip.attempts');

    // Lower the bucket rather than sending 120 requests: the limit under test is the
    // configured one, and config is where §4 says these numbers live.
    config(['ratelimit.buckets.api.per_ip.attempts' => 3]);

    for ($i = 1; $i <= 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->getJson('/v1/limit-probe')->assertStatus(200);
    }

    $refused = $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe');

    $refused->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);

    expect($refused->headers->get('Retry-After'))->not->toBeNull()
        ->and($refused->headers->get('Content-Type'))->toContain('application/problem+json')
        // The envelope says nothing about the bucket, the key or the window: a limiter
        // that reports which limit was hit tells a caller how to stay under it.
        ->and((string) $refused->getContent())->not->toContain('ratelimit')
        ->not->toContain($this->ip)
        ->not->toContain($this->tenant);

    // The configured default is untouched -- this case lowered a copy, not the file.
    expect($limit)->toBe(120);
});

it('limits per tenant as well as per IP, keyed by the resolver and not the request — D5', function (): void {
    config([
        'ratelimit.buckets.api.per_ip.attempts' => 1000,
        'ratelimit.buckets.api.per_tenant.attempts' => 2,
    ]);

    // Two different addresses, one tenant. A limiter keyed only by IP is no protection
    // at all behind a NAT or a mobile carrier, which is why both keys exist.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson('/v1/limit-probe')->assertStatus(200);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->getJson('/v1/limit-probe')->assertStatus(200);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->getJson('/v1/limit-probe')->assertStatus(429);

    // ...and another tenant from the same address is unaffected, which is the property:
    // one tenant's traffic cannot consume the capacity the others share.
    $this->actingForTenant();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->getJson('/v1/limit-probe')->assertStatus(200);
});

it('takes the tenant from the resolver, so no request input can buy a fresh bucket', function (): void {
    config(['ratelimit.buckets.api.per_tenant.attempts' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe')->assertStatus(200);

    // Every shape a caller could try. None of them reaches the resolver (B5): the stub
    // reads a container binding and nothing else.
    $attempts = [
        ['X-Tenant-Id' => (string) Str::uuid7()],
        ['X-Tenant' => (string) Str::uuid7()],
        ['Authorization' => 'Bearer '.Str::uuid7()],
    ];

    foreach ($attempts as $headers) {
        $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->getJson('/v1/limit-probe?tenant='.Str::uuid7(), $headers)
            ->assertStatus(429);
    }
});

it('keeps serving with Redis down, and says so in the log — B7 fail-open', function (): void {
    Log::spy();

    // Real Redis, taken away. Measured in phase 1: with the port pointed at a dead
    // socket, ping, Cache::get, RateLimiter::attempt and tooManyAttempts ALL threw
    // RedisException -- so "the API keeps serving with Redis down" is false by default
    // and this posture is code rather than a property.
    takeRedisAway();

    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->getJson('/v1/limit-probe')->assertStatus(200);

    // Fail-open is a posture, not an accident, so it is logged while it is happening:
    // an outage that silently removes rate limiting is the residual B7 sends to §5 with
    // owner Task 12.
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'failing open')
            // The class only, never the message: a connection failure's message carries
            // the host and the port.
            && is_string($context['exception'] ?? null)
            && ! str_contains(implode(' ', array_map('strval', $context)), '127.0.0.1'),
    );
});

it('fails closed instead when the posture is configured closed', function (): void {
    config(['ratelimit.fail_open' => false]);
    takeRedisAway();

    // The posture is a configuration value and both halves work, which is what "stated
    // and tested" asks for. Open is the chosen default because the alternative is that
    // losing Redis takes the whole API down -- and the gate's own clause is that the API
    // keeps serving.
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->getJson('/v1/limit-probe')->assertStatus(429);
});

it('keeps both probes answering while the limiter is refusing everything', function (): void {
    // One attempt, then over. NOT zero: Laravel's tooManyAttempts() resets the counter
    // and returns false when the attempt count has reached the maximum and no timer key
    // exists yet, so a maximum of 0 lets the FIRST request through -- which is how this
    // case first failed, and it is worth knowing before someone configures a bucket at 0
    // expecting a closed door.
    config(['ratelimit.buckets.api.per_ip.attempts' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe')->assertStatus(200);
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe')->assertStatus(429);

    // The probe group is empty on purpose, which is the mechanism behind "while health
    // and readiness keep answering". Neither carries the limiter, so neither can be
    // shed by it.
    $this->getJson('/healthz')->assertStatus(200);
    expect($this->getJson('/readyz')->status())->toBeIn([200, 503]);
});

it('counts unknown paths on their own bucket, and not against /v1’s', function (): void {
    config([
        'ratelimit.buckets.unknown.per_ip.attempts' => 1,
        'ratelimit.buckets.api.per_ip.attempts' => 10,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/no-such-a')->assertStatus(404);
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/no-such-b')->assertStatus(429);

    // Separate buckets: a scan that exhausts the 404 limit must not lock the same
    // address out of the real API, and vice versa.
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe')->assertStatus(200);
});

/*
 * Security finding #1. Route::fallback() registers the route for GET alone, and
 * RouteCollection::match() looks only in the bucket for the request's own method -- so
 * every other verb found no route, fell through to checkForAlternateVerbs, and was
 * answered 405 by the ROUTER before any route middleware ran. Measured at cb584c3:
 * GET /nope recorded 3 attempts while POST, PUT, DELETE and OPTIONS recorded 0, and
 * OPTIONS /nope was answered 200 text/html with an Allow header.
 *
 * The clause is "the rate limiter covers unknown routes (404s)" and it held for GET
 * only, which is also why the case above -- the one that was supposed to prove it --
 * stayed green: it uses getJson.
 */

it('counts an unknown path on every verb the router dispatches, not only GET', function (string $verb): void {
    config(['ratelimit.buckets.unknown.per_ip.attempts' => 1]);

    // First request through: 404, and COUNTED. Second: refused, which is the proof that
    // the first one reached the bucket at all -- asserting 404 twice would pass against
    // a limiter that never ran.
    $first = $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->json($verb, '/no-such-path-a');
    $second = $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->json($verb, '/no-such-path-b');

    $first->assertStatus(404);
    $second->assertStatus(429);

    // HEAD carries no body by definition, so the envelope is asserted on the verbs that
    // have one. The status and the bucket are the property under test either way.
    if ($verb !== 'HEAD') {
        $first->assertJsonPath('type', ProblemType::NotFound->value);
        $second->assertJsonPath('type', ProblemType::RateLimited->value);
    }
})->with([
    // Router::$verbs in full, spelled out here on purpose: the production registration
    // uses any() so it cannot go stale, and this list is the independent check that it
    // really covers them. POST and OPTIONS are the two the review named.
    'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'QUERY',
]);

it('no longer answers an unauthenticated 200 to OPTIONS on any path', function (): void {
    // The router's own OPTIONS short-circuit returned 200 text/html with an Allow
    // header, on no middleware at all -- a free, uncounted, unshed 200 for any path in
    // the application, and the one response that was not a problem document.
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->json('OPTIONS', '/healthz');

    expect($response->status())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->headers->get('Allow'))->toBeNull();
});

it('answers a wrong verb on a real route as 404 rather than 405', function (): void {
    // The accepted trade of registering the fallback for every verb: the fallback sits
    // in that verb's bucket and matches, so the router never reaches
    // checkForAlternateVerbs. For an API that is the better answer -- a 405 tells a
    // scanner the path exists under some other verb -- and it is asserted here so the
    // change is deliberate rather than discovered later.
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->json('POST', '/healthz')
        ->assertStatus(404);

    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->json('DELETE', '/v1/limit-probe')
        ->assertStatus(404);
});

/*
 * Security finding #2. The per-IP bucket is only a per-IP bucket if ip() is the client.
 *
 * Both directions are asserted, and the second one is why the boot check exists: the
 * DEFAULT is wrong, not merely unset. These go through a real request and read the key
 * the middleware actually wrote, for the same reason the case below does -- a test that
 * computes the key it expects is testing its own arithmetic.
 */

it('keys the per-IP bucket on the forwarded client when the peer is a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);

    $client = '198.51.100.23';
    $proxy = '10.0.0.7';

    $this->withServerVariables(['REMOTE_ADDR' => $proxy])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->getJson('/v1/limit-probe')
        ->assertStatus(200);

    $keys = implode(' ', array_map('strval', (array) Redis::connection('cache')->keys('*ratelimit*')));

    // No message argument: Pest's toContain takes FURTHER NEEDLES, so a message there
    // becomes a second string the key list has to contain. The file's own earlier case
    // records that trap; this one walked into it anyway.
    expect($keys)->toContain(sha1($client))
        ->not->toContain(sha1($proxy));
});

it('keys it on the peer when nothing is trusted, which is the default the boot check refuses', function (): void {
    // THE MEASUREMENT BEHIND THE REFUSAL, kept as a test rather than a comment. With no
    // trusted proxy Laravel calls setTrustedProxies([], ...) and ip() is REMOTE_ADDR,
    // so behind the ALB every client shares one bucket. Asserting the broken behaviour
    // is what makes the boot check's existence checkable: delete the check and this
    // still passes, delete the CONFIG and the case above fails.
    config(['trustedproxy.proxies' => null]);

    $client = '198.51.100.99';
    $proxy = '10.0.0.8';

    $this->withServerVariables(['REMOTE_ADDR' => $proxy])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->getJson('/v1/limit-probe')
        ->assertStatus(200);

    $keys = implode(' ', array_map('strval', (array) Redis::connection('cache')->keys('*ratelimit*')));

    expect($keys)->toContain(sha1($proxy))
        ->not->toContain(sha1($client));
});

it('hashes the address in the key the middleware actually writes', function (): void {
    // Through a REAL REQUEST, and that matters: the first version of this case built a
    // key itself and asserted the hash it had just computed, so reverting the
    // middleware's sha1() left it green. A control that tests its own arithmetic is not
    // a control.
    //
    // Keys are read from the `cache` connection, not `default`: config/cache.php's
    // redis store uses REDIS_CACHE_CONNECTION (default 'cache') on REDIS_CACHE_DB, so
    // scanning the default connection finds nothing and proves nothing.
    $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson('/v1/limit-probe')->assertStatus(200);

    $keys = array_map('strval', (array) Redis::connection('cache')->keys('*ratelimit*'));

    expect($keys)->not->toBe([], 'the limiter wrote no key at all');

    $joined = implode(' ', $keys);

    // The address is not a secret, but it is personal data, and these keys live in a
    // store the whole task shares. The hash is stable, so the bucket is.
    expect($joined)->toContain(sha1($this->ip))
        ->not->toContain($this->ip);

    // ...and the tenant id IS in the clear, deliberately: it is the key's whole purpose,
    // it is a uuid this application issued, and §4 › Realtime already builds the Redis
    // namespace from exactly this value.
    expect($joined)->toContain($this->tenant);
});

/*
 * QA L-1, which is R-045's shape with a real instance behind it at last.
 *
 * R-045 (Task 2, QA L-4): keysFor() reads config("ratelimit.buckets.{$bucket}", []), so an
 * unconfigured bucket name yields NO keys, the foreach body never runs, and the request is
 * served with no limit at all -- silently. At Task 2 that was theoretical: `api` and
 * `unknown` were the only buckets and both were hard-coded beside their config. Task 3
 * added the first route-level bucket parameter, `RateLimit:pager-create`, so the typo R-045
 * describes is now one character away from being real and nothing tested it.
 *
 * THIS ASSERTS THE PROPERTY, NOT THE INSTANCE. Testing that `pager-create` happens to be
 * spelled right today would pass tomorrow with a fifth bucket misspelled. Instead every
 * bucket name any route actually references is read off the router and checked against
 * config, so a new route with a bad name fails here rather than serving unlimited.
 *
 * R-045 itself stays open: the row asks that an unconfigured name REFUSE or throw at boot,
 * which is a change to RateLimit owned by Task 12. This closes the "no test covers it" half
 * for every name now in use.
 */
it('configures every rate-limit bucket that a route actually references — R-045, QA L-1', function (): void {
    $referenced = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, RateLimit::class.':')) {
                continue;
            }

            $referenced[] = substr($middleware, strlen(RateLimit::class) + 1);
        }
    }

    // The group-level RateLimit carries no parameter and defaults to `api`, which no
    // route spells out -- so `api` is added here rather than discovered, and `unknown` is
    // the fallback route's, applied in bootstrap/app.php.
    $referenced = array_values(array_unique([...$referenced, 'api', 'unknown']));

    // A positive control: if the scan found nothing, this case would pass vacuously while
    // asserting about an empty list, which is R-036's defect.
    // in_array + toBeTrue, not toContain: Pest's toContain is VARIADIC over needles, so a
    // second argument is another needle rather than a message. Cost two red runs in this
    // round before the pattern was obvious.
    expect(in_array('pager-create', $referenced, true))->toBeTrue(
        'the router scan found no route-level bucket at all, so the loop below asserts nothing'
    );

    foreach ($referenced as $bucket) {
        /** @var array<string, mixed> $limits */
        $limits = (array) config("ratelimit.buckets.{$bucket}");

        expect($limits)->not->toBe(
            [],
            "the route middleware references bucket [{$bucket}] and config/ratelimit.php has no such bucket, "
            .'so every route carrying it is served with NO limit at all (R-045)'
        );

        // A bucket with neither dimension configured is the same silent no-op as a
        // missing one: keysFor() checks per_ip and per_tenant individually.
        expect(isset($limits['per_ip']) || isset($limits['per_tenant']))->toBeTrue(
            "bucket [{$bucket}] has neither a per_ip nor a per_tenant limit, so it produces no keys"
        );
    }
});

it('refuses past the pager-create bucket, which is the route-level one Task 3 added', function (): void {
    config(['ratelimit.buckets.pager-create.per_ip.attempts' => 2]);

    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $create = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->postJson('/v1/pager-sessions', ['order_reference' => 'A-'.random_int(100, 999)]);

    expect($create()->status())->toBe(201)
        ->and($create()->status())->toBe(201);

    $refused = $create();

    // The tighter bucket bites before the api group's 120, which is the whole reason the
    // route carries its own: creating a session does real work per call (a CSPRNG draw,
    // an insert and an audit row) while the four transition routes act on a row that
    // already exists.
    $refused->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);

    expect((string) $refused->getContent())->not->toContain('pager-create')
        ->not->toContain('ratelimit');
});

/*
 * ================================================================================
 * EVERY BUCKET ON THE AUTH SURFACE, DRIVEN TO AN ACTUAL 429
 * ================================================================================
 *
 * `R-063`'s clause, which `QRPAGER-12 §10.4(d)` restates for this task: a limit is proven by
 * being DRIVEN, not by being read off a config. Task 5 shipped its buckets with neither --
 * `grep -rn 429 tests/Feature/Auth/` found nothing, so the only thing holding the four new
 * buckets was `R-045`'s case above, which checks that a referenced name EXISTS. A bucket
 * that exists and never refuses is the silent no-op that case was written to catch, one
 * layer up.
 *
 * Both `§11` reviews found the same two holes by dumping the live route table: `auth.me` and
 * `auth.logout` named no bucket at all, and `auth-passkey` -- named in the ticket -- did not
 * exist, so the passkey routes shared the bucket sized for bcrypt. Both are fixed; this is
 * what holds them fixed.
 */
dataset('auth surface buckets', [
    // [bucket, method, path, body, the status a permitted request gets]
    'auth-login on the login route' => ['auth-login', 'postJson', '/v1/auth/login',
        ['email' => 'nobody@example.test', 'password' => 'correct-horse-battery-staple'], 401],
    'auth-2fa on the challenge route' => ['auth-2fa', 'postJson', '/v1/auth/two-factor/challenge',
        ['code' => '123456'], 401],
    'auth-passkey on the login ceremony' => ['auth-passkey', 'getJson', '/v1/auth/passkeys/login/options',
        [], 200],
    // 200 and not 401: this file's beforeEach authenticates, so `me` answers. That is the
    // point of carrying the permitted status in the dataset rather than assuming a refusal.
    'auth-session on me' => ['auth-session', 'getJson', '/v1/auth/me', [], 200],
    'auth-session on logout' => ['auth-session', 'postJson', '/v1/auth/logout', [], 204],
]);

it('refuses past each bucket on the auth surface', function (
    string $bucket, string $method, string $path, array $body, int $permitted
): void {
    // Two, so the third request is the refusal. The configured allowances are deliberately
    // generous enough that driving them literally would be 30 or 60 requests per case for
    // no extra information -- what is under test is that the bucket BITES, and `R-045`'s
    // case above is what pins the numbers themselves to the config.
    config(["ratelimit.buckets.{$bucket}.per_ip.attempts" => 2]);

    $send = fn () => $method === 'getJson'
        ? $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->getJson($path)
        : $this->withServerVariables(['REMOTE_ADDR' => $this->ip])->{$method}($path, $body);

    // THE POSITIVE CONTROL: the first two are answered by the ROUTE, whatever that answer
    // is. Without this a 429 on the third would be indistinguishable from a surface that
    // refuses everything -- and two of these paths answer 401 when permitted, which is
    // exactly the confusion worth ruling out.
    expect($send()->status())->toBe($permitted, "{$bucket}: first request")
        ->and($send()->status())->toBe($permitted, "{$bucket}: second request");

    $refused = $send();

    $refused->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);

    // And the refusal names neither the bucket nor the mechanism, so a caller probing the
    // surface learns nothing about how it is apportioned.
    expect((string) $refused->getContent())->not->toContain($bucket)
        ->not->toContain('ratelimit');
})->with('auth surface buckets');

it('refuses past devices-issue, the fourth bucket the ticket named, on both of its dimensions', function (): void {
    // The one new bucket with a PER-TENANT half as well, which is why it is driven apart
    // from the dataset above: minting a device token happens inside a resolved tenant, so
    // `R-063`'s "no tenant before the credential resolves" does not apply and both keys
    // exist. Each dimension is driven on its own, because a single run cannot show which
    // of the two refused.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $issue = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->postJson('/v1/devices', [
            'name' => 'kds-'.random_int(1000, 9999),
            'ability' => Ability::PagerRead->value,
            'ttl_minutes' => 60,
        ]);

    config(['ratelimit.buckets.devices-issue.per_ip.attempts' => 2]);

    expect($issue()->status())->toBe(201)->and($issue()->status())->toBe(201);

    $issue()->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);

    // NOW THE PER-TENANT HALF, from a different address so the per-IP counter above cannot
    // be the thing that refuses. This is the dimension `R-085` exists about on the customer
    // surface, and the one place at this task where it is actually present.
    app(RequestLimiter::class)->clear('ratelimit:devices-issue:tenant:'.$tenant);

    config([
        'ratelimit.buckets.devices-issue.per_ip.attempts' => 100,
        'ratelimit.buckets.devices-issue.per_tenant.attempts' => 2,
    ]);

    $fromElsewhere = fn () => $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/v1/devices', [
            'name' => 'kds-'.random_int(1000, 9999),
            'ability' => Ability::PagerRead->value,
            'ttl_minutes' => 60,
        ]);

    expect($fromElsewhere()->status())->toBe(201)->and($fromElsewhere()->status())->toBe(201);

    $fromElsewhere()->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);
});

/*
 * ================================================================================
 * THE PATHS THE PER-ROUTE BUCKETS CANNOT SEE
 * ================================================================================
 *
 * `SEC H-1` at the `§11` review of Task 5's fix diff. The five auth buckets are named PER
 * ROUTE, and Laravel runs GROUP middleware first — so every refusal the session stack,
 * `ValidateCsrfToken`, `ResolvePrincipal` or `RefuseDeviceToken` produced happened before
 * any bucket was consulted. Measured at 100 requests per form: a valid device token got
 * 100 × 403 with zero 429s; `Bearer zzz|q` got 100 × 422 with zero 429s, each costing a
 * FAILED Postgres statement and needing no credential; a CSRF-less login got 100 × 419 with
 * zero 429s, each creating a Redis session key with a 120-minute TTL.
 *
 * That last one is the attack, because Redis is the session store AND the limiter's own
 * backend, and `RateLimit` fails OPEN when it cannot reach Redis (`R-027`).
 *
 * The `auth-surface` bucket now runs FIRST in the group. These cases drive each of the THREE
 * refusal forms past it — and the point of driving all three rather than one is that each is
 * refused by a DIFFERENT entry of the group, so a fix that only moved past one would pass a
 * single case: `ValidateCsrfToken` answers 419, `ResolvePrincipal`'s token lookup answers 422,
 * and `RefuseDeviceToken` answers 403.
 *
 * ⚠ THREE AND NOT FOUR. This said "four forms", counting the per-route policy as a fourth —
 * and the `§11` review of this diff was right that the count is the stated reason the cases
 * are trustworthy, so getting it wrong undercuts them. Of the six group entries below the
 * limiter only those three can refuse: `EncryptCookies` swallows a `DecryptException`, and
 * `StartSession` and `AddQueuedCookiesToResponse` refuse nothing. The per-route buckets are a
 * separate property and have their own case below.
 */
dataset('unmetered auth-surface forms', [
    // [label => [how to arm the form, method, path, body, headers]]
    // Each is refused by a DIFFERENT entry of the group, which is the point of driving all
    // of them: a fix that moved past only one would pass a single case.
    'a malformed bearer, refused inside ResolvePrincipal\'s token lookup' => ['bearer'],
    'a state-changing POST with no CSRF token, refused by ValidateCsrfToken' => ['csrf'],
]);

it('meters every auth-surface refusal, whichever group middleware produced it', function (string $form): void {
    // NO SESSION. This file's beforeEach authenticates, and ResolvePrincipal tries `web`
    // first -- so with a live session a stray Authorization header is simply ignored and
    // `me` answers 200. Measured exactly that way while writing this case.
    auth()->forgetGuards();
    app('auth')->guard('web')->logout();
    session()->flush();

    if ($form === 'csrf') {
        // CSRF is skipped under the harness (`runningUnitTests()`), so the only honest way
        // to drive its refusal is to bind the middleware with that escape removed -- the
        // same device SessionPolicyTest uses, and for the same reason.
        ValidateCsrfToken::except([]);

        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    $send = fn () => $form === 'bearer'
        ? $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->getJson('/v1/auth/me', ['Authorization' => 'Bearer zzz|q'])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->postJson('/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x']);

    // Two on the COARSE bucket, so the third is refused by it. The per-route buckets keep
    // their configured values: if this fix were reverted these forms would never reach one.
    config(['ratelimit.buckets.auth-surface.per_ip.attempts' => 2]);

    $first = $send();
    $second = $send();

    // THE POSITIVE CONTROL, and it is what makes this a statement about ORDERING: the first
    // two are refused by the group middleware under test -- 422 for the token lookup, 419
    // for CSRF -- and NOT by the limiter.
    // ⚠ 401 AND NOT 422 FOR THE BEARER FORM, since `R-110` is now closed: a malformed token
    // id is shape-checked in `App\Models\PersonalAccessToken::findToken()` before the query,
    // so an authentication failure answers the ordinary 401 and sends no statement. The first
    // version of this line pinned 422, which coupled the limiter-ordering case to an accepted
    // risk -- it would have gone red when that risk was closed, which is exactly what then
    // happened.
    $expected = $form === 'bearer' ? 401 : 419;

    expect($first->status())->toBe($expected, 'the form was not refused by the middleware it is about')
        ->and($second->status())->toBe($expected);

    $refused = $send();

    $refused->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);

    expect((string) $refused->getContent())->not->toContain('auth-surface')
        ->not->toContain('ratelimit');
})->with('unmetered auth-surface forms');

it('meters a valid device token refused on the auth surface', function (): void {
    // The fourth form, apart because it needs a real token minted through the real
    // endpoint. RefuseDeviceToken 403s it on the group, which is correct and was unmetered.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $issued = $this->postJson('/v1/devices', [
        'name' => 'kds-metering',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 60,
    ]);

    $issued->assertStatus(201);

    auth()->forgetGuards();
    app('auth')->guard('web')->logout();

    $headers = ['Authorization' => 'Bearer '.$issued->json('device.token')];

    config(['ratelimit.buckets.auth-surface.per_ip.attempts' => 2]);

    $send = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->getJson('/v1/auth/me', $headers);

    // The positive control is the 403 itself: the token authenticates and is refused by
    // RefuseDeviceToken, not by the limiter.
    expect($send()->status())->toBe(403)->and($send()->status())->toBe(403);

    $send()->assertStatus(429)->assertJsonPath('type', ProblemType::RateLimited->value);
});

it('leaves the per-route buckets as the policy, with the coarse one only a backstop', function (): void {
    // The coarse bucket must not have REPLACED the tight ones. `auth-login` is 10/min in
    // front of a bcrypt; if the group's 120 were the only thing counting, a password
    // guessing loop would get twelve times the allowance it is supposed to have.
    config(['ratelimit.buckets.auth-login.per_ip.attempts' => 2]);

    $login = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
        ->postJson('/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x']);

    // CSRF is skipped under the test harness, so these reach the route and its bucket.
    expect($login()->status())->toBe(401)->and($login()->status())->toBe(401);

    $login()->assertStatus(429);

    // And the coarse bucket is nowhere near exhausted, so the refusal above is the tight
    // one -- which is what makes this a statement about BOTH layers.
    expect((int) config('ratelimit.buckets.auth-surface.per_ip.attempts'))->toBe(120);
});

it('leaves no route on the auth surface without a bucket, and says which one is the exception', function (): void {
    /*
     * Both `§11` reviewers of the fix diff asked for this, and it is the same fail-closed
     * argument `RefuseDeviceToken` makes for itself — "a route added to routes/auth.php
     * INHERITS the refusal instead of having to remember it" — applied to the limiter,
     * which is the thing that was forgotten in the first place.
     *
     * `routes/auth.php` asserts "EVERY ROUTE HERE NAMES A BUCKET" and nothing made that
     * true. The two existing closed-world checks cannot: `RoleMatrixTest`'s `$auth` set is
     * a name allowlist, and `DeviceTokenOnAuthSurfaceTest`'s last case pins the route SET
     * on the group and asks nothing about middleware.
     *
     * The coarse `auth-surface` bucket on the group means no route is unmetered any more
     * even if it names nothing — so what this case protects is the POLICY: a login must not
     * silently fall back to 120/min because somebody forgot its own bucket.
     */
    $missing = [];
    $buckets = [];

    foreach (Route::getRoutes() as $route) {
        $gathered = $route->gatherMiddleware();

        if (! in_array('auth-surface', $gathered, true)) {
            continue;
        }

        $name = (string) ($route->getName() ?? $route->uri());

        $named = array_values(array_filter(
            $gathered,
            fn ($entry): bool => is_string($entry)
                && str_starts_with($entry, RateLimit::class.':')
                && $entry !== RateLimit::class.':auth-surface',
        ));

        if ($named === []) {
            $missing[] = $name;

            continue;
        }

        expect($named)->toHaveCount(1, "{$name} names more than one bucket: ".implode(', ', $named));

        $buckets[$name] = substr((string) $named[0], strlen(RateLimit::class.':'));
    }

    sort($missing);

    // THE ONE DECLARED EXCEPTION, and it is declared here rather than inferred: the harness
    // page is not production surface (absent from the route table under `production`) and
    // `PLAN.md §4a` records why it carries no bucket of its own.
    expect($missing)->toBe(['harness.passkeys'],
        'a route on the auth surface names no rate-limit bucket: '.implode(', ', $missing),
    );

    // AND THE COARSE BUCKET IS ON THE GROUP, FIRST — so "no bucket of its own" never means
    // "unmetered", and the ORDERING that was the defect is pinned rather than described.
    //
    // Read from the group definition and not from `gatherMiddleware()`: that returns the
    // group NAME for a grouped route and expands it only at dispatch, so a per-route
    // assertion here would look for something that is not in the list. Measured while
    // writing this case.
    $group = (array) app('router')->getMiddlewareGroups()['auth-surface'];

    expect($group[0] ?? null)->toBe(RateLimit::class.':auth-surface',
        'the limiter is not the FIRST entry of the auth-surface group, so every refusal the '
        .'entries before it produce is unmetered -- which is exactly the defect this pins',
    );

    // And it runs before each of the four that were refusing unmetered.
    foreach ([StartSession::class, ValidateCsrfToken::class, ResolvePrincipal::class, RefuseDeviceToken::class] as $later) {
        $at = array_search($later, $group, true);

        expect($at)->not->toBeFalse($later.' is no longer on the auth-surface group');
        expect((int) $at)->toBeGreaterThan(0, $later.' runs before the limiter');
    }

    // Every bucket a route names exists in config, so a typo is a failure here rather than
    // a silent no-op. `R-045` makes the same check project-wide; this one is scoped to the
    // surface and runs even if that case is ever narrowed.
    foreach ($buckets as $name => $bucket) {
        expect((array) config("ratelimit.buckets.{$bucket}"))->not->toBe([],
            "{$name} names bucket [{$bucket}], which config/ratelimit.php does not define");
    }

    // ⚠ AND THE VALUES, not just the names. The `§11` review of this diff pointed out that
    // `R-045` checks a bucket EXISTS and has at least one dimension, and nothing asserted any
    // bucket's allowance except `auth-surface`'s 120 -- so `auth-login` could be raised to
    // 120 and the whole suite would stay green. That is the failure direction that matters:
    // twelve times the password-guessing allowance in front of a bcrypt, with no red test.
    expect([
        'auth-login' => (int) config('ratelimit.buckets.auth-login.per_ip.attempts'),
        'auth-2fa' => (int) config('ratelimit.buckets.auth-2fa.per_ip.attempts'),
        'auth-passkey' => (int) config('ratelimit.buckets.auth-passkey.per_ip.attempts'),
        'auth-session' => (int) config('ratelimit.buckets.auth-session.per_ip.attempts'),
        'auth-surface' => (int) config('ratelimit.buckets.auth-surface.per_ip.attempts'),
    ])->toBe([
        // A bcrypt at 12 rounds. The tightest thing on the surface after the 2FA code.
        'auth-login' => 10,
        // Six digits in a thirty-second window: a million guesses is a real attack.
        'auth-2fa' => 5,
        // An EC signature check and a session write, three requests per sign-in attempt.
        'auth-passkey' => 30,
        // A dashboard asks `me` on every page load.
        'auth-session' => 60,
        // The coarse backstop, deliberately the loosest.
        'auth-surface' => 120,
    ]);

    // And every per-route bucket is strictly tighter than the coarse one, which is what makes
    // the group-level limiter a backstop rather than the policy. 10+5+30+60 = 105 <= 120, so
    // no mix of legitimate traffic can hit the coarse ceiling before a per-route bucket.
    foreach (['auth-login', 'auth-2fa', 'auth-passkey', 'auth-session'] as $tight) {
        expect((int) config("ratelimit.buckets.{$tight}.per_ip.attempts"))
            ->toBeLessThan((int) config('ratelimit.buckets.auth-surface.per_ip.attempts'),
                "{$tight} is not tighter than the coarse backstop, so the backstop would bind first");
    }

    // The twelve /v1/auth routes, each with the bucket it should have — so a route that is
    // silently MOVED to a looser bucket fails here too, which a presence check cannot catch.
    // Sorted, because `toBe` on an associative array compares key ORDER as well, and the
    // router's order is the registration order rather than anything meaningful.
    ksort($buckets);

    expect($buckets)->toBe([
        'auth.login' => 'auth-login',
        'auth.logout' => 'auth-session',
        'auth.me' => 'auth-session',
        'auth.passkeys.login' => 'auth-passkey',
        'auth.passkeys.login-options' => 'auth-passkey',
        'auth.passkeys.options' => 'auth-passkey',
        'auth.passkeys.register' => 'auth-passkey',
        'auth.tenant' => 'auth-login',
        'auth.two-factor.challenge' => 'auth-2fa',
        'auth.two-factor.confirm' => 'auth-2fa',
        'auth.two-factor.disable' => 'auth-login',
        'auth.two-factor.enable' => 'auth-login',
    ]);
});
