import { describe, expect, it } from 'vitest'

import {
  CURRENTCOLOR_DERIVED,
  NON_THEMED,
  analysePage,
  classify,
  compareBaseline,
  deadControls,
  describeColour,
  paintVerdict,
  parseColour,
  validateRecord,
} from '../paint-classify.mjs'

/**
 * ================================================================================
 * THE CLASSIFIER, PROVEN ON SYNTHETIC RECORDS — QRPAGER-49A, ruling 3(a)
 * ================================================================================
 *
 * *"Feed `tools/landing/paint-report.mjs` synthetic records with known values and assert the
 * partition: sentinel, declared non-themed, and other. Cover rgb(), rgba(), hsl(), named
 * colours, color-mix(), and a value one digit off a sentinel. This proves the classifier is
 * correct."* — and, in the same ruling, *"(a) can pass on a reporter wired to nothing"*: this
 * file proves the SORTING and nothing about whether a Blade literal reaches it. That is the
 * revert proof's job (`tools/gate/bin/theme-paint-revert-proof`), and neither substitutes for
 * the other.
 *
 * ⚠ THE SENTINELS HERE ARE THE REAL SIX, spelled once, because a test that sorted a made-up
 * colour proves the sorter handles made-up colours. `apps/api/tests/Support/SentinelTheme.php`
 * is their source; `SentinelThemeTest` holds that file to the studio's six roles.
 */
const THEME = {
  'color.background': '#ff00e4',
  'color.surface': '#00ff95',
  'color.text': '#1400ff',
  'color.muted': '#ff7a00',
  'color.accent': '#00e5ff',
  'color.accent_text': '#7d00ff',
}

const SENTINELS = new Map(Object.entries(THEME).map(([role, hex]) => [hex, role]))

const kind = (raw) => classify(raw, SENTINELS).kind

describe('parseColour reads every notation an engine or an author can produce', () => {
  it.each([
    ['rgb(255, 0, 228)', { r: 255, g: 0, b: 228, a: 1 }],
    ['rgb(255 0 228)', { r: 255, g: 0, b: 228, a: 1 }],
    ['RGB(255, 0, 228)', { r: 255, g: 0, b: 228, a: 1 }],
    ['rgba(255, 0, 228, 1)', { r: 255, g: 0, b: 228, a: 1 }],
    ['rgb(255 0 228 / 50%)', { r: 255, g: 0, b: 228, a: 0.5 }],
    ['rgb(100% 0% 89.4118%)', { r: 255, g: 0, b: 228, a: 1 }],
    ['color(srgb 1 0 0.894118)', { r: 255, g: 0, b: 228, a: 1 }],
    ['color(srgb 1 0 0.894118 / 0.25)', { r: 255, g: 0, b: 228, a: 0.25 }],
    ['#ff00e4', { r: 255, g: 0, b: 228, a: 1 }],
    ['#f0e', { r: 255, g: 0, b: 238, a: 1 }],
    ['#ff00e480', { r: 255, g: 0, b: 228, a: 128 / 255 }],
    ['transparent', { r: 0, g: 0, b: 0, a: 0 }],
    ['  rgb(1,2,3)  ', { r: 1, g: 2, b: 3, a: 1 }],
  ])('%s', (input, expected) => {
    expect(parseColour(input)).toEqual(expected)
  })

  it('reads hsl() in degrees, turns and radians, in comma and space syntax', () => {
    expect(parseColour('hsl(0, 100%, 50%)')).toEqual({ r: 255, g: 0, b: 0, a: 1 })
    expect(parseColour('hsl(120deg 100% 50%)')).toEqual({ r: 0, g: 255, b: 0, a: 1 })
    expect(parseColour('hsl(0.6666667turn 100% 50%)')).toEqual({ r: 0, g: 0, b: 255, a: 1 })
    expect(parseColour('hsl(4.1887902rad 100% 50%)')).toEqual({ r: 0, g: 0, b: 255, a: 1 })
    expect(parseColour('hsla(0, 100%, 50%, 0.5)')).toEqual({ r: 255, g: 0, b: 0, a: 0.5 })
    // a hue outside 0..360 wraps, which is what the spec says
    expect(parseColour('hsl(-120 100% 50%)')).toEqual({ r: 0, g: 0, b: 255, a: 1 })
    // a bare number in the S and L slots is read as a percentage
    expect(parseColour('hsl(0 100 50)')).toEqual({ r: 255, g: 0, b: 0, a: 1 })
  })

  it('returns null — never a guess — for what it cannot evaluate to sRGB', () => {
    for (const unreadable of [
      'red',
      'rebeccapurple',
      'currentcolor',
      'oklch(0.7 0.2 330)',
      'lab(50% 40 59.5)',
      'color(display-p3 1 0 0.9)',
      'color-mix(in srgb, #ff00e4, #ff00e4)',
      'rgb(255, 0)',
      'rgb(255, 0, 228, 1, 2)',
      'rgb(none 0 0)',
      'rgb(255, 0, banana)',
      'hsl(banana 100% 50%)',
      'hsl(0, 100%, banana)',
      'rgba(1, 2, 3, banana)',
      '#ff00e',
      '#ff00e4f',
      '',
      'none',
      12,
      null,
      undefined,
    ]) {
      expect(parseColour(unreadable), String(unreadable)).toBeNull()
    }
  })

  it('clamps an out-of-gamut channel rather than wrapping it', () => {
    // clamped, and MARKED: a channel that had to be forced into range is not what an engine returned
    expect(parseColour('rgb(300, -5, 228)')).toEqual({ r: 255, g: 0, b: 228, a: 1, inexact: true })
    expect(parseColour('rgb(255 0 228 / 7)')).toEqual({ r: 255, g: 0, b: 228, a: 1 })
  })

  it('refuses a channel that is a percentage where none is allowed', () => {
    expect(parseColour('hsl(50%, 100%, 50%)')).toBeNull()
  })
})

