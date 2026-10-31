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

/** Format three channels as `#RRGGBB`, upper-case. */
export function toHex([r, g, b]: [number, number, number]): Hex {
  const clamp = (c: number) => Math.min(255, Math.max(0, c))
  return `#${[r, g, b]
    .map((c) => clamp(c).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()}` as Hex
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
  const a = channels(from)
  const b = channels(to)
  return toHex([0, 1, 2].map((i) => Math.floor(a[i]! + (b[i]! - a[i]!) * amount + 0.5)) as [
    number,
    number,
    number,
  ])
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
 * Unrounded deliberately: every caller rounds at the point it reports, so a bar check and a
 * printed figure cannot disagree about the same pair. `ratioTo2dp` is what tests assert.
 */
export function contrast(a: string, b: string): number {
  const la = luminance(a)
  const lb = luminance(b)
  const hi = Math.max(la, lb)
  const lo = Math.min(la, lb)
  return (hi + 0.05) / (lo + 0.05)
}

/** The contrast ratio to two decimal places -- the form every assertion and table uses. */
export function ratioTo2dp(a: string, b: string): number {
  return Math.round(contrast(a, b) * 100) / 100
}
