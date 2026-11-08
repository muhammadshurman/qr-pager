import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import {
  APPROVED,
  BACKGROUND_ROLES,
  COLOUR_ROLES,
  FILL_PAIRS,
  FOREGROUND_ROLES,
  ORDER_READY,
  colourProperty,
  protectedProperties,
  orderReadyPairs,
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
      // ⚠ `templates/sections.tsx` AND `templates/index.tsx` WERE HERE UNTIL 2026-11-03.
      // `QRPAGER-33` retired `packages/ui/src/templates` on Muhammad's ruling, so clause 1 of
      // the invariant now covers the kit alone. `R-175` and `R-179` are RESTATED with what
      // remains rather than closed: the vocabulary and pair checks they are about still run
      // over `kit.css` -- `styles/templates.css` went too, at R-240 -- and the narrowing is
      // that no TSX template
      // source is swept here any more -- because there is none.
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
    // ⚠ ONE STYLESHEET SINCE R-240: `styles/templates.css` is deleted (Muhammad, 2026-11-04).
    for (const file of ['packages/ui/src/styles/kit.css']) {
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
      // `templates/sections.tsx` retired with the rest of that directory (QRPAGER-33).
    ]) {
      expect(read(file), `${file} sets a positive tabindex`).not.toMatch(
        /tabIndex[=:]\s*\{?\s*[1-9]/,
      )
    }
  })
})

/**
 * ================================================================================
 * THE DERIVATION'S OWN COMPLETENESS — the static half
 * ================================================================================
 *
 * The browser derives every pairing the kit RENDERS and asserts each is in the generated
 * table. That derivation is only as complete as the fixture it walks, so two things are
 * checked here, in Node, where they are cheap and run in both halves of CI:
 *
 *   1. every `.qr-` class in the kit's stylesheet appears in the browser fixture, so a new
 *      component cannot be invisible to the walk;
 *   2. every token used as a `color` is a declared FOREGROUND role and every token used as
 *      a `background-color` is a declared background or fill — in BOTH stylesheets, which
 *      is how the landing templates are covered without a browser fixture of their own.
 *
 * Together these close the hole the first table had: "text on a fill" escaped because the
 * rows were roles against backgrounds and nobody thought of that shape. A hand-written
 * list of pairings has the same defect one level up, so the pairings are derived and the
 * VOCABULARY is checked for completeness.
 */