describe('describeColour', () => {
  it('writes an opaque colour as hex and anything else as rgba()', () => {
    expect(describeColour({ r: 255, g: 0, b: 228, a: 1 })).toBe('#ff00e4')
    expect(describeColour({ r: 0, g: 0, b: 0, a: 0 })).toBe('rgba(0, 0, 0, 0)')
    expect(describeColour({ r: 255, g: 0, b: 228, a: 0.5 })).toBe('rgba(255, 0, 228, 0.5)')
    expect(describeColour({ r: 1, g: 2, b: 3, a: 0.123456 })).toBe('rgba(1, 2, 3, 0.123456)')
    // QA L6: a near-opaque alpha must never print as 1, or it reads as opaque and merges groups
    expect(describeColour({ r: 255, g: 0, b: 228, a: 0.9996 })).toBe('rgba(255, 0, 228, 0.9996)')
    expect(describeColour({ r: 255, g: 0, b: 228, a: 0.99999999 })).toBe(
      'rgba(255, 0, 228, 0.999999)',
    )
  })
})

describe('classify — (a) sentinel, (b) declared non-themed, (c) other', () => {
  describe('(a) every sentinel is recognised in every notation that can spell it', () => {
    it.each(Object.entries(THEME))('%s = %s', (name, hex) => {
      const r = Number.parseInt(hex.slice(1, 3), 16)
      const g = Number.parseInt(hex.slice(3, 5), 16)
      const b = Number.parseInt(hex.slice(5, 7), 16)

      // The engine's own form first: it is the only one a real run ever contains.
      for (const spelling of [
        `rgb(${String(r)}, ${String(g)}, ${String(b)})`,
        `rgb(${String(r)} ${String(g)} ${String(b)})`,
        `rgba(${String(r)}, ${String(g)}, ${String(b)}, 1)`,
        `rgb(${String(r)} ${String(g)} ${String(b)} / 100%)`,
        // what an engine serialises a color-mix() RESULT as — a mix that lands exactly on a
        // sentinel is a sentinel
        `color(srgb ${(r / 255).toFixed(6)} ${(g / 255).toFixed(6)} ${(b / 255).toFixed(6)})`,
        hex,
        hex.toUpperCase(),
      ]) {
        expect(classify(spelling, SENTINELS), spelling).toMatchObject({
          kind: 'sentinel',
          role: name,
          token: hex,
        })
      }
    })

    it('recognises a sentinel written as hsl(), computed independently of the module', () => {
      // rgb -> hsl is written out here rather than imported, so a mistake in the module's
      // hsl -> rgb cannot be agreed with by a copy of itself.
      const toHsl = (hex) => {
        const [r, g, b] = [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16) / 255)
        const max = Math.max(r, g, b)
        const min = Math.min(r, g, b)
        const l = (max + min) / 2
        const d = max - min

        if (d === 0) return [0, 0, l * 100]

        const s = d / (1 - Math.abs(2 * l - 1))
        const h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4

        return [(h * 60 + 360) % 360, s * 100, l * 100]
      }

      for (const [name, hex] of Object.entries(THEME)) {
        const [h, s, l] = toHsl(hex)

        expect(
          classify(`hsl(${String(h)}, ${String(s)}%, ${String(l)}%)`, SENTINELS),
        ).toMatchObject({
          kind: 'sentinel',
          role: name,
        })
      }
    })
  })

  describe('(c) a value ONE DIGIT OFF a sentinel is not that sentinel', () => {
    it.each([
      ['one blue digit up', 'rgb(255, 0, 229)'],
      ['one blue digit down', 'rgb(255, 0, 227)'],
      ['one red digit down', 'rgb(254, 0, 228)'],
      ['one green digit up', 'rgb(255, 1, 228)'],
      ['hex one digit off', '#ff00e5'],
      ['the same colour at 99% alpha', 'rgba(255, 0, 228, 0.99)'],
      ['a mix that lands one channel off', 'color(srgb 1 0 0.89)'],
      ['a mix a whole channel step off', 'color(srgb 1 0 0.9)'],
    ])('%s', (_label, spelling) => {
      expect(classify(spelling, SENTINELS)).toMatchObject({ kind: 'other' })
    })

    it('reports the near-miss as what it is, with the sorted token', () => {
      expect(classify('rgb(255, 0, 229)', SENTINELS)).toMatchObject({
        kind: 'other',
        token: '#ff00e5',
        why: 'an opaque colour that is not one of the six published sentinels',
      })
      expect(classify('rgba(255, 0, 228, 0.99)', SENTINELS)).toMatchObject({
        kind: 'other',
        token: 'rgba(255, 0, 228, 0.99)',
        why: 'a colour with alpha that is not the declared non-themed member',
      })
    })

    it('does not let a sentinel hide behind a different alpha encoding', () => {
      expect(kind('rgb(255 0 228 / 0.5)')).toBe('other')
      expect(kind('rgb(255 0 228 / 50%)')).toBe('other')
    })
  })

  describe('(c) a literal an author could write is a finding, however ordinary it looks', () => {
    it.each([
      ['a named colour', 'red'],
      ['another named colour', 'rebeccapurple'],
      ['black, which is what Phase 1 found', 'rgb(0, 0, 0)'],
      ['black as hex', '#000000'],
      ['white', 'rgb(255, 255, 255)'],
      ['hsl()', 'hsl(210, 50%, 40%)'],
      ['a colour in a space this cannot evaluate', 'oklch(0.7 0.2 330)'],
      ['a display-p3 colour', 'color(display-p3 1 0 0.9)'],
      ['an UNEVALUATED color-mix(), which no engine returns', 'color-mix(in srgb, red, blue)'],
      ['a mix that is not a sentinel', 'color(srgb 0.5 0 0.5)'],
    ])('%s', (_label, spelling) => {
      expect(kind(spelling)).toBe('other')
    })

    it('keeps the raw text of something it cannot evaluate, lower-cased, so a human can read it', () => {
      expect(classify('OKLCH(0.7 0.2 330)', SENTINELS)).toEqual({
        kind: 'other',
        token: 'oklch(0.7 0.2 330)',
        why: 'not a colour this classifier can evaluate to sRGB',
      })
      expect(classify(undefined, SENTINELS)).toMatchObject({ kind: 'other', token: 'undefined' })
    })
  })

  describe('(b) the declared non-themed set — keyed on what an engine returns', () => {
    it('has exactly one member, and it is the engine’s transparent', () => {
      expect(Object.keys(NON_THEMED)).toEqual(['rgba(0, 0, 0, 0)'])
    })

    it.each(['rgba(0, 0, 0, 0)', 'rgb(0 0 0 / 0)', 'rgb(0 0 0 / 0%)', 'transparent', '#00000000'])(
      '%s is the member',
      (spelling) => {
        expect(classify(spelling, SENTINELS)).toEqual({
          kind: 'nonThemed',
          member: 'rgba(0, 0, 0, 0)',
          token: 'rgba(0, 0, 0, 0)',
        })
      },
    )

    it('does NOT admit currentcolor — an engine never returns it, so seeing it is a finding', () => {
      expect(kind('currentcolor')).toBe('other')
      expect(kind('currentColor')).toBe('other')
      expect(Object.keys(NON_THEMED)).not.toContain('currentcolor')
    })

    it('does NOT admit #000000, which Phase 1 was tempted by and Muhammad refused', () => {
      expect(kind('#000000')).toBe('other')
      expect(kind('rgb(0, 0, 0)')).toBe('other')
    })

    it('is STRICTER than "any zero-alpha colour": an invisible literal is still a literal', () => {
      expect(kind('rgba(1, 2, 3, 0)')).toBe('other')
      // …and that includes a sentinel's own channels at zero alpha, which is not the sentinel
      expect(kind('rgba(255, 0, 228, 0)')).toBe('other')
      expect(classify('rgba(1, 2, 3, 0)', SENTINELS)).toMatchObject({
        why: 'a colour with alpha that is not the declared non-themed member',
      })
    })
  })

  it('never reports a sentinel for something that merely shares a hex prefix', () => {
    expect(kind('#ff00e4ff')).toBe('sentinel')
    expect(kind('#ff00e4fe')).toBe('other')
  })
})

