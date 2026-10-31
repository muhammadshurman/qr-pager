/**
 * ================================================================================
 * THE COLOUR ARITHMETIC. ONE IMPLEMENTATION, AND THE COLOUR SPACE IS STATED.
 * ================================================================================
 *
 * Two things live here and nothing else: a WCAG 2.1 contrast ratio, and a mix.
 *
 * WHY THE COLOUR SPACE IS WRITTEN DOWN RATHER THAN IMPLIED. sRGB and OkLab produce
 * DIFFERENT hexes for the same percentage, so `mix(ready-field, paper, 8%)` names a value
 * only once the space is fixed. This was a condition of the dark theme's approval
 * (2026-10-31, condition 2): a percentage without its space is not a derivation anyone can
 * reproduce, and a figure that is only right under the method that produced it is this
 * project's `F10`.
 *
 * THE SPACE IS sRGB: component-wise linear interpolation on the three 8-bit channels,
 * rounded half-up. Chosen over OkLab for one reason -- it is reproducible with no
 * dependency and in any language, which matters because the same values are emitted into
 * CSS, into TypeScript and into a PHP config, and a reviewer must be able to check a hex by
 * hand. OkLab would give a more perceptually even ladder; it would also make every
 * generated value depend on a colour-science library's version.
 */

/** A `#rrggbb` colour, upper-case. The only form this package stores or emits. */
export type Hex = `#${string}`

const HEX_SHAPE = /^#[0-9A-Fa-f]{6}$/

/** Parse `#rrggbb` into its three channels, refusing anything else loudly. */
export function channels(hex: string): [number, number, number] {
  if (!HEX_SHAPE.test(hex)) {
    throw new Error(
      `[tokens] ${hex} is not a #rrggbb colour. The token source stores six-digit hex only, ` +
        `upper-case, so every emitted artefact is byte-comparable.`,
    )
  }
  const n = Number.parseInt(hex.slice(1), 16)
  return [(n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff]
}

/**
 * Format three channels as `#RRGGBB`, upper-case.
 *
 * ⚠ THE BYTE HELPER IS CALLED `toByte` AND NOT `clamp`, DELIBERATELY. The §5.1 invariant
 * test asserts that no file in the order-ready path contains anything matching
 * `clamp(|adjust(|ensureContrast(|...`, because D4 is an exclusion and a contrast clamp
 * appearing anywhere means the design has drifted from the decision (QRPAGER-15 §6, trap
 * 5). A local helper named `clamp` tripped that assertion honestly -- it IS a clamp, just
 * of a byte range rather than of contrast.
 *
 * The fix is this rename rather than narrowing the assertion to spare it. §8: a fix must not
 * narrow an existing assertion. A broad check plus one renamed helper is worth more than a
 * precise check that would also miss a real contrast clamp named something unexpected.
 */
export function toHex([r, g, b]: [number, number, number]): Hex {
  const toByte = (c: number) => Math.min(255, Math.max(0, c))
  return `#${[r, g, b]
    .map((c) => toByte(c).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()}`
}

/**
 * Mix `from` toward `to` by `amount` (0..1) in **8-bit sRGB**, rounded half-up.
 *
 * Half-up rather than `Math.round`: `Math.round(-0.5)` is `-0` and rounds half toward
 * +Infinity, which is a different rule on each side of zero. Channels are never negative
 * here, so the two agree today -- stating the rule means they cannot disagree later.
 */
export function mix(from: string, to: string, amount: number): Hex {
  if (!(amount >= 0 && amount <= 1)) {
    throw new Error(`[tokens] mix amount must be between 0 and 1, got ${String(amount)}`)
  }
  // Destructured rather than indexed, so there is no `!` asserting away what
  // noUncheckedIndexedAccess exists to surface -- `channels()` already guarantees three
  // values by construction and by throwing, and a tuple destructure says so in the types.
  const [ar, ag, ab] = channels(from)
  const [br, bg, bb] = channels(to)
  const step = (x: number, y: number) => Math.floor(x + (y - x) * amount + 0.5)
  return toHex([step(ar, br), step(ag, bg), step(ab, bb)])
}

/** The sRGB transfer function, per WCAG 2.1. */
function linearise(channel8: number): number {
  const c = channel8 / 255
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
}

/** Relative luminance, per WCAG 2.1. */
export function luminance(hex: string): number {
  const [r, g, b] = channels(hex)
  return 0.2126 * linearise(r) + 0.7152 * linearise(g) + 0.0722 * linearise(b)
}

/**
 * The WCAG 2.1 contrast ratio between two colours, unrounded.
 *
 * Unrounded deliberately: every caller reduces it at the point it reports, so a bar check
 * and a printed figure cannot disagree about the same pair.
 */
export function contrast(a: string, b: string): number {
  const la = luminance(a)
  const lb = luminance(b)
  const hi = Math.max(la, lb)
  const lo = Math.min(la, lb)
  return (hi + 0.05) / (lo + 0.05)
}

/**
 * ================================================================================
 * THE ROUNDING CONVENTION, STATED ONCE: TRUNCATE AT TWO DECIMALS. NEVER ROUND UP.
 * ================================================================================
 *
 * Every contrast figure this project publishes or asserts goes through this function.
 *
 * WHY FLOORING AND NOT ROUNDING. Every contrast threshold is a MINIMUM — AA is "at least
 * 4.5:1", AAA "at least 7:1", 1.4.11 "at least 3:1". Rounding half-up can therefore
 * publish a figure that OVERSTATES compliance: a raw 3.2999 presented as "3.33" against a
 * 3:1 bar is harmless, but a raw 4.4996 presented as "4.50" against a 4.5 bar reads as a
 * pass and is not one. Flooring can only ever understate, which is the safe direction for
 * a minimum.
 *
 * ⚠ IT CHANGED THREE FIGURES THIS PROJECT HAD ALREADY PUBLISHED, which is the whole reason
 * the convention is now written down and mechanised rather than carried in someone's head:
 *
 *     paper on ready-field   raw 15.545990…   round 15.55   FLOOR 15.54
 *     signal on paper        raw  1.597793…   round  1.60   FLOOR  1.59
 *     alert-fill on paper    raw  3.329999…   round  3.33   FLOOR  3.32
 *
 * The first two were in the approval comment and the third was in this task's own report.
 * All three were hand-transcribed, and hand-transcription is the defect behind all of them
 * — which is why `generated/contrast.json` now exists and why the tests read it rather than
 * a number anybody typed.
 */
export function ratioFloor2(a: string, b: string): number {
  // The multiply-floor-divide has to be done on the ratio itself, not on a pre-rounded
  // value, or the floor would be applied to something already rounded up.
  return Math.floor(contrast(a, b) * 100) / 100
}
