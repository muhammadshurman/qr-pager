import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  CLEARED_COLOUR_FAMILIES,
  FILAMENT_OWNED_FAMILY,
  FILAMENT_RADII,
  FILAMENT_TEXT_SIZES,
  STRUCTURAL_RADII,
} from '../../../packages/tokens/scripts/panel-theme.mjs'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * THE FILAMENT PANELS' THEME, COMPILED THROUGH apps/api's OWN TAILWIND
 * ================================================================================
 *
 * `theme.test.mjs` next door proves the APPS' theme replaces Tailwind's default scales. The
 * panels cannot use that file -- `QRPAGER-23 §0 SR-1`, measured three ways -- because
 * Filament's own stylesheet CONSUMES the default theme: it calls the `--spacing(…)` theme
 * function and `@apply`s default-theme utilities including `rounded-md` and `bg-gray-50`, so
 * clearing those namespaces removes the framework's CSS rather than the escape hatch.
 *
 * `SR-1`'s ruling is **redefine, do not clear**: every error said a *variable does not
 * exist*, never that a value was wrong. So `generated/panel-theme.css` keeps each name and
 * replaces its value, and Filament cannot tell the difference.
 *
 * ⚠ WHAT IS COMPILED HERE IS THE REAL PANEL ENTRYPOINT, through the SECOND TAILWIND -- the
 * one in `apps/api/node_modules`, pinned to an exact 4.3.3 (`§9.4`). Not the workspace's
 * copy, and not a reconstruction of the panel's imports. `§7.5` is explicit that every probe
 * in the pre-coding reading ran against the workspace Tailwind and was therefore evidence
 * about Filament's CSS rather than about the bytes the panel build emits. This file closes
 * that gap: the two toolchains were measured byte-identical, and this is what keeps them so.
 *
 * ================================================================================
 * THE MEASURED SPLIT, WHICH IS WHY THIS FILE IS NOT SYMMETRIC WITH theme.test.mjs
 * ================================================================================
 *
 * `SR-1`'s outcome is a SPLIT, not a yes or a no:
 *
 *   - COLOUR, TYPE and RADIUS: the redesign works. `bg-red-500` and `text-9xl` emit nothing.
 *   - SPACING: a measurably FORCED EXCEPTION. In Tailwind v4 the existence of the bare
 *     `--spacing` key is what enables the dynamic `p-<n>` utility, so `p-5` = 20px compiles
 *     whatever value that key holds; four values were measured and all four emitted it. And
 *     the key cannot simply be dropped, because Filament calls `--spacing(…)` -- a hard
 *     count of three call sites, asserted below so the exception cannot quietly widen.
 *
 * So spacing is enforced by the panel LINT rule instead, and the two mechanisms do not
 * overlap: this file proves what the theme removes, and the lint rule refuses what it
 * cannot. Neither covers the other, which is stated here so nobody reads one as both.
 */

const require = createRequire(join(REPO, 'apps/api/package.json'))
const TAILWIND_PKG = join(dirname(require.resolve('tailwindcss')), '..')

const PANEL_ENTRY = 'apps/api/resources/css/filament/platform/theme.css'
const panelBase = join(REPO, 'apps/api/resources/css/filament/platform')

/** Compile the panel entrypoint, resolving `@import` exactly as the vite build does. */
async function compilePanel(candidates) {
  const { compile } = await import(join(TAILWIND_PKG, 'dist', 'lib.mjs'))

  const loadStylesheet = async (id, from) => {
    if (id === 'tailwindcss') {
      return { base: TAILWIND_PKG, content: readFileSync(join(TAILWIND_PKG, 'index.css'), 'utf8') }
    }
    if (id.startsWith('tailwindcss/')) {
      const p = join(TAILWIND_PKG, id.slice('tailwindcss/'.length))
      return { base: dirname(p), content: readFileSync(p, 'utf8') }
    }
    // Every other import in the panel entrypoint is RELATIVE -- apps/api is outside the
    // pnpm workspace (ADR-003), so `@qrpager/tokens/…` does not resolve from there and the
    // token imports are relative paths across the two halves of the repo (§2.1a).
    const resolved = id.startsWith('.') ? join(from, id) : require.resolve(id)
    return { base: dirname(resolved), content: readFileSync(resolved, 'utf8') }
  }

  const compiled = await compile(read(PANEL_ENTRY), {
    base: panelBase,
    loadStylesheet,
    loadModule: async () => {
      throw new Error('the panel theme should load no JS module')
    },
  })

  return compiled.build(candidates)
}

/** Does the compiled sheet carry a rule for this class? */
const emits = (css, cls) => css.includes(`.${cls.replace(/([.[\]:])/g, '\\$1')}`)

