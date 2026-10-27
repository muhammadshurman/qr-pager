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
    // R-018: the convergence is proven on a THROWAWAY role, pointed at by
    // tenancy.roles.app, instead of granting `superuser bypassrls` to the live
    // qrpager_app and reverting in a finally. That was the worst of the three sites --
    // an interrupted run between the two statements left a SUPERUSER application role
    // on the cluster, and the next suite run silently repaired it, so nothing ever
    // reported it.
    $root = $this->asRoot();

    $this->withThrowawayRole(['superuser', 'bypassrls'], function (string $connection, string $role) use ($root): void {
        // Asserted, not assumed: with the helper reverted to altering the live role,
        // every other assertion in this test still passes -- db:bootstrap converges
        // qrpager_app's attributes just as happily. This is the one line that can tell
        // the two apart, and without it the revert of R-018's fix left this site green.
        expect($role)->not->toBe('qrpager_app')
            ->and($role)->toStartWith('qrpager_probe_');

        config(['tenancy.roles.app' => $role]);

        $this->artisan('db:bootstrap')->assertSuccessful();

        $attributes = $root->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = ?', [$role],
        );

        expect((bool) $attributes->rolsuper)->toBeFalse()
            ->and((bool) $attributes->rolbypassrls)->toBeFalse();

        // And the live role, which this test no longer touches, is still as it was.
        $live = $root->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = ?', ['qrpager_app'],
        );

        expect((bool) $live->rolsuper)->toBeFalse()
            ->and((bool) $live->rolbypassrls)->toBeFalse();
    });
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
    //
    // FILTERED ON THE BOOTSTRAP'S OWN KEY, which is what this assertion always meant.
    // It used to count every advisory lock in the cluster and expect zero, and that
    // passed only because the bootstrap's was the only advisory lock anything took. It
    // is not any more: R-049's fix has the suite process hold one of its own for its
    // whole life, so the unfiltered count is 1 here and the assertion failed for a
    // reason that had nothing to do with what it checks. Same shape as R-016's
    // unfiltered audit_log count -- a count bounded by circumstance rather than by its
    // filter -- and narrowing it makes it STRONGER: it now says this key is released,
    // rather than that no advisory lock exists anywhere.
    $key = (int) config('tenancy.bootstrap_lock_key');

    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ? and objid = ?', ['advisory', $key],
    );

    expect((int) $held->c)->toBe(0, "the bootstrap left advisory lock {$key} held");
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

// A shape assertion proves nothing here: password_encryption defaults to
// scram-sha-256, so pg_authid holds a verifier even when the server was handed a
// plaintext PASSWORD. An equality assertion does prove something: Postgres silently
// re-salts anything it cannot parse as a verifier, so rolpassword matching the string
// this run built, byte for byte, is what shows the verifier arrived intact. The test
// below does that (M-2, scoped security review of the task-1 fixes).
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

