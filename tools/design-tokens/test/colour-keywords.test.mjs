import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'
import { hasInlineCss, maskBladeCss, styleAttributeCount } from '../blade-css.mjs'
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
import { colourValueIsAcceptable, valueIsAcceptable } from '../patterns.mjs'

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

/*
 * The §11 round of QRPAGER-49B. Each case is a shape the reviewers measured passing (or, for the
 * false positives, failing the repository), and each fails on the commit before the fix.
 */
describe('QRPAGER-49B §11 — shapes the first version got wrong', () => {
  const read = (html) => scanSource(maskBladeCss(html), 't.blade.php')

  it('reads a colour AFTER a url() whose body holds a semicolon or a quote', () => {
    expect(found('a{background:url(data:image/png;base64,AAA) red}')).toEqual(['red'])
    expect(found('a{background:url("a;b") red}')).toEqual(['red'])
    expect(found("a{background:url('a;b') red}")).toEqual(['red'])
  })

  it('does not read a selector or an at-rule prelude as a declaration', () => {
    for (const css of [
      'li:hover mark{margin:0}',
      '.nav:hover menu{margin:0}',
      '.row:nth-child(2) mark{margin:0}',
      'a:has(menu){margin:0}',
      '.n:is(mark,.x){margin:0}',
      '@media (prefers-color-scheme:dark){a{margin:0}}',
    ]) {
      expect(found(css), css).toEqual([])
    }

    // ...and the rule INSIDE one is still a declaration.
    expect(found('li:hover mark{color:red}')).toEqual(['red'])
    expect(found('@media (min-width:1px){a{color:red}}')).toEqual(['red'])
  })

  it('exempts a vendor-prefixed property exactly as it exempts the bare one', () => {
    expect(found('a{-webkit-transition:background .3s;-moz-animation:red 1s}')).toEqual([])
    expect(found('a{-webkit-text-fill-color:red}')).toEqual(['red'])
  })

  it('refuses a named colour inside a multi-part value in the JS inline-style reader too', () => {
    for (const value of ['1px solid red', 'red, blue', 'red', 'CanvasText']) {
      expect(colourValueIsAcceptable(value), value).toBe(false)
    }
    expect(colourValueIsAcceptable('1px solid var(--color-text)')).toBe(true)
  })

  it('reads Blade’s own @style directive, and counts it in the walk', () => {
    expect(read("<p @style(['color:#ff00ff'])>").map((f) => f.id)).toEqual(['hex'])
    expect(read("<p @style(['background-color: red'])>").map((f) => f.match)).toEqual(['red'])
    expect(
      read("<p @class(['a']) @style(['font-weight:bold' => $on, 'color:#ff00ff'])>").map(
        (f) => f.match,
      ),
    ).toEqual(['#ff00ff'])
    expect(read("<p @style(['margin:0', 'color:var(--color-text)'])>")).toEqual([])
    expect(hasInlineCss("<p @style(['margin:0'])>")).toBe(true)
    expect(styleAttributeCount("<p @style(['margin:0'])><i style=''>")).toBe(2)
    // a `)` inside a string does not end the directive early
    expect(read('<p @style([\'content:")";color:red\'])>').map((f) => f.match)).toEqual(['red'])
  })

  it('does not let a comment opened in one region hide a second region', () => {
    expect(
      read('<p style="/*">a</p><p style="color:red;background:#ff0000">b</p><p style="*/">').map(
        (f) => f.match,
      ),
    ).toEqual(['#ff0000', 'red'])
    expect(
      read('<style>.a{} /*</style><p style="color:red"></p><style>*/</style>').map((f) => f.match),
    ).toEqual(['red'])
    // a comment INSIDE one region still hides its own literal, which is what a comment is
    expect(read('<p style="/* color:red */margin:0">')).toEqual([])
    // and an unterminated one ends with its region, as a browser ends it
    expect(read('<p style="margin:0;/* color:red">')).toEqual([])
  })
})

/*
 * QRPAGER-49C, Muhammad's ruling 2: *"`KEYWORD_SAFE` and the regex inside `page.evaluate` stayed
 * as two separate lists for the same semantic question — the drift I ruled out, reinstated one
 * layer down … One definition, three consumers."* These are SOURCE assertions and say so: they
 * cannot show behaviour, only that the second list is not written again.
 */
describe('one definition, three consumers — QRPAGER-49C', () => {
  const read = (path) => readFileSync(join(import.meta.dirname, '..', '..', '..', path), 'utf8')

  it('the magnitude guard takes every allowlisted keyword from the module', () => {
    for (const keyword of Object.keys(ALLOWED_COLOUR_KEYWORDS)) {
      expect(valueIsAcceptable(keyword), keyword).toBe(true)
    }

    const source = read('tools/design-tokens/patterns.mjs')

    expect(source).toMatch(/Object\.keys\(ALLOWED_COLOUR_KEYWORDS\)/)
    // the old hand-written list, which named the CSS-wide keywords itself
    expect(source).not.toMatch(/inherit\|initial\|unset\|revert/)
  })

  it('the in-page detector is built from the module and writes no keyword list of its own', () => {
    const source = read('browser/theme-paint.spec.ts')

    expect(source).toMatch(/from '\.\.\/tools\/design-tokens\/colour-keywords\.mjs'/)
    expect(source).toMatch(
      /keywords: \[\.\.\.Object\.keys\(ALLOWED_COLOUR_KEYWORDS\), \.\.\.COLOUR_KEYWORDS\]/,
    )
    expect(source).not.toMatch(/canvastext|linktext|buttonface|accentcolor/i)
  })

  it('the harness’s classifier and the scanner both read the same module', () => {
    expect(read('tools/landing/paint-classify.mjs')).toMatch(/design-tokens\/colour-keywords\.mjs/)
    expect(read('tools/design-tokens/patterns.mjs')).toMatch(/\.\/colour-keywords\.mjs/)
  })
})
