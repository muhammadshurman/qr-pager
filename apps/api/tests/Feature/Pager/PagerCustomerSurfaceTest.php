<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\TokenResolutionFailures;
use App\Platform\Readiness\ReadinessSnapshot;
use App\Platform\Readiness\SaturationProbe;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushFixtures;

/*
 * THE CUSTOMER SURFACE: the only routes in this project with no account behind them.
 *
 * §4 › Tenancy, "two authentication surfaces, never confused": staff authenticate as
 * users and every staff route is behind RequireTenantScope; a customer has no account and
 * authenticates ONLY by presenting the QR token. These cases are what keeps the two from
 * being confused in either direction -- a customer route that demanded a tenant would
 * answer 401 to every real customer, and one that ACCEPTED a tenant from the request
 * would be a tenant-switching parameter on an unauthenticated path.
 */

function liveToken(string $tenantId, ?string &$sessionId = null, string $reference = 'A-100'): string
{
    $issued = app(PagerSessions::class)->create($tenantId, $reference);
    $sessionId = $issued->session->id;

    return $issued->token->reveal();
}

function pastTtlToken(string $tenantId, ?string &$sessionId = null): string
{
    $issued = app(PagerSessions::class)->create($tenantId, 'A-200');
    $sessionId = $issued->session->id;

    // Through the owner connection inside a scope: the test is placing a precondition, and
    // the app role cannot write a past expires_at anyway. §2.10's pattern, same as
    // PagerTtlTest.
    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->update(
        'update pager_sessions set expires_at = now() - interval \'1 minute\' where id = ?',
        [$issued->session->id],
    ), 'pgsql_owner');

    return $issued->token->reveal();
}

it('needs no tenant context at all, which is the whole point of the token', function (): void {
    // NOT on RequireTenantScope. Every staff route answers 401 without actingForTenant();
    // these four must not, because a customer has no account for a tenant to be resolved
    // from. This case is the one that would catch somebody "tidying" routes/customer.php
    // onto the api group.
    $tenant = $this->makeTenant();
    $token = liveToken($tenant);

    // Deliberately NO actingForTenant() anywhere in this test.
    $this->postJson('/v1/pager/session', ['token' => $token])->assertOk();
    $this->postJson('/v1/pager/status', ['token' => $token])->assertOk();

    // And the contrast, in the same test so the asymmetry is the assertion: the staff
    // route next door, with the same absence of a tenant, is refused.
    $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-1'])->assertStatus(401);
});

it('accepts no tenant from the request, on any route or any field', function (): void {
    // There is no tenant field in any customer body, so the test is that supplying one
    // changes nothing -- it is ignored as an unknown key rather than honoured.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $token = liveToken($alpha, $sessionId, 'ALPHA-1');

    $response = $this->postJson('/v1/pager/session', [
        'token' => $token,
        'tenant_id' => $beta,
        'tenant' => $beta,
        'session_id' => 'anything',
    ]);

    $response->assertOk()->assertJsonPath('order_reference', 'ALPHA-1');
});

it('tells an expired session apart from an unknown one, which is gate clause (a)', function (): void {
    // "A presented token that resolves to a session past its TTL is refused, and
    // DISTINGUISHABLY." Two different statuses AND two different `type` values, because
    // `type` is the part of a problem document a client is told it may switch on, and
    // §4.1 requires the PWA to handle expired-token and invalid-token as different paths.
    $tenant = $this->makeTenant();

    $expired = $this->postJson('/v1/pager/session', ['token' => pastTtlToken($tenant)]);
    $unknown = $this->postJson('/v1/pager/session', ['token' => str_repeat('Z', 22)]);

    $expired->assertStatus(409)->assertJsonPath('type', ProblemType::Expired->value);
    $unknown->assertStatus(404)->assertJsonPath('type', ProblemType::NotFound->value);

    expect($expired->status())->not->toBe($unknown->status())
        ->and($expired->json('type'))->not->toBe($unknown->json('type'));
});

it('decides expiry from the clock and not from status, because status lags', function (): void {
    // pager:expire is scheduled nowhere (R-058), so a session past its TTL is still
    // `waiting` in the database -- possibly forever. hasExpired() computes from expires_at
    // against the clock for exactly that reason, and this is the case that proves the
    // endpoint uses it rather than reading status.
    $tenant = $this->makeTenant();
    $token = pastTtlToken($tenant, $sessionId);

    $status = withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->selectOne('select status from pager_sessions where id = ?', [$sessionId]), 'pgsql_owner');

    expect($status->status)->toBe(PagerStatus::Waiting->value, 'the premise is gone: status was already expired');

    $this->postJson('/v1/pager/session', ['token' => $token])->assertStatus(409);
});