/** A sample as the collector writes it, with every field a test might not care about defaulted. */
function sample(overrides) {
  return {
    el: 0,
    path: 'html>body>p',
    shape: 'p',
    pseudo: null,
    property: 'color',
    named: true,
    value: 'rgb(20, 0, 255)',
    colour: 'rgb(20, 0, 255)',
    box: { w: 100, h: 24 },
    text: 'hello',
    facts: { hasText: true },
    ...overrides,
  }
}

describe('paintVerdict — can a recorded value reach a pixel?', () => {
  const paints = (overrides) => paintVerdict(sample(overrides)).paints
  const reason = (overrides) => paintVerdict(sample(overrides)).reason

  it('is false for an element with no box, whatever the value', () => {
    expect(reason({ box: { w: 0, h: 10 } })).toBe('the element has no box')
    expect(paints({ box: { w: 100, h: 0 } })).toBe(false)
    expect(paints({ box: { w: 0, h: 0 } })).toBe(false)
  })

  it('is false for a fully transparent value of any property', () => {
    expect(reason({ colour: 'rgba(0, 0, 0, 0)' })).toBe('the value is fully transparent')
    expect(paints({ property: 'background-color', colour: 'rgba(0, 0, 0, 0)' })).toBe(false)
  })

  it('needs an element to own a text node before its text colour paints', () => {
    expect(paints({ property: 'color', facts: { hasText: true } })).toBe(true)
    expect(reason({ property: 'color', facts: { hasText: false } })).toBe(
      'the element carries no text node of its own',
    )

    for (const property of ['-webkit-text-fill-color', 'text-shadow']) {
      expect(paints({ property, facts: { hasText: true } })).toBe(true)
      expect(paints({ property, facts: { hasText: false } })).toBe(false)
      expect(paints({ property, facts: {} })).toBe(false)
    }
  })

  it('paints a background on any element with a box', () => {
    expect(paints({ property: 'background-color', facts: {} })).toBe(true)
    expect(paints({ property: 'background-image', facts: {} })).toBe(true)
    expect(reason({ property: 'background-color', facts: {} })).toBe(
      'the element has a box to fill',
    )
  })

  it.each(['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'])(
    '%s paints only when the border is drawn',
    (property) => {
      const side = property.split('-')[1]

      expect(paints({ property, facts: { width: 1, style: 'solid' } })).toBe(true)
      expect(reason({ property, facts: { width: 1, style: 'solid' } })).toBe(
        `border-${side}: 1px solid`,
      )
      expect(paints({ property, facts: { width: 0, style: 'solid' } })).toBe(false)
      expect(paints({ property, facts: { width: 1, style: 'none' } })).toBe(false)
      expect(paints({ property, facts: { width: 1, style: 'hidden' } })).toBe(false)
      expect(reason({ property, facts: { width: 0, style: 'none' } })).toBe(
        `border-${side} is 0px none`,
      )
      // no facts at all is "not drawn", never "drawn"
      expect(paints({ property, facts: {} })).toBe(false)
    },
  )

  it('paints an outline only when one is drawn — which is why the focus ring reads as unpainted', () => {
    expect(paints({ property: 'outline-color', facts: { width: 3, style: 'solid' } })).toBe(true)
    expect(reason({ property: 'outline-color', facts: { width: 3, style: 'none' } })).toBe(
      'outline is 3px none in this state',
    )
    expect(paints({ property: 'outline-color', facts: { width: 0, style: 'solid' } })).toBe(false)
  })

  it('paints a text decoration only when there is a line', () => {
    expect(paints({ property: 'text-decoration-color', facts: { line: 'underline' } })).toBe(true)
    expect(reason({ property: 'text-decoration-color', facts: { line: 'underline' } })).toBe(
      'text-decoration-line: underline',
    )
    expect(paints({ property: 'text-decoration-color', facts: { line: 'none' } })).toBe(false)
    expect(paints({ property: 'text-decoration-color', facts: {} })).toBe(false)
  })

  it('paints a column rule only when one exists', () => {
    expect(paints({ property: 'column-rule-color', facts: { width: 2, style: 'solid' } })).toBe(
      true,
    )
    expect(reason({ property: 'column-rule-color', facts: { width: 2, style: 'solid' } })).toBe(
      'column-rule: 2px',
    )
    expect(paints({ property: 'column-rule-color', facts: { width: 0, style: 'none' } })).toBe(
      false,
    )
    expect(reason({ property: 'column-rule-color', facts: { width: 0, style: 'none' } })).toBe(
      'there is no column rule',
    )
  })

  it('paints a text emphasis only when a style is set', () => {
    expect(paints({ property: 'text-emphasis-color', facts: { style: 'filled dot' } })).toBe(true)
    expect(paints({ property: 'text-emphasis-color', facts: { style: 'none' } })).toBe(false)
    expect(paints({ property: 'text-emphasis-color', facts: {} })).toBe(false)
  })

  it('never paints a caret in the resting state', () => {
    expect(paints({ property: 'caret-color', facts: {} })).toBe(false)
    expect(reason({ property: 'caret-color', facts: {} })).toBe(
      'a caret needs an editable element in focus',
    )
  })

  it('paints shadows, SVG fills and strokes, and ASSUMES the rest does', () => {
    expect(paints({ property: 'box-shadow', facts: {} })).toBe(true)
    expect(paints({ property: 'fill', facts: {} })).toBe(true)
    expect(paints({ property: 'stroke', facts: {} })).toBe(true)
    expect(reason({ property: 'something-new', facts: {} })).toBe('assumed to paint')
  })

  it('is not fooled by an unparseable colour: it is judged on geometry, not on colour', () => {
    expect(paints({ colour: 'oklch(0.7 0.2 330)', property: 'background-color', facts: {} })).toBe(
      true,
    )
  })

  it('treats a missing facts object as no facts rather than throwing', () => {
    const bare = sample({ property: 'border-top-color' })

    delete bare.facts

    expect(paintVerdict(bare).paints).toBe(false)
  })
})

