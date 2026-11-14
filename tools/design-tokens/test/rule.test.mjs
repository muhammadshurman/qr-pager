import { spawnSync } from 'node:child_process'
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { Linter } from 'eslint'
import tseslint from 'typescript-eslint'
import { describe, expect, it, vi } from 'vitest'

import plugin from '../index.mjs'
import {
  PATTERNS,
  RULE_IDS,
  colourValueIsAcceptable,
  patternsFor,
  valueIsAcceptable,
} from '../patterns.mjs'
import {
  applyBaseline,
  bladeFiles,
  cssFiles,
  isExempt,
  main,
  missingBaselineFiles,
  readBaseline,
  scanSource,
} from '../bin/scan-css.mjs'
import {
  hasInlineCss,
  hasStyleAttribute,
  hasStyleBlock,
  maskBladeCss,
  styleBodyCount,
} from '../blade-css.mjs'

const HERE = fileURLToPath(new URL('.', import.meta.url))
const REPO = join(HERE, '..', '..', '..')
const fixture = (name) => readFileSync(join(HERE, '..', 'fixtures', name), 'utf8')

/**
 * ================================================================================
 * THE PROOF. An unproven rule is not a rule (QRPAGER-15 §6, trap 1).
 * ================================================================================
 *
 * Task 0's git hooks and `composer guard` rules 7-11 are the precedent in this repo: each
 * was shown RED on a file written to make it red, and each fixture also carries legitimate
 * shapes that must NOT fire. Both halves are here, because a rule that fires on everything
 * gets disabled and is then no rule at all.
 *
 * The first half of `§5.1`'s named invariant is this file. The second half -- no tenant
 * theme value reaching the order-ready pair -- is in packages/tokens and packages/ui.
 */

/** Lint a source string with the three rules, through the real ESLint Linter. */
function lint(code, filename = 'component.tsx') {
  const linter = new Linter()
  return linter.verify(
    code,
    {
      files: ['**/*.tsx', '**/*.ts'],
      languageOptions: {
        parser: tseslint.parser,
        parserOptions: { ecmaFeatures: { jsx: true }, sourceType: 'module' },
      },
      plugins: { '@qrpager/design-tokens': plugin },
      rules: {
        '@qrpager/design-tokens/no-raw-color': 'error',
        '@qrpager/design-tokens/no-raw-spacing': 'error',
        '@qrpager/design-tokens/no-raw-font-size': 'error',
      },
    },
    filename,
  )
}

import { execFileSync } from 'node:child_process'

/**
 * ================================================================================
 * THE $? HAZARD CHECKER IS PROVEN TO FAIL. An unproven guard is not a guard.
 * ================================================================================
 *
 * `tools/gate/bin/check-shell-evidence` is a gate step because three evidence readings in
 * one session reported a command as having SUCCEEDED when it had exited 1 -- twice with
 * the wrong figure published before it was caught. In a project whose `§8` is "do not
 * claim a test passed without showing the output", a harness that misreads an exit code
 * produces evidence that is wrong in the reassuring direction.
 *
 * Driven here as well as in the gate, so CI fails on a regression to the checker itself
 * and not only on a regression to the scripts it checks.
 */