describe("the panel theme against Tailwind's default theme", () => {
  it('clears every colour family Tailwind ships, except the one Filament owns', () => {
    // ⚠ THE CONTROL THAT FOUND THE HOLE. The `§3` answers carried a HAND-TYPED list of 21
    // families, and Tailwind 4.3.3 ships 26 -- so `olive`, `mauve`, `mist` and `taupe` were
    // left LIVE, which `bg-olive-500` proved by compiling. A list of colours that must be
    // cleared is a second source for Tailwind's palette and goes stale at the next minor
    // version; this holds it to Tailwind's own theme.css instead.
    const tailwindTheme = readFileSync(join(TAILWIND_PKG, 'theme.css'), 'utf8')
    const shipped = [
      ...new Set([...tailwindTheme.matchAll(/^\s*--color-([a-z]+)-50:/gm)].map((m) => m[1])),
    ].sort()

    expect(
      shipped.length,
      'Tailwind ships no colour families, so this check is vacuous',
    ).toBeGreaterThan(20)

    expect([...CLEARED_COLOUR_FAMILIES, FILAMENT_OWNED_FAMILY].sort()).toEqual(shipped)

    // `gray` is excluded DELIBERATELY and not by omission: Filament's own `@theme inline`
    // declares `--color-gray-*: var(--gray-*)` from the panel provider's `->colors()`, and
    // that block runs BEFORE ours -- so clearing `gray` here wipes FILAMENT's entry rather
    // than Tailwind's. That is what made the first attempt fail on `bg-gray-50`.
    expect(CLEARED_COLOUR_FAMILIES).not.toContain('gray')
    expect(FILAMENT_OWNED_FAMILY).toBe('gray')
  })

  it('names no colour, spacing or type literal in the emitted file', () => {
    const panelTheme = read('packages/tokens/generated/panel-theme.css')
    const code = panelTheme.replace(/\/\*[\s\S]*?\*\//g, ' ')

    expect(code, 'the panel @theme block carries a hex literal').not.toMatch(/#[0-9a-f]{3,8}\b/i)

    // EVERY declared value is either `initial` (a clear), a `var()` reference to a property
    // `generated/tokens.css` declares, or one of STRUCTURAL_RADII's two -- which are declared
    // with their reason: `rounded-full` means fully round and `rounded-none` means square,
    // neither is expressible on the approved 8/16/24 ladder, and Filament @applies both.
    //
    // Asserted as "the literals are EXACTLY these two" rather than "no literal matches a
    // magnitude pattern", because `none: '0'` carries no unit and a pattern looking for one
    // walked straight past it -- which is how a literal would get in.
    const literals = [...code.matchAll(/^\s*--[\w-]+(?:-\*)?:\s*([^;]+);/gm)]
      .map((m) => m[1].trim())
      .filter((v) => v !== 'initial' && !v.startsWith('var('))

    expect(literals.sort(), 'the panel @theme block carries a literal value').toEqual(
      Object.values(STRUCTURAL_RADII).sort(),
    )
  })

  it('emits NOTHING for a cleared colour family or an off-scale type step', async () => {
    // Two families from the hand-typed list and two it MISSED, so this case fails the way
    // the original hole failed rather than only the way it was expected to.
    const cands = [
      'bg-red-500',
      'text-blue-600',
      'bg-olive-500',
      'border-taupe-300',
      'bg-mauve-200',
      'text-mist-700',
      'text-9xl',
      'text-5xl',
    ]

    const out = await compilePanel(cands)
    const emitted = cands.filter((c) => emits(out, c))

    expect(
      emitted,
      'these default-theme utilities still compile in the panel build, so the namespace ' +
        'was not cleared -- §12 clause 1 is reachable through them',
    ).toEqual([])
  }, 30_000)

  it("still emits everything Filament's own stylesheet needs, so that is not vacuous", async () => {
    // THE POSITIVE CONTROL, and it is the whole reason the panels REDEFINE instead of
    // clearing. Each of these is @applied or read by Filament's shipped CSS; without them
    // the framework's own stylesheet is what disappears.
    const cands = [
      'bg-gray-50',
      'text-gray-950',
      'rounded',
      'rounded-md',
      'rounded-full',
      'rounded-none',
      'text-sm',
      'text-xs',
      'bg-white',
      'text-white',
    ]

    const out = await compilePanel(cands)
    const absent = cands.filter((c) => !emits(out, c))

    expect(
      absent,
      "these are consumed by Filament's own CSS and no longer compile, so the panel is " +
        'unstyled rather than restyled',
    ).toEqual([])
  }, 30_000)

  it('gives Filament its own size and radius names an APPROVED value', async () => {
    // Redefining is only half the ruling: the name survives AND the value becomes a token.
    // A redefinition that kept Tailwind's value would pass the case above and change
    // nothing, which is the shape §4a records four instances of.
    const cands = [
      ...Object.keys(FILAMENT_TEXT_SIZES).map((n) => `text-${n}`),
      ...Object.keys(FILAMENT_RADII).map((n) => `rounded-${n}`),
    ]

    const out = await compilePanel(cands)

    for (const [name, step] of Object.entries(FILAMENT_TEXT_SIZES)) {
      const rule = new RegExp(`\\.text-${name}\\s*\\{([^}]*)\\}`).exec(out)
      expect(rule, `.text-${name} was not emitted`).not.toBeNull()
      expect(rule[1], `.text-${name} does not read the approved step`).toContain(
        `var(--text-${name}`,
      )
      // And the theme entry behind that name is the approved size, not Tailwind's.
      expect(read('packages/tokens/generated/panel-theme.css')).toContain(
        `--text-${name}: var(--size-${step});`,
      )
    }

    for (const [name, step] of Object.entries(FILAMENT_RADII)) {
      expect(read('packages/tokens/generated/panel-theme.css')).toContain(
        `--radius-${name}: var(--radius-${step});`,
      )
    }
  }, 30_000)

  it('points every @source glob at a directory that exists and has files in it', async () => {
    // ⚠ THE SILENT FAILURE THIS TASK WAS WARNED ABOUT IN §9.3, NOW GUARDED. The stub that
    // `make:filament-theme` writes points BOTH globs at `app/Filament/…`, and this
    // repository has no such directory -- its panels live under the deptrac-enforced feature
    // layout. Tailwind emits no utility for a class it never saw, so a glob that matches
    // nothing produces a panel that renders UNSTYLED and a build that reports success. There
    // is no error to read.
    //
    // Both panels are checked, and each glob must match at least one file: a directory that
    // exists but is empty scans nothing, which is the same outcome by a different route.
    const { globSync } = await import('node:fs')

    const themes = ['platform', 'studio'].map(
      (panel) => `apps/api/resources/css/filament/${panel}/theme.css`,
    )

    for (const theme of themes) {
      const dir = dirname(join(REPO, theme))
      const globs = [...read(theme).matchAll(/@source\s+'([^']+)'/g)].map((m) => m[1])

      // Two per theme: the panel's PHP and the panel's Blade. Asserted so a deleted
      // @source line cannot pass this case by leaving nothing to check.
      expect(globs.length, `${theme} declares no @source glob`).toBe(2)

      for (const glob of globs) {
        // Resolved relative to the stylesheet, which is how Tailwind resolves it.
        const pattern = join(dir, glob)
        const matches = globSync(pattern, { withFileTypes: true }).filter((e) => e.isFile())

        expect(
          matches.length,
          `${theme}: @source '${glob}' matches no file, so every class under it is ` +
            'invisible to Tailwind and that half of the panel compiles to nothing',
        ).toBeGreaterThan(0)
      }
    }
  })

  it('carries the spacing exception, and a HARD COUNT of what forces it', async () => {
    // ⚠ THE ONE CLAUSE §12 DOES NOT GET IN THE PANELS, STATED AS A MEASUREMENT RATHER THAN
    // AS A CONCESSION.
    //
    // In Tailwind v4 the bare `--spacing` key's EXISTENCE enables the dynamic `p-<n>`
    // utility, so `p-5` = 20px compiles whatever value the key holds -- four values were
    // measured and all four emitted it. The key cannot be dropped either: Filament calls
    // the `--spacing(…)` theme function, and a missing key fails the build.
    //
    // So this case asserts the exception EXISTS (p-5 compiles) and bounds WHY: a hard count
    // of Filament's call sites. If the vendor stops calling `--spacing(…)` the count drops,
    // this reddens, and the exception can be revisited instead of outliving its reason.
    // Counted in JS over the vendor tree rather than shelled out to grep: the pattern
    // begins with `--`, which grep reads as an option terminator, so the first version of
    // this line failed with `unrecognized option`. A counting step that cannot run is not a
    // bound.
    const { globSync } = await import('node:fs')
    const vendorCss = globSync('apps/api/vendor/filament/**/*.css', { cwd: REPO })

    expect(
      vendorCss.length,
      'no vendor stylesheet was found, so the count is vacuous',
    ).toBeGreaterThan(10)

    const sites = vendorCss
      .flatMap((f) => [...read(f).matchAll(/--spacing\(\s*([0-9.]+)\s*\)/g)])
      .map((m) => `--spacing(${m[1]})`)

    expect(sites.sort()).toEqual(['--spacing(7)', '--spacing(8)', '--spacing(8)'])

    const out = await compilePanel(['p-5', 'p-4', 'p-16'])

    // p-5 is NOT an approved step and it compiles. Stated so a reviewer reads it here
    // rather than discovering it, and enforced by the lint rule instead.
    expect(emits(out, 'p-5'), 'p-5 no longer compiles -- the spacing exception has changed').toBe(
      true,
    )

    // And an APPROVED step resolves to its own explicit key rather than to the multiplier,
    // which is what makes the token the source for every value the panel is meant to use.
    const approved = /\.p-4\s*\{([^}]*)\}/.exec(out)
    expect(approved, '.p-4 was not emitted').not.toBeNull()
    expect(approved[1]).toContain('var(--spacing-4')
  }, 30_000)
})
