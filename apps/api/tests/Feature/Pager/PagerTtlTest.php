<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerSessionExpired;
use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * QA H-1 and security S-2, which are one defect from two directions: the 2 h TTL that
 * PROJECT_INSTRUCTIONS.md §4 › Data states as an invariant was enforced on NO write path.
 *
 * THE THREE THINGS THAT WERE ALL TRUE AT ONCE, which is why nothing caught it:
 *   - PagerSessions::lock() selected `expires_at` and apply() never read it;
 *   - ResolvedPagerSession::hasExpired() existed and had no production caller at all --
 *     its only reference in the tree was a test;
 *   - the expiry job that would have moved `status` is registered and scheduled nowhere
 *     (R-058), and R-058's own mitigation cell leaned on hasExpired().
 * So a leaked QR token was, in practice, a bearer credential with an unbounded life.
 *
 * EVERY CASE BELOW GOES THROUGH HTTP unless it says otherwise, because the refusal has to
 * be distinguishable to a CLIENT -- that was the user's wording when accepting the fix --
 * and `type` is the only part of a problem document a client is told it may switch on.
 *
 * REVERT PROOF -- 6 of these 9 cases are revert-proved, and the three that stay green are
 * exactly the carve-outs. Re-measured 2026-10-27 by `bin/ttl-revert-proof`, which runs
 * each half separately, because THIS HEADER USED TO BE WRONG IN BOTH HALVES (R-068): it
 * said "the first four cases" for apply() and "the fifth" for renotify().
 *
 *   delete the refuseIfPastTtl() call in apply() ONLY     -> SIX go red: 1, 2, 3, 4, 8, 9
 *   delete the refuseIfPastTtl() call in renotify() ONLY  -> ONE goes red: case 2,
 *                                                           "refuses ready and renotify on
 *                                                           a past-TTL session that is
 *                                                           already ready", which is where
 *                                                           the renotify limb lives
 *
 * Case 5, "leaves terminal statuses alone", stays green under BOTH reverts and is meant
 * to: it is the carve-out that keeps idempotency working on an old completed order, so a
 * revert of the guard cannot change its answer. Cases 6 and 7 are the other two -- a
 * cross-tenant 404 and the inside-TTL positive control -- and neither is about the guard
 * either.
 *
 * The aggregate -- 6 of 9 -- is the figure §0, §4a and R-058 quote, and it was right all
 * along; what was wrong was which call guards which case. `bin/ttl-revert-proof` is in the
 * repo (R-043) so the next reader checks this claim against a run rather than against
 * this sentence.
 */

/**
 * A session in a chosen status with a chosen TTL.
 *
 * Self-contained rather than calling TestCase::makeSession(), which is protected and so
 * unreachable from a global function in a Pest file -- the same constraint
 * PagerTransitionTest::sessionIn() records. The owner connection inside a tenant scope is
 * §2.10's pattern: this is test setup placing a precondition, not an application path,
 * and the app role could not write a past `expires_at` anyway.
 */
function ttlSession(string $tenantId, PagerStatus $status, string $expiresAt): string
{
    $id = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->insert(
        'insert into pager_sessions (id, tenant_id, token_hash, order_reference, status, expires_at)
         values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, ?)',
        [$id, $tenantId, bin2hex(random_bytes(32)), 'T-'.random_int(100, 999), $status->value, $expiresAt],
    ), 'pgsql_owner');

    return $id;
}

/** Past its TTL by an hour, in whichever live status the case needs. */
function expiredSession(string $tenant, PagerStatus $status = PagerStatus::Waiting): string
{
    return ttlSession($tenant, $status, now()->subHour()->toIso8601String());
}

it('refuses every staff transition on a session past its TTL, as an expired conflict', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    foreach (['ready', 'complete', 'cancel'] as $action) {
        $session = expiredSession($tenant);

        $response = $this->postJson("/v1/pager-sessions/{$session}/{$action}");

        expect($response->status())->toBe(409, "{$action} accepted a session past its TTL");
        $response->assertJsonPath('type', ProblemType::Expired->value)
            ->assertJsonPath('title', ProblemType::Expired->title())
            ->assertJsonPath('status', 409);
    }
});

it('refuses ready and renotify on a past-TTL session that is already ready', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    foreach (['ready', 'renotify'] as $action) {
        $session = expiredSession($tenant, PagerStatus::Ready);

        $response = $this->postJson("/v1/pager-sessions/{$session}/{$action}");

        // `ready` here is the IDEMPOTENT case -- the session is already in the state the
        // caller asked for -- and it is still refused, because answering 200 "it is
        // ready" for a session the worker is about to expire is a lie staff act on. The
        // TTL check therefore sits BEFORE the idempotency branch, not after it.
        expect($response->status())->toBe(409, "{$action} accepted an expired ready session");
        $response->assertJsonPath('type', ProblemType::Expired->value);
    }
});

