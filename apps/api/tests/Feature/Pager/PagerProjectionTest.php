<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\PagerStatusView;
use App\Features\Pager\ResolvedPagerSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

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

    expect(app(PagerProjection::class)->read($token, new DateTimeImmutable))->toBeFalse();
});

it('forgets both keys together, so a pointer never outlives its projection', function (): void {
    // Not on any request path -- tooling and tests -- but it has to drop both or the next
    // poll resolves a token to a projection that is gone, which costs two Redis reads to
    // reach the same Stale the pointer's own absence gives in one.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'F-1');

    $this->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();

    $token = PagerToken::fromPresented($issued->token->reveal());
    $redis = app('redis')->connection();

    expect($redis->exists(PagerProjection::pointerKey($token)))->toBe(1)
        ->and($redis->exists(PagerProjection::projectionKey($tenant, $issued->session->id)))->toBe(1);

    app(PagerProjection::class)->forget($token, $tenant, $issued->session->id);

    expect($redis->exists(PagerProjection::pointerKey($token)))->toBe(0)
        ->and($redis->exists(PagerProjection::projectionKey($tenant, $issued->session->id)))->toBe(0);
});

it('swallows every Redis failure rather than taking a request down with it', function (): void {
    // THE WHOLE POSTURE OF THIS CLASS, driven with the socket dead. A cache that throws is
    // a cache that turns an outage into a 500 on a screen a customer is watching, so every
    // method answers rather than raising -- read() false, the writes false, forget()
    // silent. §4 › Realtime's fail-open posture for the limiter is the same decision one
    // dependency over, and B7 is the precedent.
    $token = PagerToken::issue();
    $tenant = (string) Str::uuid7();
    $session = (string) Str::uuid7();
    $now = new DateTimeImmutable;

    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    $projection = app(PagerProjection::class);

    $resolved = new ResolvedPagerSession($session, $tenant, PagerStatus::Waiting, 'A-1', $now->modify('+1 hour'), null, null);

    expect($projection->read($token, new DateTimeImmutable))->toBeFalse()
        ->and($projection->remember($token, $resolved, $now))->toBeFalse()
        ->and($projection->refresh($tenant, $session, PagerStatus::Ready, 'A-1', $now->modify('+1 hour'), $now))->toBeFalse();

    // And forget() does not raise either, which is the one that returns nothing and so
    // could only be proved by the absence of an exception.
    $projection->forget($token, $tenant, $session);

    expect(true)->toBeTrue('forget() raised with Redis unreachable');
});

it('refuses a pointer it cannot read, rather than building a key from rubbish', function (): void {
    // The pointer's value is server-written, so a malformed one means Redis is holding
    // something this build did not write -- a rolling deploy, a manual edit, a collision.
    // Treated as a miss: the client re-opens and Postgres is the authority.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'P-9');

    $this->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();

    $token = PagerToken::fromPresented($issued->token->reveal());

    foreach (['not json', '[]', '{"t":"only-a-tenant"}', '{"s":"only-a-session"}', '{"t":1,"s":2}'] as $rubbish) {
        app('redis')->connection()->setex(PagerProjection::pointerKey($token), 60, $rubbish);

        expect(app(PagerProjection::class)->read($token, new DateTimeImmutable))->toBeNull("read() accepted the pointer: {$rubbish}");
    }
});

/*
 * ===================================================================================
 * R-074's THREE QUESTIONS, answered against measurement rather than against the code
 * ===================================================================================
 *
 * The pointer key is the one key in this task that is not tenant-namespaced, so what it
 * is BUILT FROM carries the whole weight. The cases below answer, in order: is it the
 * raw token or the stored hash; what invalidates it; and can it or its input reach a log
 * or an error body.
 */

