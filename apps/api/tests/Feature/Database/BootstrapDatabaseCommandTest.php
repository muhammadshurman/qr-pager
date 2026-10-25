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

/*
 * F4 (security review of task-1): ALTER ROLE ... PASSWORD '<plaintext>' put the
 * role password in the statement text, which log_statement captures, and in a
 * bound parameter, which Postgres logs on error and Laravel interpolates into
 * QueryException::getMessage(). Both breach PROJECT_INSTRUCTIONS.md §4 ("no
 * secrets in logs", "stack traces never carry SQL or bound parameters"). The
 * verifier is now computed locally, so the plaintext never leaves the process.
 */

it('never sends a role password to the server, in a statement or in a binding', function (): void {
    $password = 'NotTheRealOne-'.bin2hex(random_bytes(8));

    config([
        'database.connections.pgsql.password' => $password,
        'database.connections.pgsql_owner.password' => $password,
        'database.connections.pgsql_worker.password' => $password,
    ]);

    $bootstrap = DB::connection('pgsql_bootstrap');
    $bootstrap->flushQueryLog();
    $bootstrap->enableQueryLog();

    try {
        app(RoleBootstrapper::class)->run();

        $sent = '';

        foreach ($bootstrap->getQueryLog() as $entry) {
            $sent .= $entry['query'];

            foreach ($entry['bindings'] as $binding) {
                $sent .= '|'.(is_scalar($binding) ? (string) $binding : '');
            }
        }

        // The verifier has to be there, or this asserts nothing at all.
        expect($sent)->toContain('SCRAM-SHA-256$4096:')
            ->and($sent)->not->toContain($password);
    } finally {
        $bootstrap->disableQueryLog();

        // Put the roles back on the password the rest of the suite connects with.
        config([
            'database.connections.pgsql.password' => env('DB_PASSWORD'),
            'database.connections.pgsql_owner.password' => env('DB_OWNER_PASSWORD'),
            'database.connections.pgsql_worker.password' => env('DB_WORKER_PASSWORD'),
        ]);

        app(RoleBootstrapper::class)->run();
        $this->purgeConnections();
    }
});

// Note: asserting that pg_authid holds a SCRAM verifier proves nothing here --
// password_encryption defaults to scram-sha-256, so the server stores one even
// when it is handed a plaintext PASSWORD. What changed is that the plaintext no
// longer reaches the server at all, which the test above is what proves.
it('refuses a password SASLprep would rewrite, without echoing it', function (): void {
    // The verifier is built locally, which is only sound where SASLprep is the
    // identity. A non-ASCII password would produce a verifier that authenticates
    // nobody, so it is refused -- and the refusal must not quote the password.
    $password = "pa\u{00df}word-\u{2003}";

    config(['database.connections.pgsql_worker.password' => $password]);

    try {
        app(RoleBootstrapper::class)->run();
        $this->fail('a non-ASCII role password was accepted');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('printable ASCII')
            ->and($e->getMessage())->not->toContain($password);
    } finally {
        config(['database.connections.pgsql_worker.password' => env('DB_WORKER_PASSWORD')]);
    }
});

/*
 * F5: Postgres reserves connection slots for superusers only, so without a
 * per-role limit qrpager_app exhausting max_connections starves the worker (the
 * only sender of notifications, C5) and the owner (migrations, incident response).
 */

it('gives every role a connection limit, and leaves the cluster headroom', function (): void {
    $limits = [];

    foreach ($this->asRoot()->select(
        'select rolname, rolconnlimit from pg_roles where rolname = any(?::text[])',
        ['{qrpager_app,qrpager_owner,qrpager_worker}'],
    ) as $role) {
        $limits[(string) $role->rolname] = (int) $role->rolconnlimit;
    }

    expect($limits)->toHaveCount(3);

    foreach (['owner', 'app', 'worker'] as $key) {
        $name = (string) config("tenancy.roles.{$key}");

        expect($limits[$name] ?? -1)
            ->toBe((int) config("tenancy.connection_limits.{$key}"), "{$name} has no connection limit")
            ->toBeGreaterThan(0);
    }

    // The point of the limits is that one role cannot take the whole cluster, so
    // their sum has to leave room for the superuser reserve and the two
    // administrative connections on top.
    $max = (int) $this->asRoot()->selectOne("select current_setting('max_connections') as v")->v;
    $reserved = (int) $this->asRoot()->selectOne("select current_setting('superuser_reserved_connections') as v")->v;

    expect(array_sum($limits))->toBeLessThan($max - $reserved);
});

it('refuses to create a role with no connection limit configured', function (): void {
    config(['tenancy.connection_limits.app' => null]);

    expect(fn () => app(RoleBootstrapper::class)->run())
        ->toThrow(RuntimeException::class, 'No connection limit is configured for role [app]');
});