describe('exactness — QA L4, L5, L6: what the classifier will not round into a match', () => {
  it('L4: an alpha that is not EXACTLY zero is not the non-themed member', () => {
    expect(kind('rgba(0, 0, 0, 0.0004)')).toBe('other')
    expect(kind('rgba(0, 0, 0, 0.0000001)')).toBe('other')
    expect(classify('rgba(0, 0, 0, 0.0004)', SENTINELS).token).toBe('rgba(0, 0, 0, 0.0004)')
    expect(kind('rgba(0, 0, 0, 0)')).toBe('nonThemed')
  })

  it('L5: a fractional channel is not the sentinel it rounds to', () => {
    expect(kind('rgb(254.5, 0, 228)')).toBe('other')
    expect(kind('rgb(255, 0, 228.4)')).toBe('other')
    expect(kind('color(srgb 1 0 0.8959)')).toBe('other')
    expect(classify('rgb(254.5, 0, 228)', SENTINELS).why).toBe(
      'a channel that is not an exact 8-bit value, which no engine returns',
    )
    // …while the engine's own colour(srgb) form, which is an integer over 255 to its printed
    // precision, still IS the sentinel
    expect(kind('color(srgb 1 0 0.894118)')).toBe('sentinel')
    expect(kind('rgb(255.004, 0, 228)')).toBe('sentinel')
  })

  it('L5: an out-of-gamut channel that was clamped is not the sentinel it clamps to', () => {
    expect(kind('rgb(300, 0, 228)')).toBe('other')
    expect(kind('rgb(255, -4, 228)')).toBe('other')
  })

  it('L6: a near-opaque alpha is reported with its alpha, never as `1`', () => {
    expect(classify('rgba(255, 0, 228, 0.9996)', SENTINELS)).toMatchObject({
      kind: 'other',
      token: 'rgba(255, 0, 228, 0.9996)',
    })
    expect(classify('rgba(255, 0, 228, 0.9996)', SENTINELS).token).not.toBe('rgba(255, 0, 228, 1)')
    // and two different alphas are two different tokens, so two groups
    expect(classify('rgba(255, 0, 228, 0.9996)', SENTINELS).token).not.toBe(
      classify('rgba(255, 0, 228, 0.9994)', SENTINELS).token,
    )
  })
})

