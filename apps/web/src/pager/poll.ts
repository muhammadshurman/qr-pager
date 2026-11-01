/**
 * ================================================================================
 * THE POLLING CADENCE. `§4 › Realtime`'s client half, as arithmetic.
 * ================================================================================
 *
 * `§4 › Realtime`: *"client-side jittered interval + exponential backoff"*, and `§3` row 9
 * clause (b): *"Client backoff and jitter verified. The server half is Task 4's and is
 * built … This task builds the client that obeys them."*
 *
 * THE SPLIT IS THE SERVER'S WORDING, not a choice made here. `PagerStatusView`:
 *
 *   *"`poll_after_seconds` is the SERVER half … the interval is the server's to advertise
 *   and the jitter is the client's to apply, because jitter applied here would be identical
 *   for every device that happened to poll in the same second."*
 *
 * WHY JITTER IS NOT POLITENESS. Every phone in one restaurant scans within the same lunch
 * rush and is handed the SAME `poll_after_seconds`. Without jitter they converge on one tick
 * and stay there forever, so the endpoint sees a 7-second square wave instead of a flat
 * load, and the 503s that wave causes arrive for every device at once. The jitter is drawn
 * PER REQUEST, not once per session, so two devices that happen to align separate again.
 *
 * EVERY NUMBER HERE IS EXACT AND ASSERTED AT ITS EXACT VALUE, never as a range -- the
 * discipline this project states as *"assert exact values, never ranges"*. The randomness is
 * injected, so the tests are deterministic and the BOUNDS are checked at the extremes
 * (`random = 0` and `random = 1 - Number.EPSILON`) rather than by sampling and hoping.
 */

/** ±20 % of the advertised interval. */
export const JITTER_FRACTION = 0.2

/** Doubling, from the advertised interval. */
export const BACKOFF_BASE = 2

/** The ceiling. Two minutes: past this the client is not polling, it is waiting. */
export const BACKOFF_CAP_SECONDS = 120

/**
 * Consecutive failures after which the client stops its own timer and asks the customer.
 *
 * SIX, and the number is derived rather than picked: 7 s doubling to the 120 s cap gives
 * 7, 14, 28, 56, 112, 120 -- the sixth attempt is the first one at the cap, so a seventh
 * would be the first that learns nothing new about the cadence. After it, the waiting
 * screen is `offline`/`degraded` with a manual retry, which is `§4.1`'s *"no dead end"*.
 */
export const MAX_CONSECUTIVE_FAILURES = 6

/** The injected randomness. `Math.random` in the app; a sequence in the tests. */
export type Random = () => number

/**
 * The delay before the next poll, in milliseconds.
 *
 * @param advertisedSeconds `poll_after_seconds` from the last answer the server gave.
 * @param consecutiveFailures 0 after a 200. Incremented by a 503/429 and by unreachable.
 * @param random a value in [0, 1).
 *
 * ⚠ THE JITTER IS APPLIED AFTER THE BACKOFF AND AFTER THE CAP, in that order, and the order
 * is observable: jittering first and then capping would make the cap exact and the jitter
 * one-sided at the ceiling, so every device at the cap would fire on the same tick -- the
 * exact convergence jitter exists to prevent, reappearing at the worst moment, when the
 * server is already shedding.
 */
export function nextDelayMs(
  advertisedSeconds: number,
  consecutiveFailures: number,
  random: Random,
): number {
  const base = Math.max(1, Math.floor(advertisedSeconds))
  const grown = base * BACKOFF_BASE ** Math.max(0, consecutiveFailures)
  const capped = Math.min(grown, BACKOFF_CAP_SECONDS)
  // random in [0,1) -> factor in [1 - f, 1 + f). Rounded to whole milliseconds so a
  // reported figure is reproducible from the inputs.
  const factor = 1 - JITTER_FRACTION + random() * 2 * JITTER_FRACTION

  return Math.round(capped * factor * 1000)
}

/**
 * A server hint, when there is one, beats our own arithmetic -- but never below the floor.
 *
 * The 503 and 429 both carry `poll_after_seconds` (Task 4's `PacedRoute`), and a server that
 * is shedding knows better than a client's doubling. It is still jittered, and it is still
 * capped, because an advisory header is not a promise and a hint of 0 would be a busy loop.
 */
export function delayFromAnswerMs(
  hintSeconds: number | null,
  advertisedSeconds: number,
  consecutiveFailures: number,
  random: Random,
): number {
  if (hintSeconds !== null && Number.isFinite(hintSeconds) && hintSeconds >= 1) {
    const capped = Math.min(Math.floor(hintSeconds), BACKOFF_CAP_SECONDS)
    const factor = 1 - JITTER_FRACTION + random() * 2 * JITTER_FRACTION

    return Math.round(capped * factor * 1000)
  }

  return nextDelayMs(advertisedSeconds, consecutiveFailures, random)
}

/**
 * Elapsed whole seconds, computed from the SERVER's own timestamp.
 *
 * ⚠ NOT A LOCAL TICK COUNT, AND THAT IS THE PHONE-SLEEPS CASE. A `setInterval` in a
 * backgrounded or sleeping tab is throttled or stopped by every engine, so a counter that
 * increments itself is wrong by however long the phone slept -- and it is wrong in the
 * direction that makes a long wait look short, which is the direction a customer notices.
 *
 * The clock is therefore `now - scannedAt` where both are read fresh, and the *confirmed*
 * reading is `asOf`, so the degraded state can say *"last confirmed {time}"* truthfully
 * rather than showing a number it cannot stand behind.
 *
 * Returns 0 rather than a negative number for a clock skewed behind the server's: a
 * customer's phone being thirty seconds fast is common and `-30` on screen is not.
 */
export function elapsedSeconds(scannedAtIso: string, nowMs: number): number {
  const scanned = Date.parse(scannedAtIso)

  if (Number.isNaN(scanned)) return 0

  return Math.max(0, Math.floor((nowMs - scanned) / 1000))
}
