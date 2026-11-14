import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { afterEach, describe, expect, it } from 'vitest'

import { CURRENTCOLOR_DERIVED } from '../paint-classify.mjs'
import { DEFAULT_BASELINE, WIDTHS, run } from '../paint-report.mjs'

/**
 * ================================================================================
 * THE REPORT, DRIVEN END TO END ON SYNTHETIC RECORDS — QRPAGER-49A
 * ================================================================================
 *
 * `paint-classify.test.mjs` proves the SORTING. This proves what is built on it: that a record
 * which cannot be trusted is refused rather than classified, that the exit code is the
 * verdict, that the templates are whatever the caller enumerates (never a list in the
 * reporter), and that the dead-control baseline is red in each of its three ways.
 *
 * ⚠ IT RUNS `run()` IN PROCESS, which is why the coverage scope can see it, and ADDITIONALLY
 * spawns the real file twice at the bottom — once with no arguments, once on a clean fixture —
 * because `run()` returning 0 is not the same claim as the process exiting 0.
 */
const THEME = {
  'color.background': '#ff00e4',
  'color.surface': '#00ff95',
  'color.text': '#1400ff',
  'color.muted': '#ff7a00',
  'color.accent': '#00e5ff',
  'color.accent_text': '#7d00ff',
}

const rgb = (hex) =>
  `rgb(${[1, 3, 5].map((i) => String(Number.parseInt(hex.slice(i, i + 2), 16))).join(', ')})`

/**
 * One sample, as the collector writes it.
 */
function sample(overrides) {
  return {
    el: 0,
    path: 'html>body>p',
    shape: 'p',
    pseudo: null,
    property: 'color',
    named: true,
    value: '',
    colour: '',
    box: { w: 100, h: 24 },
    text: 'hello',
    facts: { hasText: true },
    ...overrides,
  }
}

/**
 * The six roles, each painted by exactly one sample, so a page built from this uses every
 * picker. A test that wants a dead role removes one entry.
 */
function paintingSamples(omit = []) {
  const all = {
    'color.background': sample({
      el: 1,
      shape: 'body',
      property: 'background-color',
      colour: rgb(THEME['color.background']),
      facts: {},
    }),
    'color.surface': sample({
      el: 2,
      shape: 'article.card',
      property: 'background-color',
      colour: rgb(THEME['color.surface']),
      facts: {},
    }),
    'color.text': sample({
      el: 3,
      shape: 'p',
      property: 'color',
      colour: rgb(THEME['color.text']),
    }),
    'color.muted': sample({
      el: 4,
      shape: 'section',
      property: 'border-top-color',
      colour: rgb(THEME['color.muted']),
      facts: { width: 1, style: 'solid' },
    }),
    'color.accent': sample({
      el: 5,
      shape: 'a.cta',
      property: 'background-color',
      colour: rgb(THEME['color.accent']),
      facts: {},
    }),
    'color.accent_text': sample({
      el: 5,
      shape: 'a.cta',
      property: 'color',
      colour: rgb(THEME['color.accent_text']),
    }),
  }

  return Object.entries(all)
    .filter(([role]) => !omit.includes(role))
    .map(([, s]) => ({ ...s, value: s.colour }))
}

const roots = []

afterEach(() => {
  while (roots.length > 0) rmSync(roots.pop(), { recursive: true, force: true })
})

/**
 * Write a directory of records for `templates`, and return its path.
 *
 * `page(template, width)` may return overrides for that record; `samples(template, width)` may
 * return that record's samples.
 */
