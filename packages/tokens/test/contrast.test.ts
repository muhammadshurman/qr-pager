import { describe, expect, it } from 'vitest'

import { contrast, mix, ratioTo2dp, toHex, channels, luminance } from '../src/color.ts'
import {
  APPROVED,
  COLOUR_ROLES,
  MIX_COLOUR_SPACE,
  ORDER_READY,
  ORDER_READY_PAIRS,
  THEMES,
  colourProperty,
  protectedProperties,
  resolveColour,
  resolveOrderReady,
  resolveTheme,
  type ColourRole,
  type Theme,
} from '../src/source.ts'

/**
 * ================================================================================
 * THE CONTRAST BARS, AS ARITHMETIC. No browser, and that is the point.
 * ================================================================================
 *
 * `§12` requires WCAG AA across the kit and **AAA on the order-ready screen**. Because D4
 * fixes that screen's colour pair in the token source, AAA there is arithmetic over two
 * values rather than a measurement -- which is what lets it be asserted here instead of
 * waiting for `R-118`'s browser harness.
 *
 * EXACT VALUES, NEVER RANGES (QRPAGER-15 §8). A `toBeGreaterThan(7)` would pass on a
 * palette nobody approved; `toBe(15.55)` fails the moment a mix percentage moves, which is
 * condition 4 of the dark theme's approval in so many words.
 */

/** The three backgrounds text can sit on, per theme. A bar must hold against all three. */
const BACKGROUNDS = ['paper', 'surface', 'surface-2'] as const satisfies readonly ColourRole[]

describe('the eleven approved values are exactly what was approved', () => {
  it('holds the approved hexes verbatim', () => {
    // Typed out here deliberately, from the 2026-10-31 approval comment. This is the ONE
    // place in the repository where these are written twice, and that is the test's job:
    // if the source drifts from the approval, this fails.
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

  it('is the only set of typed colours: every other value is a rule', () => {
    // Condition 1 of the approval: the DERIVATION lives here, never the result. A dark hex
    // committed in the source would survive a change to `paper` and be a second source.
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
        // Every rule carries its reason. A derivation nobody can explain is a guess.
        expect(rule.why.length).toBeGreaterThan(10)
      }
    }
  })

  it('states the colour space, so a percentage is reproducible', () => {
    // Condition 2. sRGB and OkLab give different hexes for the same percentage.
    expect(MIX_COLOUR_SPACE).toBe('srgb-8bit-component-linear-round-half-up')
  })
})

describe('the order-ready screen -- AAA, computed from the source', () => {
  it.each([
    ['ready-ink', 'ready-field', 15.55, 'the page text'],
    ['ready-signal', 'ready-field', 9.73, 'the order number'],
    ['ready-field', 'ready-signal', 9.73, 'the acknowledge button'],
  ])('%s on %s is exactly %s:1 (%s)', (fg, bg, expected) => {
    expect(
      ratioTo2dp(
        resolveOrderReady(fg as keyof typeof ORDER_READY),
        resolveOrderReady(bg as keyof typeof ORDER_READY),
      ),
    ).toBe(expected)
  })

  it('clears AAA on every pair the source declares', () => {
    expect(ORDER_READY_PAIRS).toHaveLength(3)
    for (const pair of ORDER_READY_PAIRS) {
      expect(contrast(resolveOrderReady(pair.fg), resolveOrderReady(pair.bg))).toBeGreaterThanOrEqual(
        pair.bar,
      )
    }
  })

  /**
   * CONDITION 5, and `§5.1`'s own words. ⚠ THIS CASE FAILED ON THE FIRST IMPLEMENTATION
   * and the fix was a redesign, not a patch: the three colours were THEME ROLES, so `paper`
   * resolved to #F2F4F1 in light and #0A1F17 in dark -- the role swap the dark theme needs
   * -- and two of the three flipped. They are now their own `--ready-*` namespace, resolved
   * with NO theme argument.
   */
  it('takes no theme argument at all, which is the mechanism', () => {
    // resolveOrderReady's signature is the proof: there is no theme to pass. A per-theme
    // variant cannot be requested because none exists.
    expect(resolveOrderReady('ready-field')).toBe('#0A1F17')
    expect(resolveOrderReady('ready-ink')).toBe('#F2F4F1')
    expect(resolveOrderReady('ready-signal')).toBe('#3DDC97')
  })

  it('is not a theme role in either theme, so no theme can redeclare it', () => {
    for (const theme of THEMES) {
      const roles = Object.keys(COLOUR_ROLES[theme])
      for (const name of Object.keys(ORDER_READY)) {
        expect(roles).not.toContain(name)
      }
      // And the protected PROPERTY names are not producible from any theme role either.
      const themeProperties = roles.map(colourProperty)
      for (const property of protectedProperties()) {
        expect(themeProperties).not.toContain(property)
      }
    }
  })

  it('is the approved pair, not merely self-consistent', () => {
    expect(Object.keys(ORDER_READY).map((n) => resolveOrderReady(n as keyof typeof ORDER_READY))).toEqual([
      '#0A1F17',
      '#F2F4F1',
      '#3DDC97',
    ])
  })
})

