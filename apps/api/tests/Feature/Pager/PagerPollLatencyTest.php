<?php

declare(strict_types=1);

/*
 * The p99 clause, driven from the suite so the harness cannot rot.
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

it('keeps a runnable latency harness that measures zero Postgres and two Redis commands', function (): void {
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
})->group('slow');
