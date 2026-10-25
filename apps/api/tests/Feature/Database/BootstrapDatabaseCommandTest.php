<?php

declare(strict_types=1);

use App\Platform\Database\RoleBootstrapper;
use Illuminate\Support\Facades\DB;

/*
 * db:bootstrap is what makes "the gates pass from a fresh clone" true: roles, the
 * database and the per-role timeouts all come from here, and the suite runs it
 * itself (PROJECT_INSTRUCTIONS.md §6).
 */

it('is idempotent, so a second run converges instead of failing', function (): void {
    $this->artisan('db:bootstrap')->assertSuccessful();
    $this->artisan('db:bootstrap')->assertSuccessful();
});

it('reports what it did', function (): void {
    $this->artisan('db:bootstrap')
        ->expectsOutputToContain('role updated: qrpager_owner')
        ->expectsOutputToContain('qrpager_app: statement_timeout = 5s')
        ->expectsOutputToContain('Migrate with: php artisan migrate --database=pgsql_owner')
        ->assertSuccessful();
});

it('restores the role attributes if someone changes them by hand', function (): void {
    $root = $this->asRoot();
    $root->statement('alter role qrpager_app superuser bypassrls');

    try {
        $this->artisan('db:bootstrap')->assertSuccessful();
    } finally {
        $root->statement('alter role qrpager_app nosuperuser nobypassrls');
    }

    $attributes = $root->selectOne(
        'select rolsuper, rolbypassrls from pg_roles where rolname = ?', ['qrpager_app'],
    );

    expect((bool) $attributes->rolsuper)->toBeFalse()
        ->and((bool) $attributes->rolbypassrls)->toBeFalse();
});

it('creates the database when it does not exist yet', function (): void {
    $probe = 'qrpager_bootstrap_probe';

    config(['database.connections.pgsql.database' => $probe]);

    try {
        app(RoleBootstrapper::class)->run();

        $exists = DB::connection('pgsql_bootstrap')->selectOne(
            'select exists(select 1 from pg_database where datname = ?) as present', [$probe],
        );

        expect((bool) $exists->present)->toBeTrue();

        // The grants are per database, so they have to land on the database that
        // was just created and not on whatever the root connection points at.
        $granted = DB::connectUsing('probe_check', array_merge(
            config('database.connections.pgsql_root'),
            ['database' => $probe, 'url' => null],
        ), true)->selectOne(
            "select has_schema_privilege('qrpager_owner', 'public', 'CREATE') as owner_can_create,
                    has_schema_privilege('qrpager_app', 'public', 'USAGE') as app_can_use,
                    has_schema_privilege('qrpager_app', 'public', 'CREATE') as app_can_create"
        );

        expect((bool) $granted->owner_can_create)->toBeTrue()
            ->and((bool) $granted->app_can_use)->toBeTrue()
            ->and((bool) $granted->app_can_create)->toBeFalse();
    } finally {
        DB::purge('probe_check');
        DB::connection('pgsql_bootstrap')->statement("drop database if exists {$probe}");
    }
});

it('refuses to apply a role setting that is not a timeout', function (): void {
    // The setting name is interpolated into ALTER ROLE, so the allowlist is the
    // thing standing between config and arbitrary DDL.
    config(['tenancy.timeouts' => ['app' => ['work_mem' => '512MB']]]);

    expect(fn () => app(RoleBootstrapper::class)->run())
        ->toThrow(RuntimeException::class, '[work_mem] is not a role setting this bootstrapper may change');
});

it('refuses to create a role with no password configured', function (): void {
    config(['database.connections.pgsql_worker.password' => '']);

    expect(fn () => app(RoleBootstrapper::class)->run())
        ->toThrow(RuntimeException::class, 'No password is configured for role [worker]');
});

it('refuses to run with no target database configured', function (): void {
    config(['database.connections.pgsql.database' => null]);

    expect(fn () => app(RoleBootstrapper::class)->run())
        ->toThrow(RuntimeException::class, 'No target database is configured');
});

it('releases the advisory lock it serialises on, even when it fails', function (): void {
    config(['tenancy.timeouts' => ['app' => ['work_mem' => '512MB']]]);

    try {
        app(RoleBootstrapper::class)->run();
    } catch (RuntimeException) {
        // expected
    }

    // Parallel suites queue on this lock; a bootstrap that died holding it would
    // hang every other runner (PROJECT_INSTRUCTIONS.md §4 › Postgres traps).
    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ?', ['advisory'],
    );

    expect((int) $held->c)->toBe(0);
});
