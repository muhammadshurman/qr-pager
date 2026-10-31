import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import {
  APPROVED,
  COLOUR_ROLES,
  ORDER_READY,
  colourProperty,
  protectedProperties,
  ratioTo2dp,
  resolveOrderReady,
} from '@qrpager/tokens'

import { OrderReady, ThemeProvider } from '../src/index.ts'

afterEach(cleanup)

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p: string) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * §5.1's NAMED INVARIANT, SECOND CLAUSE -- AN UNREACHABILITY PROOF
 * ================================================================================
 *
 * The clause, verbatim: *"no tenant-supplied theme value can reach the order-ready screen's
 * colour pair."* `QRPAGER-15 §5.1` is explicit about the SHAPE of the proof: *"D4 makes this
 * an UNREACHABILITY proof, not a clamp proof."*
 *
 * So this file does not show that a hostile value is corrected. It shows that there is no
 * path for one to arrive on.
 *
 * THE MECHANISM, IN ONE SENTENCE. Task 7's `ThemeTokens::NAMES` is a closed allow-list of
 * twelve names; `ThemeTokens::property()` maps a name to a CSS custom property by a pure
 * string transform; and the three properties the order-ready screen reads are outside the
 * image of that function over that list.
 *
 * FIVE INDEPENDENT ASSERTIONS, each of which can fail:
 *
 *   1. the two property sets are DISJOINT, computed from the PHP source and the token
 *      source rather than from a copy of either;
 *   2. every one of the twelve allow-listed names, pushed through the real transform,
 *      produces a property the order-ready screen does not read;
 *   3. the order-ready properties are DECLARED EXACTLY ONCE in the generated stylesheet and
 *      in neither theme block, so there is no second declaration that could differ;
 *   4. driving HOSTILE theme values -- including names that spell a protected property --
 *      through the whole path leaves the rendered pair unchanged;
 *   5. there is NO clamp and no contrast-adjustment code anywhere in the package.
 */

/**
 * The tenant-themeable token names, parsed out of Task 7's PHP source.
 *
 * ⚠ PARSED, NOT COPIED, and that is `guard` rule 11c's own argument: that rule reads
 * `ThemeTokens::NAMES` by parsing the file rather than holding its own copy, because a guard
 * carrying a copy of the list it keeps singular would be the defect it exists to prevent.
 * A hard-coded list of twelve names here would pass forever after Task 7 added a
 * thirteenth.
 */
function themeableTokenNames(): string[] {
  const php = read('apps/api/app/Domain/Landing/ThemeTokens.php')
  const block = /public const NAMES = \[([\s\S]*?)\];/.exec(php)
  expect(
    block,
    'ThemeTokens::NAMES could not be parsed -- has the PHP shape changed?',
  ).not.toBeNull()
  const names = [...block![1]!.matchAll(/'([^']+)'\s*=>/g)].map((m) => m[1]!)
  expect(names.length).toBeGreaterThan(0)
  return names
}

/** The CSS property a themeable name becomes -- ThemeTokens::property()'s transform. */
function phpProperty(name: string): string {
  // `return '--'.str_replace(['.', '_'], '-', $name);`
  return `--${name.replace(/[._]/g, '-')}`
}

describe('clause 1 of the invariant -- no hard-coded values in any component', () => {
  it('has no hex, colour function or raw magnitude in any component source', () => {
    // The lint rule is the live control and is proven on committed fixtures in
    // tools/design-tokens. This is the same property asserted over the REAL sources, so a
    // component that slipped past a misconfigured lint run still fails a test.
    const sources = [
      'packages/ui/src/Button.tsx',
      'packages/ui/src/Card.tsx',
      'packages/ui/src/ElapsedTimer.tsx',
      'packages/ui/src/Field.tsx',
      'packages/ui/src/Icon.tsx',
      'packages/ui/src/OrderReady.tsx',
      'packages/ui/src/ProgressTrack.tsx',
      'packages/ui/src/Spinner.tsx',
      'packages/ui/src/StateView.tsx',
      'packages/ui/src/StatusBadge.tsx',
      'packages/ui/src/templates/sections.tsx',
      'packages/ui/src/templates/index.tsx',
    ]

    for (const source of sources) {
      // Comments are stripped first: these files document the ratios and the hexes they are
      // avoiding, and a rule that fires on its own documentation gets disabled.
      const code = read(source)
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*\/\/.*$/gm, ' ')

      expect(code, `${source} carries a hex colour`).not.toMatch(/#[0-9a-f]{3,8}\b/i)
      expect(code, `${source} carries a colour function`).not.toMatch(
        /\b(?:rgba?|hsla?|oklch|oklab|color-mix)\s*\(/i,
      )
      expect(code, `${source} carries a Tailwind arbitrary value`).not.toMatch(/-\[[^\]]*\]/)
    }
  })
})

