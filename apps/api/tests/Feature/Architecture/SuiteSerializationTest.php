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
    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ? and objid = ?',
        ['advisory', SUITE_LOCK_KEY],
    );

    expect((int) $held->c)->toBe(
        1,
        'the suite lock is not held, so two concurrent runners would truncate under each other',
    );
});

it('keeps holding it after a connection purge, which is the half that made the naive fix useless', function (): void {
    // The failure a DB::connection()-based lock would have had, asserted rather than
    // argued: purge everything, then look again.
    $this->purgeConnections();

    $held = DB::connection('pgsql_root')->selectOne(
        'select count(*) as c from pg_locks where locktype = ? and objid = ?',
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