it('withholds the statement text when a role statement fails', function (): void {
    // The other half of F4: the plaintext never reaching the server is measured
    // above, but a role statement that FAILS must not carry the verifier either --
    // Laravel interpolates bindings into QueryException::getMessage(), and a chained
    // previous exception would put the statement back into a rendered stack trace.
    //
    // CONNECTION LIMIT takes a signed int4, so PHP_INT_MAX fails the ALTER ROLE with
    // 42601 and leaves the role untouched. It is the only lever that reaches the
    // redaction path without doing something destructive to the cluster.
    //
    // COUPLING, deliberate and fragile: this test reaches the redaction path only
    // through F5's CONNECTION LIMIT clause. Remove that clause, or stop interpolating
    // the limit into the statement, and this test stops exercising redaction -- it
    // fails loudly rather than passing vacuously, but the next person to change the
    // limit should know it disarms this test's lever, not just its last assertion.
    $limit = (int) config('tenancy.connection_limits.app');

    config(['tenancy.connection_limits.app' => PHP_INT_MAX]);

    $password = (string) config('database.connections.pgsql.password');
    $caught = null;

    try {
        app(RoleBootstrapper::class)->run();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('an invalid role statement was accepted')
        ->and($caught->getMessage())->toContain('Could not create or alter role [qrpager_app]')
        // R-013: the message now distinguishes the two phases, and this is the
        // EXECUTE one -- the statement was built and the cluster rejected it.
        ->and($caught->getMessage())->toContain('The statement was sent and failed')
        ->and($caught->getMessage())->toContain('text is withheld')
        // ...and names the real SQLSTATE. 42601 is a syntax error, which is what an
        // out-of-range CONNECTION LIMIT produces. Before R-013 this clause read
        // "(SQLSTATE 42601)" too, because getCode() happens to agree with errorInfo[0]
        // for statement errors -- it is connection failures where it prints 7.
        ->and($caught->getMessage())->toContain('(SQLSTATE 42601)')
        ->and($caught->getMessage())->not->toContain($password)
        ->and($caught->getMessage())->not->toContain('SCRAM-SHA-256')
        ->and($caught->getMessage())->not->toContain('ALTER ROLE')
        ->and($caught->getPrevious())->toBeNull();

    // The failed role statement changed nothing: the owner was altered idempotently
    // before app threw, and the limit under test never landed.
    expect((int) $this->asRoot()->selectOne(
        'select rolconnlimit from pg_roles where rolname = ?', ['qrpager_app'],
    )->rolconnlimit)->toBe($limit);
});

it('stores the verifier it built, byte for byte', function (): void {
    // Postgres accepts a malformed verifier silently: get_password_type() fails to
    // parse it, falls back to PLAINTEXT and re-hashes the string with a fresh salt. The
    // role's live password then becomes the verifier string -- which this same code
    // writes to the Postgres log. Nothing in the server tells you; only equality does.
    //
    // What this does NOT catch, measured by injecting each defect: a verifier that is
    // mis-derived but still well-formed is stored verbatim, so equality passes and only
    // authentication failing reveals it. And if a MALFORMED verifier breaks a role the
    // suite needs, TestCase::setUp dies with 08006 before this assertion runs -- loud,
    // but not from here. The precise diagnosis this gives is for the case where the
    // cluster still works: it says which role Postgres refused to take a verifier for.
    $bootstrap = DB::connection('pgsql_bootstrap');
    $bootstrap->flushQueryLog();
    $bootstrap->enableQueryLog();

    try {
        app(RoleBootstrapper::class)->run();

        $built = [];

        foreach ($bootstrap->getQueryLog() as $entry) {
            if (preg_match('/ROLE "?(\w+)"? .*PASSWORD \'(SCRAM-SHA-256\$[^\']+)\'/', (string) $entry['query'], $match) === 1) {
                $built[$match[1]] = $match[2];
            }
        }

        expect($built)->toHaveCount(3);

        foreach ($built as $role => $verifier) {
            // Read the stored value and compare IN PHP. The first version bound the
            // verifier into the comparison -- `select rolpassword = ? ...` -- and the
            // root connection logs bound parameters, so this test published three
            // verifiers to the Postgres log per run:
            //
            //   DETAIL:  parameters: $1 = 'SCRAM-SHA-256$4096:A5bRy/...'
            //
            // Found by BootstrapLoggingTest's container-log assertion, which is exactly
            // what that assertion is for. The role name is bound and is not a secret;
            // the verifier never crosses the wire in either direction.
            $stored = (string) $this->asRoot()->selectOne(
                'select rolpassword from pg_authid where rolname = ?', [$role],
            )->rolpassword;

            expect(hash_equals($verifier, $stored))
                ->toBeTrue("{$role}: pg_authid does not hold the verifier this run built, so Postgres did not accept it as one");
        }
    } finally {
        $bootstrap->disableQueryLog();
    }
});