describe('the pair derivation cannot miss a component', () => {
  const kitCss = read('packages/ui/src/styles/kit.css').replace(/\/\*[\s\S]*?\*\//g, ' ')
  // ⚠ `templatesCss` WAS HERE AND ITS FILE IS DELETED — R-240, Muhammad 2026-11-04. The
  // vocabulary sweep below now covers `kit.css` alone, which is every stylesheet that
  // remains; `R-175` and `R-179` are narrowed to that in `§5` rather than closed.
  const fixture = read('browser/fixtures/kit.html')

  it('has every kit class in the browser fixture, so the walk sees every component', () => {
    // Class selectors only, and the base class rather than its state variants: a
    // `[data-state]` or `:focus-visible` qualifier is a state of a class already listed.
    const classes = new Set([...kitCss.matchAll(/\.(qr-[a-z0-9_-]+)/g)].map((m) => m[1]!))
    expect(classes.size).toBeGreaterThan(20)

    const missing = [...classes].filter((c) => !fixture.includes(c)).sort()
    expect(
      missing,
      'these kit classes are styled but absent from browser/fixtures/kit.html, so the ' +
        'rendered-pair derivation never sees them',
    ).toEqual([])
  })

  it('uses no colour token outside the table\u2019s declared vocabulary', () => {
    // The static analogue of the browser walk, and the only coverage the landing templates
    // get for this — they have no browser fixture of their own, so a token used as text
    // there must still be a declared foreground role.
    // ⚠ THE FILL PAIRS' FOREGROUNDS COUNT TOO, and the first version of this check
    // omitted them -- so it flagged `--color-surface` used as the primary button's label,
    // which IS declared, as a role with no bar. `surface` is a foreground in FILL_PAIRS
    // rather than in FOREGROUND_ROLES, because what it sits on is a fill and not one of
    // the three backgrounds. Reading both is what makes the vocabulary complete.
    const foregrounds = new Set<string>([
      ...Object.keys(FOREGROUND_ROLES),
      ...FILL_PAIRS.map((p) => p.fg),
    ])
    const backgrounds = new Set<string>([
      ...BACKGROUND_ROLES,
      // the fills a label is declared to sit on
      ...FILL_PAIRS.map((p) => p.bg),
      // and the two non-colour grounds a component may legitimately declare
      'transparent',
    ])
    const protectedNames = new Set(protectedProperties().map((p) => p.replace(/^--/, '')))

    const used = (css: string, property: 'color' | 'background-color') => {
      const found = new Set<string>()
      const re = new RegExp(`(?:^|[;{\\s])${property}\\s*:\\s*([^;}]+)`, 'g')
      for (const m of css.matchAll(re)) {
        const value = m[1]!.trim()
        if (value === 'transparent' || value === 'currentcolor' || value === 'inherit') {
          found.add('transparent')
          continue
        }
        const token = /var\(\s*--(?:color-)?([a-z0-9-]+)\s*\)/.exec(value)
        if (token) found.add(token[1]!)
      }
      return found
    }

    for (const [name, css] of [['kit.css', kitCss]] as const) {
      for (const token of used(css, 'color')) {
        if (token === 'transparent' || protectedNames.has(token)) continue
        expect(
          foregrounds.has(token),
          `${name} uses --color-${token} as TEXT, but it is not a declared foreground role ` +
            `in the contrast table — so no bar was ever computed for it`,
        ).toBe(true)
      }
      for (const token of used(css, 'background-color')) {
        if (token === 'transparent' || protectedNames.has(token)) continue
        expect(
          backgrounds.has(token),
          `${name} uses --color-${token} as a BACKGROUND, but it is not a declared ` +
            `background or fill in the contrast table`,
        ).toBe(true)
      }
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

  /**
   * ================================================================================
   * (4) WHICH PROPERTY `.qr-ready` ACTUALLY READS — IN EVERY STYLESHEET IT CAN
   * ================================================================================
   *
   * ⚠ THIS ASSERTION REPLACES A VACUOUS ONE, and the vacuity is worth stating because it
   * read as coverage. The old `(4)` put the hostile declarations on the PARENT of
   * `.qr-ready` and then asserted `.qr-ready`'s own markup carried no hex — which it never
   * would, because the hostile values were never written there. It could not fail.
   *
   * What the other four assertions cover: name-set disjointness, the transform over every
   * allow-listed name, a single declaration outside both theme blocks, and the absence of a
   * clamp. **None of them reads which custom property the order-ready RULES reference.**
   * Measured before this change: substituting
   * `.qr-ready { background-color: var(--color-surface) }` left `lint:tokens` at 0,
   * `typecheck` at 0 and all 370 Vitest cases green. Only Playwright reddened, and
   * incidentally.
   *
   * The attack that opens: a tenant sets `color.surface` to `#0A1F17` — an allow-listed
   * name with an allow-listed value, refused by nothing — and the order-ready pair
   * collapses toward 1:1 on the one screen with an AAA bar. That is `D4`'s excluded failure
   * exactly.
   *
   * SO: every colour declaration inside every `.qr-ready*` rule, in every stylesheet the
   * screen can be rendered under, must name a member of `protectedProperties()`. The
   * stylesheet list is DERIVED by walking the packages and both apps, not written out, and
   * bounded below so an empty walk reddens rather than passing.
   */
  const READY_CLASS = '.qr-ready'

  /** Every stylesheet the order-ready screen can be rendered under, found by walking. */
  function stylesheetsReachingTheScreen(): string[] {
    const found: string[] = []
    const walk = (dir: string) => {
      for (const entry of readdirSync(join(REPO, dir), { withFileTypes: true })) {
        const rel = `${dir}/${entry.name}`
        if (entry.isDirectory()) {
          if (['node_modules', '.next', 'coverage', 'dist', 'out'].includes(entry.name)) continue
          walk(rel)
        } else if (entry.name.endsWith('.css')) {
          found.push(rel)
        }
      }
    }
    // The kit and the templates, and BOTH apps — `apps/*` by glob rather than by name, so
    // a third app added at Task 9 or 11 is covered without editing this file.
    walk('packages/ui/src/styles')
    for (const app of readdirSync(join(REPO, 'apps'), { withFileTypes: true })) {
      if (!app.isDirectory() || app.name === 'api') continue
      walk(`apps/${app.name}/app`)
    }
    return found
  }

  /** Rules whose selector names `.qr-ready`, as `{ selector, body }`, comments stripped. */
  function readyRules(css: string): { selector: string; body: string }[] {
    const code = css.replace(/\/\*[\s\S]*?\*\//g, ' ')
    return [...code.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
      .map((m) => ({ selector: m[1]!.trim(), body: m[2]! }))
      .filter((rule) => rule.selector.includes(READY_CLASS))
  }

  it('(4) every colour in every .qr-ready rule names a PROTECTED property', () => {
    const prot = new Set<string>(protectedProperties())
    const sheets = stylesheetsReachingTheScreen()

    // Non-vacuity, both halves: the walk found the stylesheets, and the kit is among them.
    expect(sheets.length, 'the stylesheet walk found nothing').toBeGreaterThanOrEqual(3)
    expect(sheets).toContain('packages/ui/src/styles/kit.css')
    expect(sheets).toContain('apps/web/app/globals.css')
    /*
     * ⚠ **`apps/site/app/globals.css` WAS NAMED HERE AND NO LONGER EXISTS, AND THE REPLACEMENT
     * IS A DERIVATION RATHER THAN A SHORTER LIST.** Task 11 (`ADR-005`) made `apps/site` the
     * local stand-in for the CDN's `Host` -> prefix mapping: it renders nothing, so it ships
     * no stylesheet, so there is nothing for a Tailwind default to leak into — which is how
     * `R-176` is discharged rather than proven.
     *
     * A hard-coded pair was the thing that broke when the surface changed, so the claim is
     * now the general one: every app that HAS a stylesheet is in the walk. That survives an
     * app being added AND an app's stylesheet being removed, which the pair did not.
     * `apps/site/test/site-surface.test.ts` holds the other end — that this app has no CSS at
     * all — so the set cannot shrink in silence.
     */
    const appsWithSheets = readdirSync(join(REPO, 'apps'), { withFileTypes: true })
      .filter((entry) => entry.isDirectory() && entry.name !== 'api')
      .filter((entry) => sheets.some((sheet) => sheet.startsWith(`apps/${entry.name}/`)))
      .map((entry) => entry.name)

    expect(
      appsWithSheets.length,
      'no app stylesheet reached the walk at all',
    ).toBeGreaterThanOrEqual(1)

    for (const app of appsWithSheets) {
      expect(sheets).toContain(`apps/${app}/app/globals.css`)
    }

    const offenders: string[] = []
    let declarations = 0

    for (const sheet of sheets) {
      for (const rule of readyRules(read(sheet))) {
        for (const m of rule.body.matchAll(
          /(?:^|[;{\s])(color|background-color|background|border-color|outline-color|border)\s*:\s*([^;}]+)/g,
        )) {
          const property = m[1]!
          const value = m[2]!.trim()
          // A length or a keyword in a shorthand is not a colour decision. What must be
          // true is that every custom property this rule reads for a COLOUR is protected.
          const names = [...value.matchAll(/var\(\s*(--[a-z0-9-]+)/gi)].map((v) => v[1]!)
          if (names.length === 0) {
            // `transparent` and `0` are the only non-token values the kit may use here, and
            // anything else would already be a finding for `lint:tokens`.
            if (!/^(?:transparent|currentcolor|inherit|0|none|1px solid var)/i.test(value)) {
              offenders.push(`${sheet}: ${rule.selector} { ${property}: ${value} }`)
            }
            continue
          }
          for (const name of names) {
            declarations += 1
            if (!prot.has(name)) {
              offenders.push(`${sheet}: ${rule.selector} { ${property}: var(${name}) }`)
            }
          }
        }
      }
    }

    // A lower bound on what was examined, so a selector-matching change that finds no
    // `.qr-ready` rule at all cannot report "no offenders" over nothing.
    expect(
      declarations,
      'no colour declaration was found in any .qr-ready rule — the extraction is broken',
    ).toBeGreaterThanOrEqual(8)
    expect(
      offenders,
      'these .qr-ready declarations read a property a tenant can set, so the order-ready ' +
        'pair is reachable from a theme — D4 and §5.1 clause 2',
    ).toEqual([])
  })

  it('(4b) no app declares a .qr-ready rule of its own, and each that has a sheet loads the kit', () => {
    /*
     * The other half of "every stylesheet the screen can use". The apps' own files declare
     * nothing today, which would make the check above vacuous FOR THEM — so the reason it is
     * vacuous is itself asserted: they declare no such rule, and the kit they import is where
     * the rules live and is checked substantively.
     *
     * ⚠ THE LIST WAS `['web', 'site']` AND `apps/site` NO LONGER HAS A STYLESHEET (`ADR-005`,
     * and `R-176` with it). Derived from the walk now, for the reason case (4) gives: a
     * hard-coded pair is exactly what goes stale when the surface moves either way.
     */
    const appSheets = stylesheetsReachingTheScreen().filter((sheet) => sheet.startsWith('apps/'))

    expect(appSheets.length, 'no app stylesheet reached the walk at all').toBeGreaterThanOrEqual(1)

    for (const sheet of appSheets) {
      const css = read(sheet)
      expect(readyRules(css), `${sheet} declares its own .qr-ready rule`).toEqual([])
      expect(css, `${sheet} does not load the kit`).toContain("@import '@qrpager/ui/kit.css'")
    }
  })

  it('(4c) a hostile theme reaches the ancestor and the component still reads no colour', () => {
    /**
     * The render half, kept for what it does prove: the publish pipeline's own shape — a
     * declaration list in a `style` attribute on an ancestor — reaching a rendered
     * `OrderReady`, with every allow-listed name and three forged `--ready-*` names set to
     * an attacking value.
     *
     * It is no longer the carrier of the clause. (4) above is.
     */
    const hostile: Record<string, string> = {}
    for (const name of themeableTokenNames()) {
      hostile[phpProperty(name)] = '#000000'
    }
    // Task 7's ThemeTokens::accepts() refuses all of these before storage; they are driven
    // anyway, because a control that is only correct because its caller is correct is not a
    // control.
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

    const { container } = render(
      <div style={{ ...hostile, ...forged } as React.CSSProperties}>
        <ThemeProvider theme="dark">
          <OrderReady {...props} />
        </ThemeProvider>
      </div>,
    )

    const ready = container.querySelector('.qr-ready')!
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

  it('states the three AAA ratios, QUOTED from the generated table', () => {
    // §12 requires AAA on this screen. Because D4 fixes the pair in the token source it is
    // arithmetic over two values rather than a browser measurement -- which is why it is
    // asserted here and not left to R-118's browser harness.
    //
    // ⚠ RAIL, 2026-11-08: 16.73 / 7.87 / 7.87, raw 16.739645… and 7.870682…, where floor
    // and round agree. Direction B's were 15.54 / 9.72 / 9.72 AND THIS FILE SAID
    // 15.55 / 9.73 / 9.73. Raw: 15.545990… and
    // 9.729662…. Under the stated convention -- TRUNCATE at two decimals, never round up,
    // because every contrast threshold is a MINIMUM -- both floor rather than round. The
    // figures are read from orderReadyPairs() now instead of three literals typed here,
    // which is the mechanism; the expected values stay explicit because they are the gate
    // clause. All three clear AAA's 7.0 either way, so no conclusion changes.
    const pairs = orderReadyPairs()
    expect(pairs.map((p) => [p.fg, p.bg, p.ratio])).toEqual([
      ['--ready-ink', '--ready-field', 16.73],
      ['--ready-signal', '--ready-field', 7.87],
      ['--ready-field', '--ready-signal', 7.87],
    ])
    for (const p of pairs) expect(p.pass).toBe(true)
  })

  it('names exactly three order-ready colours, so the set cannot grow unnoticed', () => {
    expect(Object.keys(ORDER_READY)).toEqual(['ready-field', 'ready-ink', 'ready-signal'])
  })
})

/**
 * ================================================================================
 * LOGICAL PROPERTIES, IN THE STYLESHEETS — WHERE THE LAYOUT ACTUALLY LIVES
 * ================================================================================
 *
 * ⚠ THE EXISTING CHECK READ INLINE STYLES AND NOTHING ELSE. `templates.test.tsx` asserts
 * `style="…margin-left…"` appears in no rendered markup — but these components carry
 * almost no inline style by design (`§12`'s lint rule forbids a value there), so the
 * assertion was watching the one place the property could not be. Every length in this kit
 * is declared in `kit.css` (and, until R-240 deleted it, `templates.css`), and neither was
 * scanned.
 *
 * `§6` trap 2 is the clause: *"RTL is not `direction: rtl`. Icons, progress, timers and
 * anything with directional meaning mirror; logos and numbers do not. Arabic is a launch
 * language, not a later addition."* A `padding-left` in a stylesheet does not mirror under
 * `dir="rtl"` and a logical `padding-inline-start` does, so this is the mechanical half of
 * that clause for the half of the code that has the lengths in it.
 *
 * `text-align: left|right` and `float` are included: both are physical and both have
 * logical spellings (`start`/`end`, `inline-start`/`inline-end`).
 */
describe('RTL is logical properties, not a second stylesheet', () => {
  /** Physical properties with a logical spelling, and the spelling to use instead. */
  const PHYSICAL: readonly [RegExp, string][] = [
    [/\bmargin-(?:left|right)\s*:/g, 'margin-inline-start / margin-inline-end'],
    [/\bpadding-(?:left|right)\s*:/g, 'padding-inline-start / padding-inline-end'],
    [/\bborder-(?:left|right)(?:-(?:width|style|color))?\s*:/g, 'border-inline-start / -end'],
    [/\b(?:^|[;{])\s*(?:left|right)\s*:/gm, 'inset-inline-start / inset-inline-end'],
    [/\btext-align\s*:\s*(?:left|right)\b/g, 'text-align: start / end'],
    [/\bfloat\s*:\s*(?:left|right)\b/g, 'float: inline-start / inline-end'],
    [/\bborder-(?:top|bottom)-(?:left|right)-radius\s*:/g, 'border-start-start-radius etc.'],
  ]

  // `styles/templates.css` was the second entry here until R-240 deleted it (2026-11-04).
  const SHEETS = ['packages/ui/src/styles/kit.css', 'packages/tokens/generated/tokens.css']

  it('declares no physical inline-axis property in any stylesheet this task ships', () => {
    const offenders: string[] = []
    for (const sheet of SHEETS) {
      // Comments stripped FIRST. The retired `templates.css` documented why `margin-inline`
      // is used *"and `margin-left: auto` would not"* — a check that read its own
      // documentation as evidence is the mistake this task made three times already.
      const css = read(sheet).replace(/\/\*[\s\S]*?\*\//g, ' ')
      for (const [pattern, instead] of PHYSICAL) {
        for (const m of css.matchAll(pattern)) {
          const line = css.slice(0, m.index).split('\n').length
          offenders.push(`${sheet}:${String(line)}  ${m[0].trim()}  -> use ${instead}`)
        }
      }
    }
    expect(
      offenders,
      'these declarations are physical and do not mirror under dir="rtl" — §6 trap 2',
    ).toEqual([])
  })

  it('actually uses the logical spellings, so the check above is not passing on an empty axis', () => {
    // The other half: a kit that declared no inline-axis length at all would satisfy the
    // assertion above for the uninteresting reason. Both stylesheets lay out on that axis.
    for (const sheet of ['packages/ui/src/styles/kit.css']) {
      const css = read(sheet).replace(/\/\*[\s\S]*?\*\//g, ' ')
      const logical = [
        ...css.matchAll(/\b(?:margin|padding|border|inset)-inline(?:-(?:start|end))?\s*:/g),
      ]
      expect(
        logical.length,
        `${sheet} declares no logical inline-axis property, so the physical check is vacuous`,
      ).toBeGreaterThanOrEqual(3)
    }
  })

  it('catches a planted physical property, driven rather than read', () => {
    // The guard, exercised on the shape it exists for, so "no offenders" is a measurement
    // and not an absence of measurement.
    const planted = '.qr-card { padding-left: var(--space-16); text-align: right; float: left; }'
    const caught = PHYSICAL.flatMap(([pattern]) => [...planted.matchAll(pattern)].map((m) => m[0]))
    expect(caught).toHaveLength(3)
  })
})