it('writes nothing at all when it refuses', function (): void {
    $tenant = $this->makeTenant();
    $session = expiredSession($tenant);
    $this->actingForTenant($tenant);

    $this->postJson("/v1/pager-sessions/{$session}/ready");

    $row = withTenant($tenant, fn () => DB::table('pager_sessions')
        ->where('id', $session)
        ->first(['status', 'ready_at', 'last_notified_at']));
    $outbox = withTenant($tenant, fn (): int => DB::table('outbox')->where('pager_session_id', $session)->count());
    $audit = withTenant($tenant, fn (): int => DB::table('audit_log')->where('subject_id', $session)->count());

    expect($row?->status)->toBe(PagerStatus::Waiting->value)
        ->and($row?->ready_at)->toBeNull()
        ->and($row?->last_notified_at)->toBeNull()
        ->and($outbox)->toBe(0, 'a refused expiry wrote an outbox row')
        ->and($audit)->toBe(0, 'a refused expiry wrote an audit row');
});

it('is distinguishable from an illegal transition, which is the whole point of a separate type', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    // Terminal, inside its TTL: an illegal transition.
    $cancelled = ttlSession($tenant, PagerStatus::Cancelled, now()->addHour()->toIso8601String());
    $illegal = $this->postJson("/v1/pager-sessions/{$cancelled}/ready");

    // Live, past its TTL: expired.
    $expired = expiredSession($tenant);
    $tooLate = $this->postJson("/v1/pager-sessions/{$expired}/ready");

    expect($illegal->status())->toBe(409)
        ->and($tooLate->status())->toBe(409)
        ->and($illegal->json('type'))->toBe(ProblemType::Conflict->value)
        ->and($tooLate->json('type'))->toBe(ProblemType::Expired->value)
        ->and($illegal->json('type'))->not->toBe($tooLate->json('type'));
});

it('leaves terminal statuses alone, so idempotency survives the fix', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    // `expires_at` recedes into the past for every session that ever existed, so an
    // UNCONDITIONAL check would refuse `complete` on an order collected last week and
    // break the gate's own idempotency clause. isLive() is the carve-out.
    $collected = ttlSession($tenant, PagerStatus::Completed, now()->subWeek()->toIso8601String());

    $response = $this->postJson("/v1/pager-sessions/{$collected}/complete");

    expect($response->status())->toBe(200);
    $response->assertJsonPath('session.status', PagerStatus::Completed->value)
        ->assertJsonPath('session.changed', false);
});

it('still answers 404 and never 409 for another tenant’s expired session', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaExpired = expiredSession($beta);

    $this->actingForTenant($alpha);

    // THE REGRESSION THIS FIX COULD HAVE INTRODUCED. 409 Expired asserts that the id is
    // real, so if the TTL check ran before row level security hid the row, the new type
    // would be exactly the existence oracle PagerIdorTest exists to prevent. lock() finds
    // nothing inside alpha's scope and throws NotFound first; this pins that order.
    $response = $this->postJson("/v1/pager-sessions/{$betaExpired}/ready");

    expect($response->status())->toBe(404)
        ->and($response->json('type'))->toBe(ProblemType::NotFound->value);
});

it('passes a session inside its TTL straight through, which is the positive control', function (): void {
    $tenant = $this->makeTenant();
    $live = ttlSession($tenant, PagerStatus::Waiting, now()->addHour()->toIso8601String());
    $this->actingForTenant($tenant);

    $response = $this->postJson("/v1/pager-sessions/{$live}/ready");

    expect($response->status())->toBe(200);
    $response->assertJsonPath('session.status', PagerStatus::Ready->value)
        ->assertJsonPath('session.changed', true);
});

it('refuses at the service too, so the rule is not a property of the HTTP layer', function (): void {
    $tenant = $this->makeTenant();
    $session = expiredSession($tenant);

    /** @var PagerSessions $sessions */
    $sessions = app(PagerSessions::class);

    expect(fn () => $sessions->markReady($tenant, $session))
        ->toThrow(PagerSessionExpired::class);
});

it('compares against the database clock, not PHP’s', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    // One second into the future is inside the TTL; one second behind is not. Both
    // verdicts are computed by Postgres as `expires_at <= now()` on the locked row, so
    // the role that writes the TTL, the role that enforces it and the role that expires
    // it all read one clock -- there is no window where PHP and the cluster disagree.
    $justLive = ttlSession($tenant, PagerStatus::Waiting, now()->addSeconds(30)->toIso8601String());
    $justPast = ttlSession($tenant, PagerStatus::Waiting, now()->subSeconds(30)->toIso8601String());

    expect($this->postJson("/v1/pager-sessions/{$justLive}/cancel")->status())->toBe(200)
        ->and($this->postJson("/v1/pager-sessions/{$justPast}/cancel")->status())->toBe(409);
});
