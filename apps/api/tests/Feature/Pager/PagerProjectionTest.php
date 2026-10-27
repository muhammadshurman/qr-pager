<?php

declare(strict_types=1);

use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\PagerStatusView;
use App\Features\Pager\ResolvedPagerSession;
use Illuminate\Support\Str;

/*
 * REDIS CARRIES NO ROW LEVEL SECURITY, which is the sharpest edge in this task and the
 * reason QRPAGER-11 has a comment of its own about it:
 *
 *   "Everything Task 1 built -- three roles, FORCE RLS on every table, per-role policies,
 *    column grants, the leak test across a pooled connection -- STOPS AT THE POSTGRES
 *    CONNECTION. Redis enforces nothing. A key is a key."
 *
 * The gate clause that follows from it is: "every Redis key holding tenant data is
 * namespaced by tenant id, and A TEST PROVES one tenant's projection cannot be read
 * through another tenant's context". This file is that test, plus the properties the
 * namespacing rests on.
 */

it('namespaces the projection by tenant id, in the exact shape the gate names', function (): void {
    // `t:{tenant_id}:pager:{session_id}` is prescribed verbatim by QRPAGER-11's own
    // comment -- "Namespace it: t:{tenant_id}:pager:{session_id}. Then a wrong tenant
    // cannot construct the key even with the right session id." Asserted against the
    // literal shape rather than against a second copy of the builder, because a test that
    // calls the same method it is checking proves only that the method is deterministic.
    $tenant = '11111111-1111-1111-1111-111111111111';
    $session = '22222222-2222-2222-2222-222222222222';

    expect(PagerProjection::projectionKey($tenant, $session))
        ->toBe('t:11111111-1111-1111-1111-111111111111:pager:22222222-2222-2222-2222-222222222222')
        ->toStartWith('t:'.$tenant.':');
});

it('cannot be read through another tenant’s context, even with the right session id', function (): void {
    // THE GATE CLAUSE, driven rather than described. Two tenants, each with a scanned
    // session; then every cross pairing of (tenant, session) is attempted against Redis.
    // Only the two correct pairs resolve.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $alphaIssued = app(PagerSessions::class)->create($alpha, 'ALPHA-1');
    $betaIssued = app(PagerSessions::class)->create($beta, 'BETA-1');

    $this->postJson('/v1/pager/session', ['token' => $alphaIssued->token->reveal()])->assertOk();
    $this->postJson('/v1/pager/session', ['token' => $betaIssued->token->reveal()])->assertOk();

    $redis = app('redis')->connection();

    $pairs = [
        'alpha tenant, alpha session' => [$alpha, $alphaIssued->session->id, true],
        'beta tenant, beta session' => [$beta, $betaIssued->session->id, true],
        // The attack: tenant B knows (or guesses) tenant A's session id and builds a key
        // with its OWN tenant id, which is the only one it could ever have.
        'beta tenant, ALPHA session' => [$beta, $alphaIssued->session->id, false],
        'alpha tenant, BETA session' => [$alpha, $betaIssued->session->id, false],
    ];

    foreach ($pairs as $label => [$tenantId, $sessionId, $shouldExist]) {
        $exists = $redis->exists(PagerProjection::projectionKey($tenantId, $sessionId)) === 1;

        expect($exists)->toBe($shouldExist, "{$label}: expected ".($shouldExist ? 'a hit' : 'nothing'));
    }
});

it('takes the tenant from the pointer the server wrote, never from the caller', function (): void {
    // The namespacing is only worth anything if the tenant id cannot come from input. It
    // comes from the pointer's VALUE, which remember() wrote from a ResolvedPagerSession,
    // and remember()'s signature is what makes that structural: it takes the token and the
    // resolved session TOGETHER, so there is no call shape in which a session is filed
    // under a token it does not belong to.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $alphaIssued = app(PagerSessions::class)->create($alpha, 'ALPHA-2');
    $this->postJson('/v1/pager/session', ['token' => $alphaIssued->token->reveal()])->assertOk();

    // Tenant beta's id in every field a caller controls. The answer is still alpha's
    // session, because none of those fields is read.
    $this->postJson('/v1/pager/status', [
        'token' => $alphaIssued->token->reveal(),
        'tenant_id' => $beta,
        'tenant' => $beta,
    ])->assertOk()->assertJsonPath('order_reference', 'ALPHA-2');
});

it('keys the pointer by the token’s own hash, which is the one derivation that exists', function (): void {
    // One source: PagerToken::hashHex() is what produces pager_sessions.token_hash, and
    // the pointer uses the same method rather than a second sha256 call that could drift
    // (a different encoding, a different input, a salt somebody added).
    $token = PagerToken::issue();

    expect(PagerProjection::pointerKey($token))->toBe('pager:tok:'.$token->hashHex())
        // And the raw token is NOT in the key, which is what keeps it out of a Redis
        // keyspace dump, a SCAN, a slowlog entry and a MONITOR stream.
        ->and(PagerProjection::pointerKey($token))->not->toContain($token->reveal());
});