function fixture({
  templates,
  samples = () => paintingSamples(),
  page = () => ({}),
  theme = THEME,
}) {
  const dir = mkdtempSync(join(tmpdir(), 'theme-paint-'))

  roots.push(dir)

  for (const template of templates) {
    writeFileSync(
      join(dir, `manifest-${template}.json`),
      JSON.stringify({ template, theme, checksum: 'x' }),
    )

    for (const width of WIDTHS) {
      writeFileSync(
        join(dir, `paint-${template}-${String(width)}.json`),
        JSON.stringify({
          url: 'http://demo.test/',
          template,
          requestedWidth: width,
          innerWidth: width,
          innerHeight: 844,
          documentHeight: 4000,
          minWidth48rem: width >= 768,
          elements: 110,
          properties: ['color', 'background-color'],
          customProperties: Object.fromEntries(
            Object.entries(theme).map(([role, value]) => [
              `--${role.replace(/[._]/g, '-')}`,
              value,
            ]),
          ),
          samples: samples(template, width),
          styleAttributes: [{ path: 'html>body>figure', style: 'margin:0' }],
          browser: { name: 'chromium', version: '142.0.7444.0', userAgent: 'x' },
          ...page(template, width),
        }),
      )
    }
  }

  return dir
}

function baselineFile(dead) {
  const dir = mkdtempSync(join(tmpdir(), 'theme-paint-baseline-'))

  roots.push(dir)

  const path = join(dir, 'baseline.json')

  writeFileSync(path, JSON.stringify({ generated: '2026-11-14', dead }))

  return path
}

function report(options) {
  const lines = []
  const result = run({ ...options, out: (text) => lines.push(text), write: options.write ?? false })

  return { ...result, text: lines.join('\n') }
}

const EMPTY = () => baselineFile([])

describe('a clean page', () => {
  it('exits 0 when every value is a sentinel or a declared member and every role paints', () => {
    const dir = fixture({ templates: ['alpha', 'beta'] })
    const {
      exitCode,
      text,
      report: result,
    } = report({ dir, templates: ['alpha', 'beta'], baselinePath: EMPTY() })

    expect(exitCode).toBe(0)
    expect(text).toContain('every colour-bearing computed value is a sentinel or a declared member')
    expect(text).toContain('the dead controls are exactly the frozen baseline (0)')
    expect(text).toContain('none. Every colour-bearing computed value')
    expect(result.findings).toEqual([])
    expect(result.dead).toEqual([])
  })

  it('prints the engine build it was handed, and every figure the claim relies on', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(text).toContain('chromium 142.0.7444.0')
    expect(text).toMatch(/alpha @ 390\s+390\s+844\s+4000\s+false\s+110\s+6\s+6\s+0\s+0/)
    expect(text).toMatch(/alpha @ 1280\s+1280\s+844\s+4000\s+true/)
    expect(text).toContain('properties read per element')
    expect(text).toContain('color, background-color')
    // the per-role cell is occurrences/painting, so "zero occurrences" is a printed fact
    expect(text).toMatch(/color\.surface\s+390: 1\/1\s+1280: 1\/1\s+paints/)
  })

  it('writes the machine-readable report beside the records when asked to', () => {
    const dir = fixture({ templates: ['alpha'] })

    report({ dir, templates: ['alpha'], baselinePath: EMPTY(), write: true })

    const written = JSON.parse(readFileSync(join(dir, 'theme-paint-report.json'), 'utf8'))

    expect(written.templates).toEqual(['alpha'])
    expect(written.totals.samples).toBe(12)
    expect(written.browser).toBe('chromium 142.0.7444.0')
  })
})

