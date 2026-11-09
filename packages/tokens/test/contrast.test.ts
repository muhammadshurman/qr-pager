import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { channels, contrast, luminance, mix, ratioFloor2, toHex } from '../src/color.ts'
import {
  APPROVED,
  BARS,
  BACKGROUND_ROLES,
  COLOUR_ROLES,
  FOREGROUND_ROLES,
  HEADROOM,
  MIX_COLOUR_SPACE,
  ORDER_READY,
  THEMES,
  colourProperty,
  contrastFailures,
  contrastPairs,
  orderReadyPairs,
  protectedProperties,
  resolveColour,
  resolveOrderReady,
  type ColourRole,
  type ContrastRow,
  type Theme,
} from '../src/source.ts'

/**
 * ================================================================================
 * THE CONTRAST BARS. READ FROM THE GENERATED TABLE, NOT TRANSCRIBED.
 * ================================================================================
 *
 * `§12` requires WCAG AA across the kit and **AAA on the order-ready screen**. Because D4
 * fixes that screen's pair in the token source, AAA there is arithmetic over two values
 * rather than a browser measurement.
 *
 * ⚠ THIS FILE USED TO CARRY ITS FIGURES AS LITERALS AND THAT WAS THE DEFECT. Three
 * published ratios were wrong — typed by hand and rounded UP against a MINIMUM bar:
 *
 *     paper on ready-field    raw 15.545990…   published 15.55   floored 15.54
 *     signal on ready-field   raw  9.729662…   published  9.73   floored  9.72
 *     alert-fill on paper     raw  3.329999…   published  3.32   floored  3.32
 *
 * So the figures now come from `generated/contrast.json`, which the generator emits from
 * the same source, and `pnpm tokens:check` fails if that artefact and the source disagree.
 * Two things are still asserted as LITERALS and deliberately so: the approved eleven, which
 * are the approval's own values and must be pinned against drift; and the three order-ready
 * ratios, which are a gate clause.
 */

const GENERATED = join(import.meta.dirname, '..', 'generated')

interface ContrastArtefact {
  readonly convention: { readonly rounding: string; readonly colourSpace: string }
  readonly bars: typeof BARS
  readonly headroom: number
  readonly orderReady: {
    readonly pairs: readonly {
      readonly fg: string
      readonly bg: string
      readonly ratio: number
      readonly pass: boolean
    }[]
  }
  readonly pairs: readonly ContrastRow[]
  readonly failures: readonly ContrastRow[]
  readonly summary: { readonly pairs: number; readonly failures: number }
}

const artefact = (): ContrastArtefact =>
  JSON.parse(readFileSync(join(GENERATED, 'contrast.json'), 'utf8')) as ContrastArtefact

const BG = BACKGROUND_ROLES

