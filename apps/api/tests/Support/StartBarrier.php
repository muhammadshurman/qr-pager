<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * ================================================================================
 * THE START BARRIER THE CONCURRENCY TESTS RELEASE, AND IT KEEPS THE CHILDREN'S OUTPUT.
 * ================================================================================
 *
 * Two tests drive a race by starting two children, waiting for both to announce that they
 * have booted, and only then releasing them — so the window the race happens in contains the
 * two statements and not two process boots. `MemberConcurrencyTest` measured why that matters:
 * without a barrier, whether the two transactions overlapped was luck, and its guard would
 * have let a defect through one run in five.
 *
 * ⚠ **WHY THIS IS A CLASS AND NOT TWO COPIES OF A SPIN LOOP, WHICH IS WHAT IT WAS UNTIL
 * 2026-11-04.** Both copies threw `RuntimeException('the two children never both reached the
 * barrier')` on timeout, and **threw away the only evidence of why**: the parent reads each
 * child's stdout and stderr *after* the barrier, so a child that died *before* announcing had
 * its reason read by nobody. Measured on `qrpager-22-support-access`:
 * `StaffBoardConcurrencyTest` went red in a full `composer gate` run with exactly that
 * message, passed 3 of 3 attempts in isolation 1.05s later, and the cause was
 * **unobtainable** — `PLAN.md §4a` calls an unexplained red this project's most expensive
 * defect shape, and this was one by construction.
 *
 * A child's pre-barrier work is: boot, write a session, compute a cookie. Every way that
 * fails — and `R-191`'s own measurement says the likeliest is a connect stall on this host —
 * produced the same six words.
 *
 * So the timeout path now TERMINATES the children and reports, per child: whether it
 * announced itself, how it ended, and both of its streams. The next occurrence names its own
 * cause, which is the difference between a row with a measured mechanism and a re-run.
 *
 * ⚠ STDERR IS INTERPOLATED, which `§11` SECURITY L-9 constrains rather than forbids: a child
 * prints only a class name on stdout and its full message on stderr, because the parent's
 * failure message can reach a committed transcript and a `QueryException` carries bound
 * parameters. Both callers' existing post-barrier assertion
 * (`"a child process wrote to stderr: {$stderr}"`) already interpolates stderr on the failure
 * path, so this follows that precedent rather than setting a new one — and only a RED run
 * prints it, while `docs/tickets/*-gate-php.txt` is the transcript of a GREEN one.
 */
final class StartBarrier
{
    /**
     * How long the parent waits for every child to announce itself.
     *
     * ⚠ **60 SECONDS, AND THE NUMBER IS ABOVE THIS HOST'S MEASURED WORST CASE RATHER THAN A
     * ROUND ONE.** It was 30, and `R-191`'s `why_not_a_code_defect` measurement puts a single
     * `connect()` through the WSL2 + Docker userspace port forward at **35,648 ms** under CPU
     * contention, with no Postgres in the path. A child's pre-barrier work includes exactly
     * such a connect (its session store), so the old bound sat *below* the host's measured
     * worst case: the suite could fail this test for a stall the host is known to produce.
     *
     * This costs nothing in the green direction — both files release in about 150 ms — and is
     * paid for only on a run that was going to fail anyway.
     */
    public const PARENT_SECONDS = 60.0;

    /**
     * The matching bound inside the child scripts, for how long they wait to be released.
     *
     * ⚠ **STRICTLY GREATER THAN `PARENT_SECONDS`, WHICH IS A CORRECTNESS PROPERTY AND WAS
     * VIOLATED.** The children waited 10s while the parent waited 30, so a child that booted
     * fast and a sibling that booted slowly made the FIRST child time out from the inside
     * while the parent was still legitimately waiting — and because its `.ready` file stays on
     * disk, the parent then saw two announcements, released a barrier nobody was waiting on,
     * and reported `error:BarrierTimeout` as the race's outcome.
     *
     * The parent must always be the side that times out, because the parent is the side that
     * can say why. `StartBarrierTest` asserts this ordering so the two numbers cannot drift.
     */
    public const CHILD_SECONDS = 90.0;

