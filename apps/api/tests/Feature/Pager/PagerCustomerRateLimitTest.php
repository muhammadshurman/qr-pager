<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\TokenResolutionFailures;
use App\Platform\Http\RequestLimiter;
use Tests\Support\PushFixtures;

/*
 * R-063, the Medium this task owns, and the clause Muhammad wrote into the §3 gate for it
 * BEFORE this task began rather than after a review of it:
 *
 *   "The token endpoint carries its own rate-limit bucket, tighter than `api` and keyed
 *    per IP -- a token carries no tenant until it resolves, so the per-tenant half cannot
 *    apply before the lookup -- failed resolutions are counted so an enumeration is
 *    observable, and THE BUCKET IS PROVEN TO REFUSE BY DRIVING IT rather than by reading
 *    the config."
 *
 * "By driving it" is the part that matters and it is why there is no assertion in this
 * file about config/ratelimit.php's numbers: a test that reads the config and checks the
 * config proves the config equals itself. Every case below sends real requests until the
 * endpoint refuses.
 */

beforeEach(function (): void {
    // A known starting point WITHIN the test, which is RateLimitTest's convention. The
    // suite-wide sweep in TestCase::setUp() clears between tests; this makes the count
    // inside one test start at zero even if a helper above has spent some.
    $limiter = app(RequestLimiter::class);

    foreach (['pager-token', 'pager-poll', 'pager-subscribe'] as $bucket) {
        $limiter->clear("ratelimit:{$bucket}:ip:".sha1('127.0.0.1'));
    }
});

it('refuses the token endpoint once its own bucket is spent, proven by driving it', function (): void {
    $allowed = (int) config('ratelimit.buckets.pager-token.per_ip.attempts');

    // A WELL-FORMED, UNKNOWN token on every request, which is the enumeration R-063 is
    // about -- 22 random base64url characters are free to generate, and each one costs a
    // sha256, a plpgsql call with two set_config statements, an index probe and a round
    // trip. The limiter is what bounds that loop.
    for ($i = 0; $i < $allowed; $i++) {
        $this->postJson('/v1/pager/session', ['token' => str_pad((string) $i, 22, 'a', STR_PAD_LEFT)])
            ->assertStatus(404, "request {$i} of {$allowed} was refused before the bucket was spent");
    }

    $refused = $this->postJson('/v1/pager/session', ['token' => str_repeat('z', 22)]);

    $refused->assertStatus(429)
        ->assertJsonPath('type', ProblemType::RateLimited->value)
        ->assertHeader('Retry-After');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('is tighter than the api bucket, which is the clause’s own comparison', function (): void {
    // Stated as a RELATION rather than as a number, so it survives Task 14 re-sizing both
    // from load results (C6) and still fails if somebody loosens the customer bucket past
    // the staff one.
    $token = (int) config('ratelimit.buckets.pager-token.per_ip.attempts');
    $api = (int) config('ratelimit.buckets.api.per_ip.attempts');

    expect($token)->toBeLessThan($api);
});

it('has no per-tenant half on any customer bucket, because a token carries no tenant', function (): void {
    // The clause's own parenthesis, asserted: "a token carries no tenant until it
    // resolves, so the per-tenant half cannot apply before the lookup". Resolving one in
    // order to rate-limit the request that asks for it is work an unauthenticated caller
    // could make this process do.
    foreach (['pager-token', 'pager-poll', 'pager-subscribe'] as $bucket) {
        expect(config("ratelimit.buckets.{$bucket}.per_tenant"))->toBeNull("{$bucket} has a per-tenant limit");
        expect(config("ratelimit.buckets.{$bucket}.per_ip"))->not->toBeNull("{$bucket} has no per-IP limit");
    }
});

it('refuses the poll bucket too, so the cheapest route is still bounded', function (): void {
    // Two Redis reads is cheap and not free, and this is the route a waiting device hits
    // every few seconds, so it is the one an attacker gets the most attempts against.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-900');
    $this->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();

    $allowed = (int) config('ratelimit.buckets.pager-poll.per_ip.attempts');

    for ($i = 0; $i < $allowed; $i++) {
        $this->postJson('/v1/pager/status', ['token' => $issued->token->reveal()])->assertOk();
    }

    $this->postJson('/v1/pager/status', ['token' => $issued->token->reveal()])->assertStatus(429);
});

it('refuses the subscription bucket, which is the one customer route that writes', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-901');
    $allowed = (int) config('ratelimit.buckets.pager-subscribe.per_ip.attempts');

    for ($i = 0; $i < $allowed; $i++) {
        $device = PushFixtures::subscription();

        $this->postJson('/v1/pager/subscription', [
            'token' => $issued->token->reveal(),
            'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth'],
        ])->assertStatus(201);
    }

    $last = PushFixtures::subscription();

    $this->postJson('/v1/pager/subscription', [
        'token' => $issued->token->reveal(),
        'endpoint' => $last['endpoint'], 'p256dh' => $last['p256dh'], 'auth' => $last['auth'],
    ])->assertStatus(429);
});

it('counts a refused request against the bucket and not against the failure counter', function (): void {
    // The two observables answer different questions and must not be conflated: the bucket
    // counts REQUESTS (what an attacker spends) and the failure counter counts FAILED
    // RESOLUTIONS (what an attacker learns nothing from). A 429 never reaches the
    // controller, so it cannot increment the counter -- which keeps the counter a measure
    // of enumeration rather than of throttling.
    $failures = app(TokenResolutionFailures::class);
    $allowed = (int) config('ratelimit.buckets.pager-token.per_ip.attempts');

    for ($i = 0; $i < $allowed; $i++) {
        $this->postJson('/v1/pager/session', ['token' => str_pad((string) $i, 22, 'b', STR_PAD_LEFT)])->assertStatus(404);
    }

    $now = new DateTimeImmutable;
    $afterSpending = $failures->count($now);

    $this->postJson('/v1/pager/session', ['token' => str_repeat('y', 22)])->assertStatus(429);

    expect($failures->count($now))->toBe($afterSpending, 'a throttled request was counted as a failed resolution');
});
