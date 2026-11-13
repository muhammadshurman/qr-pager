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

/**
 * Every thing in this repository that carries the fixture guard, and how to run it.
 *
 * ⚠ TWO GROUNDS, WALKED THE SAME WAY, BECAUSE A DERIVED SET IS ONLY DERIVED OVER THE GROUND
 * IT ACTUALLY WALKS. This was a glob over `bin/*` alone -- which was itself a correction of a
 * glob over `bin/issue-*`, and the docblock below records why. `QRPAGER-48` added
 * `studio:demo-tenant`: an ARTISAN COMMAND that writes as the database owner and creates an
 * owner-role account whose password is a literal in this repository. It was invisible here,
 * and so was the shape of its own refusal (a capital R against a lowercase grep).
 *
 * The grep is the same in both places -- the guard's own refusal string -- so a new fixture
 * is covered the moment it opts in by carrying the guard, whichever of the two it is.
 *
 * ⚠ THE ARTISAN HALF GREPS THE CLASS FILE AND READS THE SIGNATURE OUT OF IT, rather than
 * asking the console kernel for registered commands. `Artisan::all()` would answer with
 * Laravel's own hundred-odd commands and this would become a filter over THEM; the question
 * here is narrower and structural: which of OUR files carry the guard, and what is each one
 * called. A command that carries the guard and no `$signature` is a malformed command, and
 * `preg_match` returning nothing drops it -- which the exact-set assertion then catches.
 *
 * @return array<string, list<string>> subject name => argv to run it
 */
function guardedSubjects(): array
{
    $subjects = [];

    foreach (glob(base_path('bin/*')) ?: [] as $path) {
        if (! is_file($path)) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), 'refusing to run')) {
            $subjects['bin/'.basename($path)] = [$path];
        }
    }

    $commands = glob(base_path('app/*/*/Console/*.php')) ?: [];

    foreach ($commands as $path) {
        $source = (string) file_get_contents($path);

        /*
         * ⚠ COMMENTS STRIPPED BEFORE THE GREP, AND WITHOUT THIS THE SET GAINED A WORKER.
         * `WorkOutboxCommand` contains the phrase in a docblock -- *"this degrades to 'no
         * graceful stop' rather than refusing to run"* -- so the first version of this
         * derivation reported `outbox:work` as a guarded fixture and then tried to run it
         * under `APP_ENV=production`. The guard has to be in CODE to be a guard; prose about
         * one is the thing a derivation must not mistake for the thing itself.
         */
        $code = (string) preg_replace(['#/\*[\s\S]*?\*/#', '#//[^\n]*#'], ' ', $source);

        if (! str_contains($code, 'refusing to run')) {
            continue;
        }

        if (preg_match('/\$signature\s*=\s*\'([^\']+)\'/', $code, $name) !== 1) {
            continue;
        }

        // The command NAME only -- a signature may carry arguments and options after it,
        // across several lines, so the split is on whitespace rather than on a space.
        $signature = (string) (preg_split('/\s+/', trim($name[1])) ?: [''])[0];

        if ($signature === '') {
            continue;
        }

        $subjects[$signature] = [PHP_BINARY, base_path('artisan'), $signature];
    }

    return $subjects;
}

/**
 * Run a guarded fixture with an overridden APP_ENV and return [status, output].
 *
 * ⚠ IT TAKES A COMMAND LINE, NOT A PATH, SINCE `QRPAGER-48` `§11` QA M-3 / SECURITY M-1.
 * The derivation below now finds guarded ARTISAN COMMANDS as well as `bin/*` scripts, and
 * those are run as `php artisan <name>` rather than executed directly. `guardedSubjects()`
 * returns the argv for each, so this function never has to know which kind it is holding.
 *
 * @param  list<string>  $argv
 */