describe('paintVerdict — measured render facts, not a box-size threshold', () => {
  const verdict = (overrides) => paintVerdict(sample(overrides))

  it('M5: a 1px hairline paints — the old 2px threshold called it unpainted', () => {
    const bar = { property: 'background-color', facts: {}, box: { w: 300, h: 1 } }

    expect(verdict(bar).paints).toBe(true)
    expect(verdict({ ...bar, box: { w: 1, h: 300 } }).paints).toBe(true)
    expect(verdict({ ...bar, box: { w: 1, h: 1 } }).paints).toBe(true)
  })

  it('still excludes the visually-hidden 1px box, by what actually hides it', () => {
    // `.sr`: position:absolute; 1px by 1px; overflow:hidden; clip-path:inset(50%)
    const hidden = { box: { w: 1, h: 1 }, render: { clipPath: 'inset(50%)' } }

    expect(verdict(hidden).paints).toBe(false)
    expect(verdict(hidden).reason).toBe('clipped by clip-path: inset(50%)')
    expect(verdict({ box: { w: 1, h: 1 }, render: { clipPath: 'none' } }).paints).toBe(true)
  })

  it('M4: visibility, effective opacity, clip and a zero font size each stop a value painting', () => {
    expect(verdict({ render: { visibility: 'hidden' } })).toEqual({
      paints: false,
      reason: 'visibility is hidden',
    })
    expect(verdict({ render: { visibility: 'collapse' } }).paints).toBe(false)
    expect(verdict({ render: { visibility: 'visible' } }).paints).toBe(true)

    expect(verdict({ render: { opacity: 0 } })).toEqual({
      paints: false,
      reason: 'the element’s effective opacity is 0',
    })
    expect(verdict({ render: { opacity: 0.01 } }).paints).toBe(true)
    expect(verdict({ render: { opacity: 1 } }).paints).toBe(true)

    expect(verdict({ render: { clip: 'rect(0px, 0px, 0px, 0px)' } }).paints).toBe(false)
    expect(verdict({ render: { clip: 'auto' } }).paints).toBe(true)

    // font size matters to TEXT paint only
    expect(verdict({ render: { fontSize: 0 } })).toEqual({
      paints: false,
      reason: 'the font size is 0',
    })
    expect(
      verdict({ property: 'background-color', facts: {}, render: { fontSize: 0 } }).paints,
    ).toBe(true)
    expect(verdict({ render: { fontSize: 16 } }).paints).toBe(true)
  })

  it('M4: a generated box is not measured, so it counts as unpainted rather than inheriting its parent’s size', () => {
    const generated = {
      property: 'background-color',
      facts: {},
      pseudo: '::before',
      box: { w: 300, h: 40 },
    }

    expect(verdict(generated).paints).toBe(false)
    expect(verdict(generated).reason).toBe(
      'a generated box’s own size is not measured, so it counts as unpainted',
    )
    expect(verdict({ ...generated, pseudo: null }).paints).toBe(true)
  })

  it('a text stroke paints only when the text has a stroke width', () => {
    expect(
      verdict({ property: '-webkit-text-stroke-color', facts: { hasText: true, width: 1 } }).paints,
    ).toBe(true)
    expect(
      verdict({ property: '-webkit-text-stroke-color', facts: { hasText: true, width: 0 } }).paints,
    ).toBe(false)
    expect(
      verdict({ property: '-webkit-text-stroke-color', facts: { hasText: false, width: 1 } })
        .paints,
    ).toBe(false)
    expect(
      verdict({
        property: '-webkit-text-stroke-color',
        facts: { hasText: true, width: 1 },
        render: { fontSize: 0 },
      }).paints,
    ).toBe(false)
  })

  it('treats a sample with no render facts as visible, so a record without them is not silently all-dead', () => {
    expect(paintVerdict(sample({})).paints).toBe(true)
  })
})

