<?php

declare(strict_types=1);

use App\Platform\Database\RoleBootstrapper;
use Illuminate\Support\Facades\DB;

/*
 * R-009: the role password verifier must not reach the Postgres log.
 *
 * F4 moved the PLAINTEXT off the wire and did not stop the VERIFIER reaching the log.
 * The verifier is the complete pg_authid secret -- ServerKey completes a SCRAM
 * handshake AS THE SERVER, which sslmode=require cannot detect (R-008), and StoredKey
 * enables offline recovery -- so this is the §4 "no secrets in logs" clause applied to
 * the one statement in the codebase that carries a credential.
 */

/**
 * log_statement = 'all' for new sessions on this database, as root.
 *
 * Without this the test PASSES FOR THE WRONG REASON: the session would report 'none'
 * because nothing ever asked for anything else, so the assertion would hold whether or
 * not the bootstrapper did its job.
 *
 * It used to say "locally compose already runs the cluster with log_statement=all
 * (R-004), so the local run would be honest and CI's would not". That is no longer true
 * and the reason is this same R-004: Task 3 removed the cluster-wide setting from
 * compose.yaml, because from Task 3 on every token lookup binds a hash and a cluster that
 * logs everything writes tenant data into an unredacted log. Local and CI now agree
 * BECAUSE NEITHER has it on by default and this helper is what turns it on -- which was
 * always the stronger arrangement, and is why removing the compose flag cost this file
 * nothing but a comment.
 *
 * ALTER DATABASE rather than ALTER SYSTEM: it is inherited by every new session on this
 * database, which is what the session-level SET has to override, and it does not write
 * to postgresql.auto.conf or need a config reload.
 */
function logEveryStatementOnThisDatabase(): string
{
    $database = (string) config('database.connections.pgsql.database');

    DB::connection('pgsql_root')->unprepared("alter database \"{$database}\" set log_statement = 'all'");

    return $database;
}

function stopLoggingEveryStatement(string $database): void
{
    DB::connection('pgsql_root')->unprepared("alter database \"{$database}\" reset log_statement");
}

it('runs the bootstrap session with log_statement off, over an inherited all — R-009', function (): void {
    $database = logEveryStatementOnThisDatabase();

    try {
        // A fresh session, so it inherits the database setting above. Reading it on the
        // bootstrap connection AFTER run() is what matters: the setting is session-
        // scoped, so it has to still be in force on the session that issued the ALTER
        // ROLE statements.
        DB::purge('pgsql_bootstrap');

        app(RoleBootstrapper::class)->run();

        expect((string) DB::connection('pgsql_bootstrap')->selectOne(
            "select current_setting('log_statement') as value",
        )->value)->toBe('none');
    } finally {
        stopLoggingEveryStatement($database);
        DB::purge('pgsql_bootstrap');
    }
});

it('suppresses the log for its own session only, not for the database', function (): void {
    $database = logEveryStatementOnThisDatabase();

    try {
        DB::purge('pgsql_bootstrap');
        DB::purge('pgsql_owner');

        app(RoleBootstrapper::class)->run();

        // The measurement behind R-009's fix: the bootstrap session is quiet and the
        // grant connection is not. The point is that the suppression is SESSION-scoped --
        // if it reached the database or the cluster it would silence every other session
        // too, and an assertion that the bootstrap session is quiet would then be
        // trivially true of everything.
        //
        // This comment used to add "it would take R-004's deliberate local control with
        // it, and Task 4's proof depends on that control existing". Both halves are gone:
        // Task 3 removed the cluster-wide log_statement (R-004) and Task 4's zero-queries
        // proof has a query listener, which is the instrument Task 2 already used for the
        // same claim. The scoping assertion below is worth keeping on its own terms.
        expect((string) DB::connection('pgsql_bootstrap')->selectOne(
            "select current_setting('log_statement') as value",
        )->value)->toBe('none');

        expect((string) DB::connection('pgsql_owner')->selectOne(
            "select current_setting('log_statement') as value",
        )->value)->toBe('all');
    } finally {
        stopLoggingEveryStatement($database);
        DB::purge('pgsql_bootstrap');
        DB::purge('pgsql_owner');
    }
});

