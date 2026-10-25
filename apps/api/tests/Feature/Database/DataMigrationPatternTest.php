<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * The data-migration pattern in PLAN.md §2.10.
 *
 * H-1 (QA review of task-1): the loop as it was documented enumerated tenants with
 * a plain owner read. The owner is bound by FORCE RLS too, so that list comes back
 * EMPTY inside a migration and the loop body never runs -- a silent no-op, not an
 * error, which is the worst possible failure for a backfill. The corrected pattern
 * takes the list through the one audited cross-tenant read and then does the work
 * per tenant with no bypass at all.
 */

// CHARACTERISATION TEST, not a regression test. H-1's fix was a prose change to
// PLAN.md §2.10, so there is no code to revert and this cannot "fail without the fix"
// in the §6 sense -- it passes against 122d203 too. It is here to keep the trap proven
// rather than remembered, and §4a records that H-1 has no discriminating regression
// test. Do not count it as §11 coverage.
it('characterises the data-migration trap and its corrected pattern, with no fix to revert', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    // The read the old wording opened with. Recorded here so the trap stays proven
    // rather than remembered.
    expect($this->asOwner()->table('tenants')->pluck('id')->all())->toBe([]);

    $ids = withoutTenantScope(
        'task-1 regression: enumerate tenants for a backfill',
        fn () => DB::connection('pgsql_owner')->table('tenants')->pluck('id')->all(),
    );

    expect($ids)->toHaveCount(2)
        ->toContain($alpha)
        ->toContain($beta);

    foreach ($ids as $id) {
        // Keyed, never bulk: an UPDATE that references no column of the table
        // escapes RLS row filtering altogether (PROJECT_INSTRUCTIONS.md §4).
        withTenant($id, fn () => DB::connection('pgsql_owner')
            ->table('tenants')
            ->where('id', $id)
            ->update(['timezone' => 'Asia/Kuala_Lumpur']), 'pgsql_owner');
    }

    $touched = withoutTenantScope(
        'task-1 regression: count the backfilled rows',
        fn () => DB::connection('pgsql_owner')
            ->table('tenants')
            ->where('timezone', 'Asia/Kuala_Lumpur')
            ->count(),
    );

    expect($touched)->toBe(2);
});

it('survives at transaction level 1, which is where every migration body runs', function (): void {
    // Migrator wraps each migration in a transaction because PostgresGrammar reports
    // supportsSchemaTransactions, so the pattern is always nested. set_config(...,
    // true) binds to the outermost transaction, which is why both helpers save and
    // restore rather than refusing to nest (F1).
    $alpha = $this->makeTenant();

    $owner = DB::connection('pgsql_owner');

    $owner->transaction(function () use ($owner, $alpha): void {
        expect($owner->transactionLevel())->toBe(1);

        $ids = withoutTenantScope(
            'task-1 regression: enumerate inside a migration transaction',
            fn () => $owner->table('tenants')->pluck('id')->all(),
        );

        expect($ids)->toBe([$alpha]);

        withTenant($alpha, fn () => $owner
            ->table('tenants')
            ->where('id', $alpha)
            ->update(['timezone' => 'Asia/Kuching']), 'pgsql_owner');

        // Neither helper may leave anything behind for the rest of the migration.
        expect($owner->selectOne("select coalesce(current_setting('app.tenant_id', true), '') as v")->v)->toBe('')
            ->and($owner->selectOne("select coalesce(current_setting('app.bypass_tenant', true), '') as v")->v)->toBe('')
            ->and($owner->table('tenants')->count())->toBe(0);
    });

    $applied = withoutTenantScope(
        'task-1 regression: confirm the committed backfill',
        fn () => DB::connection('pgsql_owner')->table('tenants')->where('id', $alpha)->value('timezone'),
    );

    expect($applied)->toBe('Asia/Kuching');
});