function runFixtureCommand(array $argv, string $environment): array
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
        $argv,
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

    expect($process)->not->toBeFalse('could not start '.implode(' ', $argv));

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

    /*
     * ⚠ DERIVED FROM `bin/` RATHER THAN LISTED — `§11` SECURITY L-1. This was a hard-coded
     * pair, and 10b added `bin/issue-board-pager` (which writes rows as the owner AND prints
     * a raw QR token) while `bin/issue-staff-fixture` had never been in it either: two
     * guarded scripts whose guard nothing exercised. *An unproven guard is not a guard*, and
     * a list somebody has to remember to extend is how this one got two entries behind.
     *
     * The derivation is a grep for the guard's own refusal string over `bin/`, so a NEW
     * fixture script is covered the moment it carries the guard — and one that carries no
     * guard is reported by the vacuity check below rather than silently skipped.
     *
     * ⚠ **THE GLOB WAS `bin/issue-*` AND IS NOW `bin/*`, BECAUSE THE PATTERN WAS ITSELF THE
     * HARD-CODED LIST THIS COMMENT CLAIMS TO HAVE REPLACED.** Found at 10c, by the script that
     * fell outside it: `bin/count-dispatches` reads real rows inside a tenant scope and carries
     * the identical guard, and `issue-*` could not see it — so a sixth guarded script existed
     * with nothing exercising its guard, which is exactly the two-entries-behind state
     * `§11` SECURITY L-1 found and this derivation was written to end.
     *
     * The property is *every script under `bin/` that refuses outside local/testing really does
     * refuse*, and the honest derivation of that is the refusal string over the whole directory.
     * Nothing else in `bin/` contains it — the proofs and the gate runners write no rows of
     * their own — so widening the glob adds exactly the scripts that opted in by carrying the
     * guard, which is what makes this derived rather than listed.
     */
    $guarded = array_keys(guardedSubjects());

    sort($guarded);

    // Not vacuous, and an EXACT set: a grep that silently stopped matching would make the
    // loop below iterate nothing and this case pass having proven nothing — which is the
    // shape `MembershipCallSiteTest`'s own planted-call-site control exists for.
    expect($guarded)->toBe([
        // 10c's, and the one that proved the old `issue-*` glob was a list. It reads real rows
        // inside a tenant scope as the oracle for `QRPAGER-27 §0`'s dispatch count.
        // Task 11a's. `R-133` needs a domain that has actually GONE LIVE rather than a row set
        // to `active` by hand -- activation is what calls `republish()`, which is the code under
        // test -- so this walks the real lifecycle and writes a DNS zone fixture and a
        // certificate ledger entry on the way.
        'bin/activate-domain-fixture',
        'bin/count-dispatches',
        'bin/issue-board-pager',
        'bin/issue-harness-user',
        // 10c's. A device token's plaintext half exists exactly once, at generation, so the
        // resync proof has to be handed one to present at `/kds`.
        'bin/issue-kds-token',
        'bin/issue-pager-session',
        'bin/issue-staff-fixture',
        // Task 11's. `§3` row 11's invariant needs TWO tenants with real published pages in
        // the real object store, and a fixture that wrote the bytes itself would be proving
        // its own layout rather than the publisher's -- so this one goes through
        // `LandingPages::saveDraft()` and `publish()`, writing to the bucket as the owner.
        'bin/publish-landing-fixture',
        /*
         * ⚠ TWO ARTISAN COMMANDS, AND THE DERIVATION COULD NOT SEE EITHER UNTIL NOW.
         * `QRPAGER-48` `§11` QA M-3 / SECURITY M-1: `studio:demo-tenant` bootstraps the real
         * kernel, writes as `pgsql_owner`, and creates a login-capable OWNER with a password
         * literal committed in this repo -- strictly more dangerous than anything in `bin/`
         * -- and it was outside this set on TWO independent counts. It lives in
         * `app/Features/.../Console/` rather than `bin/`, and it spelled its refusal with a
         * capital R, so widening the glob alone would still have missed it. Both are fixed
         * at the source: the string matches, and `guardedSubjects()` walks commands too.
         */
        'studio:demo-imagery',
        'studio:demo-tenant',
    ]);

    $subjects = guardedSubjects();

    foreach ($guarded as $script) {
        [$status, $output] = runFixtureCommand($subjects[$script], 'production');

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
        [$status, $output] = runFixtureCommand(guardedSubjects()['bin/issue-pager-session'], $environment);

        expect($status)->toBe(1, "APP_ENV='{$environment}' was accepted");
        expect($output)->toContain('refusing to run');
    }
});

it('still runs under testing, so the guard is a guard and not a lockout', function (): void {
    // The positive control. Without it the two cases above would pass on a script that
    // refused everything, which is the vacuous shape this project refuses.
    [$status, $output] = runFixtureCommand(guardedSubjects()['bin/issue-pager-session'], 'testing');

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
