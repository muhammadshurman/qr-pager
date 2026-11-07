<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerAuditAction;
use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * EVERY STAFF PAGER TRANSITION NAMES WHO DID IT — `R-237`, AND IT IS SIX ROUTES
 * ================================================================================
 *
 * The row is about one route: *"A PAGER RE-ISSUE IS AUDITED WITH NO ACTOR —
 * `actor_user_id` IS NULL WHILE `actor_type` IS `user` … The action it describes is a
 * credential rotation that voids a live pager: the question **who stopped this customer's
 * code from working** is exactly what the row exists to answer."* Accepted 2026-11-03 from
 * Task 10's scoped `§11` security review; fixed at Task 12, its owner.
 *
 * ⚠ ITS REVIEW-BY ASKED FOR A SWEEP — *"Sweep the other pager transitions for the same shape
 * while in there"* — AND THE SWEEP FOUND ALL SIX. Every write on
 * `PagerSessionController` passed `actorIp` alone: `store`, `ready`, `renotify`, `complete`,
 * `cancel` and `reissue`. So **every `pager.session.*` row in `audit_log` carried an IP and
 * nothing else**, not just the re-issues.
 *
 * ⚠ AND IT WAS A PROMISE MADE IN TASK 3 AND NOT KEPT IN TASK 5, which is why nobody noticed.
 * `PagerSessions::audit()`'s docblock read *"actor_type is `user` with a NULL actor_user_id
 * **until Task 5 exists** … Task 5 fills the id and changes nothing else here"*. Task 5 built
 * the principals and never returned to the call sites, so `?string $actorUserId = null` sat
 * there with no caller passing it from Task 3 until a scoped review of Task 10 found one row
 * of it. The parameter was always ready; nothing ever used it.
 *
 * ⚠ THE LIST OF ACTIONS IS DERIVED FROM THE ENUM, NOT TYPED HERE. `PagerAuditAction` is the
 * single source, so a seventh transition added later is a case this file drives on the commit
 * that adds it rather than a row nobody asserts — which is the whole shape of the defect
 * above, where a parameter existed and no call site used it.
 */

/**
 * Every audited row for one session, as action => actor_user_id.
 *
 * @return array<string, string|null>
 */
function auditActors(string $tenantId, string $sessionId): array
{
    /** @var list<object{action: string, actor_user_id: string|null, actor_type: string}> $rows */
    $rows = withTenant($tenantId, fn (): array => DB::table('audit_log')
        ->where('subject_id', $sessionId)
        ->orderBy('created_at')
        ->get(['action', 'actor_user_id', 'actor_type'])
        ->all());

    $actors = [];

    foreach ($rows as $row) {
        $actors[(string) $row->action] = $row->actor_user_id === null ? null : (string) $row->actor_user_id;
    }

    return $actors;
}

it('records the calling staff user on every one of the six staff transitions', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $caller = auth()->user();
    assert($caller instanceof User);
    $callerId = (string) $caller->getKey();

    // One session, driven through the whole state machine over HTTP. `renotify` before
    // `complete` because the transitions are ordered and a completed session notifies nobody.
    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'ACT-1'])->assertStatus(201);
    $session = (string) $created->json('session.id');

    $this->postJson("/v1/pager-sessions/{$session}/ready")->assertOk();
    $this->postJson("/v1/pager-sessions/{$session}/renotify")->assertOk();
    $this->postJson("/v1/pager-sessions/{$session}/reissue")->assertOk();
    $this->postJson("/v1/pager-sessions/{$session}/complete")->assertOk();

    $actors = auditActors($tenant, $session);

    // ⚠ THE ACTIONS COME FROM THE ENUM AND THE EXPECTED SET IS WHAT THIS DRIVE PRODUCED, so a
    // transition that stopped auditing at all would fail here rather than silently drop out of
    // the loop below. `Expired` is excluded and is the one genuinely actorless action: the
    // worker expires sessions and no user asks for it (`PagerExpiryTest` holds that, with
    // `actor_type` = `system`).
    $expected = [
        PagerAuditAction::Created->value,
        PagerAuditAction::Ready->value,
        PagerAuditAction::Renotified->value,
        PagerAuditAction::Reissued->value,
        PagerAuditAction::Completed->value,
    ];

    foreach ($expected as $action) {
        // ⚠ `array_key_exists(...)->toBeTrue($message)` AND NOT `toHaveKey($key, $message)`.
        // Pest's `toHaveKey()` takes an expected VALUE as its second argument, not a message,
        // so the two-argument form asserted the row's actor equals the failure text — which is
        // how this case first failed ("Failed asserting that two strings are equal.
        // -'no audit row at all for [pager.session.created]'"). `toContain()` has the same
        // shape and `ResponseHeaderTest` records it there; this is the same trap on a second
        // matcher.
        expect(array_key_exists($action, $actors))
            ->toBeTrue("no audit row at all for [{$action}]");

        expect($actors[$action])->toBe(
            $callerId,
            "[{$action}] is audited with actor_user_id ".
            ($actors[$action] === null ? 'NULL' : '['.$actors[$action].']').
            " instead of the caller's id",
        );
    }
});