describe('the generated contrast table is the source of every figure', () => {
  it('exists, and is not empty — so nothing below can pass vacuously', () => {
    const a = artefact()
    // Exact counts. A `toBeGreaterThan(0)` would pass on a table that had lost most of
    // its rows, which is the shape that let three roles go unchecked in the first place.
    expect(a.pairs).toHaveLength(42)
    expect(a.summary.pairs).toBe(42)
    expect(a.orderReady.pairs).toHaveLength(3)
  })

  it('AGREES with a live computation, so the committed file cannot be quietly stale', () => {
    // tokens:check byte-diffs it in the gate; this is the same property from inside the
    // suite, so a stale artefact fails a test as well as a gate step.
    expect(artefact().pairs).toEqual(contrastPairs())
    expect(artefact().orderReady.pairs).toEqual(orderReadyPairs())
  })

  it('has ZERO failures', () => {
    // The whole table, every theme, every pair. This is the assertion the three-role
    // "every bar met" table could not make.
    const a = artefact()
    expect(a.failures).toEqual([])
    expect(a.summary.failures).toBe(0)
    expect(contrastFailures()).toEqual([])
  })

  it('states the rounding convention, and it is TRUNCATION', () => {
    expect(artefact().convention.rounding).toContain('TRUNCATED')
    expect(artefact().convention.colourSpace).toBe(MIX_COLOUR_SPACE)
    expect(artefact().bars).toEqual({ aaaText: 7.0, aaText: 4.5, aaLarge: 3.0 })
    expect(artefact().headroom).toBe(0.1)
  })

  it('covers EVERY foreground role against EVERY background, in both themes', () => {
    // Totality, mechanically: a role added to FOREGROUND_ROLES appears without anyone
    // remembering, and a role REMOVED from the table fails here.
    const a = artefact()
    for (const theme of THEMES) {
      for (const fg of Object.keys(FOREGROUND_ROLES)) {
        for (const bg of BG) {
          const row = a.pairs.find((r) => r.theme === theme && r.fg === fg && r.bg === bg)
          expect(row, `${theme} ${fg} on ${bg} is missing from the table`).toBeDefined()
        }
      }
    }
    expect(Object.keys(FOREGROUND_ROLES)).toHaveLength(6)
  })

  it('gives every barred row at least the stated HEADROOM over its bar', () => {
    // The lesson of 2026-10-31, as an assertion rather than a habit: border-strong cleared
    // its bar by 0.02 and 0.01 while ink-mid had already been raised for exactly that.
    for (const row of artefact().pairs) {
      if (row.bar === null) continue
      expect(
        row.marginOverBar,
        `${row.theme} ${row.fg} on ${row.bg} clears its bar by only ${String(row.marginOverBar)}`,
      ).toBeGreaterThanOrEqual(HEADROOM)
    }
  })

  it('names a bar for every role that carries text or a boundary, and none for hairline', () => {
    for (const row of artefact().pairs) {
      if (row.fg === 'hairline') {
        expect(row.bar).toBeNull()
      } else {
        expect(row.bar).not.toBeNull()
      }
    }
  })
})

/**
 * ================================================================================
 * EVERY PAIR, PINNED TO AN EXACT LITERAL. THE NARROWING THIS UNDOES.
 * ================================================================================
 *
 * ⚠ THE REWRITE THAT INTRODUCED THE GENERATED TABLE NARROWED THIS FILE, and the narrowing
 * was not declared. Measured per file across the rewrite: every suite was unchanged except
 * this one, which went **41 cases to 37**, which is the whole 308 -> 304 the gate reported.
 *
 * Most of that churn was renaming — the describes changed and the figures moved by a
 * hundredth under the floor convention — but **eight exact per-pair figures became
 * structural assertions**. The old file said `light ink-mid` is exactly `5.17, 5.72, 4.74`;
 * the new one said the artefact has zero failures and every row clears its bar plus
 * headroom. That is a weaker claim about any INDIVIDUAL pair: a palette change that kept
 * every row above its bar would have passed silently.
 *
 * `§8`: *"a fix must not narrow an existing assertion; if narrowing is unavoidable it is
 * stated in the commit message."* It was avoidable, so it is undone here rather than
 * declared: all **42** pairs are pinned to exact literals below.
 *
 * AND THIS IS NOT THE HAND-TRANSCRIPTION ITEM 3 REMOVED. The distinction is which
 * direction the number travels. A DOCUMENT quoting a ratio someone computed in their head
 * is a second source that drifts silently — that is what `generated/contrast.json` fixed.
 * A TEST holding the expected value is the pin itself: it is supposed to fail when the
 * value changes, and that is the only way a figure can be defended at all. These literals
 * were emitted from the artefact rather than typed, and `tokens:check` keeps the artefact
 * tied to the source, so there are three independent things to disagree before a wrong
 * figure ships.
 */
