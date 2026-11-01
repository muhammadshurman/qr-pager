<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * THE TWO FIXTURE SCRIPTS REFUSE TO RUN OUTSIDE local/testing — `§11` SEC M-6
 * ================================================================================
 *
 * `bin/issue-harness-user` creates a login-capable account whose password is a committed
 * literal; `bin/issue-pager-session` creates a `tenants` row and prints a raw pager token.
 * Both bootstrap the real kernel and write as `pgsql_owner`, so before this guard they worked
 * against whatever database `.env` pointed at — including a production one. The scripts ship
 * inside `apps/api`, which Task 13 packages wholesale.
 *
 * ⚠ DRIVEN AS REAL PROCESSES, NOT BY READING THE SOURCE. An exit code cannot be asserted from
 * a `grep`, and `§6`'s own clause about this ("it exits 1 as a real process, because an exit
 * code cannot be asserted any other way") is already a case in `GuardBootTest`. The STATUS is
 * captured from `proc_close` directly and never read after a pipe.
 */

/** Run a script with an overridden APP_ENV and return [status, stderr]. */
function runFixtureScript(string $script, string $environment): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    /**
     * ⚠ `DB_DATABASE` IS PASSED, AND WITHOUT IT THIS TEST MEASURED THE WRONG DATABASE.
     * `§11` round two, SEC-R2 L-4 and QA M-6.
     *
     * The environment handed to the subprocess was `APP_ENV`/`PATH`/`HOME` only, so the script
     * read `.env` and wrote to **`qrpager`** — the DEVELOPMENT database the E2E stack serves —
     * while the assertions counted rows on the test database. Measured by a reviewer: running
     * the script as this test does moved `qrpager`'s tenants 163 → 164 and `qrpager_test`'s
     * 0 → 0. So the case commented *"THE HALF THAT MATTERS: the refusal is before the write"*
     * could not fail, and its positive control was writing a real tenant and pager session into
     * the development database on every suite run.
     */
    $process = proc_open(
        [base_path($script)],
        $descriptors,
        $pipes,
        base_path(),
        [
            'APP_ENV' => $environment,
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        ]
    );

    expect($process)->not->toBeFalse("could not start {$script}");

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return [proc_close($process), $out.$err];
}

it('refuses to run as production, on both scripts, and writes no row when it refuses', function (): void {
    /**
     * ⚠ COUNTED AS `pgsql_root`, NOT `pgsql_owner`. `§11` round two, SEC-R2 L-4.
     *
     * `tenants` carries `FORCE ROW LEVEL SECURITY`, so the owner sees ZERO rows with no
     * `app.tenant_id` set — `DataMigrationPatternTest` asserts exactly that. The tenant count
     * was therefore 0 before and 0 after whether the script wrote a row or not, which made the
     * assertion below unfalsifiable for the one script that writes `tenants`, `pager_sessions`,
     * an outbox row and an audit row. `pgsql_root` is the superuser and is the only connection
     * that can count an RLS-forced table without inventing a tenant scope.
     *
     * `users` carries no RLS (measured: `relrowsecurity` is `f`), so either connection sees it —
     * it is counted on the same one for symmetry rather than for necessity.
     */
    $usersBefore = (int) DB::connection('pgsql_root')->table('users')->count();
    $tenantsBefore = (int) DB::connection('pgsql_root')->table('tenants')->count();

    foreach (['bin/issue-harness-user', 'bin/issue-pager-session'] as $script) {
        [$status, $output] = runFixtureScript($script, 'production');

        expect($status)->toBe(1, "{$script} did not exit 1 under APP_ENV=production");
        expect($output)->toContain('refusing to run');
        // The refusal says which environment it saw, because a guard that will not say why it
        // fired is the kind somebody disables.
        expect($output)->toContain("'production'");
    }

    // THE HALF THAT MATTERS: the refusal is before the write, not after it.
    expect((int) DB::connection('pgsql_root')->table('users')->count())->toBe($usersBefore);
    expect((int) DB::connection('pgsql_root')->table('tenants')->count())->toBe($tenantsBefore);
});

it('fails CLOSED on every environment it does not recognise, case included', function (): void {
    /*
     * A junk or near-miss `APP_ENV` must not be treated as development. `Local` and `prod` are
     * in the list on purpose: `environment()` compares exactly, so a capitalised or
     * abbreviated value is NOT local and must refuse.
     *
     * ⚠ AN EMPTY `APP_ENV` IS DELIBERATELY NOT IN THIS LIST, and the first version of this
     * case put it there and FAILED -- the test was wrong, not the guard. An empty override is
     * falsy, so Laravel falls through to `.env`, which is the file that DEFINES the
     * environment: on this machine that is `local`, and on a production host it is
     * `production` and the guard fires. Asserting that an empty value refuses would have been
     * asserting that the script ignores `.env`, which is neither true nor desirable.
     */
    foreach (['staging', 'Local', 'prod', 'development'] as $environment) {
        [$status, $output] = runFixtureScript('bin/issue-pager-session', $environment);

        expect($status)->toBe(1, "APP_ENV='{$environment}' was accepted");
        expect($output)->toContain('refusing to run');
    }
});

it('still runs under testing, so the guard is a guard and not a lockout', function (): void {
    // The positive control. Without it the two cases above would pass on a script that
    // refused everything, which is the vacuous shape this project refuses.
    [$status, $output] = runFixtureScript('bin/issue-pager-session', 'testing');

    /**
     * ⚠ THE OUTPUT IS NOT INTERPOLATED INTO THE MESSAGE. `§11` round two, SEC-R2 L-4 noted that
     * this script PRINTS A RAW PAGER TOKEN, and a failure message carrying it would put the
     * token into a test report — which `§4 › Data` forbids wherever it appears. The status is
     * the assertion; the output is reduced to whether the refusal line is in it.
     */
    expect($status)->toBe(0, 'the script refused a legitimate environment (output withheld: it carries a raw token)');
    expect($output)->not->toContain('refusing to run');

    /**
     * ⚠ THE ROW IS NOT CLEANED UP, AND PASSING `DB_DATABASE` IS WHAT MAKES THAT ACCEPTABLE.
     *
     * This control writes a real tenant, a real pager session, an outbox row and an audit row,
     * and they cannot be removed by deleting the tenant — `pager_sessions_tenant_id_fkey`
     * refuses it, measured (`SQLSTATE[23503]`). Unpicking the graph in a test would be a second
     * implementation of the schema's own cascade order, which is the duplicated-list shape this
     * project keeps paying for.
     *
     * What matters is WHICH database receives it. Before `§11` round two the subprocess read
     * `.env` and wrote to the DEVELOPMENT database on every suite run; it now writes to the
     * suite's own, which `db:bootstrap` and `migrate` recreate. A disposable row in a
     * disposable database is not litter worth a cascade.
     */
});