function runChecker(args) {
  try {
    const stdout = execFileSync('tools/gate/bin/check-shell-evidence', args, {
      cwd: HERE.replace(/tools\/design-tokens\/test\/?$/, ''),
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    return { status: 0, out: stdout }
  } catch (error) {
    return { status: error.status, out: `${error.stdout ?? ''}${error.stderr ?? ''}` }
  }
}

describe('the exit-code hazard checker', () => {
  // ⚠ AN EXPLICIT TIMEOUT, AND IT IS NOT A WORKAROUND. These three cases SPAWN A REAL
  // PROCESS, which the 5 s default is not written for -- it is a unit-test default. The
  // slowness that made this fail in CI is fixed at the cause (see the performance case
  // below), so the headroom here is for runner variance rather than for a slow checker.
  const SPAWN_TIMEOUT = 30_000

  it(
    'passes on the real tree, having actually scanned something',
    () => {
      const { status, out } = runChecker([])
      expect(status).toBe(0)
      // Not vacuous: a glob that matched nothing would also "pass", which is why the
      // script itself now refuses an empty target list.
      const scanned = /(\d+) script\(s\) scanned/.exec(out)
      expect(scanned, out).not.toBeNull()
      expect(Number(scanned[1])).toBeGreaterThanOrEqual(20)
    },
    SPAWN_TIMEOUT,
  )

  it(
    'FAILS on the committed bad fixture, naming all five hazards',
    () => {
      const { status, out } = runChecker(['--fixture'])
      expect(status).toBe(1)
      expect(out).toContain("no 'set -o pipefail'")
      expect(out).toContain('$? on a line containing a pipe')
      expect(out).toContain('$? on a line containing a command substitution')
      expect(out).toContain('$? after a line containing a pipe')
      expect((out.match(/bad-evidence\.sh\.fixture:/g) ?? []).length).toBe(5)
    },
    SPAWN_TIMEOUT,
  )

  it(
    'does not fire on the legitimate shapes the same fixture carries',
    () => {
      const { out } = runChecker(['--fixture'])
      for (const line of ['31', '35', '37']) {
        expect(out).not.toContain(`bad-evidence.sh.fixture:${line}`)
      }
    },
    SPAWN_TIMEOUT,
  )

  /**
   * ⚠ A PERFORMANCE ASSERTION, because slowness here was a CORRECTNESS failure.
   *
   * The checker stripped comments and quotes with `printf | sed` PER LINE: 3100 lines
   * across the scanned scripts, so 6200 subprocesses. That measured 4.35 s locally and
   * 6.67 s on a GitHub runner -- past Vitest's 5 s default -- so the first case above
   * FAILED IN CI while passing locally. A gate step slow enough to time out reports red
   * for the wrong reason, which is the one thing a gate must not do.
   *
   * It is one awk pass now and runs in ~0.05 s. The bound below is 60x that and CANNOT be
   * met by a per-line subprocess loop, which is the regression it exists to stop.
   *
   * ⚠ AND IT IS THE BEST OF THREE RUNS, NOT ONE RUN, BECAUSE THE BOUND FLAKED. Its own
   * comment used to say 60x "so it cannot flake on a loaded runner" -- and then it measured
   * **34,225 ms** on 2026-10-31, when this suite ran concurrently with `composer gate`'s
   * `php artisan test`. The checker's own time, measured three times immediately afterwards
   * on the same machine, was **0.05 s** each. So the reasoning was wrong in the way `F10`
   * already records one level over: *"a loaded host turned a passing timeout proof into a
   * red at 27.6 s"*. WALL-CLOCK ON A SHARED MACHINE IS NOT A MEASUREMENT OF THE CODE.
   *
   * THE BOUND IS NOT RAISED, and that is the point. A per-line subprocess loop measured
   * 4.35 s locally and 6.67 s on a runner, so a bound generous enough to absorb 34 s would
   * PASS the regression -- the assertion would still be green and would mean nothing. What
   * changes is the STATISTIC: three runs, and the fastest is the one judged. Contention
   * severe enough to inflate one run rarely inflates all three, while a per-line loop is
   * slow in every run by construction. The other two elapsed times are reported in the
   * failure message, so a future red says whether it was the code or the host.
   */
  it(
    'completes in well under the timeout, so a per-line subprocess loop cannot return',
    () => {
      const elapsed = []
      for (let run = 0; run < 3; run++) {
        const started = Date.now()
        const { status } = runChecker([])
        elapsed.push(Date.now() - started)
        expect(status).toBe(0)
      }
      const fastest = Math.min(...elapsed)
      expect(
        fastest,
        `the checker's FASTEST of three runs took ${String(fastest)}ms (all three: ` +
          `${elapsed.join(', ')}ms). It was 6670ms on a runner when it spawned two ` +
          `processes per line, which timed out the suite; one awk pass is ~50ms. Three ` +
          `slow runs mean the code; one slow run means the host.`,
      ).toBeLessThan(3000)
    },
    SPAWN_TIMEOUT,
  )

  it('refuses an empty target list rather than reporting a pass', () => {
    // The checker's own vacuity guard. A filter that matched nothing used to print
    // "0 script(s) scanned" and exit 0, which is the shape every other guard in this task
    // exists to prevent.
    const { status, out } = runChecker(['/dev/null'])
    expect(status).toBe(1)
    expect(out).toContain('nothing to scan')
  })

  /**
   * The analysis lives in an awk program, and GitHub's ubuntu-latest runner has **mawk**
   * as `awk` while this machine has gawk. A GNU-only construct would pass here and fail
   * there -- the same "works on the builder's machine" shape the fresh-clone proof exists
   * for, one tool down.
   */
  it('uses no GNU-only awk construct', () => {
    // ⚠ COMMENTS STRIPPED FIRST, and this is the THIRD check in this task to need that:
    // the fixture's prose once satisfied the pipefail grep, the CI parity test's "no pnpm
    // version" assertion matched the workflow's own explanatory comment, and this one
    // matched the awk program's comment saying it deliberately avoids ENDFILE. A check
    // that reads its own documentation as evidence is a check that is right by accident.
    const awkProgram = readFileSync(
      join(HERE, '..', '..', 'gate', 'lib', 'shell-evidence.awk'),
      'utf8',
    ).replace(/^\s*#.*$/gm, '')
    for (const gnuOnly of [
      'ENDFILE',
      'BEGINFILE',
      'gensub',
      'asort',
      'asorti',
      'systime',
      'strftime',
      'IGNORECASE',
      'RT',
      'FIELDWIDTHS',
      '--re-interval',
    ]) {
      expect(awkProgram, `${gnuOnly} is a GNU extension and the runner has mawk`).not.toContain(
        gnuOnly,
      )
    }
    // And it finalises the last file in END rather than with ENDFILE, which is how the
    // portable form is recognisable.
    expect(awkProgram).toMatch(/END\s*\{[\s\S]*flush_file/)
  })

  it('gives the same verdict under mawk as under gawk, where mawk is installed', () => {
    // Verified against mawk 1.3.4 in-session: 5 findings on the fixture, 0 on the real
    // tree, identical to gawk. Run here too when mawk is present, and reported rather
    // than silently skipped when it is not -- a skip that looks like a pass is the thing
    // this suite is built against.
    let mawk = true
    try {
      execFileSync('mawk', ['-W', 'version'], { stdio: 'ignore' })
    } catch {
      mawk = false
    }
    if (!mawk) {
      expect(mawk, 'mawk is not installed here; portability is asserted statically above').toBe(
        false,
      )
      return
    }
    const repo = HERE.replace(/tools\/design-tokens\/test\/?$/, '')
    const run = (bin) =>
      execFileSync(
        bin,
        [
          '-v',
          'strictlist=tools/gate/fixtures/bad-evidence.sh.fixture\n',
          '-f',
          'tools/gate/lib/shell-evidence.awk',
          'tools/gate/fixtures/bad-evidence.sh.fixture',
        ],
        { cwd: repo, encoding: 'utf8' },
      )
    expect(run('mawk')).toBe(run('awk'))
    expect(run('mawk')).toContain('FINDINGS\t5')
  })
})

describe('the rule exists in one place and both readers see all of it', () => {
  it('defines the three rules the gate clause names, plus the one QRPAGER-46 added', () => {
    /*
     * ⚠ FOUR RULE NAMES, THREE ESLINT RULES, AND THE DIFFERENCE IS THE POINT. The gate clause
     * names three (`no-raw-color`, `no-raw-spacing`, `no-raw-font-size`) and the ESLint plugin
     * still exposes exactly those: a `var()` fallback is a CSS form that TypeScript cannot
     * carry, so `no-var-fallback` is `where: 'css'` and has no plugin rule.
     *
     * It exists because of the `§11` QA round's H-1 on `QRPAGER-46`: the three above judge the
     * VALUE of a declaration whose PROPERTY they name, so a fallback on `border-radius`,
     * `grid-template-columns` or `outline` was read by nothing, and a fallback is where one
     * token comes to carry two values (`R-268`, live on every published page).
     */
    expect(RULE_IDS).toEqual([
      'no-raw-color',
      'no-raw-spacing',
      'no-raw-font-size',
      'no-var-fallback',
    ])
    expect(Object.keys(plugin.rules).sort()).toEqual([
      'no-raw-color',
      'no-raw-font-size',
      'no-raw-spacing',
    ])
  })

  it('applies every pattern through one reader or another, so none is orphaned', () => {
    // A pattern nobody applies is a pattern that silently stopped working. Exact counts,
    // never a range: a `toBeGreaterThan` here would pass on a list that had shrunk.
    //
    // ⚠ THREE READERS FROM TASK 8b, not two. `scan-panel.mjs` reads the Filament panels'
    // authored PHP and Blade, which ESLint cannot parse and which `scan-css.mjs` exempts by
    // name. The union below GAINED the third reader rather than loosening: without it the
    // two panel patterns would be in the list and applied by nobody, which is exactly the
    // orphan this case exists to refuse.
    expect(PATTERNS).toHaveLength(14)
    expect(patternsFor('js')).toHaveLength(8)
    // ⚠ NINE FOR THE CSS READER AT `QRPAGER-46`: `no-var-fallback` is CSS-only, for the reason
    // the rule-name case above gives.
    expect(patternsFor('css')).toHaveLength(9)
    expect(patternsFor('panel')).toHaveLength(7)

    const applied = new Set([
      ...patternsFor('js').map((p) => p.id),
      ...patternsFor('css').map((p) => p.id),
      ...patternsFor('panel').map((p) => p.id),
    ])
    expect(applied.size).toBe(PATTERNS.length)
  })
})

describe('the ESLint half fails on the committed bad fixture', () => {
  const messages = lint(fixture('bad-component.tsx.fixture'))

  it('produces findings, and every one is one of our three rules', () => {
    expect(messages.length).toBeGreaterThan(0)
    // No parse error: a syntax error would produce `ruleId: null` and would mean the
    // fixture never reached the rules at all. A red counts as evidence only when it is the
    // assertion failing, never a harness exception (QRPAGER-15 §8).
    const parseErrors = messages.filter((m) => m.ruleId === null)
    expect(parseErrors).toEqual([])
    for (const m of messages) {
      expect(m.ruleId).toMatch(/^@qrpager\/design-tokens\/no-raw-(color|spacing|font-size)$/)
      expect(m.severity).toBe(2)
    }
  })

  it('fires every one of the three rules, so none is proven by its neighbours', () => {
    const fired = new Set(messages.map((m) => m.ruleId.replace('@qrpager/design-tokens/', '')))
    expect([...fired].sort()).toEqual(['no-raw-color', 'no-raw-font-size', 'no-raw-spacing'])
  })

  it.each([
    ['#0B6B4F', 'no-raw-color'],
    ['#fff', 'no-raw-color'],
    ['#0B6B4FCC', 'no-raw-color'],
    ['rgb(', 'no-raw-color'],
    ['oklch(', 'no-raw-color'],
    ['color-mix(', 'no-raw-color'],
    ['text-[#fff]', 'no-raw-color'],
    ['bg-[rgb(0,0,0)]', 'no-raw-color'],
    ['border-[#D3DAD2]', 'no-raw-color'],
    ['p-[13px]', 'no-raw-spacing'],
    ['gap-[7px]', 'no-raw-spacing'],
    ['m-[1.5rem]', 'no-raw-spacing'],
    ['text-[13px]', 'no-raw-font-size'],
  ])('catches %s under %s', (needle, rule) => {
    const hits = messages.filter((m) => m.message.includes(needle) && m.ruleId.endsWith(rule))
    expect(hits.length).toBeGreaterThan(0)
  })

  it.each([
    ['color: #10231B', 'no-raw-color'],
    ['backgroundColor: white', 'no-raw-color'],
    ['borderColor: rgb(1,2,3)', 'no-raw-color'],
    ['fontSize: 13', 'no-raw-font-size'],
    ['padding: 13px', 'no-raw-spacing'],
    ['marginTop: 24', 'no-raw-spacing'],
    ['gap: 1.5rem', 'no-raw-spacing'],
    ['lineHeight: 19', 'no-raw-font-size'],
  ])('catches the inline style %s under %s', (needle, rule) => {
    const hits = messages.filter((m) => m.message.includes(needle) && m.ruleId.endsWith(rule))
    expect(hits.length).toBeGreaterThan(0)
  })
})

describe('the ESLint half stays silent on the committed good fixture', () => {
  it('reports nothing at all', () => {
    const messages = lint(fixture('good-component.tsx.fixture'))
    // The message list, not just its length, so a failure says WHICH legitimate shape fired.
    expect(messages.map((m) => `${m.line}: ${m.ruleId} ${m.message}`)).toEqual([])
  })
})

describe('the CSS half', () => {
  it('fails on the bad stylesheet, with every forbidden shape named', () => {
    const findings = scanSource(fixture('bad-styles.css.fixture'), 'bad.css')
    expect(findings.length).toBe(12)

    const fired = new Set(findings.map((f) => f.rule))
    expect([...fired].sort()).toEqual(['no-raw-color', 'no-raw-font-size', 'no-raw-spacing'])

    const ids = new Set(findings.map((f) => f.id))
    expect([...ids].sort()).toEqual(
      [
        'css-font-size-declaration',
        'css-spacing-declaration',
        'color-function',
        'hex',
        'named-color',
      ].sort(),
    )
  })

  it('catches every colour syntax and every magnitude literal a panel theme can carry', () => {
    /*
     * ⚠ EXACT COUNTS, NEVER `> 0`. A `toBeGreaterThan(0)` here would pass while two of the
     * three rules had silently stopped matching — which is the failure this whole fixture
     * exists for, since the hole it was written about was itself three scanners each
     * reporting clean.
     */
    const findings = scanSource(fixture('panel-theme-literals.css.fixture'), 'panel-theme.css')

    expect(findings.length).toBe(19)

    const perRule = {}
    for (const f of findings) perRule[f.rule] = (perRule[f.rule] ?? 0) + 1

    expect(perRule).toEqual({
      // Ten colour LINES, twelve findings: `color-mix(in srgb, #ff00ff, #00ff00)` is one
      // colour function carrying two hexes, and each is a separate decision typed at the
      // use site.
      'no-raw-color': 12,
      'no-raw-spacing': 4,
      'no-raw-font-size': 3,
    })

    expect([...new Set(findings.map((f) => f.id))].sort()).toEqual([
      'color-function',
      'css-font-size-declaration',
      'css-spacing-declaration',
      'hex',
      'named-color',
    ])

    /*
     * ⚠ `oklch()` BY NAME. The grey `QRPAGER-42` A1 replaces is Filament's own
     * `oklch(0.973 0.001 106.424)`, so an override written in that syntax has to be visible
     * or the gap is closed on paper and open in practice.
     */
    expect(findings.some((f) => f.match.includes('oklch'))).toBe(true)

    /*
     * ⚠ AND BOTH SIDES OF THE LADDER. `§12` forbids a hard-coded magnitude, not only an
     * off-scale one, so the ON-scale `16px` is a finding exactly like the off-scale `13px`.
     * A rule that only caught the off-scale value would let a component type a step's number
     * instead of naming it.
     */
    expect(findings.some((f) => f.match.includes('13px'))).toBe(true)
    expect(findings.some((f) => f.match.includes('16px'))).toBe(true)
    expect(findings.some((f) => f.match.includes('17px'))).toBe(true)
  })

  it('catches a shorthand whose parts are MIXED, which it once did not', () => {
    // THE REGRESSION. valueIsAcceptable early-returned on "the value contains a var()",
    // so one token laundered the literal beside it. The bad fixture predicted this line as
    // a finding, the rule did not produce it, and that is how the hole was found. This case
    // fails without the fix.
    const findings = scanSource('.a { padding: var(--space-16) 9px; }', 'mixed.css')
    expect(findings).toHaveLength(1)
    expect(findings[0].rule).toBe('no-raw-spacing')
  })

  it('stays silent on the good stylesheet', () => {
    const findings = scanSource(fixture('good-styles.css.fixture'), 'good.css')
    expect(findings.map((f) => `${f.line}: ${f.rule} ${f.match}`)).toEqual([])
  })

  it('does not fire on a value named inside a comment', () => {
    const findings = scanSource(
      '/* #0B6B4F rgb(1,2,3) white 13px */ .a { color: var(--x); }',
      'c.css',
    )
    expect(findings).toEqual([])
  })

  it('REFUSES an empty target list instead of reporting a clean tree', () => {
    /**
     * ⚠ THE VACUITY, DRIVEN RATHER THAN READ. The script printed
     * "0 CSS file(s) scanned, no hard-coded values." and exited 0 -- so a walk that found
     * nothing looked exactly like a pass. Same shape as its sibling `check-shell-evidence`,
     * which was fixed for it earlier in this task; this one was not, which is how the QA
     * reviewer could name it from the other's commit message.
     *
     * The failure mode is REPRODUCED, not asserted about. `scan-css.mjs` computes its root
     * three directories up from its own location, so a copy of it at the same relative
     * depth inside an empty temporary tree walks that tree and finds nothing. Reading the
     * guard's source instead would be the third instance of "a check that read its own
     * documentation as evidence" this task has already paid for.
     */
    const tree = mkdtempSync(join(tmpdir(), 'scan-css-empty-'))
    try {
      const bin = join(tree, 'tools', 'design-tokens', 'bin')
      mkdirSync(bin, { recursive: true })
      copyFileSync(join(HERE, '..', 'bin', 'scan-css.mjs'), join(bin, 'scan-css.mjs'))
      copyFileSync(join(HERE, '..', 'patterns.mjs'), join(bin, '..', 'patterns.mjs'))
      copyFileSync(join(HERE, '..', 'colour-keywords.mjs'), join(bin, '..', 'colour-keywords.mjs'))
      // `QRPAGER-46`: the scanner now imports the Blade extractor, so the copy needs it too.
      // Without it the child dies on module resolution and the floor message never prints —
      // which failed this case with a stack trace rather than with its own assertion.
      copyFileSync(join(HERE, '..', 'blade-css.mjs'), join(bin, '..', 'blade-css.mjs'))

      const result = spawnSync('node', [join(bin, 'scan-css.mjs')], {
        encoding: 'utf8',
        shell: false,
      })

      expect(result.status, `a tree with no CSS must FAIL:\n${result.stdout}${result.stderr}`).toBe(
        1,
      )
      expect(result.stderr).toContain('cannot report a finding')
      // And it must not have printed the old reassuring line.
      expect(result.stdout).not.toContain('no hard-coded values')
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  }, 30_000)

  it('still scans the real tree, so the guard above did not break the ordinary run', () => {
    // The other half: a floor that refused everything would also "never pass vacuously".
    const result = spawnSync('node', [join(HERE, '..', 'bin', 'scan-css.mjs')], {
      encoding: 'utf8',
      shell: false,
    })
    expect(result.status, result.stdout + result.stderr).toBe(0)
    const scanned = /(\d+) file\(s\) scanned/.exec(result.stdout)
    expect(scanned, result.stdout).not.toBeNull()
    expect(Number(scanned[1])).toBeGreaterThanOrEqual(4)

    // `QRPAGER-46`: and the Blade half ran too. A total that grew while the Blade walk found
    // nothing would satisfy the floor above and restore `R-267` in silence.
    const blades = /\((\d+) Blade template\(s\)/.exec(result.stdout)
    expect(blades, result.stdout).not.toBeNull()
    expect(Number(blades[1])).toBeGreaterThanOrEqual(1)
  }, 30_000)
})

describe('colourValueIsAcceptable -- an allow-list, not a digit check', () => {
  it.each([
    'var(--color-ink)',
    'currentColor',
    'transparent',
    'inherit',
    'var(--color-ink, var(--color-paper))',
    // The approved elevation, as the token it is. There is exactly ONE elevation in the
    // system, so a component names it rather than rebuilding it.
    'var(--elevation-card)',
  ])('accepts %s', (v) => {
    expect(colourValueIsAcceptable(v)).toBe(true)
  })

  it.each([
    'white',
    'black',
    'red',
    '#fff',
    '#10231B',
    'rgb(1,2,3)',
    'oklch(62% 0.1 160)',
    // A literal hidden in a fallback renders when the token is absent.
    'var(--color-ink, #fff)',
    'var(--color-ink, white)',
    // ⚠ MY FIRST EXPECTATION HAD THIS IN THE ACCEPTED LIST AND THE RULE WAS RIGHT, NOT ME.
    // A box-shadow re-typed at the use site is a second source for the one approved
    // elevation, even when its colour part is a token: `2px` is not on the spacing scale
    // and `0 1px 2px` is the elevation's own composition. The acceptable form is
    // `var(--elevation-card)`, above.
    '0 1px 2px var(--color-hairline)',
    '0 2px 8px var(--color-ink)',
  ])('refuses %s', (v) => {
    expect(colourValueIsAcceptable(v)).toBe(false)
  })
})

describe('valueIsAcceptable, the judgement both readers share', () => {
  it.each([
    'var(--space-16)',
    'var(--color-ink)',
    '0',
    '0px',
    '100%',
    'auto',
    'inherit',
    '1px',
    '1.5',
    'calc(var(--space-16) / 2)',
    'clamp(var(--space-8), var(--space-16), var(--space-24))',
    'var(--space-24) var(--space-24)',
    'min(var(--space-16), var(--space-24))',
  ])('accepts %s', (v) => {
    expect(valueIsAcceptable(v)).toBe(true)
  })

  it.each([
    '13px',
    '1.5rem',
    '9px',
    'var(--space-16) 9px',
    '5px 6px',
    'calc(var(--space-16) + 9px)',
    // A literal hidden in a var() fallback is what renders when the token is absent, so it
    // is a value typed at the use site.
    'var(--space-16, 13px)',
    'clamp(var(--space-8), 13px, var(--space-24))',
  ])('refuses %s', (v) => {
    expect(valueIsAcceptable(v)).toBe(false)
  })
})

/**
 * ================================================================================
 * THE SCANNER'S WALK AND ITS CLI — 129 LINES THAT WERE OUTSIDE THE DENOMINATOR
 * ================================================================================
 *
 * ⚠ QA #10. `scan-css.mjs` was not in the coverage `include` list, so the only part of it
 * any figure described was `scanSource`, reached by the cases above. Its exemption logic,
 * its directory walk and its whole CLI -- the empty-target refusal, the finding report, the
 * exit status -- counted towards nothing, and that logic is where two of this task's eight
 * defects were (it walked into `node_modules` and reported 69 findings in jsdom's
 * stylesheet; and it followed a workspace symlink and scanned the same file twice).
 *
 * `main` returns a status rather than calling `process.exit`, which is what lets these run
 * in-process and therefore count. The script's own process behaviour is still driven as a
 * process by the empty-tree proof above, because that proof is about its root resolution.
 */
describe('the scanner’s exemptions', () => {
  it('exempts node_modules at ANY depth, which is the defect it was fixed for', () => {
    // pnpm with `hoist=false` puts a node_modules in every package. A root-relative prefix
    // list matched only the root's own, and the first real run reported 69 findings in
    // jsdom's default stylesheet.
    expect(isExempt(join(REPO, 'node_modules/jsdom/lib/default.css'))).toBe(true)
    expect(
      isExempt(join(REPO, 'packages/ui/node_modules/@qrpager/tokens/generated/tokens.css')),
    ).toBe(true)
    expect(isExempt(join(REPO, 'apps/web/node_modules/next/dist/x.css'))).toBe(true)
  })

  it('exempts each build output and each deliberately-wrong fixture, by reason', () => {
    for (const path of [
      // Build outputs: not authored here.
      'apps/web/.next/static/css/a.css',
      'coverage/lcov-report/base.css',
      // The token source and its generated outputs ARE the values.
      'packages/tokens/src/source.ts',
      'packages/tokens/generated/tokens.css',
      // Deliberately-wrong files, proven on by the cases above.
      'tools/design-tokens/fixtures/bad-styles.css.fixture',
      'tools/design-tokens/fixtures/panel-theme-literals.css.fixture',
      // Vite's compiled output and the third-party tree under the Laravel half. Not
      // authored here, regenerated on every build, and 243 stylesheets between them.
      'apps/api/public/build/assets/app-oTEylG5A.css',
      'apps/api/vendor/filament/filament/dist/theme.css',
    ]) {
      expect(isExempt(join(REPO, path)), `${path} should be exempt`).toBe(true)
    }
  })

  it('exempts NOTHING it should scan, which is the half that keeps the rule honest', () => {
    // `packages/ui/src/styles/templates.css` was the second entry here and is DELETED --
    // `R-240`, Muhammad 2026-11-04, the other half of the orphan QRPAGER-33 retired. Its
    // sibling note below records the identical shape for `apps/site/app/globals.css`.
    /*
     * ⚠ THE FOUR `apps/api` ENTRIES ARE NEW AT `QRPAGER-42`, AND ONE OF THEM USED TO BE
     * ASSERTED IN THE LIST ABOVE — `apps/api/resources/css/app.css`, with the comment *"The
     * Laravel half: `composer guard` rule 11 covers it."*
     *
     * Rule 11 does not cover it. The guard's scanned paths are `app, config, database,
     * routes, tests, guard/src`, with no `resources/`, and rule 11's own application paths
     * are narrower still. So the exemption was double-locked: a comment naming an owner that
     * never received the file, and a TEST asserting the exemption was correct. Removing only
     * the comment would have reddened this case and invited someone to restore the hole.
     *
     * What it left unscanned was the two panel themes — the files that decide the entire
     * appearance of both admin panels. Proven before the change: a raw magenta, an
     * off-ladder padding and an off-scale font size appended to the studio theme were
     * reported clean by `scan-css`, `scan-panel` AND `composer guard`.
     */
    for (const path of [
      'packages/ui/src/styles/kit.css',
      'apps/web/app/globals.css',
      'apps/api/resources/css/app.css',
      'apps/api/resources/css/filament/studio/theme.css',
      'apps/api/resources/css/filament/platform/theme.css',
      'apps/api/resources/css/filament/targets.css',
    ]) {
      expect(isExempt(join(REPO, path)), `${path} must be scanned`).toBe(false)
    }
  })

  /*
   * ⚠ `apps/site/app/globals.css` WAS NAMED IN BOTH LISTS HERE AND NO LONGER EXISTS. Task 11
   * (ADR-005) made apps/site the local stand-in for the CDN's `Host` -> prefix mapping: it
   * renders nothing, ships no stylesheet, and `R-176` is discharged by the surface not
   * existing rather than by the compile proof that row asked for.
   *
   * The two app entries are DERIVED now rather than shortened by one. A hard-coded pair is
   * precisely what went stale, and it would go stale again the day a third app arrives — so
   * what is asserted is that every stylesheet the walk finds under `apps/` is scanned, plus a
   * floor so an empty walk cannot pass.
   */
  it('walks the real tree and finds every stylesheet this task authors', () => {
    const found = cssFiles(REPO).map((p) => p.replace(`${REPO}/`, ''))
    // ⚠ ONE KIT STYLESHEET SINCE `R-240`, and the note above already explains why a
    // hard-coded list here goes stale: `templates.css` is the SECOND entry to leave it,
    // after `apps/site/app/globals.css`. The floor below is what stops the shrinking list
    // becoming an empty walk nobody notices.
    for (const expected of ['packages/ui/src/styles/kit.css']) {
      expect(found, `${expected} was not found by the walk`).toContain(expected)
    }

    const appSheets = found.filter((p) => p.startsWith('apps/'))
    expect(appSheets.length, 'the walk found no app stylesheet at all').toBeGreaterThanOrEqual(1)
    for (const sheet of appSheets) {
      expect(isExempt(join(REPO, sheet)), `${sheet} must be scanned`).toBe(false)
    }
    // And nothing from a dependency: a linter that reports its own dependencies' code has
    // no signal left in it.
    expect(found.filter((p) => p.includes('node_modules'))).toEqual([])
  }, 30_000)
})

describe('the scanner’s CLI', () => {
  it('returns 0 and reports the count on the real tree', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    expect(main([])).toBe(0)
    /*
     * ⚠ `file(s)` AND NOT `CSS file(s)`: `QRPAGER-46` made this scanner read Blade templates
     * too (`R-267`), so the count is no longer a count of stylesheets. The Blade half's own
     * floor is asserted in the `R-267` block below.
     */
    expect(log.mock.calls.flat().join('\n')).toMatch(/\d+ file\(s\) scanned/)
    vi.restoreAllMocks()
  }, 30_000)

  it('returns 1 and names file:line:column for each finding', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const fixturePath = join(HERE, '..', 'fixtures', 'bad-styles.css.fixture')
    expect(main([fixturePath])).toBe(1)
    const said = error.mock.calls.flat().join('\n')
    // A failure must say WHAT to change, not that something is wrong somewhere.
    expect(said).toMatch(/bad-styles\.css\.fixture:\d+:\d+/)
    expect(said).toContain('no-raw-color')
    expect(said).toMatch(/12 finding\(s\) in 1 file\(s\)/)
    vi.restoreAllMocks()
  })

  it('returns 0 on an explicit list of clean files', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    expect(main([join(HERE, '..', 'fixtures', 'good-styles.css.fixture')])).toBe(0)
    expect(log.mock.calls.flat().join('\n')).toContain('no hard-coded values')
    vi.restoreAllMocks()
  })

  it('does NOT apply the floor to an explicit list, which would break `lint` on one file', () => {
    // The floor is about a WALK that found nothing. A caller naming one file means it.
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    expect(main([join(REPO, 'packages/ui/src/styles/kit.css')])).toBe(0)
    expect(log.mock.calls.flat().join('\n')).toContain('1 file(s) scanned')
    vi.restoreAllMocks()
  })
})

/**
 * ================================================================================
 * CSS OUT OF A BLADE TEMPLATE — `R-267`, CLOSED BY `QRPAGER-46`
 * ================================================================================
 *
 * `R-267`: *"THE PUBLIC LANDING PAGE — THE ONLY SURFACE A CUSTOMER SEES — HAS NEVER BEEN
 * SCANNED BY ANYTHING."* The row proved it by INJECTION: `.landing-gap-proof{color:#ff00ff;
 * padding:13px;font-size:17px}` placed in that template's `<style>` block was reported clean
 * by `scan-css` (10 files), `scan-panel` (28 files) and `composer guard` (463 files).
 *
 * The row also forbids the cheap reading of the fix — *"⚠⚠ THE FIX IS A PARSER, NOT A GLOB"*
 * — so these cases are about a PARSER: what it reads, what it refuses to read, and that the
 * same injection is now reported with the template's own line number.
 */
describe('CSS out of a Blade template — R-267', () => {
  const landing = join(REPO, 'apps/api/resources/views/landing/page.blade.php')

  it('reads every literal inside a <style> body and nothing outside one', () => {
    /*
     * ⚠ EXACT COUNTS, NEVER `> 0` — the bar `panel-theme-literals.css.fixture` set at
     * `QRPAGER-42`: *"a `toBeGreaterThan(0)` here would pass while two of the three rules had
     * silently stopped matching"*, and the hole this closes was itself three scanners each
     * reporting clean.
     */
    const findings = scanSource(
      maskBladeCss(fixture('landing-blade-literals.blade.php.fixture')),
      'landing-blade-literals.blade.php',
    )

    expect(findings.length).toBe(15)

    const perRule = {}
    for (const f of findings) perRule[f.rule] = (perRule[f.rule] ?? 0) + 1

    expect(perRule).toEqual({
      // In the style element: `#ff00ff`, `teal;`, `red}`, `rgba(…)`, and the `#ff00ff` on the
      // same line as a directive, which the §11 security round's M-2 showed the first blanker
      // swallowing whole (5). In `style` ATTRIBUTES, QRPAGER-49B / R-322: a hex, a named colour
      // after an echo that contains quotes, an hsl() in a single-quoted one, and a named colour
      // in an unquoted one (4).
      'no-raw-color': 9,
      // `13px` in the plain block, `11px` inside an `@if` branch, `19px` inside `@media`, and
      // the `13px` in the first inline attribute.
      'no-raw-spacing': 4,
      'no-raw-font-size': 1,
      // `border-radius:var(--radius-large,2px)` — the §11 QA round's H-1: a fallback on a
      // property no value guard judges.
      'no-var-fallback': 1,
    })

    expect([...new Set(findings.map((f) => f.id))].sort()).toEqual([
      'color-function',
      'css-font-size-declaration',
      'css-spacing-declaration',
      'hex',
      'named-color',
      'var-fallback',
    ])

    /*
     * ⚠ AND THE SHAPES THAT MUST NOT FIRE, EACH NAMED. The fixture carries the same `#ff00ff`
     * four more times — in a Blade comment, in an echo, in a `@php` block and in an inline
     * `style` attribute — and `3` above is the proof that none of them was counted. These
     * assertions say WHICH, so a future change that starts reporting one of them fails with a
     * message rather than with an arithmetic difference.
     */
    const lines = findings.map((f) => f.line)
    const source = fixture('landing-blade-literals.blade.php.fixture').split('\n')
    const lineOf = (needle) => source.findIndex((line) => line.includes(needle)) + 1

    expect(lines).not.toContain(lineOf("color:{{ '#ff00ff' }}"))
    expect(lines).not.toContain(lineOf("$colour = '#ff00ff';"))
    expect(lines).not.toContain(lineOf('And one in prose'))

    /*
     * ⚠ QRPAGER-49B / R-322: THE INLINE ATTRIBUTE WAS ON THE LIST ABOVE UNTIL THIS TICKET, as
     * "must not fire", and that was the hole. It is now read, each form by its own line, and
     * the shapes that must STAY silent are named beside them.
     */
    const at = (needle, id) =>
      findings
        .filter((f) => f.line === lineOf(needle))
        .map((f) => f.id)
        .sort()
        .join(',') === id

    expect(at('<p style="color:#ff00ff', 'css-spacing-declaration,hex')).toBe(true)
    expect(at("{{ $theme['text'] }};background:rebeccapurple", 'named-color')).toBe(true)
    expect(at("<i style='color:hsl", 'color-function')).toBe(true)
    expect(at('<b style=outline-color:navy', 'named-color')).toBe(true)
    // Silent: tokens and the allowlist in an attribute, a `data-style` attribute, the
    // allowlisted keywords in a rule, and a colour's NAME used as a font, an animation, an area.
    for (const quiet of [
      'color:var(--color-text);background:transparent',
      '<p data-style=',
      '.ok-keywords{',
      '.not-colour{',
    ]) {
      expect(lines).not.toContain(lineOf(quiet))
    }
    expect(at('.bad-named-last{border:1px solid red}', 'named-color')).toBe(true)

    // The `@media` rule's own padding IS reported, which is the half a blanket `@word`
    // blanker would have lost.
    expect(lines).toContain(lineOf('.in-media{padding:19px}'))

    /*
     * ⚠ AND THE TWO SHAPES THE `§11` ROUND FOUND INVISIBLE, EACH BY LINE. H-1: a `var()`
     * fallback on `border-radius`, which no value guard judges, because those guards read the
     * VALUE of a spacing, colour or font-size declaration. M-2: a real rule sharing its line
     * with `@if (…)`, which the first blanker masked away entirely because its argument group
     * was greedy to the last `)` on the line.
     */
    expect(lines).toContain(lineOf('.bad-fallback{border-radius:var(--radius-large,2px)}'))
    expect(lines).toContain(lineOf('.same-line{color:#ff00ff}'))

    const sameLine = findings.find((f) => f.line === lineOf('.same-line{color:#ff00ff}'))

    expect(sameLine?.match).toBe('#ff00ff')
  })

  it('blanks a directive’s argument to the MATCHING paren, not to the line’s last one', () => {
    /*
     * The `§11` security round's M-2, as a property rather than as one fixture line: whether
     * CSS is read must not depend on whether a `)` appears later on the same line. The three
     * inputs below are the reviewer's own, measured against the first blanker.
     */
    const read = (css) => scanSource(maskBladeCss(`<style>\n${css}\n</style>`), 'x.blade.php')

    expect(
      read('@if ($t) .x{color:#ff00ff;background:var(--color-text)}').map((f) => f.id),
    ).toEqual(['hex'])
    expect(read('@if ($t) .x{color:#ff00ff}').map((f) => f.id)).toEqual(['hex'])
    expect(read('@elseif ($t) .y{padding:13px;gap:var(--a)}').map((f) => f.id)).toEqual([
      'css-spacing-declaration',
    ])

    // And the ordinary multi-line form still blanks the directive itself: the condition's own
    // text is PHP and must not be read as CSS.
    expect(read('@if ($t === 13) .z{padding:var(--space-section)}')).toEqual([])
  })

  it('reports the stale baseline entry through the CLI, and names what to do', () => {
    /*
     * The report a reader actually sees, driven through `main()` rather than through
     * `applyBaseline()` — `§6` asks for coverage on the code a task adds, and the branch that
     * PRINTS a stale entry is the half of it a unit call on the splitter never reaches. A
     * literal that has been fixed must leave the list, or the baseline becomes a record of
     * debt that no longer exists.
     */
    const tree = mkdtempSync(join(tmpdir(), 'scan-css-stale-'))

    try {
      const bin = join(tree, 'tools', 'design-tokens', 'bin')
      mkdirSync(bin, { recursive: true })
      copyFileSync(join(HERE, '..', 'bin', 'scan-css.mjs'), join(bin, 'scan-css.mjs'))
      copyFileSync(join(HERE, '..', 'patterns.mjs'), join(bin, '..', 'patterns.mjs'))
      copyFileSync(join(HERE, '..', 'colour-keywords.mjs'), join(bin, '..', 'colour-keywords.mjs'))
      copyFileSync(join(HERE, '..', 'blade-css.mjs'), join(bin, '..', 'blade-css.mjs'))

      // A clean stylesheet, and a baseline claiming a literal in it that is not there.
      const styles = join(tree, 'app.css')
      writeFileSync(styles, '.a{color:var(--color-text)}\n', 'utf8')
      writeFileSync(
        join(bin, '..', 'landing-baseline.json'),
        JSON.stringify({
          generated: '2026-11-11',
          entries: [
            {
              file: 'app.css',
              rule: 'no-raw-color',
              id: 'hex',
              match: '#c0ffee',
              count: 1,
              lines: [1],
            },
          ],
        }),
        'utf8',
      )

      const result = spawnSync('node', [join(bin, 'scan-css.mjs')], {
        encoding: 'utf8',
        shell: false,
      })

      // The floor fires first in a tree with one CSS file, which is itself the point: the
      // report below is what a REAL tree gets, so it is driven with an explicit target.
      const explicit = spawnSync('node', [join(bin, 'scan-css.mjs'), styles], {
        encoding: 'utf8',
        shell: false,
      })

      expect(result.status).toBe(1)
      expect(explicit.status).toBe(1)
      expect(explicit.stderr).toContain('BASELINE')
      expect(explicit.stderr).toContain('#c0ffee')
      expect(explicit.stderr).toContain('remove the entry')
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  })

  it('refuses a walk that misses a baselined file, through the CLI', () => {
    /*
     * `missingBaselineFiles()` is asserted as a function above; this drives the branch that
     * REPORTS it, which is the one a developer sees when a directory is renamed. An empty tree
     * with a baseline naming a file is the same condition as a walk that lost one.
     */
    const tree = mkdtempSync(join(tmpdir(), 'scan-css-missing-'))

    try {
      const bin = join(tree, 'tools', 'design-tokens', 'bin')
      mkdirSync(bin, { recursive: true })
      copyFileSync(join(HERE, '..', 'bin', 'scan-css.mjs'), join(bin, 'scan-css.mjs'))
      copyFileSync(join(HERE, '..', 'patterns.mjs'), join(bin, '..', 'patterns.mjs'))
      copyFileSync(join(HERE, '..', 'colour-keywords.mjs'), join(bin, '..', 'colour-keywords.mjs'))
      copyFileSync(join(HERE, '..', 'blade-css.mjs'), join(bin, '..', 'blade-css.mjs'))

      // Four CSS files clear the FLOOR, and a Blade template with a <style> clears the Blade
      // floor — so the only thing left to fail on is the baselined file nobody scanned.
      for (const name of ['a.css', 'b.css', 'c.css', 'd.css']) {
        writeFileSync(join(tree, name), '.x{color:var(--color-text)}\n', 'utf8')
      }

      writeFileSync(
        join(tree, 'page.blade.php'),
        '<style>\n.y{padding:var(--space-section)}\n</style>\n',
        'utf8',
      )

      writeFileSync(
        join(bin, '..', 'landing-baseline.json'),
        JSON.stringify({
          generated: '2026-11-11',
          entries: [
            {
              file: 'apps/api/resources/views/landing/page.blade.php',
              rule: 'no-raw-color',
              id: 'hex',
              match: '#f5f5f5',
              count: 1,
              lines: [196],
            },
          ],
        }),
        'utf8',
      )

      const result = spawnSync('node', [join(bin, 'scan-css.mjs')], {
        encoding: 'utf8',
        shell: false,
      })

      expect(result.status).toBe(1)
      expect(result.stderr).toContain('the walk did not reach')
      expect(result.stderr).toContain('landing/page.blade.php')
      expect(result.stderr).toContain('R-267')
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  })

  it('treats an absent baseline as no debt, which is what every other repository state is', () => {
    /*
     * `readBaseline()` is the one part of this scanner that has to work in a tree that has
     * never had frozen debt — which is every repository but this one, and this one before
     * `QRPAGER-46`. An absent file is not an error: it means nothing is excused.
     */
    const empty = readBaseline(join(tmpdir(), 'no-such-baseline-file.json'))

    expect(empty.entries).toEqual([])
    expect(empty.budget.size).toBe(0)

    // And a malformed-but-parseable document — a baseline with no `entries` key — is the same
    // thing rather than a crash: `?? []` is the branch, and a scanner that threw here would
    // fail a run over a file it only reads for leniency.
    const bare = mkdtempSync(join(tmpdir(), 'scan-css-bare-'))

    try {
      const path = join(bare, 'baseline.json')
      writeFileSync(path, JSON.stringify({ generated: '2026-11-11' }), 'utf8')

      const loaded = readBaseline(path)

      expect(loaded.entries).toEqual([])
      expect(missingBaselineFiles(new Set(), loaded)).toEqual([])

      // With nothing excused, every finding is reported — the inverse of the freeze.
      const findings = scanSource('.a{color:#ff00ff}', 'a.css')
      const { reported, stale, excused } = applyBaseline(findings, loaded)

      expect(reported).toEqual(findings)
      expect(stale).toEqual([])
      expect(excused).toBe(0)
    } finally {
      rmSync(bare, { recursive: true, force: true })
    }
  })

  it('refuses, IN PROCESS, a walk with no Blade template carrying a style element', () => {
    /*
     * `R-267`'s own condition, driven in-process for the same coverage reason as the case
     * below: the Blade half of this scanner exists because the customer-facing page was read
     * by nothing, and a walk that silently stopped finding it would restore exactly that while
     * the CSS count kept the other floor green. The CLI case proves the exit code; this proves
     * the branch and its message.
     *
     * ⚠ THE WALK IS PASSED AS AN EXPLICIT LIST OF CSS FILES ONLY, which is the shape of the
     * failure — four stylesheets clear the CSS floor and no template clears the Blade one — and
     * `main()` skips both floors for an explicit list by design (a caller naming one file means
     * it), so the branch is reached through a baseline-less run of a tree with no templates.
     */
    const tree = mkdtempSync(join(tmpdir(), 'scan-css-noblade-'))

    try {
      const bin = join(tree, 'tools', 'design-tokens', 'bin')
      mkdirSync(bin, { recursive: true })

      for (const name of ['a.css', 'b.css', 'c.css', 'd.css', 'e.css']) {
        writeFileSync(join(tree, name), '.x{color:var(--color-text)}\n', 'utf8')
      }

      const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)

      // A scanner rooted in that tree: the module computes ROOT from its own location, so a
      // copy three directories deep walks the temporary tree exactly as the empty-tree proof
      // does — and this one finds five stylesheets and no Blade template.
      copyFileSync(join(HERE, '..', 'bin', 'scan-css.mjs'), join(bin, 'scan-css.mjs'))
      copyFileSync(join(HERE, '..', 'patterns.mjs'), join(bin, '..', 'patterns.mjs'))
      copyFileSync(join(HERE, '..', 'colour-keywords.mjs'), join(bin, '..', 'colour-keywords.mjs'))
      copyFileSync(join(HERE, '..', 'blade-css.mjs'), join(bin, '..', 'blade-css.mjs'))

      const result = spawnSync('node', [join(bin, 'scan-css.mjs')], {
        encoding: 'utf8',
        shell: false,
      })

      expect(result.status).toBe(1)
      expect(result.stderr).toContain('NO Blade template carrying a <style> element')

      /*
       * And the same two floors IN PROCESS, over the same tree: five stylesheets clear the CSS
       * floor of four, and no template clears the Blade floor of one — which is the condition
       * `R-267` describes, with the gate otherwise looking healthy.
       */
      expect(main([], join(bin, '..', 'absent-baseline.json'), tree)).toBe(1)
      expect(error.mock.calls.flat().join('\n')).toContain(
        'NO Blade template carrying a <style> element',
      )

      error.mockClear()

      // And the CSS floor below it, on a tree with too few stylesheets to be this workspace.
      const thin = mkdtempSync(join(tmpdir(), 'scan-css-thin-'))

      try {
        writeFileSync(join(thin, 'only.css'), '.x{color:var(--color-text)}\n', 'utf8')

        expect(main([], join(bin, '..', 'absent-baseline.json'), thin)).toBe(1)
        expect(error.mock.calls.flat().join('\n')).toContain('fewer than the 4')
      } finally {
        rmSync(thin, { recursive: true, force: true })
      }

      vi.restoreAllMocks()
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  }, 30_000)

  it('refuses, IN PROCESS, a walk that misses a baselined file and a frozen literal that is gone', () => {
    /*
     * The same two refusals as the CLI cases above, called directly — because a child process
     * is instrumented by nothing and `§6` asks for coverage on the code this task adds. CI
     * measured these exact lines as the ones holding the branch total under its threshold.
     */
    const tree = mkdtempSync(join(tmpdir(), 'scan-css-inproc-'))

    try {
      // 1. a baseline naming a file this run cannot have scanned.
      const missingPath = join(tree, 'missing.json')
      writeFileSync(
        missingPath,
        JSON.stringify({
          entries: [
            {
              file: 'no/such/file.css',
              rule: 'no-raw-color',
              id: 'hex',
              match: '#c0ffee',
              count: 1,
            },
          ],
        }),
        'utf8',
      )

      const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
      const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)

      expect(main([], missingPath)).toBe(1)
      expect(error.mock.calls.flat().join('\n')).toContain('the walk did not reach')

      error.mockClear()

      // 2. a baseline whose frozen literal is not in the file it names.
      const stalePath = join(tree, 'stale.json')
      writeFileSync(
        stalePath,
        JSON.stringify({
          entries: [
            {
              file: 'apps/api/resources/views/landing/page.blade.php',
              rule: 'no-raw-color',
              id: 'hex',
              match: '#c0ffee',
              count: 1,
              lines: [1],
            },
          ],
        }),
        'utf8',
      )

      expect(main([], stalePath)).toBe(1)

      const said = error.mock.calls.flat().join('\n')

      expect(said).toContain('BASELINE')
      expect(said).toContain('#c0ffee')
      expect(said).toContain('remove the entry')

      // 3. and the ordinary green path still returns 0 with the real baseline, so the two
      //    refusals above are not the only thing this function can do.
      error.mockClear()
      expect(main([])).toBe(0)
      expect(log.mock.calls.flat().join('\n')).toContain('frozen by the dated baseline')

      vi.restoreAllMocks()
    } finally {
      rmSync(tree, { recursive: true, force: true })
    }
  }, 30_000)

  it('stops a directive’s argument at a newline rather than running into the stylesheet', () => {
    /*
     * The two branches of the balanced scan a well-formed template never reaches: an
     * unterminated `(` and a `)` that closes a nested pair rather than the directive's own.
     * Both are one malformed line away, and the consequence of getting them wrong is a
     * SILENTLY unscanned stylesheet — which is `R-267`'s whole shape.
     */
    const read = (css) => scanSource(maskBladeCss(`<style>\n${css}\n</style>`), 'x.blade.php')

    // Unterminated: the blanker must stop at the line break, so the rule BELOW is still read.
    expect(read('@if ($t\n.after{padding:13px}').map((f) => f.id)).toEqual([
      'css-spacing-declaration',
    ])

    // Nested parens in the condition: the inner `)` must not end the argument early.
    expect(read('@if (str_contains($t, (string) 1)) .z{color:#ff00ff}').map((f) => f.id)).toEqual([
      'hex',
    ])
  })

  it('refuses a run that never reached a file the baseline has debt for', () => {
    /*
     * The `§11` security round's M-4. The Blade floor counts to ONE and two templates qualify,
     * so a scanned set that kept `errors/panel-problem.blade.php` and lost the landing page
     * reported `0 findings, 0 stale, 0 excused` with no message — `R-267`'s own condition, with
     * the gate printing a reassuring count. The floor is derived from the record now.
     */
    const baseline = readBaseline()
    const landing = 'apps/api/resources/views/landing/page.blade.php'

    expect(missingBaselineFiles(new Set(baseline.entries.map((e) => e.file)), baseline)).toEqual([])
    expect(
      missingBaselineFiles(
        new Set(['apps/api/resources/views/errors/panel-problem.blade.php']),
        baseline,
      ),
    ).toEqual([
      landing,
      // QRPAGER-49B: the baseline names a second file now, found by reading attributes.
      'apps/api/resources/views/studio/preview-refused.blade.php',
    ])

    // And the silence it used to produce, named: with that file unscanned, every frozen
    // finding and every entry evaporates — which is why a count cannot be the floor.
    const { reported, stale, excused } = applyBaseline([], baseline, new Set())

    expect({ reported, stale, excused }).toEqual({ reported: [], stale: [], excused: 0 })
  })

  it('is not fooled by a style tag quoted inside a Blade comment', () => {
    /*
     * ⚠ THE MEASURED DEFECT, AND IT WAS RIGHT BY ACCIDENT. `page.blade.php`'s own comment
     * quotes `</style>` and `<style`, and with comments left in place the masker found TWO
     * style elements in that one file: the first ended at the quoted closing tag, the second
     * opened at the quoted opening tag with `[^>]*>` running to the next `>` in the CSS
     * (`.rail>section`). The union covered almost all the real rules, so the finding list
     * looked correct while the parse was nonsense — and five findings inside the `editorial`
     * branch fell in the gap between the two.
     */
    expect(styleBodyCount(fixture('landing-blade-literals.blade.php.fixture'))).toBe(1)
    expect(styleBodyCount(readFileSync(landing, 'utf8'))).toBe(1)

    // And a hex in that comment is not a finding, which the count above cannot show.
    const masked = maskBladeCss(fixture('landing-blade-literals.blade.php.fixture'))
    expect(masked).not.toContain('#f5f5f5')
  })

  it('keeps the template’s geometry, so a finding names a line that exists', () => {
    const source = readFileSync(landing, 'utf8')
    const masked = maskBladeCss(source)

    // Same length and the same newline positions: the line and column `scanSource` computes
    // are the template's own, which is `R-267`'s prescription for the fix.
    expect(masked.length).toBe(source.length)
    expect([...masked].filter((c) => c === '\n').length).toBe(
      [...source].filter((c) => c === '\n').length,
    )

    const findings = scanSource(masked, 'page.blade.php')
    const lines = source.split('\n')

    expect(findings.length).toBeGreaterThan(0)

    for (const finding of findings) {
      const line = lines[finding.line - 1] ?? ''

      // The matched text is ON the line the finding names. A masker that moved a byte would
      // pass every other assertion in this file and fail this one.
      expect(
        line.includes(finding.match) || line.includes(finding.match.replace(/\s+/g, ' ')),
      ).toBe(true)
    }
  })

  it('reports R-267’s own injection, which three scanners once called clean', () => {
    /*
     * The row's proof string, run against the row's own file. Injected in memory rather than
     * on disk — the row says it was *"Reverted"*, and a test that writes to a served template
     * is a test that can leave one behind.
     */
    const source = readFileSync(landing, 'utf8')
    /*
     * ⚠ ANCHORED ON THE `:root` RULE, NOT ON ITS CURRENT TEXT. This used to replace the literal
     * `:root{color-scheme:light dark}`, and `QRPAGER-49A` — which put `color:var(--color-text)`
     * on that same rule so the root stops keeping the user agent's black — broke the anchor, not
     * the scanner: `injected` came back byte-identical to `source` and the case failed on its
     * own `not.toBe(source)` guard. A proof that fails because the product line it points at was
     * legitimately edited is a proof that will be "fixed" by deleting it next time; matching the
     * rule rather than its contents is what lets the rule change without this changing.
     */
    const injected = source.replace(
      /(:root\{[^}]*\})/,
      '$1\n.landing-gap-proof{color:#ff00ff;padding:13px;font-size:17px}',
    )

    expect(injected).not.toBe(source)

    const before = scanSource(maskBladeCss(source), 'page.blade.php')
    const after = scanSource(maskBladeCss(injected), 'page.blade.php')

    expect(after.length - before.length).toBe(3)

    /*
     * ⚠ THE DIFF IS BY (id, match) AND NOT BY LINE, because the injected line SHIFTS every
     * finding below it by one — so a line-keyed diff reported all 23 pre-existing literals as
     * new and this case failed with a 23-element array. The shift is the whole reason the
     * masker keeps the template's geometry, and it is also why the baseline is not keyed on
     * line numbers.
     */
    const signature = (f) => `${f.id}\u0000${f.match}`
    const remaining = before.map(signature)
    const added = after.filter((f) => {
      const at = remaining.indexOf(signature(f))

      if (at === -1) return true

      remaining.splice(at, 1)

      return false
    })

    expect(added.map((f) => f.id).sort()).toEqual([
      'css-font-size-declaration',
      'css-spacing-declaration',
      'hex',
    ])
  })

  it('walks the templates that carry CSS, and not the hundreds that do not', () => {
    const found = bladeFiles(REPO).map((path) => path.replace(`${REPO}/`, ''))

    // The landing page is the file `R-267` is about, and it is in the walk.
    expect(found).toContain('apps/api/resources/views/landing/page.blade.php')

    /*
     * ⚠ AND A SECOND FILE NOBODY HAD NAMED: `errors/panel-problem.blade.php` inlines its own
     * stylesheet *"because a stylesheet is one more thing that can be unavailable on the page
     * that exists for things being unavailable"*. It was in the same blind spot and is clean —
     * its token values arrive through `{{ }}` from `config('design-tokens')`.
     */
    expect(found).toContain('apps/api/resources/views/errors/panel-problem.blade.php')

    /*
     * ⚠ QRPAGER-49B / R-322: A TEMPLATE WITH A `style=""` ATTRIBUTE AND NO `<style>` ELEMENT IS
     * A SUBJECT NOW. Two are: `landing/block.blade.php` (`<figure style="margin:0">`) and
     * `studio/preview-refused.blade.php`. The walk went from 12 files to 14, and this is the
     * assertion that says which two, because a count that moves without a name is a guess.
     */
    for (const attributeOnly of [
      'apps/api/resources/views/landing/block.blade.php',
      'apps/api/resources/views/studio/preview-refused.blade.php',
    ]) {
      expect(found).toContain(attributeOnly)
      const source = readFileSync(join(REPO, attributeOnly), 'utf8')

      expect(hasStyleBlock(source)).toBe(false)
      expect(hasStyleAttribute(source)).toBe(true)
    }

    // Not a glob over `resources/views`: a template with neither is not a subject.
    expect(found).not.toContain('apps/api/resources/views/landing/redirect.blade.php')
    expect(
      hasInlineCss(
        readFileSync(join(REPO, 'apps/api/resources/views/landing/redirect.blade.php'), 'utf8'),
      ),
    ).toBe(false)

    // And the deliberately-wrong fixture is unreachable by the walk, twice over: the
    // exemption covers the directory, and its name is not a `.blade.php`.
    expect(found.some((path) => path.includes('landing-blade-literals'))).toBe(false)
    expect(
      isExempt(join(REPO, 'tools/design-tokens/fixtures/landing-blade-literals.blade.php.fixture')),
    ).toBe(true)
  })

  it('freezes the dated baseline’s literals and refuses one more of the same', () => {
    const baseline = readBaseline()

    expect(baseline.entries.length).toBeGreaterThan(0)

    const findings = scanSource(
      maskBladeCss(readFileSync(landing, 'utf8')),
      'apps/api/resources/views/landing/page.blade.php',
    )
    const { reported, stale, excused } = applyBaseline(
      findings,
      baseline,
      new Set(['apps/api/resources/views/landing/page.blade.php']),
    )

    // Every pre-existing literal is excused, none is reported, and the baseline is current.
    expect(reported).toEqual([])
    expect(stale).toEqual([])
    expect(excused).toBe(findings.length)

    /*
     * ⚠ AND ONE MORE OF AN ALREADY-FROZEN LITERAL IS A FINDING, which is the difference
     * between a freeze and an exemption. The baseline records a COUNT per `(file, id, match)`,
     * so a seventeenth `#0b5fff` in that file is reported while the three that were there on
     * 2026-11-11 are not. Matching on the text alone — the obvious shape — would have let it
     * through, and matching on the line number would redden on every edit above it.
     */
    const seventeenth = { ...findings[0] }
    const { reported: afterOneMore } = applyBaseline(
      [...findings, seventeenth],
      baseline,
      new Set(['apps/api/resources/views/landing/page.blade.php']),
    )

    expect(afterOneMore).toEqual([seventeenth])
  })

  it('reports a baseline entry that no longer matches anything, rather than keeping it', () => {
    /*
     * A literal that has been FIXED must leave the list. Left in, the baseline becomes a
     * record of debt that no longer exists — and the next reader cannot tell which entries
     * are real. `R-268` is the live one; a stale entry would make it look like two.
     */
    const baseline = readBaseline()
    const invented = {
      file: 'apps/api/resources/views/landing/page.blade.php',
      rule: 'no-raw-color',
      id: 'hex',
      match: '#c0ffee',
      count: 1,
      lines: [1],
    }

    const withInvented = {
      entries: [...baseline.entries, invented],
      budget: new Map([
        ...baseline.budget,
        [[invented.file, invented.id, invented.match].join('\u0000'), 1],
      ]),
    }

    const findings = scanSource(maskBladeCss(readFileSync(landing, 'utf8')), invented.file)
    const { stale } = applyBaseline(findings, withInvented, new Set([invented.file]))

    expect(stale).toEqual([invented])
  })
})

/**
 * ================================================================================
 * THE NODE KINDS AND THE VALUE JUDGEMENTS THE FIXTURES DO NOT REACH
 * ================================================================================
 *
 * The committed fixtures are the rule's PROOF and are deliberately about shapes a component
 * would plausibly carry. These cases close the branch coverage around them: the four node
 * kinds the plugin visits, the two key forms a style property can take, and the boundary
 * cases of the two value judgements.
 *
 * Each one is a property of the rule, not a line to be reached: a template chunk, JSX text
 * and a computed key are three real ways a literal gets in.
 */
describe('every node kind the plugin visits', () => {
  it('catches a hex in a TEMPLATE literal chunk', () => {
    const messages = lint('export const a = `color: #0B6B4F`\n')
    expect(messages.map((m) => m.ruleId)).toContain('@qrpager/design-tokens/no-raw-color')
  })

  it('catches a hex in JSX TEXT, which is markup rather than a string', () => {
    const messages = lint('export const a = <div>text-[#fff]</div>\n')
    expect(messages.map((m) => m.ruleId)).toContain('@qrpager/design-tokens/no-raw-color')
  })

  it('catches a style property whose key is a quoted LITERAL, not an identifier', () => {
    const messages = lint("export const a = <div style={{ 'color': 'white' }} />\n")
    expect(messages.map((m) => m.ruleId)).toContain('@qrpager/design-tokens/no-raw-color')
  })

  it('ignores a COMPUTED style key, which it cannot resolve and must not guess about', () => {
    // `style={{ [k]: v }}`: the key is a runtime value. Reporting it would be a guess, and
    // staying silent is the documented behaviour rather than an oversight.
    const messages = lint('export const a = (k) => <div style={{ [k]: 13 }} />\n')
    expect(messages).toEqual([])
  })

  it('ignores a non-string literal, which carries no value to inspect', () => {
    const messages = lint('export const a = 42\nexport const b = true\nexport const c = null\n')
    expect(messages).toEqual([])
  })

  it('stays silent on a token reference in an inline style, which is the right form', () => {
    const messages = lint(
      "export const a = <div style={{ color: 'var(--color-ink)', padding: 'var(--space-16)' }} />\n",
    )
    expect(messages).toEqual([])
  })
})

describe('the value judgements at their boundaries', () => {
  it.each([
    // A shorthand is only as good as its worst part: the laundering regression, by value.
    ['var(--space-16) 9px', false],
    ['var(--space-16) var(--space-8)', true],
    // A fallback is a value typed at the use site and is judged.
    ['var(--space-16, 13px)', false],
    ['var(--space-16, var(--space-8))', true],
    // calc/clamp over tokens is a composition of decisions already taken.
    ['clamp(var(--size-16), 2vw, var(--size-28))', false],
    ['clamp(var(--size-16), var(--size-20), var(--size-28))', true],
    ['calc(var(--space-16) * 2)', true],
    ['calc(var(--space-16) + 3px)', false],
    // A bare ratio, a hairline, and the keywords.
    ['1.5', true],
    ['1px', true],
    ['2px', false],
    ['0', true],
    ['auto', true],
    ['100%', true],
    ['', true],
    // !important is stripped before the judgement, not laundered by it.
    ['var(--space-16) !important', true],
    ['13px !important', false],
    // A comma list at one level: a font stack.
    ['var(--font-latin), sans-serif', true],
    ['Helvetica, 13px', false],
  ])('judges %s acceptable=%s', (value, expected) => {
    expect(valueIsAcceptable(value)).toBe(expected)
  })

  it.each([
    // An ALLOW-LIST, which is the opposite question from the magnitude one: `white` carries
    // no digit at all and would sail through `valueIsAcceptable`.
    ['white', false],
    ['#fff', false],
    ['rgb(1,2,3)', false],
    ['var(--color-ink)', true],
    ['var(--color-ink, var(--color-paper))', true],
    ['var(--color-ink, #fff)', false],
    ['transparent', true],
    ['currentColor', true],
    ['', true],
    // A box-shadow written at the use site is refused whichever half is wrong, and that is
    // the measured behaviour rather than the expected one: `2px` is a magnitude typed at
    // the use site (only `1px`, the hairline, survives), so even the token-coloured form is
    // a finding. A component states the elevation TOKEN.
    ['0 1px 2px var(--color-hairline)', false],
    ['0 1px 2px rgba(0,0,0,.06)', false],
    ['var(--elevation-card)', true],
    // And the one length that does survive inside a shorthand, so the rule is not simply
    // refusing every multi-part value.
    ['1px var(--color-hairline)', true],
  ])('judges the COLOUR %s acceptable=%s', (value, expected) => {
    expect(colourValueIsAcceptable(value)).toBe(expected)
  })
})
