import { describe, expect, it } from 'vitest'

import {
  BACKOFF_CAP_SECONDS,
  BOARD_INTERVAL_SECONDS,
  MAX_CONSECUTIVE_FAILURES,
  isStale,
  nextBoardDelayMs,
  shouldTellThem,
} from '../src/staff/board.ts'
import { JITTER_FRACTION } from '../src/pager/poll.ts'

/**
 * ================================================================================
 * THE BOARD'S CADENCE. `§4 › Realtime`'s client half, asserted at exact values.
 * ================================================================================
 *
 * `src/pager/poll.ts`'s own practice, because this module reuses its arithmetic: **every
 * number is asserted at its exact value, never as a range**, and the randomness is injected
 * so the BOUNDS are checked at the extremes rather than by sampling and hoping.
 *
 * ⚠ AND THE EXPECTED VALUES ARE DERIVED FROM THE EXPORTED CONSTANTS, not written as
 * literals. A test carrying its own copy of `5` would pass against a module whose interval
 * had changed and whose callers had not — which is the second-source defect the module's own
 * header is about.
 */

/** The midpoint of the jitter window: `random() = 0.5` gives exactly the base delay. */
const mid = () => 0.5

/** The floor and the ceiling of the window, at the extremes rather than near them. */
const low = () => 0
const high = () => 1 - Number.EPSILON

describe('the board interval', () => {
  it('is five seconds, and is NOT the customer poll interval', () => {
    expect(BOARD_INTERVAL_SECONDS).toBe(5)

    // ⚠ THE POINT OF THIS ASSERTION IS THE INEQUALITY. `config('pager.poll_interval')` is 7
    // and was sized against the C6 ceiling of 300 000 concurrent sessions; this is sized
    // against staff headcount in one room. Two constants with two reasons, and a change
    // that made them equal would be the single-source-serving-two-cadences drift the
    // module's header refuses -- so it fails here rather than quietly re-pacing a kitchen.
    expect(BOARD_INTERVAL_SECONDS).not.toBe(7)
  })

  it('jitters by exactly ±20% of the interval at the extremes', () => {
    const base = BOARD_INTERVAL_SECONDS * 1000

    expect(nextBoardDelayMs(0, null, mid)).toBe(base)
    expect(nextBoardDelayMs(0, null, low)).toBe(Math.round(base * (1 - JITTER_FRACTION)))
    expect(nextBoardDelayMs(0, null, high)).toBe(
      Math.round(base * (1 + JITTER_FRACTION) - base * 2 * JITTER_FRACTION * Number.EPSILON),
    )
  })

  it('doubles per consecutive failure and stops at the cap', () => {
    // 5 -> 10 -> 20 -> 40 -> 80 -> 120(capped), at the midpoint so the jitter contributes
    // nothing and the doubling is the only thing being read.
    const atMid = (failures: number) => nextBoardDelayMs(failures, null, mid) / 1000

    expect(atMid(0)).toBe(5)
    expect(atMid(1)).toBe(10)
    expect(atMid(2)).toBe(20)
    expect(atMid(3)).toBe(40)
    expect(atMid(4)).toBe(80)

    // The cap, and it is reached at the fifth doubling rather than exceeded.
    expect(atMid(5)).toBe(BACKOFF_CAP_SECONDS)
    expect(atMid(50)).toBe(BACKOFF_CAP_SECONDS)
  })

  it('prefers the server’s Retry-After over its own doubling, still jittered and capped', () => {
    // A server that is already refusing knows better than a client's arithmetic. `R-224`.
    expect(nextBoardDelayMs(0, 30, mid)).toBe(30_000)

    // Even deep into a run of failures: the hint wins, which is the whole point of reading it.
    expect(nextBoardDelayMs(4, 30, mid)).toBe(30_000)

    // And it is still capped -- an advisory header is not a promise.
    expect(nextBoardDelayMs(0, 9_999, mid)).toBe(BACKOFF_CAP_SECONDS * 1000)

    // A hint of zero or below is ignored rather than honoured: it would be a busy loop.
    expect(nextBoardDelayMs(0, 0, mid)).toBe(BOARD_INTERVAL_SECONDS * 1000)
  })
})

describe('R-224 — when the person is told', () => {
  it('says NOTHING on the first 429, and only speaks once the window is exhausted', () => {
    // Muhammad's ruling, third clause: "NO message on the first 429. Copy appears once the
    // backoff window is exhausted."
    expect(shouldTellThem(0)).toBe(false)
    expect(shouldTellThem(1)).toBe(false)

    // ⚠ THE THRESHOLD IS DERIVED FROM TASK 9's OWN CEILING rather than picked, so if the cap
    // or the doubling base ever changes the two halves of the ruling move together. The
    // assertion is therefore written against the constant and against the one below it.
    for (let failures = 0; failures < MAX_CONSECUTIVE_FAILURES; failures += 1) {
      expect(shouldTellThem(failures), `told at ${String(failures)} failures`).toBe(false)
    }

    expect(shouldTellThem(MAX_CONSECUTIVE_FAILURES)).toBe(true)
    expect(shouldTellThem(MAX_CONSECUTIVE_FAILURES + 1)).toBe(true)
  })

  it('is the attempt at which the backoff first reaches its cap, which is what *exhausted* means', () => {
    // The derivation, asserted rather than asserted-in-a-comment: the threshold attempt is
    // the FIRST one whose delay is the cap, so a further attempt learns nothing new about
    // the cadence -- which is the honest reading of "the window is exhausted".
    const atMid = (failures: number) => nextBoardDelayMs(failures, null, mid) / 1000

    expect(atMid(MAX_CONSECUTIVE_FAILURES - 1)).toBe(BACKOFF_CAP_SECONDS)
    expect(atMid(MAX_CONSECUTIVE_FAILURES - 2)).toBeLessThan(BACKOFF_CAP_SECONDS)
  })
})

describe('staleness', () => {
  it('is false while the board is fresher than one interval, and true after', () => {
    const now = 1_762_000_000_000

    expect(isStale(now, now)).toBe(false)
    expect(isStale(now - BOARD_INTERVAL_SECONDS * 1000, now)).toBe(false)
    expect(isStale(now - BOARD_INTERVAL_SECONDS * 1000 - 1, now)).toBe(true)
  })

  it('is false when nothing has ever been confirmed', () => {
    // A board that has not answered yet is LOADING, not stale. Showing "last updated" with
    // nothing behind it would be a claim about a reading that never happened.
    expect(isStale(null, Date.now())).toBe(false)
  })
})
