import { describe, expect, it } from 'vitest'
import { maskBladeCss, styleAttributeCount } from '../blade-css.mjs'
import { scanSource } from '../bin/scan-css.mjs'
import {
  ALLOWED_COLOUR_KEYWORDS,
  COLOUR_KEYWORDS,
  NAMED_COLOURS,
  SYSTEM_COLOURS,
  computedNonThemedMembers,
  isAllowedColourKeyword,
  isLiteralColourKeyword,
  literalColourKeywords,
} from '../colour-keywords.mjs'
import { NON_THEMED } from '../../landing/paint-classify.mjs'
import { colourValueIsAcceptable } from '../patterns.mjs'

/*
 * QRPAGER-49B, item 2 — the allowlist is ONE definition, and the named-colour hole is closed.
 * Counts are exact: the hole this closes was a closed list of 26 names that nobody counted.
 */

const found = (css) => literalColourKeywords(css).map((hit) => hit.keyword)

describe('the one definition', () => {
  it('is small and stated: transparent, currentcolor and the five CSS-wide keywords', () => {
    expect(Object.keys(ALLOWED_COLOUR_KEYWORDS).sort()).toEqual([
      'currentcolor',
      'inherit',
      'initial',
      'revert',
      'revert-layer',
      'transparent',
      'unset',
    ])
  })

  it('names all 148 CSS Color 4 colours (CSS Color 4 §6.1, checked against `color-name`)', () => {
    expect(NAMED_COLOURS).toHaveLength(148)
    expect(new Set(NAMED_COLOURS).size).toBe(148)
    // Spot checks across the alphabet, including the ones the old 26-name list never had.
    for (const name of [
      'aliceblue',
      'rebeccapurple',
      'tomato',
      'whitesmoke',
      'yellowgreen',
      'tan',
    ]) {
      expect(COLOUR_KEYWORDS.has(name)).toBe(true)
    }
  })

  it('treats system colours as literals too — "every other colour keyword is a literal"', () => {
    expect(SYSTEM_COLOURS.length).toBeGreaterThan(20)
    for (const name of ['CanvasText', 'ButtonFace', 'Highlight', 'LinkText', 'AccentColor']) {
      expect(isLiteralColourKeyword(name)).toBe(true)
    }
  })

  it('keeps the two sets disjoint: nothing is both allowed and a literal', () => {
    for (const keyword of Object.keys(ALLOWED_COLOUR_KEYWORDS)) {
      expect(COLOUR_KEYWORDS.has(keyword)).toBe(false)
      expect(isAllowedColourKeyword(keyword)).toBe(true)
      expect(isAllowedColourKeyword(keyword.toUpperCase())).toBe(true)
    }
  })

  it('is what the harness declares as non-themed — derived, with no second list', () => {
    // The harness's set is exactly the allowlisted keywords that HAVE a computed form.
    const withComputed = Object.entries(ALLOWED_COLOUR_KEYWORDS)
      .filter(([, entry]) => entry.computed !== null)
      .map(([, entry]) => entry.computed)

    expect(withComputed).toEqual(['rgba(0, 0, 0, 0)'])
    expect(Object.keys(NON_THEMED)).toEqual(withComputed)
    expect(computedNonThemedMembers().map((m) => m.keyword)).toEqual(['transparent'])
    expect(NON_THEMED['rgba(0, 0, 0, 0)'].keyword).toBe('transparent')
  })

  it('is what the JS inline-style guard accepts, plus `none`, which is no colour keyword', () => {
    for (const keyword of [...Object.keys(ALLOWED_COLOUR_KEYWORDS), 'none']) {
      expect(colourValueIsAcceptable(keyword)).toBe(true)
    }
    for (const name of ['red', 'CanvasText', '#fff']) {
      expect(colourValueIsAcceptable(name)).toBe(false)
    }
  })
})

