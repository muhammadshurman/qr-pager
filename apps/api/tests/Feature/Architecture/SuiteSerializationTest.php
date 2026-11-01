<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * R-049: the suite's per-test TRUNCATE is serialised against a second runner.
 *
 * A META-TEST, and that is the point of it. §3.2 records that every §3 gate clause in this
 * project is a property of the APPLICATION, and that R-049 was the ledger's first row about
 * the EVIDENCE instead -- so the §3.1 audit's question ("what invariant can a review
 * falsify?") does not reach it at all. Three of fifty-eight ledger assignments are now
 * about the toolchain and no gate clause can fail any of them.
 *
 * This file is the cheap answer to that for this one row: the control becomes something
 * `php artisan test` itself checks, so it cannot rot silently between here and whenever a
 * gate clause about the toolchain gets written.
 *
 * The full behavioural proof is apps/api/bin/concurrent-suite-proof, which runs two
 * invocations at once and is in the repository rather than in a session scratchpad --
 * which is R-043's whole complaint, so it is not repeated here.
 */

/** The key TestCase holds. Duplicated deliberately -- see the last case in this file. */
const SUITE_LOCK_KEY = 2_000_000_049;

it('holds a process-lifetime advisory lock, so a second runner queues rather than truncating — R-049', function (): void {
    // Observed from an ordinary Laravel connection, which is the point: the lock is held
    // by a raw PDO handle outside the connection manager, so tearDown()'s purge of every
    // Laravel connection cannot release it. If it could, this would read 0 on the second
    // test in the process rather than on every one.
    /*
     * ⚠ `granted` IS FILTERED, AND THE UNFILTERED FORM REPORTED THE OPPOSITE OF THE TRUTH.
     * `pg_locks` carries a row for a WAITER as well as for the holder (`granted = false`),
     * so a correctly-QUEUED second runner — which is precisely what `R-049`'s lock exists to
     * produce — appeared as a second row and this count read 2. The message then said *"the
     * suite lock is not held, so two concurrent runners would truncate under each other"*,
     * which is the exact opposite of what was happening.
     *
     * Measured by the §11 round-two QA review, whose own run overlapped the other reviewer's:
     * **2 failed, 972 passed, 175 pending**, both cases here, on a tree where the lock was
     * working. A reviewer who read that message would file a false Critical — a control
     * reporting the inverse of reality is worse than an absent one.
     *
     * So: `and granted` on both counts. The holder is one row; a queued waiter is not a
     * second holder.
     */
    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ? and objid = ? and granted',
        ['advisory', SUITE_LOCK_KEY],
    );

    expect((int) $held->c)->toBe(
        1,
        'the suite lock is not HELD (granted), so two concurrent runners would truncate under each other',
    );
});

it('keeps holding it after a connection purge, which is the half that made the naive fix useless', function (): void {
    // The failure a DB::connection()-based lock would have had, asserted rather than
    // argued: purge everything, then look again.
    $this->purgeConnections();

    // `granted` filtered here too, for the reason the case above carries at length.
    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ? and objid = ? and granted',
        ['advisory', SUITE_LOCK_KEY],
    );

    expect((int) $held->c)->toBe(1, 'a purge released the suite lock');
});

it('does not collide with the key RoleTimeoutTest draws, because this lock is never released', function (): void {
    // RoleTimeoutTest's lock_timeout firing proof does random_int(1, 2_000_000_000) and
    // has a second session take that key first. A suite key inside that range would not
    // merely clash: because this one is held for the life of the process and never
    // unlocked, the draw would block that test FOREVER -- a hung suite with no output,
    // one run in two billion. Out of the range by construction, and pinned here so the
    // range and the key cannot drift towards each other unnoticed.
    expect(SUITE_LOCK_KEY)->toBeGreaterThan(2_000_000_000);
});

it('keeps its behavioural proof in the repository, not in a session scratchpad — R-043', function (): void {
    $proof = base_path('bin/concurrent-suite-proof');

    expect(is_file($proof))->toBeTrue('R-049 has no reproducible proof in the repository')
        ->and(is_executable($proof))->toBeTrue('bin/concurrent-suite-proof is not executable');

    // The two files from the reproduction, named in the script rather than any two: one
    // writes tenant_users (the 23503 face) and the other asserts a write survives (the
    // lost-write face). A proof that stopped exercising both faces would still pass its
    // own exit-code check.
    $source = (string) file_get_contents($proof);

    expect($source)->toContain('OwnerAndBypassTest.php')
        ->and($source)->toContain('RefusalDiagnosticsTest.php');
});

/*
 * QA L-5: R-054's fix is one line and nothing guarded it.
 *
 * `bin/gate` exports PAO_DISABLE=1 because `laravel/pao` swaps the console output style
 * whenever AgentDetector::detect()->isAgent, so without it the gate log is a single JSON
 * line with no frame list in any agent session and Pest's normal output in a terminal --
 * the gate log's CONTENT depended on who ran it, which is what R-054 was. The claim in
 * bin/gate, CLAUDE.md and R-049's compensating control was kept and made true for every
 * runner by that one export.
 *
 * Deleting it would restore the frameless form silently: no test fails, the gate still
 * exits 0, and the loss only shows up on the next red run, which is exactly when the
 * frames are needed. The precedent for pinning a bin/ script's content is the R-043 case
 * above, which is why this lives in the same file.
 */
it('keeps the gate log readable for every runner, which is one export line — R-054, QA L-5', function (): void {
    $gate = base_path('bin/gate');

    expect(is_file($gate))->toBeTrue('bin/gate is missing')
        ->and(is_executable($gate))->toBeTrue('bin/gate is not executable');

    $source = (string) file_get_contents($gate);

    // The export itself, and that it is EXPORTED rather than merely assigned: the steps
    // run as child processes, so a shell variable without `export` would not reach them.
    // str_contains + toBeTrue rather than toContain, because Pest's toContain is
    // VARIADIC over needles -- a second argument is another needle, not a message, so the
    // first draft of this case asserted that bin/gate contained its own failure message.
    // It went red and said so, which is the only reason this comment exists.
    expect(str_contains($source, 'export PAO_DISABLE=1'))->toBeTrue(
        'bin/gate no longer exports PAO_DISABLE=1, so its log is the frameless JSON form in any agent session (R-054)'
    );

    // And that the switch is the package's own, not a guess: if this constant ever stops
    // being the name laravel/pao checks, the assertion above is pinning nothing. Read
    // from the vendor source, which is where QRPAGER-10 §4 measured it.
    $provider = base_path('vendor/laravel/pao/src/Laravel/ServiceProvider.php');

    if (is_file($provider)) {
        expect(str_contains((string) file_get_contents($provider), 'PAO_DISABLE'))->toBeTrue(
            'laravel/pao no longer reads PAO_DISABLE, so bin/gate is exporting a dead variable'
        );
    }
});