it('writes no projection for an expired session, so a poll cannot resurrect it', function (): void {
    // The 409 is returned BEFORE remember() is reached. A projection written here would
    // keep answering 200 to a poll for as long as its TTL ran, which is a live status for
    // a session that is over.
    $tenant = $this->makeTenant();
    $token = pastTtlToken($tenant);

    $this->postJson('/v1/pager/session', ['token' => $token])->assertStatus(409);
    $this->postJson('/v1/pager/status', ['token' => $token])
        ->assertStatus(404)
        ->assertJsonPath('type', ProblemType::Stale->value);
});

it('registers a device once and updates it thereafter, so repeating is safe', function (): void {
    $tenant = $this->makeTenant();
    $token = liveToken($tenant, $sessionId);
    $device = PushFixtures::subscription();

    $body = ['token' => $token, 'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth']];

    $this->postJson('/v1/pager/subscription', $body)->assertStatus(201);

    // The browser re-derives its keys and re-subscribes on every page load. That is the
    // ordinary case, not a conflict: UNIQUE (pager_session_id, endpoint) makes it one row.
    $rotated = PushFixtures::subscription($device['endpoint']);
    $this->postJson('/v1/pager/subscription', [
        'token' => $token, 'endpoint' => $device['endpoint'], 'p256dh' => $rotated['p256dh'], 'auth' => $rotated['auth'],
    ])->assertStatus(200);

    $rows = withTenant($tenant, fn () => DB::connection('pgsql_owner')->select(
        'select p256dh from push_subscriptions where pager_session_id = ?', [$sessionId]), 'pgsql_owner');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->p256dh)->toBe($rotated['p256dh'], 'the keys were not updated');
});

it('removes a device, and removing one that is not there still succeeds', function (): void {
    $tenant = $this->makeTenant();
    $token = liveToken($tenant, $sessionId);
    $device = PushFixtures::subscription();

    $this->postJson('/v1/pager/subscription', [
        'token' => $token, 'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth'],
    ])->assertStatus(201);

    $this->deleteJson('/v1/pager/subscription', ['token' => $token, 'endpoint' => $device['endpoint']])->assertOk();
    $this->deleteJson('/v1/pager/subscription', ['token' => $token, 'endpoint' => $device['endpoint']])->assertOk();

    $rows = withTenant($tenant, fn () => DB::connection('pgsql_owner')->select(
        'select id from push_subscriptions where pager_session_id = ?', [$sessionId]), 'pgsql_owner');

    expect($rows)->toBe([]);
});

it('refuses to register a device against an expired session', function (): void {
    // Otherwise the worker holds a subscription for a session nothing will ever notify,
    // and the row survives until the session's cascade removes it.
    $tenant = $this->makeTenant();
    $device = PushFixtures::subscription();

    $this->postJson('/v1/pager/subscription', [
        'token' => pastTtlToken($tenant),
        'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth'],
    ])->assertStatus(409)->assertJsonPath('type', ProblemType::Expired->value);
});

it('validates the subscription body against the database’s own bounds', function (): void {
    // Every bound is §2.5's, so a value that would come back as a 23514 or a 22001 is a
    // 422 with the field named instead.
    $tenant = $this->makeTenant();
    $token = liveToken($tenant);
    $good = PushFixtures::subscription();

    $cases = [
        'http endpoint' => ['endpoint' => 'http://push.example.test/x'],
        'oversized endpoint' => ['endpoint' => 'https://push.example.test/'.str_repeat('x', 2100)],
        'oversized p256dh' => ['p256dh' => str_repeat('A', 300)],
        'non base64url p256dh' => ['p256dh' => 'has spaces and !!'],
        'oversized auth' => ['auth' => str_repeat('A', 300)],
    ];

    foreach ($cases as $label => $override) {
        $body = array_merge([
            'token' => $token, 'endpoint' => $good['endpoint'], 'p256dh' => $good['p256dh'], 'auth' => $good['auth'],
        ], $override);

        $this->postJson('/v1/pager/subscription', $body)
            ->assertStatus(422, "{$label} should be refused as validation")
            ->assertJsonPath('type', ProblemType::Validation->value);
    }
});

