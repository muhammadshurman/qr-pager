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

    $process = proc_open(
        [base_path($script)],
        $descriptors,
        $pipes,
        base_path(),
        ['APP_ENV' => $environment, 'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')]
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
    $usersBefore = (int) DB::connection('pgsql_owner')->table('users')->count();
    $tenantsBefore = (int) DB::connection('pgsql_owner')->table('tenants')->count();

    foreach (['bin/issue-harness-user', 'bin/issue-pager-session'] as $script) {
        [$status, $output] = runFixtureScript($script, 'production');

        expect($status)->toBe(1, "{$script} did not exit 1 under APP_ENV=production");
        expect($output)->toContain('refusing to run');
        // The refusal says which environment it saw, because a guard that will not say why it
        // fired is the kind somebody disables.
        expect($output)->toContain("'production'");
    }

    // THE HALF THAT MATTERS: the refusal is before the write, not after it.
    expect((int) DB::connection('pgsql_owner')->table('users')->count())->toBe($usersBefore);
    expect((int) DB::connection('pgsql_owner')->table('tenants')->count())->toBe($tenantsBefore);
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

    expect($status)->toBe(0, "the script refused a legitimate environment: {$output}");
    expect($output)->not->toContain('refusing to run');
});