describe('analysePage', () => {
  const page = (samples) => ({ samples })

  it('counts every value into exactly one of the three, and attributes each sentinel to a role', () => {
    const analysis = analysePage(
      page([
        sample({ el: 1, colour: 'rgb(20, 0, 255)' }),
        sample({ el: 2, property: 'background-color', colour: 'rgb(255, 0, 228)', facts: {} }),
        sample({ el: 3, property: 'background-color', colour: 'rgba(0, 0, 0, 0)', facts: {} }),
        sample({ el: 4, property: 'background-color', colour: 'rgb(1, 2, 3)', facts: {} }),
      ]),
      SENTINELS,
    )

    expect(analysis.counts).toEqual({ samples: 4, sentinel: 2, nonThemed: 1, other: 1, derived: 0 })
    expect(analysis.roles['color.text']).toEqual({ total: 1, painting: 1 })
    expect(analysis.roles['color.background']).toEqual({ total: 1, painting: 1 })
    expect(analysis.roles['color.surface']).toEqual({ total: 0, painting: 0 })
    expect(analysis.findings).toHaveLength(1)
    expect(analysis.findings[0]).toMatchObject({ value: '#010203', property: 'background-color' })
  })

  it('counts a sentinel that cannot paint as PRESENT but not PAINTING', () => {
    const analysis = analysePage(
      page([
        // a sentinel on an element that owns no text: the value is computed and never drawn
        sample({ el: 1, colour: 'rgb(255, 122, 0)', facts: { hasText: false } }),
        // and an element too small to see
        sample({
          el: 2,
          property: 'background-color',
          colour: 'rgb(255, 122, 0)',
          box: { w: 0, h: 0 },
          facts: {},
        }),
      ]),
      SENTINELS,
    )

    expect(analysis.roles['color.muted']).toEqual({ total: 2, painting: 0 })
  })

  it('collapses the eleven properties of ONE root defect into one finding with consequences', () => {
    const root = { el: 0, shape: 'html', path: 'html', text: '', box: { w: 390, h: 4000 } }
    const black = 'rgb(0, 0, 0)'
    const samples = [
      sample({ ...root, property: 'color', colour: black, facts: { hasText: false } }),
      ...CURRENTCOLOR_DERIVED.map((property) =>
        sample({
          ...root,
          property,
          colour: black,
          facts: { width: 0, style: 'none', line: 'none' },
        }),
      ),
    ]

    const analysis = analysePage(page(samples), SENTINELS)

    expect(CURRENTCOLOR_DERIVED).toHaveLength(11)
    expect(analysis.counts).toMatchObject({
      samples: CURRENTCOLOR_DERIVED.length + 1,
      other: CURRENTCOLOR_DERIVED.length + 1,
      derived: CURRENTCOLOR_DERIVED.length,
    })
    expect(analysis.findings).toHaveLength(1)
    expect(analysis.findings[0]).toMatchObject({ property: 'color', value: '#000000', count: 1 })
    expect(analysis.findings[0].derived).toEqual([...CURRENTCOLOR_DERIVED].sort())
  })

  it('does NOT collapse a derived property that has diverged from its own color', () => {
    // the root's colour is fixed (a sentinel) and ONE border is still black: that border is a
    // finding in its own right — which is the moment it should stop being hidden.
    const root = { el: 0, shape: 'html', path: 'html', text: '', box: { w: 390, h: 4000 } }
    const analysis = analysePage(
      page([
        sample({
          ...root,
          property: 'color',
          colour: 'rgb(20, 0, 255)',
          facts: { hasText: false },
        }),
        sample({
          ...root,
          property: 'border-top-color',
          colour: 'rgb(0, 0, 0)',
          facts: { width: 1, style: 'solid' },
        }),
        sample({
          ...root,
          property: 'outline-color',
          colour: 'rgb(20, 0, 255)',
          facts: { width: 0, style: 'none' },
        }),
      ]),
      SENTINELS,
    )

    expect(analysis.counts).toMatchObject({ sentinel: 2, other: 1, derived: 0 })
    expect(analysis.findings).toHaveLength(1)
    expect(analysis.findings[0]).toMatchObject({
      property: 'border-top-color',
      value: '#000000',
      paints: true,
      derived: [],
    })
  })

  it('collapses per ELEMENT: two elements with the same defect are one group, two with different ones are not', () => {
    const a = { el: 1, shape: 'a', path: 'html>a', text: 'x' }
    const b = { el: 2, shape: 'b', path: 'html>b', text: 'y' }
    const analysis = analysePage(
      page([
        sample({ ...a, property: 'color', colour: 'rgb(0, 0, 0)' }),
        sample({ ...a, property: 'caret-color', colour: 'rgb(0, 0, 0)', facts: {} }),
        // `b` has its own colour sentinel but a black outline — NOT a consequence of anything
        sample({ ...b, property: 'color', colour: 'rgb(20, 0, 255)' }),
        sample({
          ...b,
          property: 'outline-color',
          colour: 'rgb(0, 0, 0)',
          facts: { width: 3, style: 'solid' },
        }),
      ]),
      SENTINELS,
    )

    expect(analysis.findings.map((f) => `${f.shape}:${f.property}`).sort()).toEqual([
      'a:color',
      'b:outline-color',
    ])
    expect(analysis.findings.find((f) => f.shape === 'a').derived).toEqual(['caret-color'])
  })

  it('merges identical findings across elements into one counted line', () => {
    const analysis = analysePage(
      page([
        sample({
          el: 1,
          shape: 'li.card',
          property: 'background-color',
          colour: 'rgb(1, 2, 3)',
          facts: {},
        }),
        sample({
          el: 2,
          shape: 'li.card',
          property: 'background-color',
          colour: 'rgb(1, 2, 3)',
          facts: {},
        }),
        sample({
          el: 3,
          shape: 'li.card',
          property: 'background-color',
          colour: 'rgb(1, 2, 3)',
          facts: {},
        }),
      ]),
      SENTINELS,
    )

    expect(analysis.findings).toHaveLength(1)
    expect(analysis.findings[0].count).toBe(3)
  })

  it('keeps a pseudo-element apart from the element that generates it', () => {
    const analysis = analysePage(
      page([
        sample({ el: 5, property: 'color', colour: 'rgb(0, 0, 0)' }),
        sample({
          el: 5,
          pseudo: '::before',
          property: 'caret-color',
          colour: 'rgb(0, 0, 0)',
          facts: {},
        }),
      ]),
      SENTINELS,
    )

    // the ::before's caret is not a consequence of the element's own colour: different box
    expect(analysis.findings.map((f) => f.property).sort()).toEqual(['caret-color', 'color'])
  })

  it('lists no consequences on a finding that is not a source colour', () => {
    const analysis = analysePage(
      page([sample({ el: 1, property: 'background-color', colour: 'rgb(1, 2, 3)', facts: {} })]),
      SENTINELS,
    )

    expect(analysis.findings[0].derived).toEqual([])
  })

  it('reports an unevaluable value as a finding carrying its raw text', () => {
    const analysis = analysePage(
      page([
        sample({ el: 1, property: 'background-color', colour: 'OKLCH(0.7 0.2 330)', facts: {} }),
      ]),
      SENTINELS,
    )

    expect(analysis.findings[0]).toMatchObject({
      value: 'oklch(0.7 0.2 330)',
      why: 'not a colour this classifier can evaluate to sRGB',
    })
  })
})