describe('every pair in the generated table, pinned to an exact literal', () => {
  /** theme, foreground role, background role, expected FLOORED ratio. */
  const EXPECTED: readonly [string, string, string, number][] = [
    ['light', 'ink', 'paper', 16.61],
    ['light', 'ink', 'surface', 17.06],
    ['light', 'ink', 'surface-2', 15.89],
    ['light', 'ink-mid', 'paper', 4.89],
    ['light', 'ink-mid', 'surface', 5.02],
    ['light', 'ink-mid', 'surface-2', 4.67],
    ['light', 'alert-ink', 'paper', 8.01],
    ['light', 'alert-ink', 'surface', 8.23],
    ['light', 'alert-ink', 'surface-2', 7.66],
    ['light', 'brand', 'paper', 16.61],
    ['light', 'brand', 'surface', 17.06],
    ['light', 'brand', 'surface-2', 15.89],
    ['light', 'border-strong', 'paper', 3.7],
    ['light', 'border-strong', 'surface', 3.8],
    ['light', 'border-strong', 'surface-2', 3.54],
    ['light', 'hairline', 'paper', 1.3],
    ['light', 'hairline', 'surface', 1.33],
    ['light', 'hairline', 'surface-2', 1.24],
    ['light', 'surface', 'brand', 17.06],
    ['light', 'surface', 'alert-ink', 8.23],
    ['light', 'surface', 'alert-fill', 8.23],
    ['dark', 'ink', 'paper', 16.61],
    ['dark', 'ink', 'surface', 13.26],
    ['dark', 'ink', 'surface-2', 10.91],
    ['dark', 'ink-mid', 'paper', 7.59],
    ['dark', 'ink-mid', 'surface', 6.06],
    ['dark', 'ink-mid', 'surface-2', 4.99],
    ['dark', 'alert-ink', 'paper', 7.62],
    ['dark', 'alert-ink', 'surface', 6.08],
    ['dark', 'alert-ink', 'surface-2', 5],
    ['dark', 'brand', 'paper', 16.61],
    ['dark', 'brand', 'surface', 13.26],
    ['dark', 'brand', 'surface-2', 10.91],
    ['dark', 'border-strong', 'paper', 5.07],
    ['dark', 'border-strong', 'surface', 4.04],
    ['dark', 'border-strong', 'surface-2', 3.33],
    ['dark', 'hairline', 'paper', 2.17],
    ['dark', 'hairline', 'surface', 1.73],
    ['dark', 'hairline', 'surface-2', 1.42],
    ['dark', 'surface', 'brand', 13.26],
    ['dark', 'surface', 'alert-ink', 6.08],
    ['dark', 'surface', 'alert-fill', 3.75],
  ]

  it('pins all 42 rows, and the table carries no row this list omits', () => {
    const rows = artefact().pairs
    // Both directions, so neither list can be a stale subset of the other.
    expect(EXPECTED).toHaveLength(42)
    expect(rows).toHaveLength(EXPECTED.length)

    const key = (t: string, f: string, b: string) => `${t}/${f}/${b}`
    const pinned = new Set(EXPECTED.map(([t, f, b]) => key(t, f, b)))
    for (const r of rows) {
      expect(
        pinned.has(key(r.theme, r.fg, r.bg)),
        `${key(r.theme, r.fg, r.bg)} is not pinned`,
      ).toBe(true)
    }
  })

  it.each(EXPECTED)('%s %s on %s is exactly %s', (theme, fg, bg, expected) => {
    const row = artefact().pairs.find((r) => r.theme === theme && r.fg === fg && r.bg === bg)
    expect(row, `${theme} ${fg} on ${bg} is missing from the table`).toBeDefined()
    expect(row!.ratio).toBe(expected)
    // And the same figure from a live computation, so a stale artefact cannot satisfy this.
    expect(ratioFloor2(row!.fgHex, row!.bgHex)).toBe(expected)
  })
})

describe('the eleven approved values are exactly what was approved', () => {
  it('holds the approved hexes verbatim', () => {
    // Typed out from the RAIL approval of 2026-11-07 (Direction B's eleven, approved
    // 2026-10-31, are superseded; signal, surface-2 and the alert pair were solved by
    // measurement on 2026-11-08 and approved with the reasoning). This is the ONE place
    // these are
    // written twice, and that is the test's job: if the source drifts from the approval,
    // this fails.
    expect(APPROVED).toEqual({
      paper: '#FCFCFA',
      surface: '#FFFFFF',
      'surface-2': '#F7F7F3',
      hairline: '#DFDFD7',
      ink: '#191C22',
      'ink-mid': '#6B6F7A',
      brand: '#191C22',
      signal: '#18C880',
      'ready-field': '#0A1F17',
      'alert-fill': '#8C2F27',
      'alert-ink': '#8C2F27',
    })
  })

  it('is the only set of typed colours: every other value is a RULE', () => {
    for (const theme of THEMES) {
      for (const role of Object.keys(COLOUR_ROLES[theme]) as ColourRole[]) {
        const rule = COLOUR_ROLES[theme][role]
        expect(['approved', 'mix']).toContain(rule.kind)
        if (rule.kind === 'mix') {
          expect(APPROVED).toHaveProperty(rule.from)
          expect(APPROVED).toHaveProperty(rule.to)
        } else {
          expect(APPROVED).toHaveProperty(rule.token)
        }
        expect(rule.why.length).toBeGreaterThan(10)
      }
    }
  })

  it('states the colour space, so a percentage is reproducible', () => {
    expect(MIX_COLOUR_SPACE).toBe('srgb-8bit-component-linear-round-half-up')
  })
})

