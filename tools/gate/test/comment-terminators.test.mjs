import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * A COMMENT ENDS WHERE ITS AUTHOR ENDED IT
 * ================================================================================
 *
 * ⚠ TWICE IN ONE TASK, WHICH IS WHAT MAKES THIS A GUARD RATHER THAN TWO FIXES. `§7`: *"Never
 * fix the same bug twice. If it recurs, the regression test was missing -- add it."*
 *
 *   1. `packages/tokens/scripts/generate.mjs` -- a block comment explaining the ignore list
 *      contained `apps/` + `*` + `/node_modules`. The `*` and the `/` are adjacent, so the
 *      comment CLOSED there and the rest of the paragraph was parsed as JavaScript.
 *   2. `apps/api/resources/css/filament/<panel>/theme.css` -- a block comment explaining the
 *      `@source` globs contained `app/Features/` + `*` + `/Panel`. Same two characters, same
 *      early close, and the vite build died on **`CssSyntaxError: Unterminated string`**
 *      pointing at an apostrophe NINE LINES below the real cause.
 *
 * That second failure is the reason this file exists rather than a note in a review. The
 * reported error named a different line, a different construct and a different file position
 * from the defect, and the obvious reading -- "an apostrophe in a CSS comment breaks
 * Tailwind" -- is WRONG: `packages/tokens/generated/theme.css` carries an odd number of
 * apostrophes and compiles in both apps. Stripping the apostrophes also made the build pass,
 * so the superstitious fix was available, would have been committed, and would have left the
 * paragraph still being parsed as CSS.
 *
 * ================================================================================
 * THE RULE, AND WHY IT IS EXACT RATHER THAN A HEURISTIC
 * ================================================================================
 *
 * ⚠ The delimiter is written `\*\/` throughout this comment, escaped so the two characters
 * are never adjacent. Writing it plainly would close THIS comment -- which is the defect
 * itself, in the file that guards against it. A zero-width space between them was the first
 * attempt and `eslint`'s no-irregular-whitespace refused it, correctly: an invisible
 * character is not a way to write a visible one.
 *
 * Scan each file the way a parser does -- first `\*\/` wins -- and then require that EVERY
 * `\*\/` in the file is one of:
 *
 *   - the terminator of a block comment the scanner was inside, or
 *   - inside a string or template literal (`@source '…/*\*\/*'` is legitimate and common).
 *
 * A `\*\/` reached in CODE state is the violation. That classification is exact rather than a
 * pattern, and it catches the defect from the other end: when a glob closes a comment early,
 * the comment's REAL terminator is then reached in code state, so the orphan is always
 * detectable even though the early close itself looks like an ordinary comment ending.
 *
 * ================================================================================
 * SCOPE: CSS ONLY, AND BOTH EXCLUSIONS ARE REASONS RATHER THAN OMISSIONS
 * ================================================================================
 *
 * `.php` and `.js`/`.ts` are out of scope because **their own parsers already refuse the
 * file**. The text after an early close is parsed as code, and that is a syntax error on the
 * next run of anything: instance (1) above was caught by node failing to parse
 * `generate.mjs` at all, which is the loudest possible report. CSS is the language where the
 * same mistake is SILENT or MISDIRECTED -- prose that happens to parse as declarations, or
 * an error reported nine lines below the cause, which is instance (2).
 *
 * ⚠ AND SCOPING IT THIS WAY IS ALSO WHAT MAKES IT CORRECT. The first version swept JS and TS
 * too, with a hand-rolled scanner, and reported THREE legitimate comment terminators in
 * files that have always compiled -- `packages/ui/test/invariant.test.tsx:78`,
 * `tools/gate/test/ci-parity.test.mjs:199`, `tools/gate/test/ticket-tables.test.mjs:228`.
 * The cause: a JavaScript REGEX LITERAL such as `/['"]/` carries an unbalanced quote, and
 * telling a regex literal from a division needs real expression context. So the scanner
 * opened a string it never closed and swallowed every comment opening after it. A guard that
 * reports three false positives on a green tree is one that gets switched off, and CSS has no
 * regex literals -- a quote in CSS is always a string.
 */

/** Every tracked stylesheet, from git rather than from a glob this file maintains. */
function subjects() {
  const ls = spawnSync('git', ['ls-files', '-z'], { cwd: REPO, encoding: 'utf8' })
  expect(ls.status, 'git ls-files failed, so this check has no subjects').toBe(0)

  return (
    ls.stdout
      .split('\0')
      .filter(Boolean)
      .filter((f) => f.endsWith('.css'))
      // A fixture's whole purpose is to carry the shape a rule refuses, so a fixture is
      // evidence rather than a subject.
      .filter((f) => !f.endsWith('.fixture'))
      .sort()
  )
}