describe('deadControls', () => {
  const roles = ['color.a', 'color.b']
  const cell = (total, painting) => ({ total, painting })

  it('names a role that paints at neither width, and one that appears nowhere', () => {
    const dead = deadControls(
      {
        alpha: {
          390: { 'color.a': cell(3, 3), 'color.b': cell(2, 0) },
          1280: { 'color.a': cell(3, 3), 'color.b': cell(2, 0) },
        },
        beta: {
          390: { 'color.a': cell(0, 0), 'color.b': cell(1, 1) },
          1280: { 'color.a': cell(0, 0), 'color.b': cell(1, 1) },
        },
      },
      roles,
    )

    expect(dead).toEqual([
      { template: 'alpha', role: 'color.b', total: 4 },
      { template: 'beta', role: 'color.a', total: 0 },
    ])
  })

  it('counts a role painted at ONE width as painted — a rule inside a media query is a real use', () => {
    const dead = deadControls(
      {
        alpha: {
          390: { 'color.a': cell(1, 0), 'color.b': cell(1, 1) },
          1280: { 'color.a': cell(1, 1), 'color.b': cell(1, 1) },
        },
      },
      roles,
    )

    expect(dead).toEqual([])
  })

  it('treats a role missing from a record as not painting rather than throwing', () => {
    expect(deadControls({ alpha: { 390: {}, 1280: {} } }, ['color.a'])).toEqual([
      { template: 'alpha', role: 'color.a', total: 0 },
    ])
  })
})