describe('the order-ready screen — AAA, and the same in both themes', () => {
  it.each([
    ['--ready-ink', '--ready-field', 16.73, 'the page text'],
    ['--ready-signal', '--ready-field', 7.87, 'the order number'],
    ['--ready-field', '--ready-signal', 7.87, 'the acknowledge button'],
  ])('%s on %s is exactly %s:1 (%s)', (fg, bg, expected) => {
    // ⚠ RAIL'S RAW VALUES ARE 16.739645… and 7.870682…, floored to 16.73 and 7.87.
    // Direction B's were 15.545990… and 9.729662…, where truncation and rounding DIFFERED
    // (15.54 not 15.55, 9.72 not 9.73) and the approval comment carried the rounded forms;
    // Rail's happen to agree under both. The convention is unchanged. The task's own
    // prompt and the approval comment both carried the rounded forms. All three clear
    // AAA's 7.0 by a wide margin either way, so no conclusion changes — but a published
    // figure that rounds up against a minimum is the defect this convention removes.
    const row = artefact().orderReady.pairs.find((p) => p.fg === fg && p.bg === bg)
    expect(row, `${fg} on ${bg} is missing`).toBeDefined()
    expect(row!.ratio).toBe(expected)
    expect(row!.pass).toBe(true)
  })

  it('takes no theme argument at all, which is the mechanism', () => {
    expect(resolveOrderReady('ready-field')).toBe('#0A1F17')
    expect(resolveOrderReady('ready-ink')).toBe('#FCFCFA')
    expect(resolveOrderReady('ready-signal')).toBe('#18C880')
  })

  it('is not a theme role in either theme, so no theme can redeclare it', () => {
    const prot = protectedProperties() as string[]
    for (const theme of THEMES) {
      const roles = Object.keys(COLOUR_ROLES[theme])
      for (const name of Object.keys(ORDER_READY)) expect(roles).not.toContain(name)
      for (const property of prot) expect(roles.map(colourProperty)).not.toContain(property)
    }
  })
})

describe('the three roles corrected on 2026-10-31, each pinned to its generated value', () => {
  it.each([
    ['light', 'border-strong', '#828382', 'raised 44% -> 47%: 44% cleared 3:1 by 0.02'],
    [
      'dark',
      'border-strong',
      '#8B8C8E',
      'raised 48% -> 50%: 48% cleared by 0.01, and 49% cleared the 3.1 target by 0.00. ⚠ THE HEX MOVED ON 2026-11-09 AND THE PERCENTAGE DID NOT -- QRPAGER-39 re-pointed the dark ground off `ready-field` onto `ink`, so every mix of it lands on a new value. It was `#838E89`; the 2026-10-31 correction this row records is unchanged',
    ],
    [
      'dark',
      'alert-ink',
      '#CCA49F',
      'was `= approved alert-fill`, which FAILED AA on two of the three dark backgrounds',
    ],
  ])('%s %s resolves to %s', (theme, role, hex) => {
    expect(resolveColour(theme as Theme, role as ColourRole)).toBe(hex)
  })

  it('left dark ink-mid and the surface ladder where they were', () => {
    /*
     * The 2026-10-31 corrections were scoped: nothing else moved, so a reviewer diffing the
     * table saw three changed roles and not a repalette. That claim is about the MIX
     * PERCENTAGES, and it still holds -- none of them has changed since.
     *
     * ⚠ THE VALUES BELOW MOVED ON 2026-11-09 ANYWAY, AND NOT BY A CORRECTION TO THESE ROLES.
     * `QRPAGER-39` re-pointed dark `paper` off `ready-field` onto `ink`, and the ladder is
     * three MIXES OF THE GROUND -- so a one-line change to a different role rewrote all of
     * them. That is the chain working as designed (`§6.2`: the derivation is stored, never
     * the result), and it is the reason the ladder is pinned here at all: a reviewer sees
     * the blast radius of a ground change in one diff rather than discovering it on a screen.
     *
     * `ink-mid` is the control in this group: it is a mix of `ink-mid` toward `paper` and
     * touches the ground in no way, so it reads `#ABADB2` before and after.
     */
    expect(resolveColour('dark', 'surface')).toBe('#2B2E33')
    expect(resolveColour('dark', 'surface-2')).toBe('#393B40')
    expect(resolveColour('dark', 'hairline')).toBe('#4F5256')
    expect(resolveColour('dark', 'ink-mid')).toBe('#ABADB2')
  })

  it('clears each corrected role on its HARDEST background, not just its ground', () => {
    const worst = (theme: Theme, fg: ColourRole) =>
      Math.min(...BG.map((bg) => ratioFloor2(resolveColour(theme, fg), resolveColour(theme, bg))))

    expect(worst('light', 'border-strong')).toBe(3.54)
    expect(worst('dark', 'border-strong')).toBe(3.33)
    expect(worst('dark', 'alert-ink')).toBe(5)
    expect(worst('dark', 'ink-mid')).toBe(4.99)
  })
})

