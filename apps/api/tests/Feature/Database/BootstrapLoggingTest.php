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