it('sets it before anything else runs on that session', function (): void {
    $database = logEveryStatementOnThisDatabase();

    try {
        DB::purge('pgsql_bootstrap');

        $statements = [];

        // Every statement this session issues, in order, captured before execution --
        // beforeExecuting rather than DB::listen, for the reason TenantScopeGuard uses
        // it: listen fires after, and the question here is what ran FIRST.
        DB::connection('pgsql_bootstrap')->beforeExecuting(function (string $query) use (&$statements): void {
            $statements[] = $query;
        });

        app(RoleBootstrapper::class)->run();

        // The advisory lock is the first thing run() does after the suppression, and
        // R-009 asks for the SET to come before it: a statement issued ahead of the
        // suppression is a statement the log already has. TWO settings, because
        // log_statement = 'none' does not cover a statement that FAILS --
        // log_min_error_statement does, and its default logs the full text.
        expect($statements[0] ?? '')->toContain('log_statement')
            ->and($statements[1] ?? '')->toContain('log_min_error_statement')
            ->and($statements[2] ?? '')->toContain('pg_advisory_lock');
    } finally {
        stopLoggingEveryStatement($database);
        DB::purge('pgsql_bootstrap');
    }
});

it('leaves no verifier in the container log for a bootstrap that logs everything', function (): void {
    // The measurement R-009 records, re-run against the real container log rather than
    // asserted: 6 occurrences of SCRAM-SHA-256 per bootstrap before the fix, 0 after.
    // Skipped where the log cannot be read, because a test that silently measures
    // nothing is worse than one that says it did not run.
    //
    // The assertion is 0 and not a count, deliberately: the window spans the whole
    // suite run, so the number of bootstraps inside it is not a fixed figure. With the
    // SET removed this case measured 18 over three bootstraps -- R-009's own figure of
    // 6 each, reproduced exactly -- and 0 is the property either way.
    //
    // What this window covers is every test that has already run, which is how it found
    // two leaks neither R-009 nor I had named: a FAILED role statement logged in full by
    // log_min_error_statement, and a test binding the verifier into a comparison on the
    // root connection. It cannot see a later file; the CI step that runs the same scan
    // after the suite is what removes the ordering dependency.
    $compose = base_path('../../compose.yaml');

    if (! is_file($compose) || exec('docker compose version 2>/dev/null') === '') {
        $this->markTestSkipped('the container log is not reachable from here');
    }

    $database = logEveryStatementOnThisDatabase();

    try {
        DB::purge('pgsql_bootstrap');

        // The whole suite run, not this test's own second. A per-test window either
        // missed a leak from a neighbouring test or caught one at random depending on
        // how fast the suite ran -- measured both ways. Widened, it is deterministic,
        // and it covers every test that has already run.
        $since = $this->suiteStartedAt();

        app(RoleBootstrapper::class)->run();

        // A moment for the container to flush, then read only what this bootstrap wrote.
        usleep(750_000);

        $log = (string) shell_exec(
            'docker compose -f '.escapeshellarg($compose)
            .' logs --since '.escapeshellarg($since).' postgres 2>&1',
        );

        expect(substr_count($log, 'SCRAM-SHA-256'))->toBe(0, 'the role verifier reached the Postgres log')
            ->and(substr_count($log, 'ALTER ROLE'))->toBe(0)
            ->and(substr_count($log, 'CREATE ROLE'))->toBe(0);
    } finally {
        stopLoggingEveryStatement($database);
        DB::purge('pgsql_bootstrap');
    }
});