describe('compareBaseline — the three ways the frozen allowance is red', () => {
  const templates = ['rail', 'ember']
  const roles = ['color.surface', 'color.accent_text']
  const baseline = { dead: [{ template: 'rail', role: 'color.surface' }] }

  it('is clean when the dead controls are exactly the baseline', () => {
    expect(
      compareBaseline([{ template: 'rail', role: 'color.surface' }], baseline, templates, roles),
    ).toEqual({
      unexpected: [],
      stale: [],
      gone: [],
    })
  })

  it('is RED on a NEW dead control that is not baselined', () => {
    const verdict = compareBaseline(
      [
        { template: 'rail', role: 'color.surface' },
        { template: 'ember', role: 'color.accent_text' },
      ],
      baseline,
      templates,
      roles,
    )

    expect(verdict.unexpected).toEqual([{ template: 'ember', role: 'color.accent_text' }])
    expect(verdict.stale).toEqual([])
  })

  it('is RED on a STALE entry — a baselined pair that now paints', () => {
    const verdict = compareBaseline([], baseline, templates, roles)

    expect(verdict.stale).toEqual([{ template: 'rail', role: 'color.surface' }])
    expect(verdict.unexpected).toEqual([])
  })

  it('is RED on an entry whose TEMPLATE is no longer enumerated — which is QRPAGER-49’s acceptance criterion', () => {
    const verdict = compareBaseline([], baseline, ['tile', 'blaze'], roles)

    expect(verdict.gone).toEqual([{ template: 'rail', role: 'color.surface' }])
    // a gone entry is not ALSO stale: one cause, one line
    expect(verdict.stale).toEqual([])
  })

  it('is RED on an entry whose ROLE is no longer one of the roles', () => {
    expect(compareBaseline([], baseline, templates, ['color.accent_text']).gone).toEqual([
      { template: 'rail', role: 'color.surface' },
    ])
  })

  /*
   * QRPAGER-49B, item 3: *"an entry naming a template or role absent from LandingTemplate must
   * redden, never be skipped."* The cases above name a template or a role that is absent; these
   * are the shapes an entry can be malformed in, each of which is a way to be skipped by a check
   * that only looks at entries it understands. The browser-level proof is
   * `tools/gate/bin/theme-paint-baseline-proof`; these are its cheap siblings.
   */
  it('is RED on a malformed entry — a missing, null or differently-cased name is GONE, never skipped', () => {
    for (const entry of [
      { role: 'color.surface' },
      { template: 'rail' },
      { template: null, role: 'color.surface' },
      { template: 'rail', role: undefined },
      { template: 'Rail', role: 'color.surface' },
      { template: 'rail', role: 'COLOR.SURFACE' },
      {},
    ]) {
      const verdict = compareBaseline([], { dead: [entry] }, templates, roles)

      expect(verdict.gone, JSON.stringify(entry)).toEqual([entry])
      expect(verdict.stale, JSON.stringify(entry)).toEqual([])
      expect(verdict.unexpected, JSON.stringify(entry)).toEqual([])
    }
  })

  it('is RED on EVERY entry when the harness enumerated nothing — an empty list is not a pass', () => {
    const wide = {
      dead: [
        { template: 'rail', role: 'color.surface' },
        { template: 'ember', role: 'color.accent_text' },
      ],
    }

    expect(compareBaseline([], wide, [], roles).gone).toEqual(wide.dead)
    expect(compareBaseline([], wide, templates, []).gone).toEqual(wide.dead)
  })

  it('puts every entry in exactly ONE bucket, so a violation is counted once', () => {
    const mixed = {
      dead: [
        { template: 'rail', role: 'color.surface' },
        { template: 'ember', role: 'color.accent_text' },
        { template: 'gazebo', role: 'color.surface' },
      ],
    }
    const verdict = compareBaseline(
      [{ template: 'ember', role: 'color.accent_text' }],
      mixed,
      templates,
      roles,
    )

    expect(verdict.stale).toEqual([{ template: 'rail', role: 'color.surface' }])
    expect(verdict.gone).toEqual([{ template: 'gazebo', role: 'color.surface' }])
    expect(verdict.unexpected).toEqual([])
  })

  it('treats no baseline at all as "nothing is allowed dead"', () => {
    expect(
      compareBaseline([{ template: 'rail', role: 'color.surface' }], null, templates, roles)
        .unexpected,
    ).toHaveLength(1)
    expect(compareBaseline([], {}, templates, roles)).toEqual({
      unexpected: [],
      stale: [],
      gone: [],
    })
  })
})

describe('validateRecord — a record that cannot be trusted is not evidence', () => {
  const good = {
    template: 'rail',
    requestedWidth: 390,
    innerWidth: 390,
    browser: { name: 'chromium', version: '142.0.7444.0' },
    samples: [sample({})],
  }
  const expected = { template: 'rail', width: 390 }

  it('accepts a sound record', () => {
    expect(validateRecord(good, expected)).toEqual([])
  })

  it('refuses a page whose window.innerWidth is not the width the claim names', () => {
    const problems = validateRecord({ ...good, innerWidth: 360 }, expected)

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('window.innerWidth is 360, not 390')
  })

  it('refuses a record that was not asked for this width, or is for another template', () => {
    expect(validateRecord({ ...good, requestedWidth: 1280 }, expected)[0]).toContain(
      'asked for 1280',
    )
    expect(validateRecord({ ...good, template: 'tile' }, expected)[0]).toContain('says it is tile')
  })

  it('refuses a record that cannot say which browser produced it', () => {
    expect(validateRecord({ ...good, browser: undefined }, expected)[0]).toContain(
      'which browser build',
    )
    expect(
      validateRecord({ ...good, browser: { name: 'chromium', version: '' } }, expected),
    ).toHaveLength(1)
  })

  it('refuses a record with no samples — an empty page is not a clean page', () => {
    expect(validateRecord({ ...good, samples: [] }, expected)[0]).toContain('no samples')
    expect(validateRecord({ ...good, samples: undefined }, expected)[0]).toContain('no samples')
  })

  it('reports every problem, not only the first', () => {
    expect(
      validateRecord({ template: 'x', innerWidth: 1 }, expected).length,
    ).toBeGreaterThanOrEqual(4)
  })
})