describe('every derived value clears its bar, in BOTH themes -- condition 4', () => {
  /**
   * The generated hexes, asserted exactly. This is the table from the ticket, as coverage:
   * a mix percentage that moved would change a hex and fail here, which is what condition 4
   * asks for in so many words -- "the test must fail when a mix percentage moves".
   */
  it.each([
    ['light', 'border-strong', '#7D8981'],
    ['dark', 'surface', '#1D3028'],
    ['dark', 'surface-2', '#2A3D36'],
    ['dark', 'hairline', '#42524B'],
    ['dark', 'border-strong', '#798580'],
    ['dark', 'ink-mid', '#9DA7A1'],
  ])('%s %s resolves to exactly %s', (theme, role, hex) => {
    expect(resolveColour(theme as Theme, role as ColourRole)).toBe(hex)
  })

  it.each([
    ['light', 'ink', 7, [14.85, 16.43, 13.63]],
    ['dark', 'ink', 7, [15.55, 12.61, 10.44]],
    ['light', 'ink-mid', 4.6, [5.17, 5.72, 4.74]],
    ['dark', 'ink-mid', 4.6, [6.94, 5.63, 4.66]],
    ['light', 'border-strong', 3, [3.29, 3.64, 3.02]],
    ['dark', 'border-strong', 3, [4.49, 3.64, 3.01]],
  ])(
    '%s %s is >= %s:1 against paper, surface and surface-2 -- exactly %s',
    (theme, role, bar, expected) => {
      const fg = resolveColour(theme as Theme, role as ColourRole)
      const measured = BACKGROUNDS.map((bg) =>
        ratioTo2dp(fg, resolveColour(theme as Theme, bg)),
      )
      expect(measured).toEqual(expected)
      for (const ratio of measured) expect(ratio).toBeGreaterThanOrEqual(bar as number)
    },
  )

  it('raised dark ink-mid past the 0.02 margin the approval flagged -- condition 3', () => {
    // The approved candidate was 4.52:1 on the ground, a 0.02 margin over AA that moves
    // with the mix implementation -- this project's F10 shape. It is now chosen against the
    // HARDEST dark background, not the ground alone.
    const inkMid = resolveColour('dark', 'ink-mid')
    const worst = Math.min(
      ...BACKGROUNDS.map((bg) => ratioTo2dp(inkMid, resolveColour('dark', bg))),
    )
    expect(worst).toBe(4.66)
    expect(worst).toBeGreaterThanOrEqual(4.6)
  })
})

describe('hairline is decorative only, and border-strong is why -- condition 6', () => {
  /**
   * WCAG 1.4.11: a border that is an interactive control's only visual boundary needs 3:1
   * against its adjacent colour. This was a NEW FINDING at the dark theme's approval and it
   * bites the LIGHT theme too, so both are pinned here.
   */
  it.each([
    ['light', 1.29],
    ['dark', 2.08],
  ])('%s hairline is %s:1 on its ground -- below 3:1, so never a control boundary', (theme, expected) => {
    const t = theme as Theme
    expect(ratioTo2dp(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBe(expected)
    expect(contrast(resolveColour(t, 'hairline'), resolveColour(t, 'paper'))).toBeLessThan(3)
  })

  it.each([['light'], ['dark']])('%s border-strong clears 3:1 where hairline does not', (theme) => {
    const t = theme as Theme
    for (const bg of BACKGROUNDS) {
      expect(contrast(resolveColour(t, 'border-strong'), resolveColour(t, bg))).toBeGreaterThanOrEqual(3)
    }
  })
})

describe('the restricted tokens the approval singled out', () => {
  it('signal is 1.60:1 on paper, so it is never text on paper', () => {
    expect(ratioTo2dp(APPROVED.signal, APPROVED.paper)).toBe(1.6)
  })

  it('alert-fill fails 4.5:1 as text while alert-ink passes', () => {
    // ⚠ THE APPROVAL COMMENT SAYS 3.44:1 HERE AND THE ARITHMETIC SAYS 3.33:1. Reported
    // rather than adjusted (QRPAGER-15 §2.4). The comment's CONCLUSION -- that the fill
    // fails 4.5:1 as text -- holds either way, which is why this is a recorded discrepancy
    // and not a defect in the palette.
    expect(ratioTo2dp(APPROVED['alert-fill'], APPROVED.paper)).toBe(3.33)
    expect(contrast(APPROVED['alert-fill'], APPROVED.paper)).toBeLessThan(4.5)
    expect(ratioTo2dp(APPROVED['alert-ink'], APPROVED.paper)).toBe(6.73)
  })

  it('ink on paper is 14.85:1, not the 14.86:1 the comment rounded to', () => {
    expect(ratioTo2dp(APPROVED.ink, APPROVED.paper)).toBe(14.85)
  })

  it('white on brand clears AA for a primary button', () => {
    expect(ratioTo2dp(APPROVED.surface, APPROVED.brand)).toBe(6.5)
  })
})

describe('the arithmetic itself', () => {
  it('mixes at the endpoints exactly', () => {
    expect(mix('#000000', '#FFFFFF', 0)).toBe('#000000')
    expect(mix('#000000', '#FFFFFF', 1)).toBe('#FFFFFF')
  })

  it('rounds half UP, which is the stated rule', () => {
    // 0 -> 1 at 50% is 0.5, which half-up takes to 1.
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
    expect(ratioTo2dp('#000000', '#FFFFFF')).toBe(21)
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
    expect(colourProperty('ready-field')).toBe('--color-ready-field')
  })

  it('declares the same role set in both themes, so neither can gain a role alone', () => {
    expect(Object.keys(COLOUR_ROLES.dark)).toEqual(Object.keys(COLOUR_ROLES.light))
  })
})