it('keys the pointer on the SAME hash Postgres stores, not on the raw token', function (): void {
    // THE CRITICAL QUESTION, and it is deliberately NOT asked of hashHex() -- comparing
    // the key against the function that built it proves only that the function is
    // deterministic. It is asked of `pager_sessions.token_hash`, read back from Postgres
    // as the cluster superuser, because that column IS the definition of "the hash this
    // project stores" (§4 › Data: "stored only as a hash").
    //
    // If the key were the raw token, it would be recoverable from Redis's slowlog, from
    // MONITOR, from a connection error, and from any RDB or AOF artifact -- a Critical
    // against §4 › Data. It is not: it is the hex of the same sha256 the bytea column
    // holds.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'H-1');
    $raw = $issued->token->reveal();

    // encode(..., 'hex') so the bytea comes back as the same hex the key uses. Root,
    // because no runtime role may read token_hash at all -- which is itself the point.
    $stored = DB::connection('pgsql_root')->selectOne(
        'select encode(token_hash, \'hex\') as hex from pager_sessions where id = ?',
        [$issued->session->id],
    );

    $key = PagerProjection::pointerKey(PagerToken::fromPresented($raw));

    expect($key)->toBe('pager:tok:'.$stored->hex,
        'the Redis pointer key is not built from the hash Postgres stores');

    // And the raw token is in neither the key nor anywhere near it. 22 base64url
    // characters against 64 hex: a substring check is the honest test.
    expect(str_contains($key, $raw))->toBeFalse('the raw token is in the Redis key');
    expect($stored->hex)->toHaveLength(64)
        ->and($key)->toMatch('/^pager:tok:[0-9a-f]{64}$/');
});

it('stops resolving from Redis once the clock passes the session expiry, end to end', function (): void {
    // WHAT INVALIDATES THE KEY. Two mechanisms, and this case drives the whole chain:
    //
    //   1. the TTL, which is min(remaining life, projection_ttl), so both keys lapse no
    //      later than expires_at;
    //   2. a READ-TIME check against the view's own expires_at, so a key that is still
    //      physically present is refused once the clock has passed it.
    //
    // AND NOTE WHAT CANNOT HAPPEN, because the first version of this case assumed it
    // could: `expires_at` is NOT in any UPDATE grant. The app role may write
    // (status, ready_at, completed_at, cancelled_at, last_notified_at, renotify_count,
    // updated_at) and the worker (status, expired_at, updated_at) -- so once a session is
    // created its expiry is IMMUTABLE through every application path, and "expires_at
    // moved in Postgres" is a state no code can produce. The projection's copy therefore
    // cannot disagree with Postgres about WHEN; only about whether that moment has passed,
    // which is what (2) decides.
    //
    // So the honest end-to-end drive is a genuinely short session and a real wait.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'X-1');
    $raw = $issued->token->reveal();

    // Two seconds, placed as the owner -- test setup, §2.10's pattern, the same way
    // PagerTtlTest places a past TTL. The app role could not write this column at all.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        "update pager_sessions set expires_at = now() + interval '2 seconds' where id = ?",
        [$issued->session->id],
    ), 'pgsql_owner');

    $this->postJson('/v1/pager/session', ['token' => $raw])->assertOk();
    $this->postJson('/v1/pager/status', ['token' => $raw])->assertOk();

    $redis = app('redis')->connection();
    $projectionKey = PagerProjection::projectionKey($tenant, $issued->session->id);

    // The TTL was derived from the session and not from config: at most two seconds.
    expect($redis->ttl($projectionKey))->toBeLessThanOrEqual(2)
        ->and($redis->ttl($projectionKey))->toBeGreaterThan(0);

    sleep(3);

    $this->postJson('/v1/pager/status', ['token' => $raw])
        ->assertStatus(404)
        ->assertJsonPath('type', ProblemType::Stale->value);

    // And re-opening tells the customer WHICH kind of gone it is, which is the whole
    // reason a miss sends them back to Postgres -- gate clause (a).
    $this->postJson('/v1/pager/session', ['token' => $raw])
        ->assertStatus(409)
        ->assertJsonPath('type', ProblemType::Expired->value);
})->group('slow');

it('refuses a projection whose expiry has passed even while its key is still present', function (): void {
    // THE BACKSTOP, ISOLATED. The case above cannot separate the two mechanisms, because
    // after the wait the TTL has lapsed too. Here the key is planted with a LONG Redis TTL
    // and a PAST expires_at inside the view, which is the shape a clock disagreement would
    // produce -- PHP behind Postgres, a drifting container, a rounded SETEX.
    //
    // Without the read-time check this is a 200 carrying a live status for a session that
    // is over.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'X-3');
    $raw = $issued->token->reveal();

    $this->postJson('/v1/pager/session', ['token' => $raw])->assertOk();

    $now = new DateTimeImmutable;
    $stale = new PagerStatusView(PagerStatus::Ready, 'X-3', $now->modify('-1 second'), $now->modify('-1 hour'));

    // 600 seconds: the key is in no danger of lapsing, so only the guard can refuse it.
    app('redis')->connection()->setex(
        PagerProjection::projectionKey($tenant, $issued->session->id),
        600,
        $stale->encode(),
    );

    expect(app('redis')->connection()->ttl(PagerProjection::projectionKey($tenant, $issued->session->id)))
        ->toBeGreaterThan(500);

    expect(app(PagerProjection::class)->read(PagerToken::fromPresented($raw), $now))->toBeNull();

    $this->postJson('/v1/pager/status', ['token' => $raw])
        ->assertStatus(404)
        ->assertJsonPath('type', ProblemType::Stale->value);
});