it('never writes a projection that outlives the session it describes', function (): void {
    // The property the polling endpoint's honesty rests on: a token stops resolving in
    // Redis at the same moment it stops being valid, so a miss means "re-open" and the
    // re-open against Postgres is the only thing that can say "expired" rather than
    // "invalid".
    $projection = app(PagerProjection::class);
    $redis = app('redis')->connection();
    $now = new DateTimeImmutable;

    foreach ([60, 900, 7200, 999_999] as $secondsLeft) {
        $token = PagerToken::issue();
        $session = new ResolvedPagerSession(
            (string) Str::uuid7(),
            (string) Str::uuid7(),
            PagerStatus::Waiting,
            'A-1',
            $now->modify("+{$secondsLeft} seconds"),
            null,
            null,
        );

        expect($projection->remember($token, $session, $now))->toBeTrue();

        $pointerTtl = $redis->ttl(PagerProjection::pointerKey($token));
        $projectionTtl = $redis->ttl(PagerProjection::projectionKey($session->tenantId, $session->id));

        expect($pointerTtl)->toBeLessThanOrEqual($secondsLeft, "pointer outlives a {$secondsLeft}s session")
            ->and($projectionTtl)->toBeLessThanOrEqual($secondsLeft, "projection outlives a {$secondsLeft}s session")
            // ...and never longer than the configured ceiling either, which is what stops
            // a TTL raise on the session from silently raising the cache's.
            ->and($pointerTtl)->toBeLessThanOrEqual((int) config('pager.projection_ttl'))
            ->and($projectionTtl)->toBeLessThanOrEqual((int) config('pager.projection_ttl'))
            ->and($pointerTtl)->toBeGreaterThan(0);
    }
});

it('refreshes only what already exists, so an unscanned session gets no key', function (): void {
    $projection = app(PagerProjection::class);
    $tenant = (string) Str::uuid7();
    $session = (string) Str::uuid7();
    $now = new DateTimeImmutable;

    // XX against an absent key: false, and nothing written.
    expect($projection->refresh($tenant, $session, PagerStatus::Ready, 'A-1', $now->modify('+1 hour'), $now))->toBeFalse()
        ->and(app('redis')->connection()->exists(PagerProjection::projectionKey($tenant, $session)))->toBe(0);
});

it('round-trips a view and refuses one it cannot read, rather than guessing', function (): void {
    // Every failure mode lands on null: malformed JSON, a version from the future, a
    // missing member, a status this build does not have. None is an exception, because a
    // cache problem must not become a 500 on a screen a customer is watching.
    $now = new DateTimeImmutable('2026-10-27T10:00:00+00:00');
    $view = new PagerStatusView(PagerStatus::Ready, 'A-77', $now->modify('+2 hours'), $now);

    $decoded = PagerStatusView::decode($view->encode());

    expect($decoded)->not->toBeNull()
        ->and($decoded->status)->toBe(PagerStatus::Ready)
        ->and($decoded->orderReference)->toBe('A-77')
        ->and($decoded->expiresAt->getTimestamp())->toBe($now->modify('+2 hours')->getTimestamp());

    $rejected = [
        'not json',
        '[]',
        '{"v":2,"s":"ready","o":"A","x":"2026-10-27T10:00:00+00:00","a":"2026-10-27T10:00:00+00:00"}',
        '{"v":1,"s":"not-a-status","o":"A","x":"2026-10-27T10:00:00+00:00","a":"2026-10-27T10:00:00+00:00"}',
        '{"v":1,"s":"ready","x":"2026-10-27T10:00:00+00:00","a":"2026-10-27T10:00:00+00:00"}',
        '{"v":1,"s":"ready","o":"A","x":"not a time","a":"2026-10-27T10:00:00+00:00"}',
    ];

    foreach ($rejected as $payload) {
        expect(PagerStatusView::decode($payload))->toBeNull("decode() accepted: {$payload}");
    }
});

it('stores the status enum’s own value and keeps no vocabulary of its own', function (): void {
    // QRPAGER-11 §4.2: "The status enum stays one source, shared with Task 3. No second
    // copy for the projection." The encoded payload carries PagerStatus's value verbatim,
    // so there is nothing to drift.
    $now = new DateTimeImmutable;

    foreach (PagerStatus::cases() as $status) {
        $encoded = (new PagerStatusView($status, 'A-1', $now->modify('+1 hour'), $now))->encode();

        expect($encoded)->toContain('"s":"'.$status->value.'"');
        expect(PagerStatusView::decode($encoded)?->status)->toBe($status);
    }
});

it('answers false rather than null when Redis cannot be reached, so 503 is distinguishable', function (): void {
    // THREE-VALUED ON PURPOSE: a view, null for a miss, false for an outage. The polling
    // endpoint turns null into 404 Stale ("re-open") and false into 503 ("come back"), and
    // a customer feels the difference. Collapsing them would make a Redis outage look like
    // an invalid token.
    $token = PagerToken::issue();

    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    expect(app(PagerProjection::class)->read($token))->toBeFalse();
});
