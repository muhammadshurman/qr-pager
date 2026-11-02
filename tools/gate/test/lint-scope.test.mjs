import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { isExempt } from '../../design-tokens/bin/scan-css.mjs'

const REPO = join(import.meta.dirname, '..', '..', '..')

/**
 * ================================================================================
 * THE GATE'S RESULT DOES NOT DEPEND ON WHETHER A REVIEW IS IN FLIGHT
 * ================================================================================
 *
 * ⚠ MEASURED ON 2026-11-02, WHILE TASK 10a's TWO `§11` REVIEWERS WERE BEING SET UP.
 *
 * `§0.2` (b) made the two reviewers run AT THE SAME TIME, each in its own detached worktree
 * under `.claude/worktrees/`. `.gitignore` excludes that path, which keeps the worktrees out
 * of commits and does **nothing** to a linter that walks the filesystem.
 *
 * So `pnpm exec eslint .` went from **0 findings to 3,914** the moment two worktrees existed,
 * every one of them a duplicate of a file already linted in the main checkout. Real findings
 * -- if there had been any -- would have been unfindable in it, and `pnpm lint` is step 1 of
 * `pnpm gate`.
 *
 * **From Task 10a onward the ordinary state of this repository DURING a review round is that
 * two copies of it exist inside it.** A gate whose verdict depends on that is not a gate, so
 * the exclusion is a repo change and this file is the test that keeps it.
 *
 * ⚠ AND THE SECOND CASE IS A DIFFERENT BUG FROM THE SAME SITTING, AND THIS FILE ALMOST
 * REPEATED IT WHILE DESCRIBING IT -- the first draft of the paragraph below quoted the
 * offending sequence and terminated its own docblock. Neither comment names it literally now.
 * The first version of the
 * `eslint.config.mjs` comment explaining the exclusion was a BLOCK comment that quoted the
 * worktree glob -- whose trailing star-slash CLOSED the comment three lines early. `eslint
 * .` then died with `Invalid or unexpected token`, reporting NO findings at all.
 *
 * ⚠ AND `pnpm gate` WOULD HAVE CAUGHT THAT, which is worth saying because the first draft of
 * this comment claimed it would not. Measured: `eslint .` exits **2** on a config it cannot
 * load, and `lint:es` is step 1 of `pnpm lint`, so the gate goes red. The real cost is
 * elsewhere and is smaller: a non-zero exit with an empty finding list looks like a toolchain
 * problem rather than a code one, so it is diagnosed in the wrong place first.
 *
 * `tools/gate/test/comment-terminators.test.mjs` is deliberately CSS-only for exactly that
 * reason -- its own header says `.js`/`.ts` are out of scope because *"their own parsers
 * already refuse the file"* -- and that scoping is right. What the first case below adds is an
 * EARLIER signal in the same run: `pnpm test` imports the config, so a config that does not
 * parse fails a named test before the linter is reached.
 */

describe('the linters do not walk a reviewer worktree', () => {
  it('excludes it from ESLint, by the config ESLint actually loads', async () => {
    // ⚠ IMPORTED, NOT GREPPED, AND THAT IS THE WHOLE VALUE OF THIS CASE. A `readFileSync` plus
    // a `toContain('.claude/**')` would have passed against the broken block comment above --
    // the string was there, and the file did not parse. An import is the only assertion that
    // covers both the entry and the syntax.
    const config = await import(join(REPO, 'eslint.config.mjs'))
    const flat = config.default

    expect(Array.isArray(flat), 'eslint.config.mjs does not default-export an array').toBe(true)

    const ignores = flat.flatMap((entry) => entry.ignores ?? [])

    // Not vacuous: there IS an ignore list, and it still carries the entries whose absence
    // caused the two earlier instances of this same defect.
    expect(ignores.length).toBeGreaterThan(3)
    expect(ignores).toContain('.claude/**')
    expect(ignores).toContain('storage/**')
    expect(ignores).toContain('**/node_modules/**')
  })

  it('excludes it from the design-token CSS walk, by calling the real predicate', () => {
    // `scan-css.mjs` has a FLOOR check that fails a run which finds too few stylesheets, and
    // a worktree would push the count comfortably past it while reporting every finding three
    // times -- so the floor cannot catch this and the exclusion has to.
    const worktree = join(
      REPO,
      '.claude',
      'worktrees',
      'qa-10a',
      'packages',
      'ui',
      'src',
      'styles',
      'kit.css',
    )

    expect(isExempt(worktree), 'the CSS walk would scan a reviewer worktree').toBe(true)

    // THE CONTROL. Without it this case would pass against an `isExempt` that returned true
    // for everything -- which is a scanner that scans nothing, the exact vacuous shape
    // `scan-css.mjs`'s own floor check exists for.
    const real = join(REPO, 'packages', 'ui', 'src', 'styles', 'kit.css')

    expect(isExempt(real), 'the CSS walk would skip the real kit stylesheet').toBe(false)
  })

  it('keeps the two exclusions in step, so a third linter is the only thing left to forget', () => {
    // The two walks are independent implementations of one decision, and a decision expressed
    // twice is a decision that can be half-applied. Named here so a reviewer can see the pair
    // rather than find one of them.
    const worktreeDirectory = join(REPO, '.claude', 'worktrees', 'sec-10a')

    expect(isExempt(worktreeDirectory)).toBe(true)
    expect(isExempt(join(REPO, '.claude'))).toBe(true)
  })
})