it('writes no key at all for a session that has already expired', function (): void {
    // The write-side half. It used to floor the TTL at one second, so a terminal
    // transition on a long-expired session published a live status for a second. Both
    // writers now refuse.
    $projection = app(PagerProjection::class);
    $now = new DateTimeImmutable;
    $token = PagerToken::issue();

    $expired = new ResolvedPagerSession(
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        PagerStatus::Waiting,
        'X-2',
        $now->modify('-1 second'),
        null,
        null,
    );

    expect($projection->remember($token, $expired, $now))->toBeFalse()
        ->and(app('redis')->connection()->exists(PagerProjection::pointerKey($token)))->toBe(0)
        ->and($projection->refresh($expired->tenantId, $expired->id, PagerStatus::Completed, 'X-2', $now->modify('-1 second'), $now))->toBeFalse();
});

it('puts neither the key nor the token in any log line, at APP_DEBUG=true', function (): void {
    // VERIFIED AGAINST CAPTURED OUTPUT, not assumed -- §4 › Edge & ops as amended on
    // 2026-10-26 covers response bodies at any APP_DEBUG setting, and the token is §4 ›
    // Data's secret. A real handler on the real logger, so this reads what was EMITTED.
    config(['app.debug' => true]);

    $handler = new TestHandler;
    Log::swap(new Logger('capture', [$handler]));

    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'L-9');
    $raw = $issued->token->reveal();
    $key = PagerProjection::pointerKey(PagerToken::fromPresented($raw));

    // Every customer path, including the ones that fail: a resolve, a poll, a bad token,
    // a validation failure and a subscribe.
    $this->postJson('/v1/pager/session', ['token' => $raw]);
    $this->postJson('/v1/pager/status', ['token' => $raw]);
    $this->postJson('/v1/pager/session', ['token' => str_repeat('W', 22)]);
    $this->postJson('/v1/pager/status', ['token' => 'not-a-token']);
    $this->postJson('/v1/pager/subscription', ['token' => $raw, 'endpoint' => 'http://nope']);

    $captured = '';

    foreach ($handler->getRecords() as $record) {
        $captured .= $record->message.' '.(string) json_encode($record->context);
    }

    expect(str_contains($captured, $raw))->toBeFalse('the raw token reached captured log output');
    expect(str_contains($captured, $key))->toBeFalse('the Redis pointer key reached captured log output');
    expect(str_contains($captured, substr($key, strlen('pager:tok:'))))->toBeFalse('the token hash reached captured log output');
});

it('puts neither the key nor the token in any response body, at APP_DEBUG=true', function (): void {
    config(['app.debug' => true]);

    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'B-9');
    $raw = $issued->token->reveal();
    $hash = PagerToken::fromPresented($raw)->hashHex();

    $bodies = [
        'resolve' => $this->postJson('/v1/pager/session', ['token' => $raw])->getContent(),
        'poll' => $this->postJson('/v1/pager/status', ['token' => $raw])->getContent(),
        'unknown' => $this->postJson('/v1/pager/session', ['token' => str_repeat('V', 22)])->getContent(),
        'malformed' => $this->postJson('/v1/pager/status', ['token' => 'nope'])->getContent(),
        'invalid endpoint' => $this->postJson('/v1/pager/subscription', ['token' => $raw, 'endpoint' => 'http://nope'])->getContent(),
    ];

    foreach ($bodies as $label => $body) {
        expect(str_contains((string) $body, $raw))->toBeFalse("the {$label} body echoed the raw token");
        expect(str_contains((string) $body, $hash))->toBeFalse("the {$label} body echoed the token hash");
        expect(str_contains((string) $body, 'pager:tok:'))->toBeFalse("the {$label} body echoed the Redis key shape");
    }
});