/*
 * R-004's closure, as a test rather than as a diff — security S-3.
 *
 * The row was recorded CLOSED on the strength of removing `-c log_statement=all` from
 * compose.yaml. That edit is correct and it changed nothing on any cluster already
 * running: `docker compose` does not recreate a container when its command changes, so
 * the review found the local cluster still at `all`, with 47 `resolve_pager_token`
 * statements in the container log each followed by `DETAIL: parameters: $1 = '<sha256>'`.
 * A control nothing asserts is a control that can be off while the ledger says it is on.
 *
 * WHAT THIS ASSERTS IS THE SOURCE, NOT THE VALUE, and that distinction is the whole test.
 * Asserting `log_statement = 'none'` would be RED ON CI BY DESIGN: CI issues
 * `alter system set log_statement = 'ddl'` before the suite to give R-036's verifier scan
 * a positive control. ('all' until 2026-10-27 -- security L-4 narrowed it to 'ddl', which
 * keeps the ALTER ROLE lines that scan reads and drops every bound parameter, closing
 * R-059.) What R-004 is about is the CLUSTER-WIDE COMMAND-LINE setting, and Postgres
 * reports where a value came from:
 *
 *   compose `-c log_statement=all`  -> pg_settings.source = 'command line'
 *   CI's `alter system`             -> 'configuration file'
 *   neither                         -> 'default'            (measured 2026-10-27)
 *
 * So "never from the command line" is true locally and on CI, and goes red the moment
 * anyone puts the flag back in compose.yaml.
 *
 * THE POSITIVE CONTROL WAS WRONG ONCE AND THE WAY IT WAS WRONG IS THE LESSON. It read
 * `log_connections`'s source and required it to be `'command line'`, on the reasoning that
 * compose.yaml deliberately keeps that flag — so if the command line is being read at all,
 * that is where the proof would show. It passes locally and it was RED ON CI from the
 * commit that introduced it, which is why five pushes on this branch carried a red check.
 *
 * The flag was never removed: `-c log_connections=on` is in compose.yaml at every commit on
 * this branch, measured one by one. **CI does not use compose.yaml.** Its Postgres is a
 * GitHub Actions SERVICE CONTAINER declared with `image:` and `options:` and no `command:`
 * at all, so that cluster reads NO command line and `log_connections` is `'default'` there.
 * Reproduced locally rather than inferred from the failure text: a bare
 * `docker run postgres:16-alpine` with the same three env vars and no command reports
 * `log_connections | off | default` (2026-10-27).
 *
 * So the old control did not prove "the source column works". It proved "this cluster was
 * started by compose", which is a property of the LAUNCHER, and the two environments this
 * suite must pass in launch Postgres differently and always will. A control that depends on
 * how the server was started can be taken away by a flag, by a `command:` key, by a CI
 * provider's defaults or by a `docker compose down -v` — none of which is the thing under
 * test.
 *
 * WHAT THE CONTROL IS NOW: the test MANUFACTURES a known provenance and reads it back. It
 * issues a session `SET` on an inert GUC and requires `pg_settings.source` to report
 * `'session'` for it. That proves the two things the assertion above actually depends on --
 * `pg_settings` is readable, and `source` tracks provenance rather than answering `'default'`
 * to everything -- and it proves them from a condition this test creates inside its own
 * connection. No flag, no compose file, no container age and no CI runner can remove it, and
 * it is untouched by R-004's fix because it names none of R-004's settings.
 *
 * `vacuum_cost_delay` is the GUC, chosen because nothing in this project reads it: a control
 * must not be able to perturb the thing it is controlling for. `statement_timeout` was the
 * first choice and was rejected -- the runtime roles carry one from `ALTER ROLE` (§2.1) and
 * `RoleTimeoutTest` proves it fires, so setting it here would have a test reaching into
 * another test's subject.
 *
 * AND THE PROPERTY IS NOW CLOSED-WORLD rather than a single `!=`. Every provenance this
 * project can legitimately produce was measured (2026-10-27): untouched -> `'default'`,
 * `SET`/`SET LOCAL` -> `'session'`, `ALTER DATABASE ... SET` -> `'database'` (which is what
 * `logEveryStatementOnThisDatabase()` above produces), `ALTER SYSTEM ... SET` + reload ->
 * `'configuration file'` (CI's), compose `-c` -> `'command line'`. Asserting membership of
 * the first four fails closed on `'command line'` AND on anything new, where `!=` would have
 * admitted a provenance nobody has thought about yet.
 */
it('takes log_statement from no command-line flag, which is what R-004 removed', function (): void {
    $root = DB::connection('pgsql_root');

    // THE POSITIVE CONTROL, FIRST, because the assertion below is only worth reading if
    // this one holds. A provenance this test creates itself, read back through the same
    // view and the same column the property depends on.
    $root->statement("set vacuum_cost_delay = '7ms'");

    /** @var object{setting: string, source: string}|null $control */
    $control = $root->selectOne(
        "select setting, source from pg_settings where name = 'vacuum_cost_delay'",
    );

    expect($control)->not->toBeNull('pg_settings is not readable, so nothing below proves anything')
        ->and($control?->source)->toBe(
            'session',
            'a session SET did not show as source=session, so pg_settings.source is not tracking '
            .'provenance on this cluster and the assertion below would pass for the wrong reason'
        )
        ->and($control?->setting)->toBe('7', 'the SET did not take effect at all');

    $root->statement('reset vacuum_cost_delay');

    // THE PROPERTY. R-004 is about the CLUSTER-WIDE COMMAND-LINE setting specifically, so
    // the value is deliberately not asserted: CI sets `ddl` through `alter system` on
    // purpose, to give R-036's verifier scan a positive control.
    /** @var object{setting: string, source: string}|null $row */
    $row = $root->selectOne(
        "select setting, source from pg_settings where name = 'log_statement'",
    );

    expect($row)->not->toBeNull()
        ->and($row?->source)->toBeIn(
            ['default', 'session', 'database', 'configuration file'],
            'log_statement has a provenance this project does not produce. If it is '
            .'[command line], compose.yaml carries -c log_statement=all again, which is R-004: '
            .'every token-hash binding goes to a log nobody redacts.'
        );
});
