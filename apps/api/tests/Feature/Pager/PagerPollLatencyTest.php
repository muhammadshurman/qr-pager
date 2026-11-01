<?php

declare(strict_types=1);
use Tests\TestCase;

/*
 * A SMOKE CHECK ON THE LATENCY HARNESS. It is not the p99 of record and must never be
 * read as one.
 *
 * WHAT THIS FILE IS. It runs bin/poll-latency-proof in `--quick` mode -- 1 batch of 30
 * after 20 warm-up -- and asserts only the two LOAD-INVARIANT properties: zero Postgres
 * statements and exactly two Redis commands in the handler. It REPORTS the clock and
 * asserts nothing about it.
 *
 * WHAT THE p99 OF RECORD IS. The FULL harness: `bin/poll-latency-proof` with no
 * arguments, 20 warm-up discarded, 200 measured, best p99 of 3 batches, run on a quiet
 * machine and reported with its commit, its machine and its method into PLAN.md §4a. A
 * budget asserted by a cheaper path than the one measured is not a budget, which is why
 * the two modes are named differently in the script's own output ("QUICK -- properties
 * asserted, timing REPORTED ONLY" against "full -- properties and timing asserted").
 *
 * WHY THE SPLIT EXISTS. Measured on one commit: 3.337 ms run alone, 7.640 ms inside the
 * full suite (load 1.06, Postgres and Redis busy). The endpoint was identical. §3.1 row 4
 * calls that F10's class and says a misleading red costs a round under the round limit.
 * An earlier version of THIS FILE asserted the script's exit code, which asserted the
 * timing, and turned the whole gate red on a host being busy -- in a file whose own header
 * said it was avoiding exactly that. A header is not a mechanism; the mode flag is.
 *
 * §3.1 row 4 says this gate owes "a stated measurement method", and §3.1's reason is
 * `F10`'s class: "on this branch a loaded host turned a passing timeout proof into a red
 * at 27.6 s, and only the LOWER bound survived as sound. Under the round limit a
 * misleading red costs a round."
 *
 * SO THE SUITE DOES NOT ASSERT THE TIMING. bin/poll-latency-proof does, with the
 * best-of-three-batches guard, and it is a script a person runs and reports with its
 * commit and its machine (§4a). What the SUITE asserts is that the harness still works
 * and still measures the right things -- the two properties that cannot flake. A wall-clock
 * threshold inside `php artisan test` is precisely the shape that cost this branch a round,
 * and putting one here would be rediscovering F10 on purpose.
 *
 * The timing figure for a given commit is in the handover and in §4a, never in a test's
 * failure message.
 */

it('smoke-checks the latency harness: it runs, and still measures zero Postgres and two Redis commands', function (): void {
    $script = base_path('bin/poll-latency-proof');

    expect(is_executable($script))->toBeTrue('bin/poll-latency-proof is missing or not executable');

    $output = [];
    $status = 0;
    // --quick, AND THAT IS THE WHOLE POINT OF THIS CASE. The header above says the suite
    // does not assert the timing; the first version of it then asserted this script's EXIT
    // CODE, which asserted the timing, and the full suite went red at p99 7.640 ms on a
    // commit where the same script reported 3.337 ms when run alone. F10's class, through
    // the back door, in the test whose own comment said it was avoiding it. The mode is
    // now explicit in the script rather than implied here.
    exec(escapeshellarg($script).' --quick 2>&1', $output, $status);

    $transcript = implode(PHP_EOL, $output);

    // The two hard properties, read off the harness's own report. If the endpoint ever
    // opens a Postgres connection or grows a third Redis command, this fails in the suite
    // rather than waiting for somebody to run the script by hand.
    expect($transcript)->toContain('Postgres statements per poll   0')
        ->and($transcript)->toContain('Redis commands in the handler  2  [get,get]')
        // And the method is stated in the output, so a figure pasted from it carries its
        // own provenance -- which is what §4a asks for and what a bare number never has.
        ->and($transcript)->toContain('timing REPORTED ONLY')
        ->and($transcript)->toContain('warm-up discarded')
        // The commit LINE, not a hash: inside bin/coverage's container git cannot read the
        // mounted tree at all, and the harness says so rather than printing nothing. What
        // this case guards is that the report still carries a provenance field.
        ->and($transcript)->toMatch('/commit\s+\S/')
        ->and($transcript)->toMatch('/REPORTED p99 \d+\.\d+ ms/');

    expect($status)->toBe(0, "the latency harness failed:\n".$transcript);

    // AND THE MODE IS ASSERTED, so this case cannot silently become the timing gate again:
    // if someone drops --quick, the script starts asserting the clock and this smoke check
    // starts failing on a busy host. The line below is what makes that a deliberate change
    // rather than an accident.
    expect($transcript)->toContain('timing REPORTED ONLY')
        ->and($transcript)->not->toContain('full -- properties and timing asserted');
})->group('slow');