it('records the caller on a CANCEL too, which the drive above cannot reach', function (): void {
    // `cancel` and `complete` are both terminal, so one session cannot produce both. A second
    // session rather than a second assertion: the row's review-by asked for a sweep of the
    // transitions, and a transition left undriven is a transition nobody swept.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $caller = auth()->user();
    assert($caller instanceof User);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'ACT-2'])->assertStatus(201);
    $session = (string) $created->json('session.id');

    $this->postJson("/v1/pager-sessions/{$session}/cancel")->assertOk();

    $actors = auditActors($tenant, $session);

    expect(array_key_exists(PagerAuditAction::Cancelled->value, $actors))->toBeTrue()
        ->and($actors[PagerAuditAction::Cancelled->value])->toBe((string) $caller->getKey());
});

it('keeps actor_type `user`, so the row is not quietly reclassified as the worker’s', function (): void {
    // ⚠ THE HALF THAT WOULD HAVE MADE THE FIX LOOK DONE WHILE MAKING THE LOG WORSE. Filling
    // `actor_user_id` is only right while `actor_type` still says a PERSON did it: a row that
    // became `system` would be indistinguishable from the expiry job's, which are the only
    // genuinely actorless ones. `PagerSessions::audit()`'s original comment made exactly this
    // argument for the NULL it wrote, and it is still the right argument now the id is there.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'ACT-3'])->assertStatus(201);
    $session = (string) $created->json('session.id');

    $this->postJson("/v1/pager-sessions/{$session}/ready")->assertOk();

    /** @var list<object{actor_type: string}> $rows */
    $rows = withTenant($tenant, fn (): array => DB::table('audit_log')
        ->where('subject_id', $session)
        ->get(['actor_type'])
        ->all());

    expect($rows)->not->toBe([]);

    foreach ($rows as $row) {
        expect((string) $row->actor_type)->toBe(AuditActorType::User->value);
    }
});

it('leaves the pager’s own state and the customer surface untouched by the extra argument', function (): void {
    // The fix is one argument on six calls, so the risk is not that it fails but that it
    // changes something else: `PagerSessions::markReady()` writes the status, the outbox row
    // and the audit row in one transaction (`§3` row 3's clause), and an extra parameter
    // threaded through the shared `transition()` helper touches all three.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'ACT-4'])->assertStatus(201);
    $session = (string) $created->json('session.id');

    $ready = $this->postJson("/v1/pager-sessions/{$session}/ready")->assertOk();

    expect($ready->json('session.status'))->toBe('ready');

    [$status, $outbox] = withTenant($tenant, fn (): array => [
        DB::table('pager_sessions')->where('id', $session)->value('status'),
        DB::table('outbox')->where('pager_session_id', $session)->count(),
    ]);

    expect((string) $status)->toBe('ready')
        ->and($outbox)->toBe(1, 'the ready transition stopped writing its outbox row');
});
