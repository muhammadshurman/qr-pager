import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * TAILWIND'S DEFAULT PALETTE AND SCALES MUST NOT EXIST
 * ================================================================================
 *
 * ⚠ THIS IS THE REDESIGN `§8` REQUIRES AFTER A SECOND FALL, PROVEN. Clause 1 of the named
 * invariant -- *"no component carries a hard-coded colour, spacing or font size"* -- fell
 * twice in this task:
 *
 *   1. `padding: var(--space-16) 9px` passed, because the value check early-returned on
 *      "contains a `var()`". One token laundered the literal beside it.
 *   2. `bg-red-500 p-4 text-sm` passed `lint:es` AND `lint:tokens`. The rule knows about
 *      Tailwind's square-bracket escape hatch and cannot see the DEFAULT THEME, which
 *      `@import 'tailwindcss'` leaves entirely reachable -- `--text-sm` is 14px and is not
 *      on the approved scale.
 *
 * `§8`: *"if a clause of the named invariant falls a second time, the second fix is not a
 * patch on the state that was found -- it is a redesign that makes the whole shape
 * impossible."* A pattern matching `bg-red-500` would be the patch, and would also be a
 * second source for Tailwind's default theme: every colour, step and size it ships, listed
 * by hand, stale at the next minor version.
 *
 * The redesign: `packages/tokens/generated/theme.css` is a generated `@theme reference`
 * block that CLEARS each namespace (`--color-*: initial`) and repopulates it from the token
 * source. There is then no `bg-red-500` to write.
 *
 * WHAT THIS COMPILES IS THE REAL FILE. `apps/web/app/globals.css`, through Tailwind 4.3.3's
 * own compiler, with the fixtures' classes as the candidate set -- not a reconstruction of
 * the app's imports. A test that assembled its own stylesheet would prove something about
 * that stylesheet.
 *
 * BOTH DIRECTIONS, because one of them alone proves nothing: the bad fixture's classes must
 * emit NOTHING, and the good fixture's token-backed classes must EMIT -- otherwise a
 * compile that produced no CSS whatsoever would read as a pass.
 */

const require = createRequire(join(REPO, 'apps/web/package.json'))
const TAILWIND = dirname(require.resolve('tailwindcss'))
const TAILWIND_PKG = join(TAILWIND, '..')

/** Candidate classes from a fixture's `class="…"` attributes. */
function candidates(fixture) {
  return [
    ...new Set(
      [...read(fixture).matchAll(/class="([^"]+)"/g)].flatMap((m) => m[1].trim().split(/\s+/)),
    ),
  ]
}

/**
 * Compile a stylesheet the way the app's PostCSS plugin does, resolving `@import` against
 * the real workspace -- including the workspace links `@qrpager/tokens/theme.css` and
 * `@qrpager/ui/kit.css` go through.
 */
async function compileApp(css, base, cands) {
  const { compile } = await import(join(TAILWIND_PKG, 'dist', 'lib.mjs'))

  const loadStylesheet = async (id, from) => {
    if (id === 'tailwindcss') {
      return { base: TAILWIND_PKG, content: readFileSync(join(TAILWIND_PKG, 'index.css'), 'utf8') }
    }
    if (id.startsWith('tailwindcss/')) {
      const p = join(TAILWIND_PKG, id.slice('tailwindcss/'.length))
      return { base: dirname(p), content: readFileSync(p, 'utf8') }
    }
    // A package specifier resolves through the app's own node_modules, which is where the
    // workspace links live -- so this follows exactly what `next build` follows.
    const resolved = id.startsWith('.') ? join(from, id) : require.resolve(id)
    return { base: dirname(resolved), content: readFileSync(resolved, 'utf8') }
  }

  const compiled = await compile(css, {
    base,
    loadStylesheet,
    loadModule: async () => {
      throw new Error('this stylesheet should load no JS module')
    },
  })
  return compiled.build(cands)
}

const appCss = () => read('apps/web/app/globals.css')
const appBase = join(REPO, 'apps/web/app')

