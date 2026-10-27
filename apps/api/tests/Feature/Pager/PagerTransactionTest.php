<?php

declare(strict_types=1);

use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;

/*
 * THE FIRST CLAUSE OF THE §3 GATE, and the invariant this task ships on:
 *
 *   "The status change, the outbox row and the audit row commit in one transaction, or
 *    none of them does -- proven by injecting a failure after the status write and
 *    asserting all three are absent"
 *
 * It was moved into the gate on 2026-10-26 because it was stated in the task column only,
 * so a reviewer handed the gate alone would not have tested it -- and because it is H-B's
 * whole family: a helper reporting success over work that was rolled back.
 *
 * HOW THE FAILURE IS INJECTED, and why it needs no production seam. Connection::
 * beforeExecuting() fires BEFORE a statement executes (that is the measured difference
 * from DB::listen, and the reason TenantScopeGuard uses it), so a callback that throws
 * when it sees a particular statement stops that statement from ever running while
 * everything before it has already happened. That is literally "a failure after the
 * status write", produced entirely from the test side: there is no test-only branch, flag
 * or hook anywhere in PagerSessions.
 *
 * Two injection points, because the interesting one is the second: throwing before the
 * OUTBOX insert leaves one write done, and throwing before the AUDIT insert leaves TWO
 * writes done. If the boundary were wrong, the second case is the one that would leave
 * real rows behind.
 */

/**
 * Throw when the app connection is about to run a statement containing $needle.
 *
 * Registered on the resolved connection object, which is the same instance PagerSessions
 * reaches through withTenant() -> DB::connection('pgsql') inside one test.
 */
function throwBeforeStatementContaining(string $needle, string $message): void
{
    DB::connection('pgsql')->beforeExecuting(function (string $query) use ($needle, $message): void {
        if (str_contains(strtolower($query), $needle)) {
            throw new RuntimeException($message);
        }
    });
}

/** @return array{status: string|null, outbox: int, audit: int} */
function pagerRowsFor(string $tenantId, string $sessionId): array
{
    return withTenant($tenantId, fn (): array => [
        'status' => DB::table('pager_sessions')->where('id', $sessionId)->value('status'),
        'outbox' => DB::table('outbox')->where('pager_session_id', $sessionId)->count(),
        'audit' => DB::table('audit_log')
            ->where('tenant_id', $tenantId)
            ->where('subject_id', $sessionId)
            ->whereIn('action', [PagerAuditAction::Ready->value, PagerAuditAction::Cancelled->value])
            ->count(),
    ]);
}

it('commits the status change, the outbox row and the audit row together', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    app(PagerSessions::class)->markReady($tenant, $session);

    $rows = pagerRowsFor($tenant, $session);

    // All three, so the assertions below about ABSENCE mean something: this is the
    // positive control for them.
    expect($rows['status'])->toBe(PagerStatus::Ready->value)
        ->and($rows['outbox'])->toBe(1)
        ->and($rows['audit'])->toBe(1);
});

it('rolls back the status change when the OUTBOX insert fails — all three absent', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    throwBeforeStatementContaining('insert into outbox', 'deliberate, after the status write');

    expect(fn () => app(PagerSessions::class)->markReady($tenant, $session))
        ->toThrow(RuntimeException::class, 'deliberate, after the status write');

    // A fresh connection, so nothing is read through the one that carried the aborted
    // transaction -- and so the injected callback is not still armed for the reads.
    $this->purgeConnections();

    $rows = pagerRowsFor($tenant, $session);

    expect($rows['status'])->toBe(PagerStatus::Waiting->value, 'the status change survived a rolled-back transaction')
        ->and($rows['outbox'])->toBe(0)
        ->and($rows['audit'])->toBe(0);
});

it('rolls back the status change AND the outbox row when the AUDIT insert fails', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    // The harder case: two of the three writes have already happened when this fires.
    throwBeforeStatementContaining('insert into audit_log', 'deliberate, after the status write and the outbox row');

    expect(fn () => app(PagerSessions::class)->markReady($tenant, $session))
        ->toThrow(RuntimeException::class, 'deliberate, after the status write and the outbox row');

    $this->purgeConnections();

    $rows = pagerRowsFor($tenant, $session);

    expect($rows['status'])->toBe(PagerStatus::Waiting->value)
        ->and($rows['outbox'])->toBe(0, 'the outbox row survived a rolled-back transaction')
        ->and($rows['audit'])->toBe(0);
});

it('holds for cancel as well, which is the other operation writing all three', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    throwBeforeStatementContaining('insert into audit_log', 'deliberate, mid-cancel');

    expect(fn () => app(PagerSessions::class)->cancel($tenant, $session))
        ->toThrow(RuntimeException::class, 'deliberate, mid-cancel');

    $this->purgeConnections();

    $rows = pagerRowsFor($tenant, $session);

    expect($rows['status'])->toBe(PagerStatus::Waiting->value)
        ->and($rows['outbox'])->toBe(0)
        ->and($rows['audit'])->toBe(0);
});

it('rolls back the session row itself when create fails after the insert', function (): void {
    $tenant = $this->makeTenant();

    throwBeforeStatementContaining('insert into audit_log', 'deliberate, after the session insert');

    expect(fn () => app(PagerSessions::class)->create($tenant, 'A-900'))
        ->toThrow(RuntimeException::class, 'deliberate, after the session insert');

    $this->purgeConnections();

    // create() writes two of the three (there is no outbox row for a creation -- nobody
    // has scanned yet), and the same boundary covers it.
    $sessions = withTenant($tenant, fn (): int => DB::table('pager_sessions')->where('order_reference', 'A-900')->count());

    expect($sessions)->toBe(0, 'a session survived a rolled-back create');
});

it('writes exactly the outbox type and audit action the domain names, not strings', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    app(PagerSessions::class)->markReady($tenant, $session);

    [$type, $key, $action] = withTenant($tenant, fn (): array => [
        DB::table('outbox')->where('pager_session_id', $session)->value('type'),
        DB::table('outbox')->where('pager_session_id', $session)->value('dedupe_key'),
        DB::table('audit_log')->where('subject_id', $session)
            ->where('action', PagerAuditAction::Ready->value)->value('action'),
    ]);

    expect($type)->toBe(PagerEvent::Ready->value)
        ->and($key)->toBe(PagerEvent::Ready->dedupeKey($session))
        ->and($action)->toBe(PagerAuditAction::Ready->value);
});
