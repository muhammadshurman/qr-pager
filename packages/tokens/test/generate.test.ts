import { execFileSync } from 'node:child_process'
import { copyFileSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, relative } from 'node:path'

import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ARTEFACTS,
  buildContrastJson,
  buildContrastMarkdown,
  buildCss,
  buildPhp,
  buildThemeCss,
  buildTs,
  run,
} from '../scripts/generate.mjs'
import {
  BARS,
  HEADROOM,
  MIX_COLOUR_SPACE,
  MOTION,
  SPACING,
  RADIUS,
  TARGET_MIN_PX,
  TYPE_SCALE,
  allProperties,
  contrastFailures,
  contrastPairs,
  orderReadyPairs,
  protectedProperties,
  resolveColour,
  resolveTheme,
} from '../src/source.ts'

const PKG = join(import.meta.dirname, '..')
const REPO = join(PKG, '..', '..')

/**
 * ================================================================================
 * THE GENERATOR ITSELF — 620 LINES THAT WERE OUTSIDE THE COVERAGE DENOMINATOR
 * ================================================================================
 *
 * ⚠ QA #10. `96.73%` was measured over `packages/tokens/src`, `packages/ui/src` and
 * `tools/design-tokens/*.mjs`, which left about 990 lines of this task's own code out of the
 * denominator -- this file's subject among them. `§6` asks for coverage "on code this task
 * added", so the figure was never about that set, whatever its value.
 *
 * Adding the files to the `include` list and leaving them uncovered would have made the
 * number honest and the gate red. These are the tests, and they are not line-count tests:
 * each builder is asserted against the SOURCE's own computation, so a builder that emitted
 * plausible-looking wrong values fails here rather than being counted as covered.
 *
 * WHAT THE STALENESS CHECK CANNOT DO, AND WHY THIS IS SEPARATE FROM IT. `pnpm tokens:check`
 * byte-diffs each artefact against `build()`'s output, so it proves the file equals what the
 * generator produces -- and it would keep proving that if the generator produced nonsense.
 * These cases assert what the output MEANS. `artefacts.test.ts` is the third axis: that the
 * paths named inside an artefact exist.
 */

afterEach(() => {
  vi.restoreAllMocks()
})