describe('hairline is decorative only — the finding that produced border-strong', () => {
  // ⚠ DARK MOVED 2.14 -> 2.17 ON 2026-11-09 and the CLAIM did not: hairline is a 24% mix
  // of the ground, and `QRPAGER-39` re-pointed the ground off `ready-field` onto `ink`. Both
  // figures are far below 3:1, which is what this case is about -- the second assertion
  // below is the one that carries the claim, and it needs no literal at all.
  it.each([
    ['light', 1.3],
    ['dark', 2.17],
  ])('%s hairline is %s:1 on its ground, below 1.4.11’s 3:1', (theme, expected) => {
    const t = theme as Theme
    expect(ratioFloor2(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBe(expected)
    expect(contrast(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBeLessThan(3)
  })
})

describe('the restricted tokens the approval singled out', () => {
  it('signal is 2.12:1 on paper, so it is never text on paper', () => {
    // 1.59 and not 1.60: raw 1.597793…, which the approval comment rounded up.
    expect(ratioFloor2(APPROVED.signal, APPROVED.paper)).toBe(2.12)
  })

  it("alert-fill CLEARS AA as small text under Rail, and its >= 24px bound is the approval's not the ratio's", () => {
    /*
     * ⚠ THIS CASE ASSERTED THE OPPOSITE UNTIL 2026-11-08, AND IT IS THE PROPERTY THAT
     * INVERTED RATHER THAN A FIGURE THAT MOVED -- said here rather than quietly reversed.
     *
     * Under Direction B, alert-fill was #E4572E: 3.32 on paper, and `surface` on it measured
     * BELOW 4.5, which is exactly why the danger button's fill was ruled to be alert-ink on
     * 2026-10-31. Rail gives BOTH names the same value, #8C2F27, so alert-fill now CLEARS AA
     * as small text and the >= 24px bound on it rests on the APPROVAL's own words rather than
     * on a ratio. The 2026-10-31 pairing is kept because it is a ruling; it no longer bites.
     */
    expect(ratioFloor2(APPROVED['alert-fill'], APPROVED.paper)).toBe(8.01)
    expect(contrast(APPROVED.surface, APPROVED['alert-fill'])).toBeGreaterThan(BARS.aaText)
    expect(APPROVED['alert-fill']).toBe(APPROVED['alert-ink'])
    expect(ratioFloor2(APPROVED.surface, APPROVED['alert-ink'])).toBe(8.23)
  })

  it('ink on paper is 16.61:1 under both conventions', () => {
    expect(ratioFloor2(APPROVED.ink, APPROVED.paper)).toBe(16.61)
  })
})

/**
 * ================================================================================
 * THE RATIO FUNCTION IS PINNED, NOT ARGUED
 * ================================================================================
 *
 * Muhammad's longhand gave **9.7322** for `signal` on `ready-field` where this
 * implementation gives **9.7296620**. A relative gap of ~2.6e-4, and under a FLOOR
 * convention that gap is load-bearing: 9.7322 floors to **9.73** and 9.7296620 floors to
 * **9.72**. One of the two published figures is wrong and the digit decides which.
 *
 * So the function is pinned rather than the disagreement settled by assertion. Verified
 * independently in-session against a **50-digit `Decimal`** implementation of the same W3C
 * formula, which agrees with this float64 one to ten decimal places:
 *
 *     Decimal(50):  9.7296620160727043914770110504561502045258896349076
 *     float64:      9.7296620161
 *
 * The three checks below are what catch the ONLY way a systematic ~1e-4 error appears --
 * a wrong constant or a wrong threshold.
 */
describe('the ratio function, pinned', () => {
  it('gives EXACTLY 21 for black on white', () => {
    // The formula's fixed point: lum(#FFFFFF) is exactly 1 and lum(#000000) exactly 0, so
    // (1 + 0.05) / (0 + 0.05) = 21 with no floating-point residue at all. Any wrong
    // coefficient (they must sum to 1), a wrong 0.055/1.055/12.92, or a wrong 0.04045
    // threshold moves this off 21 — which is why it is asserted with toBe and not
    // toBeCloseTo.
    expect(contrast('#000000', '#FFFFFF')).toBe(21)
    expect(ratioFloor2('#000000', '#FFFFFF')).toBe(21)
    expect(luminance('#FFFFFF')).toBe(1)
    expect(luminance('#000000')).toBe(0)
    // The weights are a partition of unity; if they were not, white could not be 1.
    expect(0.2126 + 0.7152 + 0.0722).toBe(1)
  })

  it('is SYMMETRIC across the whole token set, every ordered pair', () => {
    // contrast() takes a max and a min, so symmetry is structural -- which is exactly why
    // it is worth asserting over every pair rather than assumed: a refactor that dropped
    // the max/min and used (fg + 0.05) / (bg + 0.05) would pass most figures and silently
    // invert the ones where the foreground is darker.
    const hexes = [...new Set(Object.values(APPROVED))]
    expect(hexes.length).toBeGreaterThan(8)
    let checked = 0
    for (const a of hexes) {
      for (const b of hexes) {
        expect(contrast(a, b)).toBe(contrast(b, a))
        expect(ratioFloor2(a, b)).toBe(ratioFloor2(b, a))
        checked += 1
      }
    }
    // Every ordered pair, including a colour against itself.
    expect(checked).toBe(hexes.length * hexes.length)
    // And a colour against itself is exactly 1.
    for (const a of hexes) expect(contrast(a, a)).toBe(1)
  })

  it('matches an INDEPENDENT 50-digit computation to 10 decimal places', () => {
    // Computed in-session with Python's `decimal` at 50 digits of precision, from the same
    // W3C formula written out separately. These are not this implementation's own output
    // fed back in -- that would prove nothing -- they are a second arithmetic agreeing
    // with the first, which is what retires the longhand disagreement.
    const independent: readonly [string, string, number][] = [
      ['#3DDC97', '#0A1F17', 9.7296620161],
      ['#F2F4F1', '#0A1F17', 15.5459903411],
      ['#0A1F17', '#3DDC97', 9.7296620161],
    ]
    for (const [a, b, expected] of independent) {
      expect(contrast(a, b)).toBeCloseTo(expected, 10)
    }
    // And the published figure follows from it: 9.7296620… floors to 9.72, never 9.73.
    expect(ratioFloor2('#3DDC97', '#0A1F17')).toBe(9.72)
    expect(Math.floor(9.7322 * 100) / 100).toBe(9.73)
  })

  it('rounds NO intermediate value — the linearised channels are raw float64', () => {
    // A rounded intermediate is how the third decimal moves, and the floor convention makes
    // the third decimal decide the published figure. This asserts the property rather than
    // the comment: a channel whose linearisation had been rounded to, say, 6 places would
    // make luminance differ from the unrounded composition below.
    const [r, g, b] = channels('#3DDC97')
    const lin = (c8: number) => {
      const c = c8 / 255
      return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
    }
    expect(luminance('#3DDC97')).toBe(0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b))
  })

  it('applies the 0.04045 threshold on the correct side', () => {
    // Below the threshold the transfer is linear, above it is the power curve. Getting the
    // boundary wrong is a systematic error concentrated in dark colours -- which is where
    // every order-ready figure lives.
    // 10/255 = 0.0392… is below; 11/255 = 0.0431… is above.
    expect(luminance('#0A0A0A')).toBeCloseTo((10 / 255 / 12.92) * 1, 15)
    const above = Math.pow((11 / 255 + 0.055) / 1.055, 2.4)
    expect(luminance('#0B0B0B')).toBeCloseTo(above, 15)
  })
})

describe('the arithmetic itself', () => {
  it('TRUNCATES rather than rounds, which is the convention', () => {
    /*
     * The cases that moved, as a property of the function rather than of a table.
     *
     * ⚠ UNDER RAIL THIS TRIO NO LONGER DEMONSTRATES THE CONVENTION BY ITSELF, and that is
     * said rather than papered over: signal on ready-field is raw 7.870682…, which floors
     * AND rounds to 7.87, so the pair cannot show the difference any more. Direction B's
     * 9.729662… could. The demonstration now rests on the LITERAL-INPUT control above --
     * `ratioFloor2('#3DDC97', '#0A1F17')` is 9.72 and never 9.73 -- which is independent of
     * whatever the palette happens to be, and is the stronger place for it to live.
     */
    expect(ratioFloor2(APPROVED.paper, APPROVED['ready-field'])).toBe(16.73)
    expect(ratioFloor2(APPROVED.signal, APPROVED['ready-field'])).toBe(7.87)
    expect(ratioFloor2(APPROVED.signal, APPROVED.paper)).toBe(2.12)
    // …and a case where flooring and rounding agree, so the function is not merely
    // subtracting a hundredth from everything.
    expect(ratioFloor2(APPROVED.ink, APPROVED.paper)).toBe(16.61)
    expect(ratioFloor2(APPROVED.surface, APPROVED.brand)).toBe(17.06)
  })

  it('never reports a figure ABOVE the raw ratio', () => {
    // The safety property the convention exists for: flooring can only understate.
    for (const row of contrastPairs()) {
      expect(row.ratio).toBeLessThanOrEqual(contrast(row.fgHex, row.bgHex))
    }
  })

  it('mixes at the endpoints exactly', () => {
    expect(mix('#000000', '#FFFFFF', 0)).toBe('#000000')
    expect(mix('#000000', '#FFFFFF', 1)).toBe('#FFFFFF')
  })

  it('rounds the MIX half UP, which is a different rule from the ratio and is stated', () => {
    // The mix rounds half up because it is producing a channel, not reporting a minimum.
    expect(mix('#000000', '#010101', 0.5)).toBe('#010101')
  })

  it('refuses an amount outside 0..1 rather than clamping it', () => {
    expect(() => mix('#000000', '#FFFFFF', 1.5)).toThrow(/between 0 and 1/)
    expect(() => mix('#000000', '#FFFFFF', -0.1)).toThrow(/between 0 and 1/)
  })

  it('refuses a colour that is not six-digit hex, loudly', () => {
    expect(() => channels('#fff')).toThrow(/not a #rrggbb colour/)
    expect(() => channels('rgb(0,0,0)')).toThrow(/not a #rrggbb colour/)
    expect(() => channels('')).toThrow(/not a #rrggbb colour/)
  })

  it('emits upper-case hex, so every artefact is byte-comparable', () => {
    expect(mix('#0a1f17', '#f2f4f1', 0.08)).toBe('#1D3028')
    expect(toHex([10, 31, 23])).toBe('#0A1F17')
  })

  it('computes luminance at the known anchors', () => {
    expect(luminance('#000000')).toBe(0)
    expect(luminance('#FFFFFF')).toBeCloseTo(1, 10)
    expect(ratioFloor2('#000000', '#FFFFFF')).toBe(21)
  })

  it('is symmetric: contrast does not depend on which colour is named first', () => {
    expect(contrast(APPROVED.ink, APPROVED.paper)).toBe(contrast(APPROVED.paper, APPROVED.ink))
  })

  it('clamps nothing in toHex beyond the byte range', () => {
    expect(toHex([-5, 300, 128])).toBe('#00FF80')
  })
})

describe('the property namespace', () => {
  it('maps a role to its CSS custom property', () => {
    expect(colourProperty('surface-2')).toBe('--color-surface-2')
  })

  it('declares the same role set in both themes', () => {
    expect(Object.keys(COLOUR_ROLES.dark)).toEqual(Object.keys(COLOUR_ROLES.light))
  })
})
