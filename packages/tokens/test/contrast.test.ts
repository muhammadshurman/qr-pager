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

describe('the eleven approved values are exactly what was approved', () => {
  it('holds the approved hexes verbatim', () => {
    // Typed out from the 2026-10-31 approval comment. This is the ONE place these are
    // written twice, and that is the test's job: if the source drifts from the approval,
    // this fails.
    expect(APPROVED).toEqual({
      paper: '#F2F4F1',
      surface: '#FFFFFF',
      'surface-2': '#E7EBE6',
      hairline: '#D3DAD2',
      ink: '#10231B',
      'ink-mid': '#5A6A62',
      brand: '#0B6B4F',
      signal: '#3DDC97',
      'ready-field': '#0A1F17',
      'alert-fill': '#E4572E',
      'alert-ink': '#96351A',
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
    ['--ready-ink', '--ready-field', 15.54, 'the page text'],
    ['--ready-signal', '--ready-field', 9.72, 'the order number'],
    ['--ready-field', '--ready-signal', 9.72, 'the acknowledge button'],
  ])('%s on %s is exactly %s:1 (%s)', (fg, bg, expected) => {
    // ⚠ 15.54 / 9.72 / 9.72, NOT 15.55 / 9.73 / 9.73. The raw values are 15.545990… and
    // 9.729662…, and under truncation they floor rather than round up. The task's own
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
    expect(resolveOrderReady('ready-ink')).toBe('#F2F4F1')
    expect(resolveOrderReady('ready-signal')).toBe('#3DDC97')
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
    ['light', 'border-strong', '#77847C', 'raised 44% -> 47%: 44% cleared 3:1 by 0.02'],
    [
      'dark',
      'border-strong',
      '#7E8A84',
      'raised 48% -> 50%: 48% cleared by 0.01, and 49% cleared the 3.1 target by 0.00',
    ],
    [
      'dark',
      'alert-ink',
      '#E99176',
      'was `= approved alert-fill`, which FAILED AA on two of the three dark backgrounds',
    ],
  ])('%s %s resolves to %s', (theme, role, hex) => {
    expect(resolveColour(theme as Theme, role as ColourRole)).toBe(hex)
  })

  it('left dark ink-mid and the surface ladder where they were', () => {
    // The corrections were scoped: nothing else moved, so a reviewer diffing the table sees
    // three changed roles and not a repalette.
    expect(resolveColour('dark', 'surface')).toBe('#1D3028')
    expect(resolveColour('dark', 'surface-2')).toBe('#2A3D36')
    expect(resolveColour('dark', 'hairline')).toBe('#42524B')
    expect(resolveColour('dark', 'ink-mid')).toBe('#9DA7A1')
  })

  it('clears each corrected role on its HARDEST background, not just its ground', () => {
    const worst = (theme: Theme, fg: ColourRole) =>
      Math.min(...BG.map((bg) => ratioFloor2(resolveColour(theme, fg), resolveColour(theme, bg))))

    expect(worst('light', 'border-strong')).toBe(3.24)
    expect(worst('dark', 'border-strong')).toBe(3.21)
    expect(worst('dark', 'alert-ink')).toBe(4.82)
    expect(worst('dark', 'ink-mid')).toBe(4.65)
  })
})

describe('hairline is decorative only — the finding that produced border-strong', () => {
  it.each([
    ['light', 1.28],
    ['dark', 2.07],
  ])('%s hairline is %s:1 on its ground, below 1.4.11’s 3:1', (theme, expected) => {
    const t = theme as Theme
    expect(ratioFloor2(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBe(expected)
    expect(contrast(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBeLessThan(3)
  })
})

describe('the restricted tokens the approval singled out', () => {
  it('signal is 1.59:1 on paper, so it is never text on paper', () => {
    // 1.59 and not 1.60: raw 1.597793…, which the approval comment rounded up.
    expect(ratioFloor2(APPROVED.signal, APPROVED.paper)).toBe(1.59)
  })

  it('alert-fill fails AA as small text, which is why it fills no button here', () => {
    // 3.32 and not 3.33: raw 3.329999…. The comment's conclusion holds either way.
    expect(ratioFloor2(APPROVED['alert-fill'], APPROVED.paper)).toBe(3.32)
    expect(contrast(APPROVED.surface, APPROVED['alert-fill'])).toBeLessThan(BARS.aaText)
    // And the pairing that replaced it.
    expect(ratioFloor2(APPROVED.surface, APPROVED['alert-ink'])).toBe(7.44)
  })

  it('ink on paper is 14.85:1 under both conventions', () => {
    expect(ratioFloor2(APPROVED.ink, APPROVED.paper)).toBe(14.85)
  })
})

describe('the arithmetic itself', () => {
  it('TRUNCATES rather than rounds, which is the convention', () => {
    // The three cases that moved, as a property of the function rather than of a table.
    expect(ratioFloor2(APPROVED.paper, APPROVED['ready-field'])).toBe(15.54)
    expect(ratioFloor2(APPROVED.signal, APPROVED['ready-field'])).toBe(9.72)
    expect(ratioFloor2(APPROVED.signal, APPROVED.paper)).toBe(1.59)
    // …and a case where flooring and rounding agree, so the function is not merely
    // subtracting a hundredth from everything.
    expect(ratioFloor2(APPROVED.ink, APPROVED.paper)).toBe(14.85)
    expect(ratioFloor2(APPROVED.surface, APPROVED.brand)).toBe(6.5)
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