describe('buildCss', () => {
  const css = buildCss()

  it('declares every property the source says it emits, and no other', () => {
    const declared = [...css.matchAll(/^\s+(--[a-z0-9-]+):/gm)].map((m) => m[1]!)
    // Every property in `allProperties()` appears; nothing outside it does.
    for (const property of allProperties()) {
      expect(declared, `${property} is not declared`).toContain(property)
    }
    const unexpected = [...new Set(declared)].filter((p) => !allProperties().includes(p))
    expect(unexpected, 'these properties are emitted but are not in allProperties()').toEqual([])
  })

  it('resolves each colour role to the value the source computes', () => {
    // Not "a hex is present": the exact value, from `resolveColour`, for both themes.
    const light = resolveTheme('light')
    const dark = resolveTheme('dark')

    const lightBlock = /:root \{([\s\S]*?)\n\}/.exec(css)
    expect(lightBlock).not.toBeNull()
    for (const [role, hex] of Object.entries(light)) {
      expect(lightBlock![1]!, `light ${role}`).toContain(`--color-${role}: ${hex};`)
    }

    const darkBlock = /\[data-theme='dark'\] \{([\s\S]*?)\n\}/.exec(css)
    expect(darkBlock).not.toBeNull()
    for (const [role, hex] of Object.entries(dark)) {
      expect(darkBlock![1]!, `dark ${role}`).toContain(`--color-${role}: ${hex};`)
    }
  })

  it('emits each protected property exactly once, outside both theme blocks', () => {
    // The same property `invariant.test.tsx` asserts about the committed file, asserted
    // here about the BUILDER -- so a generator change that started emitting a second
    // declaration fails even before the artefact is written.
    const code = css.replace(/\/\*[\s\S]*?\*\//g, ' ')
    for (const property of protectedProperties()) {
      expect([...code.matchAll(new RegExp(`${property}\\s*:`, 'g'))], property).toHaveLength(1)
    }
  })

  it('emits every scale step, the motion values and the reduced-motion override', () => {
    for (const n of SPACING) expect(css).toContain(`--space-${String(n)}: ${String(n / 16)}rem;`)
    for (const [role, value] of Object.entries(RADIUS))
      expect(css).toContain(`--radius-${role}: ${value};`)
    for (const n of TYPE_SCALE) expect(css).toContain(`--size-${String(n)}: ${String(n / 16)}rem;`)
    expect(css).toContain(`--motion-state: ${MOTION.state};`)
    expect(css).toContain(`--target-min: ${String(TARGET_MIN_PX)}px;`)

    // prefers-reduced-motion is NO motion, not less (§6 trap 3): the literal value.
    const reduced = /@media \(prefers-reduced-motion: reduce\) \{([\s\S]*?)\n\}/.exec(css)
    expect(reduced).not.toBeNull()
    expect(reduced![1]!).toContain(`--motion-state: ${MOTION.reduced};`)
    expect(reduced![1]!).toContain(`--motion-spin: ${MOTION.reduced};`)
  })

  it('states the mix colour space, so a percentage is reproducible', () => {
    // SR-3's condition 2: sRGB and OkLab give different hexes for the same percentage.
    expect(css).toContain(MIX_COLOUR_SPACE)
  })
})

describe('buildThemeCss', () => {
  const theme = buildThemeCss()

  it('clears each namespace and repopulates it from the token properties', () => {
    for (const namespace of ['--color-*', '--spacing-*', '--text-*', '--radius-*', '--font-*']) {
      expect(theme, `${namespace} is not cleared`).toContain(`${namespace}: initial`)
    }
    expect(theme).toContain('@theme reference')
    for (const n of SPACING) {
      expect(theme).toContain(`--spacing-${String(n)}: var(--space-${String(n)});`)
    }
    for (const n of TYPE_SCALE) {
      expect(theme).toContain(`--text-${String(n)}: var(--size-${String(n)});`)
    }
  })

  it('names no literal at all, so it cannot become a second source for a value', () => {
    const code = theme.replace(/\/\*[\s\S]*?\*\//g, ' ')
    expect(code).not.toMatch(/#[0-9a-f]{3,8}\b/i)
    expect(code).not.toMatch(/:\s*[^;]*\d(?:px|rem|em|%)\s*;/)
  })
})

describe('buildTs', () => {
  const ts = buildTs()

  it('exports the same values the source resolves, for both themes', () => {
    for (const theme of ['light', 'dark'] as const) {
      for (const [role, hex] of Object.entries(resolveTheme(theme))) {
        expect(ts, `${theme} ${role}`).toContain(`'${role}': '${hex}'`)
      }
    }
  })

  it('exports the protected property list and the full property list', () => {
    for (const property of protectedProperties()) expect(ts).toContain(`'${property}'`)
    for (const property of allProperties()) expect(ts).toContain(`'${property}'`)
  })

  it('is valid TypeScript, compiled rather than eyeballed', () => {
    // The strongest available assertion about generated source: hand it to the compiler.
    const dir = mkdtempSync(join(tmpdir(), 'tokens-ts-'))
    try {
      writeFileSync(join(dir, 'generated.ts'), ts, 'utf8')
      execFileSync(
        join(REPO, 'node_modules', '.bin', 'tsc'),
        [
          '--noEmit',
          '--strict',
          '--target',
          'ES2023',
          '--module',
          'ESNext',
          // The repo root carries a tsconfig.json, and `tsc` refuses TS5112 when files are
          // named on the command line beside one. The point here is the generated file in
          // isolation, so the project config is explicitly skipped.
          '--ignoreConfig',
          join(dir, 'generated.ts'),
        ],
        { encoding: 'utf8' },
      )
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  }, 60_000)
})

describe('buildPhp', () => {
  const php = buildPhp()

  it('is valid PHP, linted rather than eyeballed', () => {
    const dir = mkdtempSync(join(tmpdir(), 'tokens-php-'))
    try {
      const file = join(dir, 'design-tokens.php')
      writeFileSync(file, php, 'utf8')
      const out = execFileSync('php', ['-l', file], { encoding: 'utf8' })
      expect(out).toContain('No syntax errors')
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  }, 60_000)

  it('carries the same colour values the source resolves', () => {
    for (const theme of ['light', 'dark'] as const) {
      for (const [role, hex] of Object.entries(resolveTheme(theme))) {
        expect(php, `${theme} ${role}`).toContain(`'${role}' => '${hex}'`)
      }
    }
  })

  it('names the font FILES that exist, as a list per family', () => {
    // The A1 defect's own artefact, asserted on the builder: the arabic family is two
    // files, so a singular key could only ever have been right for one of the two.
    expect(php).toContain("'tajawal-arabic-400.woff2',")
    expect(php).toContain("'tajawal-arabic-700.woff2',")
    expect(php).toContain("'archivo-latin.woff2',")
    expect(php).not.toContain("'tajawal-arabic.woff2'")
  })

  it('round-trips through PHP to the values the source holds', () => {
    // Parsed BY PHP rather than by a regex over the text: the artefact's consumer in 8b is
    // Laravel's config loader, and this is what it will see.
    const dir = mkdtempSync(join(tmpdir(), 'tokens-php-rt-'))
    try {
      const file = join(dir, 'design-tokens.php')
      writeFileSync(file, php, 'utf8')
      const json = execFileSync(
        'php',
        ['-r', `echo json_encode(require ${JSON.stringify(file)});`],
        { encoding: 'utf8' },
      )
      const config = JSON.parse(json) as {
        colours: Record<string, Record<string, string>>
        space: Record<string, string>
        target_min_px: number
        fonts: { arabic: { files: string[] } }
      }
      expect(config.colours.light!.paper).toBe(resolveColour('light', 'paper'))
      expect(config.colours.dark!.paper).toBe(resolveColour('dark', 'paper'))
      expect(config.space['16']).toBe('1rem')
      expect(config.target_min_px).toBe(TARGET_MIN_PX)
      expect(config.fonts.arabic.files).toHaveLength(2)
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  }, 60_000)
})

describe('buildContrastJson and buildContrastMarkdown', () => {
  it('emits every declared pair, with the ratio the source computes', () => {
    const table = JSON.parse(buildContrastJson()) as {
      pairs: { theme: string; fg: string; bg: string; ratio: number; pass: boolean }[]
      orderReady: { note: string; pairs: { fg: string; bg: string; ratio: number }[] }
      failures: unknown[]
      bars: Record<string, number>
      headroom: number
    }
    const computed = contrastPairs()
    expect(table.pairs).toHaveLength(computed.length)
    for (const row of computed) {
      const emitted = table.pairs.find(
        (p) => p.theme === row.theme && p.fg === row.fg && p.bg === row.bg,
      )
      expect(emitted, `${row.theme} ${row.fg} on ${row.bg} is missing`).toBeDefined()
      expect(emitted!.ratio).toBe(row.ratio)
      expect(emitted!.pass).toBe(row.pass)
    }
    expect(table.failures).toHaveLength(contrastFailures().length)
    expect(table.headroom).toBe(HEADROOM)
    expect(table.bars.aaText).toBe(BARS.aaText)

    expect(table.orderReady.pairs).toHaveLength(orderReadyPairs().length)
    for (const pair of orderReadyPairs()) {
      expect(table.orderReady.pairs.find((p) => p.fg === pair.fg && p.bg === pair.bg)?.ratio).toBe(
        pair.ratio,
      )
    }
  })

  it('writes a markdown row for every pair, with the ratio floored to two decimals', () => {
    const md = buildContrastMarkdown()
    for (const row of contrastPairs()) {
      expect(md, `${row.theme} ${row.fg} on ${row.bg}`).toContain(
        `| \`${row.fg}\` | \`${row.bg}\` | \`${row.fgHex}\` | \`${row.bgHex}\` | **${row.ratio.toFixed(2)}**`,
      )
    }
    // The convention is part of the figure, so the document states it.
    expect(md).toContain('truncated at two decimals')
  })
})

describe('ARTEFACTS and run()', () => {
  it('names a distinct path and a callable builder for each artefact', () => {
    const paths = (ARTEFACTS as { path: string; build: () => string; what: string }[]).map(
      (a) => a.path,
    )
    expect(new Set(paths).size, 'two artefacts share a path').toBe(paths.length)
    for (const artefact of ARTEFACTS as { path: string; build: () => string; what: string }[]) {
      expect(typeof artefact.build).toBe('function')
      expect(artefact.what.length).toBeGreaterThan(5)
      expect(artefact.build().length).toBeGreaterThan(100)
    }
  })

  it('returns 0 in check mode when every artefact is current', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    expect(run({ check: true })).toBe(0)
    expect(log.mock.calls.flat().join('\n')).toContain('generated artefacts are current')
  })

  it('returns 1 and names the first differing line when one is STALE', () => {
    // Driven on a real file and restored, because the branch being covered is the diff
    // reporting -- the part that says WHERE rather than that something differs somewhere.
    const target = join(PKG, 'generated', 'tokens.css')
    const backup = `${target}.under-test`
    copyFileSync(target, backup)
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.spyOn(console, 'log').mockImplementation(() => undefined)
    try {
      writeFileSync(target, readFileSync(target, 'utf8').replace('--color-paper', '--color-paperX'))
      expect(run({ check: true })).toBe(1)
      const said = error.mock.calls.flat().join('\n')
      expect(said).toContain('STALE generated artefact(s)')
      expect(said).toContain('differs at line')
      expect(said).toContain(relative(REPO, target).split('\\').join('/'))
    } finally {
      copyFileSync(backup, target)
      rmSync(backup, { force: true })
    }
  })

  it('returns 1 when an artefact is MISSING, which a fresh clone can look like', () => {
    const target = join(PKG, 'generated', 'contrast.json')
    const backup = `${target}.under-test`
    copyFileSync(target, backup)
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.spyOn(console, 'log').mockImplementation(() => undefined)
    try {
      rmSync(target, { force: true })
      expect(run({ check: true })).toBe(1)
      expect(error.mock.calls.flat().join('\n')).toContain('missing')
    } finally {
      copyFileSync(backup, target)
      rmSync(backup, { force: true })
    }
  })

  it('WRITES every artefact in generate mode, byte-identical to what is committed', () => {
    // The writer branch, and the assertion is the valuable half: the bytes it writes equal
    // the bytes in the tree, so running this test cannot dirty the working tree -- which is
    // what CI's own "the working tree is unchanged by this job" step asserts one level up.
    const before = (ARTEFACTS as { path: string }[]).map((a) => readFileSync(a.path, 'utf8'))
    vi.spyOn(console, 'log').mockImplementation(() => undefined)
    expect(run({ check: false })).toBe(0)
    const after = (ARTEFACTS as { path: string }[]).map((a) => readFileSync(a.path, 'utf8'))
    expect(after).toEqual(before)
  })
})
