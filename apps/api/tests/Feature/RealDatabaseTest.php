<?php

use Illuminate\Support\Facades\DB;

/*
 * THE SUITE RUNS AGAINST THE REAL COMPOSED POSTGRES, NEVER SQLITE.
 *
 * `CLAUDE.md`: *"Tests run against these, never SQLite."* `§6`: *"all green against **real**
 * Postgres + Redis. No DB mocks for DB behaviour."* This is the case that makes that a fact
 * rather than a configuration anybody can change.
 *
 * ⚠ THE DATABASE NAME IS DERIVED FROM CONFIG, NOT PINNED TO A LITERAL — `§11` QA M-10, and it
 * was a real defect in the documented REVIEWER PROCEDURE rather than in this file's intent.
 *
 * It read `->toBe('qrpager_test')`. `§0.2` (b) added `tools/gate/bin/reviewer-slot`, the
 * mechanism that lets `§11`'s two reviewers run AT THE SAME TIME, and a slot exports
 * `DB_DATABASE=qrpager_test_s<N>`. So **every reviewer who followed the documented
 * instruction got a red that was neither their finding nor the builder's** — measured by Task
 * 10a's QA reviewer: slot 1 full suite `1 failed, 1198 passed`, the diff reading
 * `-'qrpager_test' / +'qrpager_test_s1'`, and the same file on the default database `1 passed`.
 * `bin/classify-reds` would have called it a REAL RED, correctly, because it is on no row.
 *
 * `apps/api/bin/parallel-reviewer-proof` did not cover it: it runs two single FILES in two
 * slots, and this is not one of them.
 *
 * WHAT IS STILL ASSERTED, so the derivation is not a weakening:
 *
 *   - the driver is `pgsql` — the SQLite clause, unchanged and the whole point of the file;
 *   - the connected database is the one the CONFIGURATION names, so a suite pointed at the
 *     development database (or at anything else by accident) still fails;
 *   - the name is a `qrpager_test` database and not merely "whatever is configured", which is
 *     what stops the derivation from making the assertion vacuous: a slot is
 *     `qrpager_test_s<N>` and the default is `qrpager_test`, and nothing else passes;
 *   - the server really is PostgreSQL 16.
 */
it('runs the suite against the real composed postgres, never sqlite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $connected = DB::selectOne('select current_database() as db')->db;
    $configured = config('database.connections.pgsql.database');

    // The connected database IS the configured one. A suite that drifted onto another
    // database -- the development one, most dangerously -- fails here.
    expect($connected)->toBe($configured);

    /*
     * AND IT IS A TEST DATABASE, matched on the shape both callers produce: `qrpager_test`
     * for an ordinary run and `qrpager_test_s<N>` for a reviewer slot. Asserted as a pattern
     * rather than as "equal to whatever is configured", because the latter would pass against
     * any database at all and this clause exists to refuse exactly that.
     */
    expect($connected)->toMatch('/^qrpager_test(_s[1-7])?$/');

    expect(DB::selectOne('select version() as v')->v)
        ->toContain('PostgreSQL 16');
});
