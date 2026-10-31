import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

import { main, panelSubjects } from '../bin/scan-panel.mjs'
import { PATTERNS, patternsFor, spacingStepsFrom } from '../patterns.mjs'

const REPO = join(import.meta.dirname, '..', '..', '..')
const FIXTURES = 'tools/design-tokens/fixtures'

/**
 * ================================================================================
 * THE PANEL READER IS PROVEN ON COMMITTED FIXTURES, BOTH WAYS
 * ================================================================================
 *
 * `scan-panel.mjs` is the THIRD reader of `patterns.mjs`, over the Filament panels' authored
 * PHP and Blade. ESLint parses neither, and `scan-css.mjs` exempts `apps/api` by name.
 *
 * ⚠ IT IS THE ONLY INSTRUMENT FOR TWO THINGS, which is why it gets a test of its own rather
 * than a line in the gate. `SR-1` measured that Filament CONSUMES Tailwind's default theme,
 * so the panel theme must REDEFINE rather than clear — and two things therefore survive in
 * the panel build that do not survive in the apps':
 *
 *   - the bare `--spacing` key, because in Tailwind v4 its existence is what enables the
 *     dynamic `p-<n>` utility. `p-5` = 20px compiles whatever value it holds, and the key
 *     cannot be dropped because Filament calls `--spacing(…)` at three sites.
 *   - `--color-white` and `--color-black`, which Filament's own CSS `@apply`s 328 times.
 *
 * For those, `panel-theme.test.mjs` can only assert that the exception EXISTS. Refusing what
 * it lets through is this rule's job, and a rule with no test is a claim.
 *
 * BOTH DIRECTIONS, because one alone proves nothing: the bad fixture's shapes must all be
 * found, and the good fixture must produce NOTHING — otherwise a rule that flagged every
 * line would pass the first half.
 */

