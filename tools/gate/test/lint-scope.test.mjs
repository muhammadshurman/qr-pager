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

    /*
     * Not vacuous: there IS an ignore list, and it still carries the entries whose absence
     * caused the two earlier instances of this same defect.
     *
     * ⚠ EXACT, AND IT WAS `toBeGreaterThan(3)` — `§11` QA L-4. The length of this list is
     * knowable: it is twelve, here, now. A floor passes while an entry is quietly removed,
     * which is the whole failure mode this file exists for, so the count is pinned and
     * adding an ignore is a deliberate edit to a test.
     */
    expect(ignores.length).toBe(12)
    expect(ignores).toContain('.claude/worktrees/**')

    /*
     * ⚠ AND NOT THE WHOLE DIRECTORY — `§11` QA L-2. The first version ignored `.claude/**`,
     * which also dropped the three TRACKED files under it: the two `§11` reviewer briefs and
     * the `task` command. Pinned as an absence, because the narrowing is the fix and a
     * re-broadening would otherwise pass every other assertion in this file.
     */
    expect(ignores).not.toContain('.claude/**')
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

  it('keeps the exclusions in step, and the third linter has its own test in the PHP gate', () => {
    // The walks are independent implementations of one decision, and a decision expressed
    // three times is a decision that can be two-thirds applied. The third is
    // `SourceGuard::markdownFiles()`, which runs from `apps/api` under `composer guard` and
    // is asserted by `tests/Feature/Architecture/GuardWorktreeScopeTest.php` -- named here so
    // a reader finds the set rather than this pair.
    const worktreeDirectory = join(REPO, '.claude', 'worktrees', 'sec-10a')

    expect(isExempt(worktreeDirectory)).toBe(true)

    /*
     * ⚠ `.claude` ITSELF IS **NOT** EXEMPT — `§11` QA L-2, and this assertion is the reverse
     * of what it was. Excluding the directory also excluded the three tracked files in it,
     * which are repository content: the two `§11` briefs and the `task` command. A walker
     * that quietly stops covering real files is the same defect as one that covers three
     * copies of them.
     */
    expect(isExempt(join(REPO, '.claude'))).toBe(false)
    expect(isExempt(join(REPO, '.claude', 'agents', 'qa-reviewer.md'))).toBe(false)
  })
})

describe('Playwright collects the browser specs and nothing else', () => {
  /**
   * ⚠ MEASURED AS A RED `gate:e2e`, AND IT COST A FULL ROUND. `browser/support/isolation.ts`
   * is a pure module with Vitest tests beside it (`§11` QA M-3 — the cross-tenant sweep's leak
   * RULE, which no browser round can prove without a real disclosure to detect). Playwright's
   * default pattern accepts any `.test.ts` under `testDir`, and the two kit projects select by
   * `testIgnore` rather than `testMatch`, so they collected *everything else* — including that
   * file. `pnpm run gate:e2e` then imported a Vitest suite into Playwright and died at
   * collection, inside `describe()`, before one browser case ran.
   *
   * Two runners now share `browser/`, so which files each one claims is a property with a
   * test. Imported rather than grepped, for `eslint.config.mjs`'s reason one case up: the same
   * sitting also closed a block comment early, in this very config, with a glob that ended in
   * a star and a slash — and only an import catches both the entry and the syntax.
   */
  it('excludes the Vitest tree from every project, by the config Playwright loads', async () => {
    const config = await import(join(REPO, 'playwright.config.ts'))
    const resolved = config.default

    expect(resolved.testDir).toBe('./browser')

    // The top level, for the projects that declare no ignore of their own.
    expect(resolved.testIgnore).toContain('**/support/test/**')

    /*
     * Not vacuous: there ARE projects, and the count is exact so a project added without a
     * selector fails here rather than silently inheriting the default pattern.
     *
     * ⚠ 23 SINCE `QRPAGER-41` PART B ADDED `studio-chromium`, and the count moving is the
     * guard working rather than a tax on it: that project carries its own `testMatch`, so the
     * rule below was satisfied the moment it was written, and this line is what made a human
     * look at it anyway. The project exists because three of part B's items -- an upload that
     * happens because a file was chosen, a pane that stays in view, and a resolved colour --
     * cannot be settled without the stack.
     */
    expect(resolved.projects).toHaveLength(23)

    for (const project of resolved.projects) {
      const selects = project.testMatch !== undefined
      const ignores = (project.testIgnore ?? []).includes('**/support/test/**')

      /*
       * ⚠ THE RULE, AND IT IS NOT "has a testIgnore". Playwright project options REPLACE the
       * top-level ones rather than merging, so a project with its own `testIgnore` does not
       * inherit the line above — which is exactly how the defect arrived. A project is safe
       * only if it names what it WANTS (`testMatch`), or if its own ignore list carries the
       * Vitest tree.
       */
      expect(
        selects || ignores,
        `project "${project.name}" would collect browser/support/test/** — give it a testMatch, or add the ignore to its own list`,
      ).toBe(true)
    }
  })
})
