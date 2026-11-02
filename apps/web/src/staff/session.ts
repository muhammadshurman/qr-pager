/**
 * ================================================================================
 * THE IDLE LOCK. `QRPAGER-25 §12` S3, and it is a security mechanism not a UI state.
 * ================================================================================
 *
 * A staff screen is left on a counter in a kitchen. After `IDLE_LOCK_SECONDS` with no human
 * input the shell signs the session OUT on the server, drops the tenant data it is holding,
 * and covers itself. So a locked screen holds no credential that works: devtools, a copied
 * cookie and a `curl` from that browser all get the same bodiless 401 as a stranger.
 *
 * ================================================================================
 * POLLING IS NOT ACTIVITY, AND THAT IS THE WHOLE REQUIREMENT
 * ================================================================================
 *
 * `§3` row 10 asks for a screen left unattended to be safe. A board that refreshes itself
 * every few seconds and counted that as activity would NEVER lock — the screens most in
 * need of this are exactly the ones with a timer running. So the only things that reset the
 * countdown are events a PERSON produces, listed in `ACTIVITY_EVENTS` below, and
 * `apps/web/test/staff-session.test.ts` drives a fake timer past the threshold while firing
 * nothing but a programmatic refresh, and asserts it still locks.
 *
 * ================================================================================
 * FIFTEEN MINUTES, AND IT IS A DECIDE-AND-RECORD
 * ================================================================================
 *
 * `§3` row 10 names no number, so this is a parameter rather than a ruling: recorded in
 * `PLAN §0` with its date and reason. Fifteen minutes is the point-of-sale convention and it
 * is long enough that it does not fire during a service while somebody is working the
 * screen, because any touch resets it.
 *
 * It is exported as ONE constant read by the hook and by its test, so 10b's board and 10c's
 * KDS consume this decision rather than taking it again. **A KDS that must stay readable
 * with nobody present is a `Principal::Device` surface** — it holds a device token and no
 * human session at all, which is why a human session may expire this aggressively and is
 * 10c's concern rather than a reason to soften this.
 */

/** Fifteen minutes. See the header: a parameter, recorded, and read from here. */
export const IDLE_LOCK_SECONDS = 15 * 60

/**
 * The events that mean a person is there.
 *
 * `pointerdown` rather than `click`, so a tap on something inert still counts; `keydown`
 * rather than `keypress`, which is deprecated and misses modifier-only presses; `wheel` and
 * `touchstart` because scrolling a board is attention. **No `visibilitychange`**: a tab
 * becoming visible is not a person, and a kiosk that cycles tabs would hold the lock open
 * forever. **No `mousemove`**: it fires from a knocked table and from a cat.
 */
export const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const

export interface IdleWatchOptions {
  /** Seconds of no human input before `onIdle` runs. */
  readonly seconds?: number
  readonly onIdle: () => void
  /** Injected in tests. Defaults to the real timer functions. */
  readonly now?: () => number
}

export interface IdleWatch {
  /** Stop watching and clear the timer. Idempotent. */
  readonly stop: () => void
  /** Reset the countdown as if a person had just touched the screen. */
  readonly poke: () => void
}

/**
 * Watch for idleness on `target`, and run `onIdle` once when it is reached.
 *
 * ⚠ ONCE, NOT REPEATEDLY. `onIdle` signs out, and a timer that fired again would sign out an
 * already-signed-out session every fifteen minutes forever — a request a locked screen in an
 * empty kitchen would keep making all night. The watch disarms itself when it fires.
 *
 * ⚠ AND IT IS A DEADLINE RATHER THAN A `setTimeout` THAT IS RESET. A long `setTimeout` is
 * not reliable across a device sleeping or a tab being throttled: browsers clamp and defer
 * timers in a background tab, so a screen asleep for an hour can wake with its timer still
 * pending and consider itself active. The deadline is a TIMESTAMP, re-checked on a short
 * interval and on every activity event, so time that passed while the device was asleep
 * counts — which is precisely the unattended case.
 */
export function watchForIdle(target: EventTarget, options: IdleWatchOptions): IdleWatch {
  const seconds = options.seconds ?? IDLE_LOCK_SECONDS
  const now = options.now ?? (() => Date.now())

  let deadline = now() + seconds * 1000
  let fired = false
  let interval: ReturnType<typeof setInterval> | null = null

  const poke = (): void => {
    if (fired) return
    deadline = now() + seconds * 1000
  }

  const stop = (): void => {
    if (interval !== null) {
      clearInterval(interval)
      interval = null
    }

    for (const event of ACTIVITY_EVENTS) {
      target.removeEventListener(event, poke)
    }
  }

  const tick = (): void => {
    if (fired || now() < deadline) return
    fired = true
    stop()
    options.onIdle()
  }

  for (const event of ACTIVITY_EVENTS) {
    // `passive`, so a listener on `touchstart` and `wheel` cannot make a kitchen screen
    // feel slow to scroll. It never calls `preventDefault`, so there is nothing to lose.
    target.addEventListener(event, poke, { passive: true })
  }

  interval = setInterval(tick, CHECK_INTERVAL_MS)

  return { stop, poke }
}

/**
 * How often the deadline is re-checked: every five seconds.
 *
 * Short enough that the lock is not noticeably late, long enough to be free. The precision
 * that matters is the DEADLINE, not the tick: a device asleep for an hour locks on its first
 * tick after waking, whatever this number is.
 */
export const CHECK_INTERVAL_MS = 5_000
