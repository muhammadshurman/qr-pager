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
 * runInBackground() is deliberately NOT used. It forks a new PHP process per run, which
 * at this cadence is twelve boots a minute, and the probe is a single query -- the fork
 * would cost more than the work.
 */
Schedule::command('readiness:refresh')
    ->everyFiveSeconds()
    ->withoutOverlapping();
