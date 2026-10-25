<?php

declare(strict_types=1);

use App\Platform\Database\TenantContextException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schema;

/*
 * Acceptance gate, clause 3: "owner is also bound by FORCE RLS".
 * Plus the data-migration pattern this task is required to settle (PLAN.md §2.10).
 */

it('binds the owner to force rls like any other role', function (): void {
    $tenant = $this->makeTenant();
    $this->seedEveryTenantTable($tenant);

    $owner = DB::connection('pgsql_owner');

    expect((string) $owner->selectOne('select current_user as role')->role)->toBe('qrpager_owner');

    foreach (Schema::tenantScopedTables() as $table) {
        expect($owner->table($table)->count())->toBe(0, "the owner saw {$table} with no tenant context");
    }
});

it('is not a superuser and holds no bypassrls, which is what makes force rls mean anything', function (): void {
    $attributes = DB::connection('pgsql_owner')->selectOne(
        'select rolsuper, rolbypassrls, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user'
    );

    expect((bool) $attributes->rolsuper)->toBeFalse()
        ->and((bool) $attributes->rolbypassrls)->toBeFalse()
        ->and((bool) $attributes->rolcreatedb)->toBeFalse()
        ->and((bool) $attributes->rolcreaterole)->toBeFalse();
});

it('lets the owner read one tenant at a time with context set', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeSession($alpha);
    $this->makeSession($beta);

    $rows = withTenant($alpha, fn () => DB::connection('pgsql_owner')->table('pager_sessions')->count(), 'pgsql_owner');

    expect($rows)->toBe(1);
});

it('lets the owner cross tenants only through the audited bypass', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeSession($alpha);
    $this->makeSession($beta);

    $rows = withoutTenantScope(
        'task-1 test: cross-tenant count',
        fn () => DB::connection('pgsql_owner')->table('pager_sessions')->count(),
    );

    expect($rows)->toBe(2);
});

it('writes an audit row for every bypass, in the same transaction', function (): void {
    $reason = 'task-1 test: audited reason '.Str::uuid7();

    withoutTenantScope($reason, fn () => true);

    // Observed from the root connection on purpose. A platform-scope audit row
    // (tenant_id NULL) is invisible to the owner unless it bypasses, and a bypass
    // used to read the log would write another row and count itself.
    $row = DB::connection('pgsql_root')->table('audit_log')
        ->where('action', 'tenancy.bypass')
        ->where('context->reason', $reason)
        ->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->actor_type)->toBe('system')
        ->and($row->tenant_id)->toBeNull();
});

it('rolls the audit row back with the work when a bypass fails', function (): void {
    $reason = 'task-1 test: failed bypass '.Str::uuid7();

    try {
        withoutTenantScope($reason, function (): void {
            throw new RuntimeException('deliberate');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::connection('pgsql_root')->table('audit_log')
        ->where('context->reason', $reason)->count())->toBe(0);
});

it('refuses a bypass without a reason', function (): void {
    expect(fn () => withoutTenantScope('  ', fn () => true))
        ->toThrow(TenantContextException::class, 'needs a reason');
});

it('refuses a bypass on any connection that is not the owner', function (): void {
    expect(fn () => withoutTenantScope('attempt from the web role', fn () => true, 'pgsql'))
        ->toThrow(TenantContextException::class, 'qrpager_app');
});

it('gains the app role nothing when it sets the bypass guc itself', function (): void {
    $alpha = $this->makeTenant();
    $this->makeSession($alpha);
    $this->makeSession($this->makeTenant());

    // Any role can call set_config on a custom GUC, so the bypass policy being
    // TO the owner alone is the only thing that makes it safe. This is the test
    // that says SQL injection in the web tier cannot cross a tenant boundary.
    $rows = DB::transaction(function (): int {
        DB::select("select set_config('app.bypass_tenant', 'on', true)");

        return DB::table('pager_sessions')->count();
    });

    expect($rows)->toBe(0);
});

it('does not leave the bypass set after the transaction', function (): void {
    withoutTenantScope('task-1 test: leak check', fn () => true);

    $bypass = DB::connection('pgsql_owner')
        ->selectOne("select coalesce(current_setting('app.bypass_tenant', true), '<null>') as value")->value;

    expect((string) $bypass)->toBe('');
});