it('says nothing about the shape of the secret when a token fails validation', function (): void {
    // A validation message on a public, unauthenticated surface is a free hint about the
    // secret's alphabet and length. Both messages are the same sentence on purpose.
    $missing = $this->postJson('/v1/pager/session', [])->assertStatus(422);
    $wrongSize = $this->postJson('/v1/pager/session', ['token' => 'short'])->assertStatus(422);

    foreach ([$missing, $wrongSize] as $response) {
        $body = $response->getContent();

        expect($body)->toContain('not a valid pager link')
            ->and(strtolower((string) $body))->not->toContain('base64')
            ->and(strtolower((string) $body))->not->toContain('22 char')
            ->and($body)->not->toContain('128');
    }
});

it('counts a failed resolution so an enumeration is observable — R-063', function (): void {
    // The limiter REFUSES; this is the TRACE. Without it a sustained enumeration leaves
    // nothing in audit_log and no metric either, which is exactly what R-063 found.
    $failures = app(TokenResolutionFailures::class);
    $now = new DateTimeImmutable;
    $before = $failures->count($now);

    $this->postJson('/v1/pager/session', ['token' => str_repeat('Q', 22)])->assertStatus(404);
    $this->postJson('/v1/pager/session', ['token' => str_repeat('R', 22)])->assertStatus(404);

    expect($failures->count($now))->toBe($before + 2);
});

it('writes no audit_log row for a failed resolution, which is the decision R-063 asked for', function (): void {
    // Not a preference: with no tenant context the audit policy's WITH CHECK is NULL and
    // the insert is refused 42501, so it is not possible without a bypass -- and
    // audit_log is append-only and unbounded (§2.11), so an unauthenticated loop writing
    // a permanent row per attempt is a worse outcome than the enumeration it records.
    $before = DB::connection('pgsql_root')->selectOne('select count(*) as n from audit_log');

    $this->postJson('/v1/pager/session', ['token' => str_repeat('S', 22)])->assertStatus(404);

    $after = DB::connection('pgsql_root')->selectOne('select count(*) as n from audit_log');

    expect((int) $after->n)->toBe((int) $before->n);

    // And the structural half, measured rather than argued: the insert really is refused.
    expect($this->sqlStateOf(fn () => DB::table('audit_log')->insert([
        'tenant_id' => null, 'actor_type' => 'system', 'action' => $this->auditAction('probe'),
        'context' => $this->auditContext(),
    ])))->toBe('42501');
});

it('sheds the live timer and never the alert path, which is the stated order', function (): void {
    // §4 › Edge & ops: "Load shedding drops nice-to-haves (live timer, analytics) BEFORE
    // alerts." So only the polling route carries ShedLoad. Shedding `session` or
    // `subscription` would stop a customer ever receiving a push, which drops the alert
    // first and the nice-to-have never -- the stated order exactly inverted.
    $tenant = $this->makeTenant();
    $token = liveToken($tenant);
    $device = PushFixtures::subscription();

    $this->postJson('/v1/pager/session', ['token' => $token])->assertOk();

    // Publish a saturated snapshot, which is what ShedLoad reads.
    $probe = app(SaturationProbe::class);
    $snapshot = $probe->measure(time());
    $probe->publish(new ReadinessSnapshot(
        appBackends: 1000, appLimit: 1000, postgresUp: true, redisUp: true, measuredAt: time(),
    ));

    $this->postJson('/v1/pager/status', ['token' => $token])
        ->assertStatus(503)
        ->assertJsonPath('type', ProblemType::Unavailable->value);

    // The alert path keeps answering under the same pressure.
    $this->postJson('/v1/pager/session', ['token' => $token])->assertOk();
    $this->postJson('/v1/pager/subscription', [
        'token' => $token, 'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth'],
    ])->assertStatus(201);
});

it('puts no token in any response body, on any answer', function (): void {
    $tenant = $this->makeTenant();
    $token = liveToken($tenant);

    $bodies = [
        $this->postJson('/v1/pager/session', ['token' => $token])->getContent(),
        $this->postJson('/v1/pager/status', ['token' => $token])->getContent(),
        $this->postJson('/v1/pager/session', ['token' => str_repeat('T', 22)])->getContent(),
        $this->postJson('/v1/pager/subscription', ['token' => $token, 'endpoint' => 'http://nope'])->getContent(),
    ];

    foreach ($bodies as $index => $body) {
        // str_contains(), not ->not->toContain($token, "message"): Pest reads the message
        // as a second needle and ->not then means "not both", which is trivially true.
        expect(str_contains((string) $body, $token))->toBeFalse("response {$index} echoed the token");
    }
});
