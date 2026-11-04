<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\StartBarrier;

/*
 * ================================================================================
 * THE BARRIER'S TIMEOUT PATH IS THE THING UNDER TEST, BECAUSE THAT IS THE PATH THAT
 * FAILED TO DO ITS JOB.
 * ================================================================================
 *
 * `StaffBoardConcurrencyTest` went red in a full `composer gate` run on
 * `qrpager-22-support-access` with six words -- *"the two clients never both reached the
 * barrier"* -- and 3 of 3 green in isolation a minute later. The cause was **unobtainable**,
 * because the parent reads a child's stdout and stderr only AFTER the barrier and a child
 * that dies BEFORE announcing is read by nobody. `PLAN.md §4a` calls an unexplained red this
 * project's most expensive defect shape.
 *
 * So the repair is a diagnostic, and a diagnostic is exactly the kind of code that is never
 * exercised until the night it matters. This file exercises it on purpose:
 *
 *   1. a child that dies before announcing has its reason in the message;
 *   2. the ordinary case still releases, so the repair did not break the green path;
 *   3. the child bound is longer than the parent bound, which is the correctness property
 *      the two hard-coded numbers violated (10s inside against 30s outside).
 *
 * ⚠ NO DATABASE AND NO APPLICATION, which is why it is a Unit test: the children are `php
 * -r` one-liners. The two real children boot Laravel, and a proof that needed that would be
 * measuring the boot rather than the barrier.
 */

/**
 * @return array{handle: resource, pipes: array<int, resource>}
 */
function barrierChild(string $code, string $barrier): array
{
    $pipes = [];

    $handle = proc_open(
        ['php', '-r', $code, $barrier],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($handle)->not->toBeFalse('could not start a barrier child');

    /** @var resource $handle */
    return ['handle' => $handle, 'pipes' => $pipes];
}

/** A child that announces itself and then waits to be released, as the real ones do. */
const BARRIER_WAITER = <<<'PHP'
$b = $argv[1];
file_put_contents($b . '.ready.' . getmypid(), '1');
$deadline = microtime(true) + 20.0;
while (! is_file($b . '.go')) {
    if (microtime(true) > $deadline) { fwrite(STDOUT, "error:BarrierTimeout:never released\n"); exit(1); }
    usleep(1000);
}
fwrite(STDOUT, "released\n");
exit(0);
PHP;

/**
 * A child that dies before announcing, printing what the real children print on that path:
 * a class name on stdout and the message on stderr (`§11` SECURITY L-9).
 */
const BARRIER_CASUALTY = <<<'PHP'
fwrite(STDOUT, "error:RedisException:see stderr, not printed here\n");
fwrite(STDERR, "Connection refused [tcp://127.0.0.1:6379]\n");
exit(1);
PHP;

function barrierPath(): string
{
    return sys_get_temp_dir().'/qrpager-barrier-proof-'.Str::random(12);
}

it('names the child that died before announcing, and carries both of its streams', function (): void {
    $barrier = barrierPath();

    $children = [
        barrierChild(BARRIER_WAITER, $barrier),
        barrierChild(BARRIER_CASUALTY, $barrier),
    ];

    // Half a second rather than the real 60: the bound under test is the REPORT, not the
    // wait. `StartBarrier::release()`'s own docblock says why the parameter exists.
    $thrown = null;

    try {
        StartBarrier::release($barrier, $children, 0.5);
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull('the barrier was released although one child never announced');

    $message = $thrown instanceof RuntimeException ? $thrown->getMessage() : '';

    /*
     * ⚠ EVERY ONE OF THESE WAS ABSENT FROM THE OLD MESSAGE, WHICH IS THE POINT. It said
     * "the two clients never both reached the barrier" and nothing else -- not which child,
     * not whether it had even started, and not one byte of what it printed.
     */
    expect($message)
        ->toContain('announcements on disk: 1 of 2')
        ->toContain('NEVER ANNOUNCED')
        ->toContain('error:RedisException')
        ->toContain('Connection refused [tcp://127.0.0.1:6379]')
        ->toContain('exited on its own (exit 1)');

    // And the child that DID announce is distinguished from the one that did not, rather
    // than both being reported as one undifferentiated failure.
    expect(substr_count($message, 'NEVER ANNOUNCED'))->toBe(1,
        'the announcing child was reported the same way as the casualty',
    );

    // The temp directory is left clean even though the throw skipped the caller's cleanup.
    expect(glob($barrier.'*') ?: [])->toBe([], 'the barrier files outlived the failed run');
});

it('still releases both children when they both announce', function (): void {
    $barrier = barrierPath();

    $children = [
        barrierChild(BARRIER_WAITER, $barrier),
        barrierChild(BARRIER_WAITER, $barrier),
    ];

    StartBarrier::release($barrier, $children, 20.0);

    $outcomes = [];

    foreach ($children as $child) {
        $outcomes[] = trim((string) stream_get_contents($child['pipes'][1]));
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['handle']);
    }

    expect($outcomes)->toBe(['released', 'released'],
        'a child was not released by the barrier: '.implode(', ', $outcomes),
    );

    foreach (glob($barrier.'*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('waits longer inside the child than outside it, so the parent is the side that times out', function (): void {
    /*
     * THE PROPERTY THE TWO HARD-CODED NUMBERS VIOLATED. The children waited 10s and the
     * parents 30, so a fast child and a slow sibling made the fast one give up from the
     * inside while the parent was still legitimately waiting -- and because a `.ready` file
     * stays on disk, the parent then released a barrier nobody was waiting on and read
     * `error:BarrierTimeout` as the race's outcome. One number is a bound on the HOST's
     * worst case; the other has to be longer than it, or the diagnostic above never runs.
     */
    expect(StartBarrier::CHILD_SECONDS)->toBeGreaterThan(StartBarrier::PARENT_SECONDS);

    /*
     * And the real callers take the DEFAULT rather than a number of their own, which is what
     * keeps the ordering true in the suite rather than only in this file. Found by walking
     * `tests/Feature`, not by naming the two files that call it today: the next concurrency
     * test to be written is exactly the one that would quietly pass its own bound.
     */
    $callers = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Feature')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'StartBarrier::release(')) {
            $callers[$file->getFilename()] = $source;
        }
    }

    expect($callers)->not->toBe([], 'no caller of StartBarrier::release() was found to check');

    foreach ($callers as $name => $source) {
        expect($source)->toContain('StartBarrier::release($barrier, $children);');
        expect(substr_count($source, 'StartBarrier::release('))->toBe(1,
            "{$name} calls the barrier more than once; one of them may carry its own bound",
        );
    }
});