describe('the panel reader', () => {
  it('applies every pattern the panel reader is meant to apply', () => {
    // The three readers between them must apply EVERY pattern. Before Task 8b this held
    // over two readers; the panel patterns would otherwise be in the list and read by
    // nobody, which is the shape a `where` value silently makes possible.
    const applied = new Set([
      ...patternsFor('js').map((p) => p.id),
      ...patternsFor('css').map((p) => p.id),
      ...patternsFor('panel').map((p) => p.id),
    ])

    expect(applied.size).toBe(PATTERNS.length)
    expect(patternsFor('panel')).toHaveLength(7)

    // The two the panel reader owns outright, by id, so a rename cannot drop them.
    const own = patternsFor('panel')
      .filter((p) => p.where === 'panel')
      .map((p) => p.id)
    expect(own.sort()).toEqual(['panel-off-ladder-spacing', 'panel-structural-colour'])
  })

  it('derives its subjects from the panel themes’ @source globs', () => {
    const subjects = panelSubjects()

    // Not a path list maintained in the scanner: the set Tailwind SCANS is declared once,
    // in each theme's @source lines, and that is the same set that can carry a utility
    // class. A narrowed glob fails `panel-theme.test.mjs`; an empty resolution fails the
    // floor in main().
    expect(subjects.length).toBeGreaterThan(8)

    // Both panels' own code, and both panels' Blade, so a glob that resolved to one half
    // is visible here.
    for (const named of [
      'apps/api/app/Features/Provisioning/Panel/PlatformPanelProvider.php',
      'apps/api/app/Features/Landing/Panel/StudioPanelProvider.php',
      'apps/api/resources/views/platform/dashboard.blade.php',
      'apps/api/resources/views/studio/builder.blade.php',
    ]) {
      expect(subjects, `${named} is not a subject`).toContain(named)
    }
  })

  it('refuses every shape in the bad fixture, by rule', () => {
    const file = `${FIXTURES}/bad-panel.blade.php.fixture`
    const source = readFileSync(join(REPO, file), 'utf8')

    // The fixture is the authority on what is tested, so a shape removed from it stops
    // being covered — hence the named classes rather than only a count.
    for (const named of [
      'p-5',
      'mt-1',
      'gap-3',
      'bg-white',
      'text-white',
      'bg-black',
      'p-[13px]',
    ]) {
      expect(source, `the bad fixture no longer carries ${named}`).toContain(named)
    }

    expect(main([file])).toBe(1)
  })

  it('finds NOTHING in the good fixture, so the proof above is not "everything is a finding"', () => {
    const file = `${FIXTURES}/good-panel.blade.php.fixture`
    const source = readFileSync(join(REPO, file), 'utf8')

    // Approved steps and token utilities, and the one hex that is INPUT-EXAMPLE COPY: a
    // `->placeholder('#1a1a1a')` on the field where a tenant types their own hex. §12's
    // clause is about a value a component renders WITH, and removing the example would make
    // the field worse for exactly the reader §12's zero-technical-background clause names.
    expect(source).toContain("->placeholder('#1a1a1a')")
    expect(source).toContain('p-16')

    expect(main([file])).toBe(0)
  })

  it('exempts only the copy method’s own argument, not the line it sits on', () => {
    // THE EXEMPTION'S BOUNDARY, driven rather than described. A blanket per-line or per-file
    // exemption would have passed the good fixture too, and would have let a real hex
    // through on any line that happened to carry a placeholder.
    const planted = `${FIXTURES}/bad-panel-copy-boundary.blade.php.fixture`
    const line =
      "TextInput::make('x')->placeholder('#1a1a1a')->extraAttributes(['style' => 'color:#10231B']);\n"

    writeFileSync(join(REPO, planted), line)

    try {
      expect(main([planted])).toBe(1)
    } finally {
      rmSync(join(REPO, planted))
    }
  })

  it('refuses an empty target list rather than reporting a clean tree', () => {
    // The vacuity shape BOTH sibling scanners were already fixed for in Task 8a:
    // `scan-css.mjs` printed "0 CSS file(s) scanned" and exited 0, and
    // `check-shell-evidence` did the same. Written in from the start here.
    //
    // ⚠ DRIVEN IN-PROCESS, against a theme directory that is EMPTY, rather than through a
    // subprocess into a copied tree. The sibling proves its floor by spawning node, which
    // proves the behaviour and contributes NO COVERAGE of the lines it exercises -- so its
    // floor shows as uncovered in the report. Injecting the directory measures the refusal
    // as well as proving it.
    const tree = mkdtempSync(join(tmpdir(), 'scan-panel-empty-'))
    try {
      mkdirSync(join(tree, 'filament'), { recursive: true })

      expect(panelSubjects(join(tree, 'filament'))).toEqual([])
      expect(main([], join(tree, 'filament'))).toBe(1)
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  })

  it('treats a MISSING theme directory as nothing to scan, and still refuses', () => {
    // The other end of the same failure: the directory renamed or removed outright, which
    // is the readdirSync throw rather than an empty read.
    const absent = join(tmpdir(), 'scan-panel-does-not-exist-' + String(process.pid))

    expect(panelSubjects(absent)).toEqual([])
    expect(main([], absent)).toBe(1)
  })

  it('contributes nothing from a panel directory that declares no theme', () => {
    // A panel directory with no `theme.css` has declared no scan scope, so it contributes
    // no subjects -- and that is deliberately NOT a finding: the themes are what declare
    // the scope, and a panel without one has declared none. Driven, because a `continue`
    // nobody reaches is a `continue` nobody has checked.
    const tree = mkdtempSync(join(tmpdir(), 'scan-panel-no-theme-'))
    try {
      mkdirSync(join(tree, 'filament', 'platform'), { recursive: true })
      mkdirSync(join(tree, 'filament', 'studio'), { recursive: true })
      // Only one of the two carries a theme, and its glob reaches one real file.
      // RELATIVE, because `panelSubjects` resolves a glob against the stylesheet exactly as
      // Tailwind does -- and `join()` with an absolute second argument silently produces a
      // path under the first, which is how the first version of this case got 0 subjects.
      const glob = relative(
        join(tree, 'filament', 'platform'),
        join(REPO, 'apps/api/resources/views/platform'),
      )
      writeFileSync(join(tree, 'filament', 'platform', 'theme.css'), `@source '${glob}/**/*';\n`)

      const subjects = panelSubjects(join(tree, 'filament'))

      expect(subjects.length).toBeGreaterThan(0)
      expect(subjects.every((f) => f.includes('views/platform'))).toBe(true)
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  })

  it('refuses a truncated spacing ladder rather than building a pattern from it', () => {
    // ⚠ THE WORST FAILURE THIS FILE COULD HAVE, so it is driven rather than trusted. The
    // off-ladder pattern is a NEGATIVE lookahead over the approved steps: built from a
    // partial read it would refuse approved values AND admit unapproved ones, and the rule
    // would be confidently wrong in both directions at once.
    expect(() => spacingStepsFrom('  --space-4: 0.25rem;\n  --space-8: 0.5rem;\n')).toThrow(
      /read only 2 spacing steps/,
    )

    // And the real artefact passes it, so the floor is not simply unreachable.
    expect(
      spacingStepsFrom(readFileSync(join(REPO, 'packages/tokens/generated/tokens.css'), 'utf8')),
    ).toEqual(['4', '8', '12', '16', '24', '32', '48', '64', '96'])
  })

  it('still scans the real tree, so the refusals above did not break the ordinary run', () => {
    // The other half: a floor that refused everything would also never pass vacuously.
    expect(main([])).toBe(0)
    expect(panelSubjects().length).toBeGreaterThanOrEqual(8)
  })
})