/*
 * R-200's REGRESSION CASE, and it asserts the PROPERTY rather than re-running the race.
 *
 * WHAT R-200 WAS, and the part of its own row that was wrong. The row says the harness's
 * seeded session is removed by "a concurrent test's TRUNCATE". It is not, and it cannot
 * be: bin/poll-latency-proof has run on its own database since it was written
 * (`qrpager_poll_proof`) and this suite runs on `qrpager_test` (phpunit.xml:64), so no
 * statement this suite issues can reach a row that harness wrote. Postgres was never the
 * shared resource.
 *
 * REDIS WAS. A pager session lives in Redis -- §4 › Realtime makes the token pointer a key
 * whose own NAME is the secret -- and Redis is shared across every Postgres database.
 * TestCase::setUp calls clearPagerProjection() before EVERY test, deleting `t:*:pager:*`
 * and `pager:tok:*`; with one prefix and one logical database for both processes those
 * patterns matched the keys the harness had just seeded. Hence 404 on a poll, hence 9 in
 * 20 rather than always: it takes one test boundary landing inside one batch.
 *
 * WHY THIS CASE AND NOT A CONCURRENCY TEST. A test that spawns the suite alongside itself
 * to reproduce a 9-in-20 race is the F10 shape this very file's header is an essay about
 * -- it would be the flakiest test in the repository and would prove the fix only
 * statistically. The fix is an ISOLATION property and isolation is checkable exactly. The
 * race was measured by hand instead, both ways, and the figures are in the script's
 * docblock: a loop deleting `*pager*` from Redis while the harness runs failed 10 of 10
 * before this change and 0 of 10 after it.
 *
 * AND THE NUMBERS ARE READ, NOT COPIED. The harness prints the keyspace it actually used
 * and this case parses that line, so there is no constant here to go stale when a default
 * moves in the script. The suite's own side is read from live config for the same reason.
 */
it('runs the harness in a keyspace this suite cannot reach, which is R-200', function (): void {
    $script = base_path('bin/poll-latency-proof');

    $output = [];
    $status = 0;
    exec(escapeshellarg($script).' --quick 2>&1', $output, $status);
    $transcript = implode(PHP_EOL, $output);

    expect($status)->toBe(0, "the latency harness failed:\n".$transcript);

    // Read what the harness SAYS it used. One source: the script, reporting itself.
    expect($transcript)->toMatch('/^  redis ns     db \S+ \/ cache db \S+ \/ prefix \S+$/m')
        ->and($transcript)->toMatch('/^  postgres db  \S+/m');

    preg_match('/^  redis ns     db (\S+) \/ cache db (\S+) \/ prefix (\S+)$/m', $transcript, $redis);
    preg_match('/^  postgres db  (\S+)/m', $transcript, $postgres);

    [, $harnessDb, $harnessCacheDb, $harnessPrefix] = $redis;

    // ----------------------------------------------------------------------------------
    // (1) The half R-200's row named, pinned so the claim cannot be made again: the two
    //     processes were never on one database.
    // ----------------------------------------------------------------------------------
    expect($postgres[1])->not->toBe(
        (string) config('database.connections.pgsql.database'),
        'the harness shares this suite\'s Postgres database -- R-200 would be about TRUNCATE after all',
    );

    // ----------------------------------------------------------------------------------
    // (2) The half that was actually the defect. BOTH axes, because either one alone
    //     closes today's two sweeps and only the pair survives a broader one written later.
    // ----------------------------------------------------------------------------------
    expect($harnessDb)->not->toBe(
        (string) config('database.redis.default.database'),
        'the harness shares this suite\'s Redis logical database -- R-200 is open',
    )->and($harnessCacheDb)->not->toBe(
        (string) config('database.redis.cache.database'),
        'the harness shares this suite\'s Redis CACHE database, which is where the rate-limit buckets live',
    )->and($harnessPrefix)->not->toBe(
        (string) config('database.redis.options.prefix'),
        'the harness shares this suite\'s Redis key prefix -- clearPagerProjection\'s patterns match its keys',
    );

    // ----------------------------------------------------------------------------------
    // (3) AND IT IS DRIVEN, not merely compared. A key in the harness's reported namespace
    //     must survive the REAL clearPagerProjection(), and a key in this suite's own
    //     namespace must NOT -- the second half is what stops this passing vacuously
    //     because the clear is broken rather than because the isolation works.
    // ----------------------------------------------------------------------------------
    $client = new Redis;
    $client->connect((string) config('database.redis.default.host'), (int) config('database.redis.default.port'));

    $suitePrefix = (string) config('database.redis.options.prefix');
    $suiteDb = (int) config('database.redis.default.database');
    $probe = 'pager:tok:r200-'.bin2hex(random_bytes(8));

    $client->select((int) $harnessDb);
    $client->set($harnessPrefix.$probe, 'harness');
    $client->select($suiteDb);
    $client->set($suitePrefix.$probe, 'suite');

    $clear = new ReflectionMethod(TestCase::class, 'clearPagerProjection');
    $clear->setAccessible(true);
    $clear->invoke(null);

    $client->select($suiteDb);
    $suiteSurvived = $client->exists($suitePrefix.$probe);
    $client->select((int) $harnessDb);
    $harnessSurvived = $client->exists($harnessPrefix.$probe);

    $client->del($harnessPrefix.$probe);
    $client->select($suiteDb);
    $client->del($suitePrefix.$probe);
    $client->close();

    expect($suiteSurvived)->toBe(0, 'clearPagerProjection did not delete a key in its OWN namespace, so (3) proves nothing')
        ->and($harnessSurvived)->toBe(1, 'clearPagerProjection reached into the harness keyspace -- R-200 is open');
})->group('slow');
