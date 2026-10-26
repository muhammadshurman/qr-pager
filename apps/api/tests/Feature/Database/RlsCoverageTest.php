<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use Illuminate\Support\Facades\DB;
use Tests\Support\Schema;

/*
 * Acceptance gate, clause 7: "meta-test fails the build if any table lacks
 * ENABLE or FORCE".
 *
 * Everything here reads pg_catalog, never the migrations, so the verdict is
 * independent of the code that produced the schema.
 */

/**
 * Tables with no tenant scope column. Adding one is a deliberate act that a reviewer
 * sees in the diff, and it must be justified in PLAN.md §2.4.
 *
 * Read from App\Domain\Database\TenantTables rather than declared here, since Task 2:
 * the runtime tenant-scope guard needs the same two lists, and a list the application
 * cannot read is a list the guard would have had a second copy of. A table in one copy
 * and not the other is precisely the gap this file exists to close, so there is now one
 * registry and the tests below pin it against pg_catalog in both directions.
 */
const GLOBAL_TABLES = TenantTables::GLOBAL;

it('has every tenant-scoped table under ENABLE and FORCE row level security', function (): void {
    $unprotected = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname,
               c.relrowsecurity      as enabled,
               c.relforcerowsecurity as forced
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind = 'r'
          and (c.relname = 'tenants' or exists (
              select 1 from pg_attribute a
              where a.attrelid = c.oid and a.attname = 'tenant_id'
                and a.attnum > 0 and not a.attisdropped
          ))
          and not (c.relrowsecurity and c.relforcerowsecurity)
        order by c.relname
    SQL);

    expect($unprotected)->toBe([], 'tenant tables without ENABLE and FORCE: '.implode(', ', array_map(
        static fn (object $r): string => (string) $r->relname, $unprotected,
    )));
});

it('fails on a deliberately bad table, so the check above is worth something', function (): void {
    // A meta-test nobody has seen fail is a meta-test nobody should trust.
    DB::connection('pgsql_owner')->unprepared('create table rls_coverage_probe (id uuid, tenant_id uuid not null)');

    try {
        $unprotected = Schema::tenantScopedTables();
        $protected = Schema::rlsTables();

        expect(array_values(array_diff($unprotected, $protected)))->toBe(['rls_coverage_probe']);
    } finally {
        DB::connection('pgsql_owner')->unprepared('drop table rls_coverage_probe');
    }

    expect(array_values(array_diff(Schema::tenantScopedTables(), Schema::rlsTables())))->toBe([]);
});

it('accounts for every table: either tenant-scoped or named in the global allowlist', function (): void {
    $unaccounted = array_values(array_diff(
        Schema::allTables(),
        Schema::tenantScopedTables(),
        GLOBAL_TABLES,
    ));

    expect($unaccounted)->toBe([], 'a table with no tenant_id arrived without being justified in PLAN.md §2.4: '
        .implode(', ', $unaccounted));
});

it('keeps the global allowlist honest: nothing on it carries a tenant_id', function (): void {
    expect(array_values(array_intersect(GLOBAL_TABLES, Schema::tenantScopedTables())))->toBe([]);
    expect(array_values(array_diff(GLOBAL_TABLES, Schema::allTables())))->toBe([]);
});

it('pins the tenant registry against pg_catalog, in both directions', function (): void {
    // The registry the runtime guard matches statements against, checked against the
    // schema itself rather than against the migration that wrote it. A tenant table
    // that arrives without being added here would be a table the guard lets through
    // unscoped; a name here that is not a tenant table would make the guard refuse
    // statements it has no business refusing.
    expect(TenantTables::TENANT)->toBe(Schema::tenantScopedTables());

    // And the two lists together account for every table, with nothing in both.
    expect(TenantTables::all())->toBe(Schema::allTables())
        ->and(array_values(array_intersect(TenantTables::TENANT, TenantTables::GLOBAL)))->toBe([]);
});

it('pins the global allowlist as an exact set, not just a consistent one', function (): void {
    // F9 (security review of task-1): tenantScopedTables() classifies a table as
    // tenant-scoped by looking for a column NAMED tenant_id, and the test above only
    // checks the allowlist against that same rule. A future tenant table scoped by a
    // differently named column -- Task 7's landing pages, media and custom domains
    // are the realistic candidates -- would be classified global, would need one line
    // added here, and neither check could tell. Pinning the set exactly means a new
    // arrival cannot join the allowlist without showing up in a diff.
    expect(GLOBAL_TABLES)->toBe(['failed_jobs', 'migrations', 'password_reset_tokens', 'users']);
});

it('gives every tenant table both an isolation policy and an owner bypass policy', function (): void {
    foreach (Schema::tenantScopedTables() as $table) {
        $names = array_map(static fn (array $p): string => $p['polname'], Schema::policies($table));

        expect($names)->toContain("tenant_isolation_{$table}")
            ->and($names)->toContain("owner_bypass_{$table}");
    }
});

it('guards every isolation policy with NULLIF, which is what makes an empty context mean zero rows', function (): void {
    foreach (Schema::tenantScopedTables() as $table) {
        $policy = collect(Schema::policies($table))->firstWhere('polname', "tenant_isolation_{$table}");

        expect($policy)->not->toBeNull();

        foreach ([$policy['using'], $policy['check']] as $expression) {
            expect($expression)->toContain('NULLIF')
                ->and($expression)->toContain("current_setting('app.tenant_id'::text, true)");
        }
    }
});

it('reads no app.* setting other than the two locked by C1', function (): void {
    expect(Schema::gucsUsedByPolicies())->toBe(['app.bypass_tenant', 'app.tenant_id']);
});

it('targets every bypass policy at the owner alone', function (): void {
    foreach (Schema::tenantScopedTables() as $table) {
        $policy = collect(Schema::policies($table))->firstWhere('polname', "owner_bypass_{$table}");

        expect($policy['roles'])->toBe('qrpager_owner', "owner_bypass_{$table} is reachable by another role");
    }
});

it('has exactly the four named cross-tenant policies and no others', function (): void {
    $crossTenant = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname || '.' || p.polname as policy
        from pg_policy p
        join pg_class c on c.oid = p.polrelid
        where p.polname not like 'tenant_isolation_%'
          and p.polname not like 'owner_bypass_%'
        order by 1
    SQL);

    expect(array_map(static fn (object $r): string => (string) $r->policy, $crossTenant))->toBe([
        'outbox.worker_drains_outbox',
        'outbox.worker_updates_outbox',
        'pager_sessions.worker_expires_sessions',
        'pager_sessions.worker_reads_expired_sessions',
    ]);
});

it('gives the app role no cross-tenant policy anywhere', function (): void {
    $reachable = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname || '.' || p.polname as policy
        from pg_policy p
        join pg_class c on c.oid = p.polrelid
        where 'qrpager_app'::regrole = any(p.polroles)
          and p.polname not like 'tenant_isolation_%'
        order by 1
    SQL);

    expect($reachable)->toBe([]);
});

it('keeps every table owned by the owner role, so FORCE RLS has something to bind', function (): void {
    $foreign = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname, pg_get_userbyid(c.relowner) as owner
        from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r' and pg_get_userbyid(c.relowner) <> 'qrpager_owner'
    SQL);

    expect($foreign)->toBe([]);
});
