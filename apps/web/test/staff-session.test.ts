import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  ACTIVITY_EVENTS,
  CHECK_INTERVAL_MS,
  IDLE_LOCK_SECONDS,
  watchForIdle,
} from '../src/staff/session.ts'

/**
 * ================================================================================
 * THE IDLE LOCK. `QRPAGER-25 §12` S3, and the assertion that carries it is NEGATIVE.
 * ================================================================================
 *
 * The property is *"a screen left unattended in a kitchen locks"*, and the way that property
 * fails in practice is not a timer that never fires — it is a timer that is **reset by
 * something that is not a person**. A board polling every few seconds, a React re-render, a
 * tab becoming visible again: each is an event, and each would hold the lock open forever on
 * the screens most in need of it.
 *
 * So the load-bearing case here is *"a poll does not count as activity"*, and it is written
 * as a drive-it-and-watch-it-still-fire rather than as a check of the listener list: a test
 * that asserted `ACTIVITY_EVENTS` does not contain `visibilitychange` would pass against an
 * implementation that listened for it anyway.
 */

describe('the idle lock', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('is fifteen minutes, read from the one constant and not from a literal here', () => {
    // The constant is the decide-and-record, and `§0` carries it. Asserted as an exact value
    // so a change is a diff somebody has to defend rather than a number that drifts.
    expect(IDLE_LOCK_SECONDS).toBe(900)
    expect(CHECK_INTERVAL_MS).toBe(5000)
  })

  it('fires after the deadline, once, and never again', () => {
    const onIdle = vi.fn()
    const target = new EventTarget()

    watchForIdle(target, { seconds: 10, onIdle })

    vi.advanceTimersByTime(9_000)
    expect(onIdle).not.toHaveBeenCalled()

    vi.advanceTimersByTime(2_000)
    expect(onIdle).toHaveBeenCalledTimes(1)

    // ⚠ ONCE. `onIdle` signs out; a watch that re-armed would sign an already-signed-out
    // session out every fifteen minutes, all night, from a locked screen in an empty
    // kitchen. An hour of further time must add nothing.
    vi.advanceTimersByTime(60 * 60 * 1000)
    expect(onIdle).toHaveBeenCalledTimes(1)
  })

  it('does NOT count a poll, a re-render or a tab becoming visible as activity', () => {
    // THE CASE THE WHOLE MECHANISM EXISTS FOR. Every event fired below is one a program
    // produces, and the clock is driven straight past the deadline while they fire.
    const onIdle = vi.fn()
    const target = new EventTarget()

    watchForIdle(target, { seconds: 10, onIdle })

    for (let elapsed = 0; elapsed < 9_000; elapsed += 1_000) {
      // The shapes a board, a framework and a browser produce. None is a person.
      for (const name of ['visibilitychange', 'focus', 'blur', 'scroll', 'mousemove', 'resize']) {
        target.dispatchEvent(new Event(name))
      }

      vi.advanceTimersByTime(1_000)
    }

    expect(onIdle).not.toHaveBeenCalled()

    vi.advanceTimersByTime(2_000)

    expect(onIdle).toHaveBeenCalledTimes(1)
  })

  it('is reset by each event a person produces, and by all of them', () => {
    // The control for the case above: if nothing reset the deadline, that case would pass
    // against a watch that ignored every event including a real touch -- and a screen that
    // locks while somebody is using it is a worse product than one that never locks.
    for (const name of ACTIVITY_EVENTS) {
      const onIdle = vi.fn()
      const target = new EventTarget()

      watchForIdle(target, { seconds: 10, onIdle })

      for (let elapsed = 0; elapsed < 30_000; elapsed += 5_000) {
        target.dispatchEvent(new Event(name))
        vi.advanceTimersByTime(5_000)
      }

      expect(onIdle, `${name} did not reset the countdown`).not.toHaveBeenCalled()

      vi.advanceTimersByTime(11_000)

      expect(onIdle, `${name} left the watch unable to fire`).toHaveBeenCalledTimes(1)
    }
  })

  it('locks a device that was asleep past its deadline, on the first tick after it wakes', () => {
    // ⚠ WHY THE DEADLINE IS A TIMESTAMP AND NOT A LONG `setTimeout`. A background tab's
    // timers are clamped and deferred, and a sleeping device runs none at all -- so a screen
    // asleep for an hour can wake with a pending timer and consider itself active, which is
    // precisely the unattended case this clause is about.
    //
    // Modelled by advancing the CLOCK without advancing the timers, then running one tick.
    let clock = 1_000_000
    const onIdle = vi.fn()
    const target = new EventTarget()

    watchForIdle(target, { seconds: 600, onIdle, now: () => clock })

    // The device sleeps for an hour. No timer fires while it is asleep.
    clock += 60 * 60 * 1000

    expect(onIdle).not.toHaveBeenCalled()

    // It wakes, and the first interval tick finds the deadline long past.
    vi.advanceTimersByTime(CHECK_INTERVAL_MS)

    expect(onIdle).toHaveBeenCalledTimes(1)
  })

  it('stops cleanly, so a locked screen holds no timer and no listener', () => {
    const onIdle = vi.fn()
    const target = new EventTarget()
    const watch = watchForIdle(target, { seconds: 10, onIdle })

    watch.stop()
    vi.advanceTimersByTime(60_000)

    expect(onIdle).not.toHaveBeenCalled()

    // Idempotent: a component unmounting twice under React strict mode must not throw.
    expect(() => {
      watch.stop()
      watch.stop()
    }).not.toThrow()
  })

  it('ignores a poke after it has fired, so a late touch cannot un-lock the screen', () => {
    // A person touching the screen one tick after it locked must get the LOCK, not a
    // resurrected session: the server session is already gone by then, so a watch that
    // re-armed would leave a shell that thinks it is live and answers 401 to everything.
    const onIdle = vi.fn()
    const target = new EventTarget()
    const watch = watchForIdle(target, { seconds: 10, onIdle })

    vi.advanceTimersByTime(11_000)
    expect(onIdle).toHaveBeenCalledTimes(1)

    watch.poke()
    target.dispatchEvent(new Event('pointerdown'))
    vi.advanceTimersByTime(60_000)

    expect(onIdle).toHaveBeenCalledTimes(1)
  })
})
