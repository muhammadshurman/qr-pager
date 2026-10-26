<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The readiness carrier (PLAN.md §6.3, B6 as corrected by measurement).
 *
 * ->everyFiveSeconds() is real sub-minute scheduling and was verified in the framework
 * source rather than assumed: ManagesFrequencies::everyFiveSeconds() exists, and
 * ScheduleRunCommand::repeatEvents() re-runs repeatable events until the end of the
 * minute while `schedule:work` starts one `schedule:run` per minute. So a 5-second
 * cadence is real, from one extra process, with no tick -- which matters because
 * Octane's ticks are Swoole-only (Worker::handleTick() is called from bin/swoole-server
 * alone) and therefore do not exist under the locked FrankenPHP runtime.
 *
 * withoutOverlapping(), because a probe that cannot reach Postgres waits on a TCP
 * timeout: without it, a cluster that is slow to refuse would stack one run every five
 * seconds, each holding a worker connection out of the worker role's 20.
 *
 * runInBackground() is deliberately NOT used, and the REASON recorded here was wrong
 * until 2026-10-27 (QA L-7). It said the flag "forks a new PHP process per run ...
 * twelve boots a minute", implying the default does not. It does: Schedule::command()
 * always shells out to `php artisan`, so the fork happens either way and
 * runInBackground() only stops `schedule:run` from WAITING for it. The decision stands
 * and the reason is different: in the foreground the repeat loop waits, so at most one
 * probe is ever in flight and a hung probe is visible to the scheduler rather than
 * accumulating behind it. withoutOverlapping() guards the same property with a cache
 * mutex; keeping both means the guard is not the only thing holding the line.
 */
Schedule::command('readiness:refresh')
    ->everyFiveSeconds()
    ->withoutOverlapping();
