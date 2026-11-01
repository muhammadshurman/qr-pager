import { describe, expect, it } from 'vitest'

import {
  BACKOFF_BASE,
  BACKOFF_CAP_SECONDS,
  JITTER_FRACTION,
  MAX_CONSECUTIVE_FAILURES,
  delayFromAnswerMs,
  elapsedSeconds,
  nextDelayMs,
} from '../src/pager/poll.ts'

/**
 * ================================================================================
 * `§3` ROW 9 CLAUSE (b): *"Client backoff and jitter verified."*
 * ================================================================================
 *
 * ⚠ EVERY FIGURE IS ASSERTED AT ITS EXACT VALUE, NEVER AS A RANGE. That discipline is the
 * project's own -- `PLAN §4a` records five range assertions across four files being replaced
 * by exact values at Task 7 -- and it is only possible because the randomness is INJECTED.
 * The bounds are checked at the extremes (`0` and the largest value below 1) rather than by
 * sampling and hoping, so there is no run in which these pass by luck.
 *
 * The server half is Task 4's and is built: `poll_after_seconds` on every answer,
 * `Retry-After` on every 503 and 429. This is the client that obeys them.
 */

/** The server's own configured interval: `config('pager.poll_interval')` is 7. */
const ADVERTISED = 7

const lowest = () => 0
const highest = () => 1 - Number.EPSILON
const midpoint = () => 0.5

describe('the cadence constants', () => {
  it('are the values the whole arithmetic below depends on', () => {
    expect(JITTER_FRACTION).toBe(0.2)
    expect(BACKOFF_BASE).toBe(2)
    expect(BACKOFF_CAP_SECONDS).toBe(120)
    expect(MAX_CONSECUTIVE_FAILURES).toBe(6)
  })
})

describe('nextDelayMs -- the jittered interval', () => {
  it('spans exactly +/- 20 % of the advertised interval on a healthy connection', () => {
    // 7 s * 0.8 = 5.6 s, 7 s * 1.2 = 8.4 s. Exact at both ends.
    expect(nextDelayMs(ADVERTISED, 0, lowest)).toBe(5600)
    expect(nextDelayMs(ADVERTISED, 0, highest)).toBe(8400)
    expect(nextDelayMs(ADVERTISED, 0, midpoint)).toBe(7000)
  })

  it('never returns the advertised interval unjittered for two different draws', () => {
    // ⚠ THE CONTROL AGAINST A JITTER THAT IS NOT APPLIED. Without it, "the interval is 7 s"
    // passes every bound check above and every device in one restaurant still polls on the
    // same tick -- which is the entire failure the jitter exists for.
    expect(nextDelayMs(ADVERTISED, 0, lowest)).not.toBe(nextDelayMs(ADVERTISED, 0, highest))
  })

  it('doubles per consecutive failure, at the midpoint draw so the growth is readable', () => {
    expect(nextDelayMs(ADVERTISED, 0, midpoint)).toBe(7000)
    expect(nextDelayMs(ADVERTISED, 1, midpoint)).toBe(14_000)
    expect(nextDelayMs(ADVERTISED, 2, midpoint)).toBe(28_000)
    expect(nextDelayMs(ADVERTISED, 3, midpoint)).toBe(56_000)
    expect(nextDelayMs(ADVERTISED, 4, midpoint)).toBe(112_000)
    // The sixth attempt is the first at the cap, which is where `MAX_CONSECUTIVE_FAILURES`
    // comes from rather than from a round number: 7 * 2^5 = 224 > 120.
    expect(nextDelayMs(ADVERTISED, 5, midpoint)).toBe(120_000)
    expect(nextDelayMs(ADVERTISED, 6, midpoint)).toBe(120_000)
  })

  it('still jitters AT the cap, and that ordering is observable', () => {
    // ⚠ JITTER AFTER THE CAP, NOT BEFORE. Capping last would make the ceiling exact and the
    // jitter one-sided there, so every device at the cap would fire on the same tick -- the
    // convergence jitter exists to prevent, reappearing at the worst possible moment: when
    // the server is already shedding.
    expect(nextDelayMs(ADVERTISED, 9, lowest)).toBe(96_000)
    expect(nextDelayMs(ADVERTISED, 9, highest)).toBe(144_000)
    expect(nextDelayMs(ADVERTISED, 9, midpoint)).toBe(120_000)
  })

  it('floors the advertised interval at one second, as the server does', () => {
    // `CustomerPagerController::pollInterval()` is `max(1, …)`. A server that ever answered
    // 0 must not turn this client into a busy loop.
    expect(nextDelayMs(0, 0, midpoint)).toBe(1000)
    expect(nextDelayMs(-5, 0, midpoint)).toBe(1000)
    expect(nextDelayMs(0.4, 0, midpoint)).toBe(1000)
  })

  it('treats a negative failure count as zero rather than shrinking the interval', () => {
    expect(nextDelayMs(ADVERTISED, -3, midpoint)).toBe(7000)
  })
})

