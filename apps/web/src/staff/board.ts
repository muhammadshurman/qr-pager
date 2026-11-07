/**
 * ================================================================================
 * THE BOARD'S POLLING CADENCE. `§4 › Realtime`'s client half, for a kitchen.
 * ================================================================================
 *
 * `O2`: no persistent connection. `§4 › Realtime`: *"client-side jittered interval +
 * exponential backoff"*. `QRPAGER-26 §2`: the board is **polled**.
 *
 * ================================================================================
 * IT REUSES TASK 9's ARITHMETIC AND ADDS ONE CONSTANT
 * ================================================================================
 *
 * `src/pager/poll.ts` already derives the jitter fraction, the doubling base, the 120-second
 * cap and the failure ceiling, with every number asserted at its exact value and the
 * randomness injected. **None of that is re-implemented here**, because two copies of the
 * same arithmetic is the duplicated-source defect `§6.2` mechanises against for the status
 * enum — and the subtle half is already written down there: the jitter is applied AFTER the
 * cap, so that devices sitting at the ceiling do not all fire on the same tick exactly when
 * the server is already shedding.
 *
 * What this module adds is the one thing that genuinely differs: **the interval**.
 *
 * ================================================================================
 * FIVE SECONDS, AND WHY NOT THE CUSTOMER'S SEVEN
 * ================================================================================
 *
 * They are two products of one mechanism and the costs scale on different axes:
 *
 *   the CUSTOMER poll  one phone waiting for one event, and `config('pager.poll_interval')`
 *                      = 7 s was sized against the `C6` ceiling of 300 000 concurrent
 *                      sessions — `§2.11` prices that at ~300 000 Redis operations/second,
 *                      which is the figure Task 14 sizes ElastiCache against. Its cost
 *                      scales with ORDER VOLUME.
 *   the staff BOARD    a handful of screens per restaurant watching a list that changes
 *                      constantly. Its cost scales with STAFF HEADCOUNT, which is bounded
 *                      by the room: measured at 100 live pagers the board query is 0.34 ms,
 *                      so five clients at this interval cost ~1 request/second/tenant.
 *
 * ⚠ **AND IT IS DELIBERATELY NOT ONE CONFIG KEY SERVING BOTH.** Making the board read
 * `pager.poll_interval` would be tidier and would mean a change made for the customer's
 * sake silently re-paced the kitchen, which is the drift `§6.2` exists to prevent one layer
 * up. Two constants with two written reasons is the honest form, and the server does not
 * advertise an interval for this route at all — `PacedRoute` is `routes/customer.php`'s.
 *
 * ================================================================================
 * `R-224` — THE SERVER'S `Retry-After` BEATS OUR DOUBLING
 * ================================================================================
 *
 * A 429 carries `Retry-After` (`StaffBoardEndpointsTest` drives the bound to prove it), and
 * a server that is already refusing knows better than a client's arithmetic. It is still
 * jittered and still capped, for `delayFromAnswerMs`'s reason: an advisory header is not a
 * promise and a hint of 0 would be a busy loop.
 *
 * **AND THE COPY IS NOT SHOWN UNTIL THE WINDOW IS EXHAUSTED**, which is the ruling's third
 * clause and the one a UI gets wrong by default. `shouldTellThem()` below is the whole of
 * it: under a working backoff nothing is required of the person, so a message is a claim
 * that something needs doing when nothing does.
 */

import {
  BACKOFF_CAP_SECONDS,
  MAX_CONSECUTIVE_FAILURES,
  delayFromAnswerMs,
  type Random,
} from '../pager/poll.ts'

/**
 * How often a kitchen board asks, in seconds. See the header for why it is not seven.
 *
 * Exported so a test can assert the exact value and so the e2e harness can shorten the
 * wait without the component taking a prop nobody in production sets.
 */
export const BOARD_INTERVAL_SECONDS = 5

/**
 * The delay before the next board read, in milliseconds.
 *
 * @param consecutiveFailures 0 after a 200. Incremented by a throttle, a 5xx and unreachable.
 * @param retryAfterSeconds the server's own hint from a 429, or null.
 * @param random a value in [0, 1).
 */
export function nextBoardDelayMs(
  consecutiveFailures: number,
  retryAfterSeconds: number | null,
  random: Random,
): number {
  return delayFromAnswerMs(retryAfterSeconds, BOARD_INTERVAL_SECONDS, consecutiveFailures, random)
}

/**
 * Whether a run of throttles has gone on long enough to be worth saying out loud.
 *
 * ⚠ **THIS FUNCTION IS `R-224`'s THIRD CLAUSE AND IT IS THE EASY ONE TO GET WRONG.**
 * Muhammad's ruling: *"NO message on the first 429. Copy appears once the backoff window is
 * exhausted."* A screen that announces every throttle is a screen that interrupts a service
 * to report something already fixing itself.
 *
 * THE THRESHOLD IS DERIVED, NOT PICKED. `MAX_CONSECUTIVE_FAILURES` is Task 9's own derived
 * ceiling — the attempt at which doubling first reaches `BACKOFF_CAP_SECONDS`, after which a
 * further attempt learns nothing new about the cadence. That is precisely *"the backoff
 * window is exhausted"*, so reusing it means the two halves of the ruling cannot drift
 * apart: if the cap or the base ever changes, this moves with them.
 *
 * At this interval the window is 5 → 10 → 20 → 40 → 80 → 120 s, so the copy appears after
 * roughly four and a quarter minutes of unbroken refusal — by which time it is true that
 * something is wrong and false that it is about to resolve itself.
 */
export function shouldTellThem(consecutiveFailures: number): boolean {
  return consecutiveFailures >= MAX_CONSECUTIVE_FAILURES
}

/**
 * Whether what is on screen is older than one interval, so the board should say when it was
 * last confirmed.
 *
 * NOT a state and not an error: the rows are still right, they are merely not fresh, and a
 * board that blanked itself over one dropped packet would be worse than one that says *last
 * updated 14:32*. `§4.1`'s *"polling degraded"* for the customer, one surface over.
 *
 * ⚠ COMPUTED FROM A TIMESTAMP, NEVER FROM A TICK COUNT, which is `elapsedSeconds`' reason
 * written for a different screen: a `setInterval` in a backgrounded tab is throttled or
 * stopped by every engine, so a counter that increments itself is wrong by however long the
 * tablet slept — and wrong in the direction that makes stale data look fresh.
 */
export function isStale(lastConfirmedMs: number | null, nowMs: number): boolean {
  if (lastConfirmedMs === null) return false

  return nowMs - lastConfirmedMs > BOARD_INTERVAL_SECONDS * 1000
}

/** Re-exported so a caller never has to import two modules to reason about one cadence. */
export { BACKOFF_CAP_SECONDS, MAX_CONSECUTIVE_FAILURES }