    /**
     * Waits for every child to announce itself, then releases them all at once.
     *
     * @param  array<int, array{handle: resource, pipes: array<int, resource>}>  $children
     * @param  float|null  $seconds  overrides {@see self::PARENT_SECONDS}. ⚠ FOR
     *                               `StartBarrierTest` AND NOTHING ELSE: proving the timeout
     *                               path at the real bound would cost the suite a minute per
     *                               case, and a diagnostic nobody can afford to test is how
     *                               this class came to be needed in the first place. No
     *                               caller in `tests/Feature` passes it, which
     *                               `StartBarrierTest` asserts by grep.
     *
     * @throws RuntimeException if they do not all announce within the bound
     */
    public static function release(string $barrier, array $children, ?float $seconds = null): void
    {
        $deadline = microtime(true) + ($seconds ?? self::PARENT_SECONDS);

        /*
         * ⚠ THROWS RATHER THAN ASSERTS, and it is this project's evidence practice rather
         * than taste. An `expect()` inside a spin loop runs an unpredictable number of times,
         * so a caller's assertion count varied run to run — measured on
         * `MemberConcurrencyTest` at 248/253/259/264 over four green runs — and `§4a`
         * publishes "N passed / M assertions" at a commit, which a non-reproducible M makes
         * meaningless.
         */
        while (count(glob($barrier.'.ready.*') ?: []) < count($children)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException(self::diagnose($barrier, $children));
            }

            usleep(1000);
        }

        touch($barrier.'.go');
    }

    /**
     * Terminates the children and says what each of them was doing, in one message.
     *
     * @param  array<int, array{handle: resource, pipes: array<int, resource>}>  $children
     */
    private static function diagnose(string $barrier, array $children): string
    {
        $lines = [
            'the children never all reached the barrier',
            'announcements on disk: '.count(glob($barrier.'.ready.*') ?: []).' of '.count($children),
        ];

        foreach ($children as $i => $child) {
            $status = proc_get_status($child['handle']);
            $pid = (int) $status['pid'];
            $announced = is_file($barrier.'.ready.'.$pid) ? 'announced' : 'NEVER ANNOUNCED';

            if ($status['running'] === true) {
                /*
                 * Terminated rather than waited for: the pipes only reach EOF when the
                 * process does, and a child blocked on a connect would otherwise hold this
                 * message open for as long as the host stalls.
                 */
                proc_terminate($child['handle']);
                usleep(100_000);

                $after = proc_get_status($child['handle']);
                $ending = $after['running'] === true
                    ? 'STILL RUNNING after SIGTERM — it was blocked, not crashed'
                    : 'was still running, terminated (exit '.$after['exitcode'].')';
            } else {
                $ending = 'exited on its own (exit '.$status['exitcode'].')';
            }

            /*
             * Non-blocking, because a surviving child's pipes may never reach EOF and
             * `stream_get_contents()` would wait for one. What is buffered is what the child
             * managed to write, which is the whole question here.
             */
            stream_set_blocking($child['pipes'][1], false);
            stream_set_blocking($child['pipes'][2], false);

            $stdout = trim((string) stream_get_contents($child['pipes'][1]));
            $stderr = trim((string) stream_get_contents($child['pipes'][2]));

            $lines[] = "child {$i} (pid {$pid}): {$announced} · {$ending}";
            $lines[] = '  stdout: '.($stdout === '' ? '(empty)' : $stdout);
            $lines[] = '  stderr: '.($stderr === '' ? '(empty)' : $stderr);

            fclose($child['pipes'][1]);
            fclose($child['pipes'][2]);
            proc_close($child['handle']);
        }

        // The caller's own cleanup is past the throw, so the barrier files are removed here
        // rather than left in the temp directory for every failed run.
        foreach (glob($barrier.'*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        return implode("\n", $lines);
    }
}
