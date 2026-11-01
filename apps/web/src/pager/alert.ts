/**
 * ================================================================================
 * SOUND AND VIBRATION ON THE ORDER-READY SCREEN
 * ================================================================================
 *
 * `§4.1` requires the order-ready screen to carry *"sound + vibration"*.
 *
 * ⚠ BOTH ARE ATTEMPTED AND NEITHER IS REQUIRED, and that is measured rather than cautious.
 * `storage/probe/engines.mjs`: **`navigator.vibrate` is absent in firefox 157.0 and
 * webkit 27.2** and present only in chromium 156.0.8078.4. A screen whose alert depended on
 * it would be silent on two of the three engines this project's gate drives, and on every
 * iPhone -- `navigator.vibrate` has never existed in Safari.
 *
 * So the ALERT is the notification (whose sound the OS owns), the full-bleed colour change,
 * and the assertive live region in `@qrpager/ui`'s `OrderReady`. Each of those works in all
 * three. Sound and vibration are enhancements on top.
 *
 * ⚠ AND UNDER `prefers-reduced-motion: reduce` VIBRATION IS NOT ATTEMPTED AT ALL.
 * `QRPAGER-15 §6` trap 3 and the approved direction both say it: reduced motion means NO
 * motion, not less. A buzz is motion -- it is the most physical motion this product
 * produces -- and a user who has asked their operating system for no motion has asked for
 * this too. The notification and the colour change carry the alert instead. This is a
 * judgement; it is stated here rather than buried, and `alert.test.ts` asserts it.
 *
 * SOUND IS NOT SUPPRESSED by reduced motion: it is not motion, and it is the channel a
 * customer who has disabled motion still has.
 */

export interface AlertEnvironment {
  /** `null` when the engine has no `AudioContext` and when autoplay policy forbids one. */
  readonly audioContext: (() => AudioContext) | null
  /** `null` in firefox and webkit, measured. */
  readonly vibrate: ((pattern: number | readonly number[]) => boolean) | null
  readonly prefersReducedMotion: boolean
}

export function alertEnvironmentFrom(win: Window): AlertEnvironment {
  /*
   * ⚠ BOTH CAPABILITIES ARE READ THROUGH `unknown`, AND THAT IS NOT CEREMONY.
   *
   * `lib.dom.d.ts` declares `Navigator.vibrate` and `Window.AudioContext` as ALWAYS PRESENT.
   * Measured in `tools/browsers/probe/engines.mjs`: `navigator.vibrate` is ABSENT in
   * firefox 157.0 and webkit 27.2. So a `typeof` guard written against the declared type is
   * reported by `@typescript-eslint/no-unnecessary-condition` as a check that cannot fail --
   * the rule is correct about the TYPES and the types are wrong about the engines.
   *
   * Reading through an index signature keeps the guard real and keeps the lie out of it.
   * Deleting the guard to satisfy the rule would be deleting the only thing standing between
   * two of the three engines and a `TypeError` on the order-ready screen.
   */
  const probe = win as unknown as Record<string, unknown>
  const navProbe = win.navigator as unknown as Record<string, unknown>

  const Ctor = probe.AudioContext
  const vibrator = navProbe.vibrate

  return {
    audioContext: typeof Ctor === 'function' ? () => new (Ctor as new () => AudioContext)() : null,
    vibrate:
      typeof vibrator === 'function'
        ? (pattern) =>
            (vibrator as (this: Navigator, p: number | readonly number[]) => boolean).call(
              win.navigator,
              pattern,
            )
        : null,
    prefersReducedMotion: win.matchMedia('(prefers-reduced-motion: reduce)').matches,
  }
}

/** What actually happened, so a test asserts the decision rather than the intent. */
export interface AlertOutcome {
  readonly sounded: boolean
  readonly vibrated: boolean
  readonly vibrationSuppressedByMotionPreference: boolean
}

/** Two short rising tones. 880 Hz then 1174.66 Hz: an interval, not a beep. */
const TONES: readonly { readonly hz: number; readonly at: number; readonly for: number }[] = [
  { hz: 880, at: 0, for: 0.12 },
  { hz: 1174.66, at: 0.16, for: 0.22 },
]

const VIBRATION_PATTERN: readonly number[] = [120, 80, 200]

export async function soundTheAlert(env: AlertEnvironment): Promise<AlertOutcome> {
  let sounded = false
  let vibrated = false

  if (env.audioContext !== null) {
    try {
      const context = env.audioContext()

      // ⚠ `resume()` AND ITS FAILURE ARE BOTH EXPECTED. Every engine starts a context
      // `suspended` without a prior gesture; on the push path there has been no gesture at
      // all, so this rejects and the screen is silent. That is correct behaviour, not a bug
      // to work around -- an alert that could bypass autoplay policy could also be an ad.
      if (context.state === 'suspended') await context.resume()

      if (context.state === 'running') {
        const gain = context.createGain()
        // A deliberate, low, fixed level. Loud enough across a table, not a shock.
        gain.gain.value = 0.18
        gain.connect(context.destination)

        for (const tone of TONES) {
          const oscillator = context.createOscillator()
          oscillator.type = 'sine'
          oscillator.frequency.value = tone.hz
          oscillator.connect(gain)
          oscillator.start(context.currentTime + tone.at)
          oscillator.stop(context.currentTime + tone.at + tone.for)
        }

        sounded = true
      }
    } catch {
      // No sound. Not an error state: the notification and the screen are the alert.
    }
  }

  if (!env.prefersReducedMotion && env.vibrate !== null) {
    try {
      vibrated = env.vibrate(VIBRATION_PATTERN)
    } catch {
      vibrated = false
    }
  }

  return {
    sounded,
    vibrated,
    vibrationSuppressedByMotionPreference: env.prefersReducedMotion && env.vibrate !== null,
  }
}
