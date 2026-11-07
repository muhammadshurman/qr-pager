/**
 * ================================================================================
 * THE KEEP-SCREEN-AWAKE TOGGLE. Driven by an outcome, never by a detection.
 * ================================================================================
 *
 * `§4.1` asks the waiting screen for a *"keep-screen-awake toggle"*.
 *
 * ⚠ FEATURE DETECTION IS A LIE HERE, AND IT IS MEASURED BOTH WAYS.
 *
 * MDN `browser-compat-data`, `api/WakeLock.json`, read this session: `safari_ios` is
 * **18.4** for full support and **16.4–18.4 partial**, with the note verbatim: ***"Does not
 * work in standalone Home Screen Web Apps."***
 *
 * That is the exact configuration this product requires -- `§4 › Realtime` says Web Push on
 * iOS works only from a Home Screen install, so every iPhone customer between 16.4 and 18.4
 * is in a context where the API is PRESENT and DOES NOTHING.
 *
 * And measured in the engines this gate drives (`storage/probe/engines.mjs`):
 * `navigator.wakeLock` is `true` in chromium 156.0.8078.4, firefox 157.0 AND webkit 27.2.
 * So a detection says yes on every engine available to test, while the compat data says the
 * one that matters is lying.
 *
 * THEREFORE: the toggle is rendered from the outcome of a real `request('screen')`. If it
 * rejects, or the API is absent, the control is NOT RENDERED and one quiet line says the
 * screen may dim -- rather than a switch that flips and changes nothing, which is worse than
 * no switch at all.
 */

export type WakeOutcome = 'held' | 'unavailable'

interface WakeLockSentinelLike {
  release: () => Promise<void>
  addEventListener: (type: 'release', listener: () => void) => void
}

export interface WakeEnvironment {
  readonly request: ((type: 'screen') => Promise<WakeLockSentinelLike>) | null
}

export function wakeEnvironmentFrom(nav: Navigator): WakeEnvironment {
  /*
   * ⚠ READ THROUGH `unknown` FOR THE SAME REASON `alert.ts` DOES. `lib.dom.d.ts` declares
   * `Navigator.wakeLock` as always present, so a guard written against the declared type is
   * flagged as a check that cannot fail -- while MDN's compat data puts full support at
   * safari_ios **18.4** and Firefox **126**, and marks 16.4–18.4 partial with the note *"Does
   * not work in standalone Home Screen Web Apps."*
   *
   * The guard is what keeps a customer on an older iPhone from a `TypeError` where this file
   * expects a rejected promise, and the rejection path is what keeps them from a switch that
   * does nothing.
   */
  const probe = nav as unknown as Record<string, unknown>
  const lock = probe.wakeLock

  if (typeof lock !== 'object' || lock === null) return { request: null }

  const request = (lock as Record<string, unknown>).request

  if (typeof request !== 'function') return { request: null }

  return {
    // Called ON the lock object: `request` is a method and detaching it loses its receiver.
    request: (type) =>
      (request as (this: unknown, t: 'screen') => Promise<WakeLockSentinelLike>).call(lock, type),
  }
}

export interface WakeHandle {
  readonly outcome: WakeOutcome
  readonly release: () => Promise<void>
}

/**
 * Try to hold the screen awake.
 *
 * `onLost` fires when the ENGINE releases the lock -- which it does whenever the document
 * becomes hidden, by specification. That is not a failure and must not flip the label to
 * "unavailable": the caller re-requests on the next `visibilitychange` to visible. A release
 * we did not ask for silently changing the UI is the shape this callback exists to avoid.
 */
export async function holdScreenAwake(
  env: WakeEnvironment,
  onLost: () => void,
): Promise<WakeHandle> {
  if (env.request === null) {
    return { outcome: 'unavailable', release: () => Promise.resolve() }
  }

  try {
    const sentinel = await env.request('screen')
    sentinel.addEventListener('release', onLost)

    return {
      outcome: 'held',
      release: async () => {
        try {
          await sentinel.release()
        } catch {
          // Already released by the engine. Nothing to do and nothing to report.
        }
      },
    }
  } catch {
    // ⚠ THE REJECTION IS THE ANSWER, and it is the iOS 16.4–18.4 standalone case above.
    // Not an error state: a wake lock is an enhancement and the alert does not depend on it.
    return { outcome: 'unavailable', release: () => Promise.resolve() }
  }
}