/**
 * Every `\*\/` in the source, classified by the state the scanner was in when it reached it.
 *
 * @returns {{line: number, state: string}[]} one entry per occurrence
 */
export function commentTerminators(source, { lineComments }) {
  const out = []
  let state = 'code'
  let line = 1

  for (let i = 0; i < source.length; i++) {
    const c = source[i]
    const next = source[i + 1]

    if (c === '\n') line++

    switch (state) {
      case 'code':
        if (c === '/' && next === '*') {
          state = 'block'
          i++
        } else if (lineComments && c === '/' && next === '/') {
          state = 'line'
          i++
        } else if (c === "'" || c === '"' || (lineComments && c === '`')) {
          state = c
        } else if (c === '*' && next === '/') {
          // An orphan: a terminator reached with no comment open.
          out.push({ line, state: 'code' })
          i++
        }
        break

      case 'block':
        if (c === '*' && next === '/') {
          out.push({ line, state: 'block' })
          state = 'code'
          i++
        }
        break

      case 'line':
        if (c === '\n') state = 'code'
        break

      default:
        // Inside a string or template literal.
        if (c === '\\') {
          i++
        } else if (c === state) {
          state = 'code'
        } else if (c === '*' && next === '/') {
          out.push({ line, state: 'string' })
          i++
        }
        break
    }
  }

  return { occurrences: out, endState: state }
}

describe('a block comment ends where its author ended it', () => {
  it('sweeps every tracked stylesheet, including the panel themes', () => {
    const files = subjects()

    expect(files.length, 'no stylesheet was found, so the sweep below is vacuous').toBeGreaterThan(
      5,
    )

    // The two files the second instance of this defect was IN, named so that moving or
    // renaming them cannot quietly drop them out of scope.
    expect(files).toContain('apps/api/resources/css/filament/platform/theme.css')
    expect(files).toContain('apps/api/resources/css/filament/studio/theme.css')
    // And a generated one, because the generator's banner is authored prose too.
    expect(files).toContain('packages/tokens/generated/panel-theme.css')
  })

  it('reaches no orphan comment terminator in any tracked stylesheet', () => {
    const offenders = []

    for (const file of subjects()) {
      const { occurrences, endState } = commentTerminators(read(file), { lineComments: false })

      for (const o of occurrences.filter((o) => o.state === 'code')) {
        offenders.push(`${file}:${o.line}`)
      }

      // An unterminated block comment at EOF is the same defect seen from the other side.
      if (endState === 'block') offenders.push(`${file}: block comment is never closed`)
    }

    expect(
      offenders,
      'these files reach a `*/` in code state, which means a block comment above them ' +
        'closed EARLIER than its author intended -- almost always a glob written in prose. ' +
        'Move the glob into a string, or write the path without the two adjacent characters',
    ).toEqual([])
  })

  it('finds the defect this guard exists for, in both languages', () => {
    // THE FALSIFICATION, and it carries the two REAL shapes rather than an invented one:
    // the glob that closed generate.mjs's comment and the one that closed the panel theme's.
    const js = [
      '/*',
      ' * ignores apps/' + '*' + '/node_modules and nothing else',
      ' */',
      'const a = 1',
      '',
    ].join('\n')
    const css = [
      '/*',
      ' * panels live under app/Features/' + '*' + '/Panel',
      ' */',
      '.a { color: red }',
      '',
    ].join('\n')

    for (const [label, source, lineComments] of [
      ['js', js, true],
      ['css', css, false],
    ]) {
      const { occurrences } = commentTerminators(source, { lineComments })
      const orphans = occurrences.filter((o) => o.state === 'code')
      expect(orphans.length, `${label}: the planted early close was not detected`).toBe(1)
      expect(orphans[0].line, `${label}: wrong line`).toBe(3)
    }
  })

  it('accepts a glob inside a string, which is where globs belong', () => {
    // The positive control. `@source '../../**/*'` is legitimate, extremely common in this
    // repo's stylesheets, and must not be reported -- otherwise the rule above would be
    // satisfied by deleting every @source line, which is the opposite of what it wants.
    const css = ["@source '../../app/Features/**/*';", '.a { color: red }', ''].join('\n')
    const { occurrences } = commentTerminators(css, { lineComments: false })

    expect(occurrences.filter((o) => o.state === 'code')).toEqual([])
    expect(occurrences.some((o) => o.state === 'string')).toBe(true)
  })
})