describe('delayFromAnswerMs -- the server hint wins, within bounds', () => {
  it('uses the hint when the server gives one', () => {
    // A server that is shedding knows better than a client's doubling. 30 s at the midpoint.
    expect(delayFromAnswerMs(30, ADVERTISED, 3, midpoint)).toBe(30_000)
  })

  it('still jitters the hint, because an advisory interval is not a schedule', () => {
    expect(delayFromAnswerMs(30, ADVERTISED, 3, lowest)).toBe(24_000)
    expect(delayFromAnswerMs(30, ADVERTISED, 3, highest)).toBe(36_000)
  })

  it('still caps the hint, so a hint of an hour is not obeyed', () => {
    expect(delayFromAnswerMs(3600, ADVERTISED, 0, midpoint)).toBe(120_000)
  })

  it('falls back to the backoff for a hint that is absent, zero or not finite', () => {
    expect(delayFromAnswerMs(null, ADVERTISED, 2, midpoint)).toBe(28_000)
    // A hint of 0 would be a busy loop. Refused, and the backoff is used instead.
    expect(delayFromAnswerMs(0, ADVERTISED, 2, midpoint)).toBe(28_000)
    expect(delayFromAnswerMs(Number.NaN, ADVERTISED, 2, midpoint)).toBe(28_000)
    expect(delayFromAnswerMs(Number.POSITIVE_INFINITY, ADVERTISED, 2, midpoint)).toBe(28_000)
  })
})

describe('elapsedSeconds -- read from the server clock, never counted locally', () => {
  const scanned = '2026-11-01T12:00:00Z'
  const at = (iso: string) => Date.parse(iso)

  it('is the difference between now and the scan, in whole seconds', () => {
    expect(elapsedSeconds(scanned, at('2026-11-01T12:00:00Z'))).toBe(0)
    expect(elapsedSeconds(scanned, at('2026-11-01T12:04:12Z'))).toBe(252)
    expect(elapsedSeconds(scanned, at('2026-11-01T13:00:00Z'))).toBe(3600)
  })

  it('floors rather than rounds, so the readout never shows a second that has not passed', () => {
    expect(elapsedSeconds(scanned, at('2026-11-01T12:00:00Z') + 999)).toBe(0)
    expect(elapsedSeconds(scanned, at('2026-11-01T12:00:00Z') + 1000)).toBe(1)
  })

  it('answers 0 for a phone whose clock is behind the server, never a negative', () => {
    // ⚠ A COMMON CASE, NOT A DEFENSIVE ONE. A customer's phone being thirty seconds fast is
    // ordinary; `-30` on the screen is not.
    expect(elapsedSeconds(scanned, at('2026-11-01T11:59:30Z'))).toBe(0)
  })

  it('answers 0 for an unparseable timestamp rather than NaN on the screen', () => {
    expect(elapsedSeconds('not a date', at('2026-11-01T12:04:12Z'))).toBe(0)
    expect(elapsedSeconds('', 0)).toBe(0)
  })

  it('survives a twenty-minute sleep, which a local tick counter would not', () => {
    // The phone-sleeps case from the ticket's failure table. A `setInterval` in a sleeping
    // tab is throttled or stopped by every engine, so a counter that increments itself is
    // wrong by however long the phone slept -- and wrong in the direction that makes a long
    // wait look short, which is the direction a customer notices.
    expect(elapsedSeconds(scanned, at('2026-11-01T12:20:00Z'))).toBe(1200)
  })
})
