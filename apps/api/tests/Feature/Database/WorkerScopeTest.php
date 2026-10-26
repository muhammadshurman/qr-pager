<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Acceptance gate, clause 5: "worker sees the outbox across tenants, app does not".
 * Also pins the expiry pattern the Task 3 job depends on (PLAN.md §2.12).
 */

it('lets the worker drain the outbox across tenants with no context', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeOutboxRow($alpha);
    $this->makeOutboxRow($beta);
    $this->makeOutboxRow($beta);

    expect($this->asWorker()->table('outbox')->count())->toBe(3);
});

it('does not let the app see another tenant outbox row', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeOutboxRow($alpha);
    $this->makeOutboxRow($beta);

    expect(DB::table('outbox')->count())->toBe(0)
        ->and(withTenant($alpha, fn () => DB::table('outbox')->count()))->toBe(1);
});

it('lets the worker claim outbox rows with for update skip locked', function (): void {
    $tenant = $this->makeTenant();
    $this->makeOutboxRow($tenant);

    $claimed = $this->asWorker()->select(
        "select id from outbox where status = 'pending' and available_at <= now()
         order by available_at, id for update skip locked limit 10"
    );

    expect($claimed)->toHaveCount(1);
});

it('does not let the worker insert or delete an outbox row', function (): void {
    $tenant = $this->makeTenant();
    $this->makeOutboxRow($tenant);

    expect($this->sqlStateOf(fn () => $this->asWorker()->table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'type' => 'pager.ready',
        'dedupe_key' => 'worker-forged',
    ])))->toBe('42501');

    expect($this->sqlStateOf(fn () => $this->asWorker()->table('outbox')->delete()))->toBe('42501');
});

it('runs the prescribed expiry pattern: select id, then update with no returning', function (): void {
    $tenant = $this->makeTenant();
    $expired = $this->makeSession($tenant, ['expires_at' => now()->subMinutes(5)->toIso8601String()]);
    $live = $this->makeSession($tenant);

    // Cross-tenant, no context: the worker sees only sessions past their TTL.
    $ids = array_map(
        static fn (object $row): string => (string) $row->id,
        $this->asWorker()->select("select id from pager_sessions where status in ('waiting', 'ready') and expires_at <= now()"),
    );

    expect($ids)->toBe([$expired])
        ->and($ids)->not->toContain($live);

    $affected = $this->asWorker()->update(
        "update pager_sessions set status = 'expired', expired_at = now(), updated_at = now()
         where id = ? and status = ?::pager_status",
        [$expired, 'waiting'],
    );

    expect($affected)->toBe(1);
    expect(withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $expired)->value('status')))
        ->toBe('expired');
});

it('is why the worker select policy is bounded by time and not by status', function (): void {
    $tenant = $this->makeTenant();
    $expired = $this->makeSession($tenant, ['expires_at' => now()->subMinutes(5)->toIso8601String()]);

    // Verified on 16.14 and the reason PLAN.md §2.12 exists: an UPDATE that reads any
    // column re-checks the NEW row against the SELECT policies. Had the worker's
    // cross-tenant SELECT policy excluded expired rows, the statement above would
    // raise 42501 -- with or without RETURNING. This is the shape that would break,
    // kept as a live regression test: the new row must stay visible.
    $state = $this->sqlStateOf(fn () => $this->asWorker()->update(
        "update pager_sessions set status = 'expired', expires_at = now() + interval '1 hour'
         where id = ?",
        [$expired],
    ));

    // Moving expires_at into the future hides the new row from the worker's own
    // policy, and Postgres refuses the statement rather than silently updating it.
    expect($state)->toBe('42501');
});

it('does not let the worker make any other cross-tenant transition', function (): void {
    $tenant = $this->makeTenant();
    $expired = $this->makeSession($tenant, ['expires_at' => now()->subMinutes(5)->toIso8601String()]);

    // WITH CHECK (status = 'expired'): cross-tenant, expiring is the only thing it may do.
    expect($this->sqlStateOf(fn () => $this->asWorker()->update(
        "update pager_sessions set status = 'ready' where id = ?", [$expired],
    )))->toBe('42501');
});

it('does not let the worker read a live session of another tenant', function (): void {
    $alpha = $this->makeTenant();
    $this->makeSession($alpha);

    expect($this->asWorker()->table('pager_sessions')->count())->toBe(0);
});

it('does not let the worker read the token hash', function (): void {
    $tenant = $this->makeTenant();
    $this->makeSession($tenant, ['expires_at' => now()->subMinute()->toIso8601String()]);

    expect($this->sqlStateOf(fn () => $this->asWorker()->table('pager_sessions')->select('token_hash')->get()))
        ->toBe('42501');
});

it('has no cross-tenant reach outside the outbox and the expiry job', function (): void {
    $tenant = $this->makeTenant();
    $this->seedEveryTenantTable($tenant);

    foreach (['tenants', 'tenant_users', 'push_subscriptions', 'audit_log'] as $table) {
        // Filtered by the tenant this test seeded (R-016). The unfiltered count was
        // bounded by row level security rather than by the filter -- the worker has no
        // context, so it reads 0 however many rows exist -- but audit_log is
        // append-only and never truncated, so an unfiltered count on this table is the
        // exact shape that passes on CI's empty database and fails on a developer
        // machine carrying earlier runs. Filtering also makes the assertion STRONGER:
        // it now says the row this test just seeded is invisible, rather than that the
        // table looks empty.
        $column = $table === 'tenants' ? 'id' : 'tenant_id';

        expect($this->asWorker()->table($table)->where($column, $tenant)->count())
            ->toBe(0, "the worker read {$table} across tenants, which is not a named path");
    }
});