describe("Tailwind's default theme, replaced rather than discouraged", () => {
  it('has both apps import the generated @theme block, after tailwindcss', () => {
    for (const app of ['web', 'site']) {
      const css = read(`apps/${app}/app/globals.css`)
      const tailwind = css.indexOf("@import 'tailwindcss'")
      const theme = css.indexOf("@import '@qrpager/tokens/theme.css'")
      expect(tailwind, `apps/${app} does not import tailwindcss`).toBeGreaterThan(-1)
      expect(theme, `apps/${app} does not import the generated @theme block`).toBeGreaterThan(-1)
      // Order is load-bearing: the clears must come after what they are clearing.
      expect(theme).toBeGreaterThan(tailwind)
    }
  })

  it('clears every namespace it repopulates, and names no literal', () => {
    const theme = read('packages/tokens/generated/theme.css')
    for (const namespace of ['--color-*', '--spacing-*', '--text-*', '--font-*', '--radius-*']) {
      expect(theme, `${namespace} is not cleared`).toContain(`${namespace}: initial`)
    }
    // `reference`, not plain `@theme`: plain would emit every variable into `:root`, which
    // is a second declaration of what tokens.css already declares AND would not follow
    // `[data-theme='dark']`.
    expect(theme).toContain('@theme reference')

    // Not one literal. Every entry is a var() reference to a token property, so this file
    // cannot become a second source for a value.
    const code = theme.replace(/\/\*[\s\S]*?\*\//g, ' ')
    expect(code, 'the @theme block carries a hex literal').not.toMatch(/#[0-9a-f]{3,8}\b/i)
    expect(code, 'the @theme block carries a magnitude').not.toMatch(
      /:\s*[^;]*\d(?:px|rem|em|%)\s*;/,
    )
  }, 30_000)

  it('emits NOTHING for any default-theme utility -- bg-red-500, p-5, text-sm', async () => {
    const cands = candidates('tools/design-tokens/fixtures/bad-tailwind-defaults.html.fixture')
    // The fixture is the authority on what is tested, so a class removed from it is a
    // shape that stops being covered -- hence the bound and the three named explicitly.
    expect(cands.length).toBeGreaterThanOrEqual(15)
    // `p-4` is deliberately NOT in this list: under the token theme it is `var(--space-4)`,
    // an approved step, so it belongs to the good fixture. The fixture's own header says so.
    for (const named of ['bg-red-500', 'p-5', 'p-7', 'text-sm', 'tracking-wide']) {
      expect(cands, `the bad fixture no longer carries ${named}`).toContain(named)
    }

    const out = await compileApp(appCss(), appBase, cands)
    const emitted = cands.filter((c) => out.includes(`.${c.replace(/([.[\]])/g, '\\$1')}`))
    expect(
      emitted,
      'these Tailwind default-theme utilities still compile, so the @theme block did not ' +
        'replace the default scales -- clause 1 of §5.1 is reachable through them',
    ).toEqual([])
  }, 30_000)

  it('still emits every TOKEN-backed utility, so the proof above is not vacuous', async () => {
    const cands = candidates('tools/design-tokens/fixtures/good-tailwind-tokens.html.fixture')
    expect(cands.length).toBeGreaterThanOrEqual(15)

    const out = await compileApp(appCss(), appBase, cands)
    const absent = cands.filter((c) => !out.includes(`.${c}`))
    expect(
      absent,
      'these token-backed utilities do not compile, so the cleared namespaces were not ' +
        'repopulated -- and "nothing is emitted for bg-red-500" would then be true for the ' +
        'uninteresting reason',
    ).toEqual([])

    // And the value a token-backed utility carries is a var() reference, never a literal:
    // that is what makes `[data-theme='dark']` still decide it.
    const paper = /\.bg-paper\s*\{([^}]*)\}/.exec(out)
    expect(paper, '.bg-paper was not emitted').not.toBeNull()
    expect(paper[1]).toContain('var(--color-paper')
    expect(paper[1], '.bg-paper carries a literal colour').not.toMatch(/#[0-9a-f]{3,8}\b/i)
  }, 30_000)

  it('leaves the arbitrary escape hatch compiling, which is the LINT rule’s job', async () => {
    // The two mechanisms do not overlap and neither covers the other: `@theme` removes the
    // default-theme utilities, and `p-[13px]` / `text-[#fff]` do not need a theme entry at
    // all -- they are the square-bracket form, and `lint:tokens` plus the ESLint rule are
    // what refuse those, proven on the committed bad fixture.
    //
    // Asserted rather than assumed, so nobody reads the @theme block as covering both.
    const out = await compileApp(appCss(), appBase, ['p-[13px]', 'text-[#fff]'])
    expect(out).toContain('13px')
  }, 30_000)
})