describe('the templates are whatever the caller enumerates', () => {
  it('reports on a FOURTH template the moment it is passed, with no list to add it to', () => {
    const templates = ['alpha', 'beta', 'gamma', 'delta']
    const dir = fixture({ templates })
    const { exitCode, text } = report({ dir, templates, baselinePath: EMPTY() })

    expect(exitCode).toBe(0)

    for (const template of templates) {
      expect(text).toContain(`${template} @ 390`)
      expect(text).toContain(`${template} @ 1280`)
    }
  })

  it('fails when an enumerated template has no measurement, rather than reporting a smaller set', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { exitCode, text } = report({ dir, templates: ['alpha', 'beta'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toContain('the manifest for beta is missing')
    expect(text).toContain('not a clean result')
  })

  it('refuses an empty list', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { exitCode, text } = report({ dir, templates: [], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toContain('the template list is empty')
  })
})

describe('category (c)', () => {
  it('is red, and names the property, the element and the value', () => {
    const dir = fixture({
      templates: ['alpha'],
      samples: () => [
        ...paintingSamples(),
        sample({
          el: 9,
          shape: 'div.rail',
          path: 'html>body>main>div.rail',
          property: 'background-color',
          value: 'rgb(18, 52, 86)',
          colour: 'rgb(18, 52, 86)',
          facts: {},
        }),
      ],
    })
    const {
      exitCode,
      text,
      report: result,
    } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(result.findings).toHaveLength(2)
    expect(text).toContain('#123456')
    expect(text).toContain('background-color')
    expect(text).toContain('html>body>main>div.rail')
    expect(text).toContain('PAINTS')
    expect(text).toContain('alpha @ 390  — 1 distinct')
    expect(text).toContain('2 finding(s) in category (c)')
  })

  it('collapses one root defect to ONE row per render and lists the rest as a consequence', () => {
    const black = 'rgb(0, 0, 0)'
    const root = { el: 0, shape: 'html', path: 'html', text: '', box: { w: 390, h: 4000 } }
    const dir = fixture({
      templates: ['alpha'],
      samples: () => [
        ...paintingSamples(),
        sample({
          ...root,
          property: 'color',
          value: black,
          colour: black,
          facts: { hasText: false },
        }),
        ...CURRENTCOLOR_DERIVED.map((property) =>
          sample({
            ...root,
            property,
            value: black,
            colour: black,
            facts: { width: 0, style: 'none', line: 'none' },
          }),
        ),
      ],
    })
    const {
      exitCode,
      text,
      report: result,
    } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    // 11 black values per render, two renders: 22 "other", but TWO findings
    expect(result.totals.other).toBe(22)
    expect(result.totals.derived).toBe(20)
    expect(result.findings).toHaveLength(2)
    expect(text).toContain('(20 of the other are currentcolor-derived consequences)')
    expect(text).toContain('consequence — the same value, inherited via currentcolor, in:')
    expect(text).toContain('border-top-color')
  })

  it('catches a root custom property that does not hold the published value — the first link', () => {
    const dir = fixture({
      templates: ['alpha'],
      page: () => ({
        customProperties: { '--color-background': '#ffffff', '--color-text': '' },
      }),
    })
    const { exitCode, text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toContain('the root custom property does not hold the published value')
    expect(text).toContain('(absent)')
    expect(text).toContain('#ffffff')
  })

  it('lists the style attributes the page sets inline', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(text).toContain('STYLE ATTRIBUTES')
    expect(text).toContain('×2  html>body>figure')
    expect(text).toContain('style="margin:0"')
  })
})

describe('a record that cannot be trusted is refused, never classified', () => {
  it('refuses a render whose window.innerWidth is not the width the claim names', () => {
    const dir = fixture({
      templates: ['alpha'],
      page: (_template, width) => (width === 390 ? { innerWidth: 360 } : {}),
    })
    const {
      exitCode,
      text,
      report: result,
    } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(result).toBeNull()
    expect(text).toContain('THE MEASUREMENT IS NOT TRUSTWORTHY')
    expect(text).toContain('window.innerWidth is 360, not 390')
    // and NOTHING was classified: a partial report would read as a result
    expect(text).not.toContain('EVERYTHING ELSE')
  })

  it('refuses a record that does not say which browser produced it', () => {
    const dir = fixture({
      templates: ['alpha'],
      page: () => ({ browser: { name: 'chromium', version: '' } }),
    })
    const { exitCode, text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toContain('does not say which browser build produced it')
  })

  it('refuses records produced by more than one browser build', () => {
    const dir = fixture({
      templates: ['alpha'],
      page: (_template, width) => ({
        browser: { name: 'chromium', version: width === 390 ? '142.0.1' : '142.0.2' },
      }),
    })
    const { exitCode, text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toContain('more than one browser build')
  })

  it('refuses a record that is missing, or is not JSON', () => {
    const dir = fixture({ templates: ['alpha'] })

    rmSync(join(dir, 'paint-alpha-1280.json'))

    expect(report({ dir, templates: ['alpha'], baselinePath: EMPTY() }).text).toContain(
      'the alpha @ 1280 record is missing',
    )

    writeFileSync(join(dir, 'paint-alpha-1280.json'), '{not json')

    const broken = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(broken.exitCode).toBe(1)
    expect(broken.text).toContain('is not valid JSON')
  })

  it('refuses manifests published under different themes, and two roles sharing one value', () => {
    const dir = fixture({ templates: ['alpha', 'beta'] })

    writeFileSync(
      join(dir, 'manifest-beta.json'),
      JSON.stringify({ template: 'beta', theme: { ...THEME, 'color.muted': '#000001' } }),
    )

    expect(report({ dir, templates: ['alpha', 'beta'], baselinePath: EMPTY() }).text).toContain(
      'beta was published under a different theme',
    )

    const shared = { ...THEME, 'color.muted': THEME['color.text'] }
    const twin = fixture({ templates: ['alpha'], theme: shared })
    const collision = report({ dir: twin, templates: ['alpha'], baselinePath: EMPTY() })

    expect(collision.exitCode).toBe(1)
    expect(collision.text).toContain('5 distinct values for 6 roles')
  })
})

describe('the dead-control baseline', () => {
  const noSurface = (template) => paintingSamples(template === 'alpha' ? ['color.surface'] : [])

  it('is RED for a dead control that is not baselined, and names it', () => {
    const dir = fixture({ templates: ['alpha', 'beta'], samples: noSurface })
    const { exitCode, text } = report({ dir, templates: ['alpha', 'beta'], baselinePath: EMPTY() })

    expect(exitCode).toBe(1)
    expect(text).toMatch(/color\.surface\s+390: 0\/0\s+1280: 0\/0\s+DEAD — NOT in the baseline/)
    expect(text).toContain('RED  a NEW dead control: alpha / color.surface')
  })

  it('is green when the dead control is baselined, and says whose it is', () => {
    const dir = fixture({ templates: ['alpha', 'beta'], samples: noSurface })
    const { exitCode, text } = report({
      dir,
      templates: ['alpha', 'beta'],
      baselinePath: baselineFile([
        { template: 'alpha', role: 'color.surface', owner: 'QRPAGER-49' },
      ]),
    })

    expect(exitCode).toBe(0)
    expect(text).toContain('DEAD — baselined, owner QRPAGER-49')
    expect(text).toContain('the dead controls are exactly the frozen baseline (1)')
  })

  it('is RED when a baselined control now paints — a stale claim of debt', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { exitCode, text } = report({
      dir,
      templates: ['alpha'],
      baselinePath: baselineFile([
        { template: 'alpha', role: 'color.surface', owner: 'QRPAGER-49' },
      ]),
    })

    expect(exitCode).toBe(1)
    expect(text).toContain('STALE baseline entry: alpha / color.surface now paints')
  })

  it('is RED when the baselined template is no longer enumerated — which is what forces QRPAGER-49 to delete it', () => {
    const dir = fixture({ templates: ['replacement'] })
    const { exitCode, text } = report({
      dir,
      templates: ['replacement'],
      baselinePath: baselineFile([
        { template: 'alpha', role: 'color.surface', owner: 'QRPAGER-49' },
      ]),
    })

    expect(exitCode).toBe(1)
    expect(text).toContain('names a template or role that is no longer enumerated')
  })

  it('prints each role by name, for each template, with the boundary of the claim stated', () => {
    const dir = fixture({ templates: ['alpha'] })
    const { text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    for (const role of Object.keys(THEME)) expect(text).toContain(role)

    expect(text).toContain('a role painted ONLY in a state this harness does not render')
    expect(text).toContain('counts as UNPAINTED here')
  })

  it('treats a role painted only at ONE width as painted', () => {
    const dir = fixture({
      templates: ['alpha'],
      samples: (_template, width) => paintingSamples(width === 390 ? ['color.accent'] : []),
    })
    const { exitCode, text } = report({ dir, templates: ['alpha'], baselinePath: EMPTY() })

    expect(exitCode).toBe(0)
    expect(text).toMatch(/color\.accent\s+390: 0\/0\s+1280: 1\/1\s+paints/)
  })

  it('refuses a missing or malformed baseline file rather than assuming nothing is allowed', () => {
    const dir = fixture({ templates: ['alpha'] })
    const missing = report({ dir, templates: ['alpha'], baselinePath: join(dir, 'nope.json') })

    expect(missing.exitCode).toBe(1)
    expect(missing.text).toContain('the dead-control baseline is missing')

    const bad = join(dir, 'bad.json')

    writeFileSync(bad, JSON.stringify({ generated: 'x' }))

    expect(report({ dir, templates: ['alpha'], baselinePath: bad }).text).toContain(
      'has no `dead` list',
    )
  })

  it('ships a baseline that names exactly the two controls Muhammad ruled not to fix, each with an owner', () => {
    const shipped = JSON.parse(readFileSync(DEFAULT_BASELINE, 'utf8'))

    expect(shipped.dead.map((entry) => `${entry.template}/${entry.role}`)).toEqual([
      'rail/color.surface',
      'ember/color.accent_text',
    ])

    for (const entry of shipped.dead) {
      expect(entry.owner).toBe('QRPAGER-49')
      expect(entry.mechanism.length).toBeGreaterThan(40)
    }
  })
})

describe('the real file, as a process', () => {
  const script = join(import.meta.dirname, '..', 'paint-report.mjs')

  it('exits 64 with a usage line when it is not told what to measure', () => {
    const result = spawnSync(process.execPath, [script], { encoding: 'utf8' })

    expect(result.status).toBe(64)
    expect(result.stderr).toContain('usage: node tools/landing/paint-report.mjs')
  })

  it('exits 0 on a clean fixture and 1 on a dirty one — the exit code IS the verdict', () => {
    const baseline = EMPTY()
    const clean = fixture({ templates: ['alpha'] })
    const ok = spawnSync(
      process.execPath,
      [script, clean, '--templates', 'alpha', '--baseline', baseline],
      { encoding: 'utf8' },
    )

    expect(ok.status).toBe(0)
    expect(ok.stdout).toContain(
      'every colour-bearing computed value is a sentinel or a declared member',
    )

    const dirty = fixture({
      templates: ['alpha'],
      samples: () => [
        ...paintingSamples(),
        sample({
          el: 9,
          property: 'background-color',
          value: 'rgb(1, 2, 3)',
          colour: 'rgb(1, 2, 3)',
          facts: {},
        }),
      ],
    })
    const bad = spawnSync(
      process.execPath,
      [script, dirty, '--templates', 'alpha', '--baseline', baseline],
      { encoding: 'utf8' },
    )

    expect(bad.status).toBe(1)
    expect(bad.stdout).toContain('finding(s) in category (c)')
  })

  it('uses the shipped baseline when none is named', () => {
    const dir = fixture({ templates: ['rail'] })
    const result = spawnSync(process.execPath, [script, dir, '--templates', 'rail'], {
      encoding: 'utf8',
    })

    // rail paints all six here, so the shipped baseline's rail/color.surface entry is STALE
    expect(result.status).toBe(1)
    expect(result.stdout).toContain('STALE baseline entry: rail / color.surface')
  })
})
