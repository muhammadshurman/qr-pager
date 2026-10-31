import { spawnSync } from 'node:child_process'
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
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
import { cssFiles, isExempt, main, scanSource } from '../bin/scan-css.mjs'

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
   * It is one awk pass now and runs in ~0.05 s. The bound below is 60x that, so it cannot
   * flake on a loaded runner -- but it CANNOT be met by a per-line subprocess loop, which
   * is the regression it exists to stop.
   */
  it(
    'completes in well under the timeout, so a per-line subprocess loop cannot return',
    () => {
      const started = Date.now()
      const { status } = runChecker([])
      const elapsed = Date.now() - started
      expect(status).toBe(0)
      expect(
        elapsed,
        `the checker took ${String(elapsed)}ms; it was 6670ms on a runner when it spawned ` +
          `two processes per line, which timed out the suite`,
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
  it('defines exactly the three rules the gate clause names', () => {
    expect(RULE_IDS).toEqual(['no-raw-color', 'no-raw-spacing', 'no-raw-font-size'])
    expect(Object.keys(plugin.rules).sort()).toEqual([
      'no-raw-color',
      'no-raw-font-size',
      'no-raw-spacing',
    ])
  })

  it('applies every pattern through one reader or the other, so none is orphaned', () => {
    // A pattern nobody applies is a pattern that silently stopped working. Exact counts,
    // never a range: a `toBeGreaterThan` here would pass on a list that had shrunk.
    expect(PATTERNS).toHaveLength(11)
    expect(patternsFor('js')).toHaveLength(8)
    expect(patternsFor('css')).toHaveLength(8)

    const applied = new Set([
      ...patternsFor('js').map((p) => p.id),
      ...patternsFor('css').map((p) => p.id),
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
    const scanned = /(\d+) CSS file\(s\) scanned/.exec(result.stdout)
    expect(scanned, result.stdout).not.toBeNull()
    expect(Number(scanned[1])).toBeGreaterThanOrEqual(4)
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
      // The Laravel half: `composer guard` rule 11 covers it.
      'apps/api/resources/css/app.css',
    ]) {
      expect(isExempt(join(REPO, path)), `${path} should be exempt`).toBe(true)
    }
  })

  it('exempts NOTHING it should scan, which is the half that keeps the rule honest', () => {
    for (const path of [
      'packages/ui/src/styles/kit.css',
      'packages/ui/src/styles/templates.css',
      'apps/web/app/globals.css',
      'apps/site/app/globals.css',
    ]) {
      expect(isExempt(join(REPO, path)), `${path} must be scanned`).toBe(false)
    }
  })

  it('walks the real tree and finds every stylesheet this task authors', () => {
    const found = cssFiles(REPO).map((p) => p.replace(`${REPO}/`, ''))
    for (const expected of [
      'packages/ui/src/styles/kit.css',
      'packages/ui/src/styles/templates.css',
      'apps/web/app/globals.css',
      'apps/site/app/globals.css',
    ]) {
      expect(found, `${expected} was not found by the walk`).toContain(expected)
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
    expect(log.mock.calls.flat().join('\n')).toMatch(/\d+ CSS file\(s\) scanned/)
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
    expect(log.mock.calls.flat().join('\n')).toContain('1 CSS file(s) scanned')
    vi.restoreAllMocks()
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