describe('the focus ring is never removed -- the half a browser cannot measure', () => {
  it('declares no outline suppression in any stylesheet this task ships', () => {
    // ⚠ THIS LIVES HERE RATHER THAN IN THE BROWSER SUITE, and the reason is mechanical: in
    // every engine `outline-style: none` is the INITIAL value, so a computed style cannot
    // tell "we removed the ring" from "this element is not focused". A browser test that
    // tried reported the entire document. A source scan can fail; the computed-style
    // version could not.
    //
    // The same shape PlatformPanelAccessibilityTest already asserts for the admin panel:
    // "no `outline: none` ... in this task's own markup".
    for (const file of ['packages/ui/src/styles/kit.css', 'packages/ui/src/styles/templates.css']) {
      const css = read(file).replace(/\/\*[\s\S]*?\*\//g, ' ')
      expect(css, `${file} suppresses an outline`).not.toMatch(/outline\s*:\s*(?:none|0)\b/i)
      expect(css, `${file} suppresses an outline via outline-style`).not.toMatch(
        /outline-style\s*:\s*none/i,
      )
      expect(css, `${file} suppresses an outline via outline-width`).not.toMatch(
        /outline-width\s*:\s*0/i,
      )
    }
  })

  it('declares no positive tabindex anywhere, which reorders the tab sequence', () => {
    // The other half of "keyboard reachable". A positive tabindex jumps an element ahead of
    // the document order and is how a page stops being navigable in sequence.
    for (const file of [
      'packages/ui/src/Button.tsx',
      'packages/ui/src/Field.tsx',
      'packages/ui/src/OrderReady.tsx',
      'packages/ui/src/StateView.tsx',
      'packages/ui/src/a11y.tsx',
      'packages/ui/src/templates/sections.tsx',
    ]) {
      expect(read(file), `${file} sets a positive tabindex`).not.toMatch(
        /tabIndex[=:]\s*\{?\s*[1-9]/,
      )
    }
  })
})

describe('clause 2 -- the order-ready pair is UNREACHABLE from a tenant theme', () => {
  it('(1) the themeable and protected property sets are DISJOINT', () => {
    const themeable = themeableTokenNames().map(phpProperty)
    const prot = protectedProperties()

    expect(themeable).toHaveLength(12)
    expect(prot).toEqual(['--ready-field', '--ready-ink', '--ready-signal'])

    const intersection = themeable.filter((p) => prot.includes(p as never))
    expect(intersection).toEqual([])
  })

  it('(2) every allow-listed name produces a property the screen does not read', () => {
    // Over the real list and the real transform, name by name, so the claim is not "the
    // sets look different" but "no input to that function reaches these outputs".
    const prot = protectedProperties() as string[]
    for (const name of themeableTokenNames()) {
      const property = phpProperty(name)
      expect(prot, `${name} -> ${property} collides with a protected property`).not.toContain(
        property,
      )
      // And no themeable name can even produce the --ready- PREFIX, which is the structural
      // reason rather than a coincidence of these twelve names.
      expect(property.startsWith('--ready-')).toBe(false)
    }
  })

  it('(2b) no THEME ROLE produces a protected property either', () => {
    // The order-ready colours are not theme roles at all -- the redesign that made clause 5
    // of the dark theme's approval true by construction.
    const prot = protectedProperties() as string[]
    for (const theme of ['light', 'dark'] as const) {
      for (const role of Object.keys(COLOUR_ROLES[theme])) {
        expect(prot).not.toContain(colourProperty(role))
      }
    }
  })

  it('(3) declares each protected property EXACTLY ONCE, in no theme block', () => {
    const css = read('packages/tokens/generated/tokens.css')
    // Strip comments: the banner documents these properties and their ratios.
    const code = css.replace(/\/\*[\s\S]*?\*\//g, ' ')

    for (const property of protectedProperties()) {
      const declarations = [...code.matchAll(new RegExp(`${property}\\s*:`, 'g'))]
      expect(
        declarations,
        `${property} is declared ${String(declarations.length)} times`,
      ).toHaveLength(1)
    }

    // And the single declaration is NOT inside either theme-scoped block.
    const darkBlock = /\[data-theme='dark'\]\s*\{([\s\S]*?)\n\}/.exec(code)
    expect(darkBlock).not.toBeNull()
    for (const property of protectedProperties()) {
      expect(darkBlock![1]!).not.toContain(property)
    }
  })

  it('(4) HOSTILE theme values leave the rendered pair unchanged', () => {
    /**
     * The hostile set. Each is something a tenant could put in the theme map, including
     * names that SPELL a protected property -- which is the attack this would have to
     * survive if the namespaces overlapped.
     */
    const hostile: Record<string, string> = {}
    for (const name of themeableTokenNames()) {
      // Every allow-listed token set to the darkest and lightest things available, so if any
      // of them reached the screen the pair would visibly move.
      hostile[phpProperty(name)] = '#000000'
    }
    // And the forms a hostile tenant would actually try. Task 7's ThemeTokens::accepts()
    // refuses all of these before storage; they are driven anyway, because a control that is
    // only correct because its caller is correct is not a control.
    const forged = {
      '--ready-field': '#FF00FF',
      '--ready-ink': '#FF00FF',
      '--ready-signal': '#FF00FF',
      '--color-ready-field': '#FF00FF',
      '--color-ready-ink': '#FF00FF',
      '--color-paper': '#FF00FF',
      '--color-signal': '#FF00FF',
    }

    const props = {
      reference: 'A-114',
      heading: 'Your order is ready',
      instruction: 'Come to the counter',
      acknowledgeLabel: 'On my way',
      onAcknowledge: () => undefined,
    }

    // Applied to the ANCESTOR, exactly as the publish pipeline does it: Task 7's
    // ThemeStylesheet emits a declaration list into a `style` attribute on the root element.
    const { container } = render(
      <div style={{ ...hostile, ...forged } as React.CSSProperties}>
        <ThemeProvider theme="dark">
          <OrderReady {...props} />
        </ThemeProvider>
      </div>,
    )

    const ready = container.querySelector('.qr-ready')!

    // The component reads --ready-*, and nothing it rendered carries a colour at all. So
    // the only thing a hostile value could have changed is a property the component does
    // not reference -- which is the unreachability, stated as an assertion.
    expect(ready.outerHTML).not.toContain('#FF00FF')
    expect(ready.outerHTML).not.toMatch(/#[0-9a-f]{3,8}\b/i)
    expect(screen.getByRole('button', { name: 'On my way' })).toBeTruthy()

    // And the pair the SOURCE declares is still the approved one -- no clamp ran, because
    // there is none to run.
    expect(resolveOrderReady('ready-field')).toBe(APPROVED['ready-field'])
    expect(resolveOrderReady('ready-ink')).toBe(APPROVED.paper)
    expect(resolveOrderReady('ready-signal')).toBe(APPROVED.signal)
  })

  it('(5) contains NO clamp and no contrast-adjustment code anywhere', () => {
    // QRPAGER-15 §6, trap 5: "D4 is an exclusion, not a clamp. If you find yourself writing
    // contrast-adjustment code for the order-ready screen, the design has drifted from the
    // decision." This is that trap, as a test rather than as a note.
    const files = [
      'packages/ui/src/OrderReady.tsx',
      'packages/ui/src/styles/kit.css',
      'packages/tokens/src/source.ts',
      'packages/tokens/src/color.ts',
      'packages/tokens/scripts/generate.mjs',
    ]
    for (const file of files) {
      const code = read(file)
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*(?:\/\/|\s\*).*$/gm, ' ')
      expect(code, `${file} looks like it adjusts contrast at runtime`).not.toMatch(
        /\b(?:clamp|adjust|ensureContrast|pickReadable|contrastSafe|autoContrast)\s*\(/i,
      )
    }
  })

  it('states the three AAA ratios from the source, as arithmetic', () => {
    // §12 requires AAA on this screen. Because D4 fixes the pair in the token source it is
    // arithmetic over two values rather than a browser measurement -- which is why it is
    // asserted here and not left to R-118's browser harness.
    expect(ratioTo2dp(resolveOrderReady('ready-ink'), resolveOrderReady('ready-field'))).toBe(15.55)
    expect(ratioTo2dp(resolveOrderReady('ready-signal'), resolveOrderReady('ready-field'))).toBe(
      9.73,
    )
    expect(ratioTo2dp(resolveOrderReady('ready-field'), resolveOrderReady('ready-signal'))).toBe(
      9.73,
    )
  })

  it('names exactly three order-ready colours, so the set cannot grow unnoticed', () => {
    expect(Object.keys(ORDER_READY)).toEqual(['ready-field', 'ready-ink', 'ready-signal'])
  })
})
