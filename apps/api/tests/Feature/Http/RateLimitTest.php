<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\RequestLimiter;
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
    $this->ip = '203.0.113.'.random_int(1, 254);

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
