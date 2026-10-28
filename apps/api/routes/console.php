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

/*
 * The expiry job (PLAN.md §3 row 3's TTL clause, and security M-2).
 *
 * IT WAS REGISTERED AND SCHEDULED NOWHERE until 2026-10-27. `PROJECT_INSTRUCTIONS.md §4 ›
 * Data` states a 2-hour TTL as a locked invariant, and the only thing enforcing it was
 * `PagerSessions::refuseIfPastTtl()` on the staff path -- a READ-path check. Nothing moved a
 * row to `expired` in any deployment, so:
 *
 *   - `pager_sessions.status` never left `waiting`/`ready`;
 *   - the partial index on `(expires_at) WHERE status IN ('waiting','ready')` grew with total
 *     order volume rather than with the live population, which is the opposite of what
 *     §2.11 claims it is for;
 *   - and §2.11's "rows reach a terminal status within 2 h of creation" was false as shipped.
 *
 * The ruling (Muhammad, 2026-10-27) is that this is "the invariant unbuilt, not a gap to
 * carry": a command registered and scheduled nowhere does not enforce anything. `R-058` now
 * covers only the cadence and the operational question -- where the scheduler runs -- not
 * whether the job runs at all.
 *
 * everyMinute(), not everyFiveSeconds(). The claim it has to keep is "within 2 hours", so a
 * minute of lag between `expires_at` passing and `status` following is three orders of
 * magnitude inside the budget. One pass is a single indexed read against the partial index
 * plus one keyed UPDATE per due row, capped at `pager.expiry_batch`; a shorter cadence would
 * buy nothing and spend a worker connection more often.
 *
 * withoutOverlapping(), for the same reason the readiness carrier has it: a pass that stalls
 * -- a lock wait, a slow cluster -- must not stack a second pass behind it, each holding one
 * of the worker role's 20 connections. The pass is already re-runnable (a keyed,
 * status-guarded UPDATE reports 0 on a row another pass took), so overlapping would be safe
 * and wasteful rather than wrong; this keeps it from being either.
 */
Schedule::command('pager:expire')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * TASK 6 — THE TWO PLATFORM COMMANDS, scheduled in the same commit that created them.
 *
 * R-058's ruling is why that is not left for later: "a command registered and scheduled
 * nowhere does not enforce anything" was the finding on `pager:expire`, which shipped
 * registered and unscheduled and made §2.11 false as deployed. The same mistake here would
 * make the provisioning wizard silently never finish — the panel would write a `pending`
 * row and nothing would ever drain it, which is a worse failure than an error because the
 * screen reports success.
 *
 * What is still Task 13's, with R-047 and R-058, is a PROCESS that runs `schedule:run`. The
 * schedule is a declaration; nothing in any deployed environment reads it yet. The gain from
 * declaring it now is the same one that applied to the expiry job: `php artisan
 * schedule:list` shows the gap instead of it being invisible.
 */

/*
 * everyMinute(), because this is the latency a platform admin WATCHES. They fill in a form
 * and wait for the restaurant to appear; a five-minute cadence would be a five-minute stare
 * at a `pending` row. One pass is a single indexed read against
 * tenant_provisionings_status_created_at_index plus three inserts per pending row, and the
 * usual row count is zero — the cost of an idle tick is one indexed read returning nothing.
 *
 * withoutOverlapping(), for the reason the expiry job has it and one more: `provision()`
 * moves a row to `running` before it starts, and this command's drain query deliberately
 * takes `pending` only, so two overlapping passes could not pick up the same row anyway.
 * The mutex is here so a stalled pass does not stack a second one behind it, each holding
 * one of the app role's connections.
 */
Schedule::command('platform:provision-tenants')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * everyFiveMinutes(), and the asymmetry with the command above is deliberate. Nobody is
 * watching a counter the way they watch a restaurant being created, and the cost is the
 * other way round: a pass is O(tenants) transactions with two aggregate reads each, so the
 * cadence is what bounds the standing load this adds to the cluster. Five minutes of
 * staleness on a dashboard figure is not a defect; a dashboard that re-aggregates every
 * tenant every minute is.
 *
 * The dashboard renders `computed_at` for exactly this reason — a figure whose age is not
 * shown is a figure a reader will assume is live.
 */
Schedule::command('platform:aggregate-stats')
    ->everyFiveMinutes()
    ->withoutOverlapping();