describe('literalColourKeywords', () => {
  it('finds a colour at the END of a rule — the `red}` shape the old pattern passed', () => {
    expect(found('a{background:red}')).toEqual(['red'])
    expect(found('a{border:1px solid red}')).toEqual(['red'])
    expect(found('a{color:tomato}')).toEqual(['tomato'])
    expect(found('a{color:RED}')).toEqual(['RED'])
  })

  it('finds one in a custom property, a gradient, a var() fallback and a system colour', () => {
    expect(found('a{--brand:rebeccapurple}')).toEqual(['rebeccapurple'])
    expect(found('a{background:linear-gradient(red,blue)}')).toEqual(['red', 'blue'])
    expect(found('a{color:var(--c,red)}')).toEqual(['red'])
    expect(found('a{color:ButtonText}')).toEqual(['ButtonText'])
  })

  it('judges an UNKNOWN property too — the list of exemptions fails closed', () => {
    expect(found('a{some-future-colour-property:red}')).toEqual(['red'])
  })

  it('is silent on the allowlist, in any case', () => {
    expect(found('a{color:transparent;background:currentColor;border-color:INHERIT}')).toEqual([])
    expect(found('a{color:initial;fill:unset;stroke:revert;outline-color:revert-layer}')).toEqual(
      [],
    )
  })

  it('is silent where the word is not a colour', () => {
    expect(found('a{font-family:Gold,serif}')).toEqual([])
    expect(found('a{animation-name:red}')).toEqual([])
    expect(found('a{grid-area:tan}')).toEqual([])
    expect(found('a{background:url(red.png)}')).toEqual([])
    expect(found('a{content:"red"}')).toEqual([])
    expect(found('a{color:var(--color-red)}')).toEqual([])
    expect(found('a{color:#add}')).toEqual([])
    expect(found('a:hover .tan{margin:0}')).toEqual([])
    expect(found('@media (prefers-color-scheme:dark){a{margin:0}}')).toEqual([])
  })

  it('reports where the keyword is, not where the declaration starts', () => {
    const css = 'a{margin:0;background:red}'
    const [hit] = literalColourKeywords(css)

    expect(css.slice(hit.index, hit.index + 3)).toBe('red')
    expect(hit.property).toBe('background')
  })

  it('is a finding through scanSource, as `named-color`, with the keyword as its match', () => {
    const findings = scanSource('a{background:red}', 'x.css')

    expect(findings.map((f) => [f.rule, f.id, f.match])).toEqual([
      ['no-raw-color', 'named-color', 'red'],
    ])
    expect(scanSource('a{background:transparent}', 'x.css')).toEqual([])
  })
})

describe('style attributes in Blade — R-322', () => {
  const read = (html) => scanSource(maskBladeCss(html), 't.blade.php')

  it('reads a literal in a style attribute, quoted either way and unquoted', () => {
    expect(read('<p style="color:#123456">').map((f) => f.id)).toEqual(['hex'])
    expect(read("<p style='color:#123456'>").map((f) => f.id)).toEqual(['hex'])
    expect(read('<p style=color:#123456>').map((f) => f.id)).toEqual(['hex'])
    expect(read('<p STYLE="color:#123456">').map((f) => f.id)).toEqual(['hex'])
  })

  it('keeps the template’s own line and column', () => {
    const [f] = read('<div>\n<p class="x"\n   style="color:#123456">')

    expect([f.line, f.column]).toEqual([3, 17])
  })

  it('is not ended early by a quote inside an echo, and reads what comes after it', () => {
    const findings = read(`<p style="color:{{ $t["text"] }};background:red">`)

    expect(findings.map((f) => f.match)).toEqual(['red'])
  })

  it('does not let two attributes run together into one declaration', () => {
    // The first attribute is `font-family`, which is exempt from the colour keyword rule; the
    // second is a colour. Without a separator between them the exemption swallowed the colour.
    expect(read('<p style="font-family:x"><b style="color:red">').map((f) => f.match)).toEqual([
      'red',
    ])
  })

  it('is silent on tokens, an allowlisted keyword, an echo-only attribute and data-style', () => {
    expect(read('<p style="color:var(--color-text);background:transparent">')).toEqual([])
    expect(read('<html style="{{ $themeStyle }}">')).toEqual([])
    expect(read('<p data-style="color:#123456">')).toEqual([])
    expect(read('{{-- <p style="color:#123456"> --}}')).toEqual([])
  })

  it('does not read an attribute SELECTOR inside a <style> body as an attribute', () => {
    const masked = maskBladeCss('<style>[style="x"]{margin:0}</style><p style="margin:0">')

    expect(masked.length).toBe('<style>[style="x"]{margin:0}</style><p style="margin:0">'.length)
    expect(styleAttributeCount('<style>[style="x"]{margin:0}</style>')).toBe(0)
    expect(styleAttributeCount('<style>a{}</style><p style="margin:0"><i style=\'\'>')).toBe(2)
  })

  it('returns a string of the same length with the same newlines', () => {
    const html = '<p\n style="color:#123456"\n>\n{{ $x }}\n'
    const masked = maskBladeCss(html)

    expect(masked).toHaveLength(html.length)
    expect([...masked].map((c) => c === '\n')).toEqual([...html].map((c) => c === '\n'))
  })
})
