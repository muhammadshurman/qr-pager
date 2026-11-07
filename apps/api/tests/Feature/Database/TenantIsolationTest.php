<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schema;

/*
 * Acceptance gate, clauses 1 and 2 (PLAN.md §3 row 1):
 *   - no tenant context -> every table returns 0 rows, no error
 *   - cross-tenant read / update / delete / insert all blocked
 */

it('returns zero rows and raises no error on every tenant table with no context', function (): void {
    $this->seedEveryTenantTable($this->makeTenant());

    // A connection that has never had the GUC set at all: current_setting returns
    // NULL here, where a reused connection would return an empty string. Both have
    // to behave the same way, so both are covered (this test and the next).
    DB::connection('pgsql')->disconnect();

    // Nine since Task 7 added landing_pages, media_assets and tenant_domains. The count is
    // pinned rather than derived so that a tenant table arriving without a row in
    // seedEveryTenantTable() fails HERE -- an empty table passes an isolation assertion by
    // having nothing to leak, which is the vacuous shape §4a records.
    $tables = Schema::tenantScopedTables();
    expect($tables)->toHaveCount(9);

    foreach ($tables as $table) {
        expect(DB::connection('pgsql')->table($table)->count())
            ->toBe(0, "{$table} leaked rows with no tenant context");
    }
});

it('still returns zero rows and no error once the connection has carried a context', function (): void {
    $tenant = $this->makeTenant();
    $this->seedEveryTenantTable($tenant);

    // Same connection, after a committed transaction that did set the GUC. This is
    // the pooled-connection case: the GUC now reads '' rather than NULL, and
    // ''::uuid raises 22P02 without the NULLIF guard (PLAN.md §2.2).
    withTenant($tenant, fn () => DB::table('tenants')->count());

    expect(DB::selectOne("select current_setting('app.tenant_id', true) = '' as empty")->empty)->toBeTrue();

    foreach (Schema::tenantScopedTables() as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} leaked rows on a reused connection");
    }
});

it('shows a tenant only its own rows', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeSession($alpha);
    $this->makeSession($beta);
    $this->makeSession($beta);

    expect(withTenant($alpha, fn () => DB::table('pager_sessions')->count()))->toBe(1);
    expect(withTenant($beta, fn () => DB::table('pager_sessions')->count()))->toBe(2);
});

it('blocks a cross-tenant read even when the other tenant id is named', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeSession($beta);

    $rows = withTenant($alpha, fn () => DB::table('pager_sessions')->where('tenant_id', $beta)->count());

    expect($rows)->toBe(0);
});

it('blocks a cross-tenant update', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $session = $this->makeSession($beta);

    $affected = withTenant($alpha, fn () => DB::table('pager_sessions')
        ->where('id', $session)
        ->update(['status' => 'cancelled']));

    expect($affected)->toBe(0);
    expect(withTenant($beta, fn () => DB::table('pager_sessions')->where('id', $session)->value('status')))
        ->toBe('waiting');
});

it('blocks a cross-tenant delete', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $session = $this->makeSession($beta);

    // push_subscriptions is the deletable tenant table app holds a DELETE grant on,
    // so this measures the policy and not a missing privilege.
    withTenant($beta, fn () => DB::table('push_subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $beta,
        'pager_session_id' => $session,
        'endpoint' => 'https://push.example.test/'.Str::random(24),
        'p256dh' => Str::random(87),
        'auth' => Str::random(22),
    ]));

    $deleted = withTenant($alpha, fn () => DB::table('push_subscriptions')->delete());

    expect($deleted)->toBe(0);
    expect(withTenant($beta, fn () => DB::table('push_subscriptions')->count()))->toBe(1);
});

it('blocks an insert made for another tenant', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $state = $this->sqlStateOf(fn () => withTenant($alpha, fn () => DB::table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $beta,
        'type' => 'pager.ready',
        'dedupe_key' => 'forged',
    ])));

    expect($state)->toBe('42501');
});

it('cannot move one of its own rows to another tenant', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $session = $this->makeSession($alpha);

    // No role holds UPDATE on any tenant_id column, so this is refused as a
    // privilege error before row level security is even consulted (PLAN.md §2.7).
    $state = $this->sqlStateOf(fn () => withTenant($alpha, fn () => DB::table('pager_sessions')
        ->where('id', $session)
        ->update(['tenant_id' => $beta])));

    expect($state)->toBe('42501');
});

it('cannot read the token hash it is allowed to write', function (): void {
    $tenant = $this->makeTenant();
    $this->makeSession($tenant);

    $state = $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::table('pager_sessions')
        ->select('token_hash')
        ->get()));

    expect($state)->toBe('42501');

    // ...while the same row is otherwise perfectly readable.
    expect(withTenant($tenant, fn () => DB::table('pager_sessions')->select('id', 'status')->count()))->toBe(1);
});

it('cannot create objects, so it cannot turn a policy off', function (): void {
    expect($this->sqlStateOf(fn () => DB::statement('create table rls_escape (id int)')))->toBe('42501');
});
