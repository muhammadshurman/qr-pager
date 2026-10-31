import { spawnSync } from 'node:child_process'
import { copyFileSync, existsSync, readFileSync, rmSync } from 'node:fs'
import { join } from 'node:path'

import { afterEach, describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * `pnpm run typecheck` MUST COVER THE ROOT TYPESCRIPT PROJECT
 * ================================================================================
 *
 * ⚠ IT DID NOT. The script was `pnpm -r --no-bail run typecheck`, which runs the script of
 * that name in every WORKSPACE PACKAGE. `tsconfig.json` at the repository root owns
 * `browser/**\/*.ts`, `vitest.config.ts` and `playwright.config.ts`, none of which belongs
 * to a package -- so `tsc` was never pointed at them. A planted TS2345 in `browser/`
 * passed `pnpm run typecheck` and passed `pnpm run lint` as well, because the ESLint config
 * uses `projectService` and reports type errors as nothing at all.
 *
 * That is 708 lines of the browser suite with no type checking, and they are the only code
 * in the repository that can read a computed style -- all four clauses of `R-118` and the
 * one check that can see what Chromium actually renders. Two of this task's worst defects
 * were harness defects in exactly that file (`PLAN §4a`: load-then-inject left four buttons
 * at the UA pair, and `setAttribute('data-theme')` invalidated partially), so it is not a
 * file where a silent error is cheap.
 *
 * THREE ASSERTIONS, and the first two are what stop the third from being a coincidence:
 *
 *   1. the aggregate script actually runs the root project, read from `package.json`;
 *   2. `tsc -p tsconfig.json --listFiles` includes each of the three real inputs, so the
 *      claim is about the project's reach rather than about one planted file's location;
 *   3. a planted TS2345 inside `browser/` is REJECTED, with the diagnostic code asserted.
 */

const PLANTED = 'browser/planted-type-error.generated.ts'

function tsc(...args) {
  return spawnSync('pnpm', ['exec', 'tsc', '-p', 'tsconfig.json', ...args], {
    cwd: REPO,
    encoding: 'utf8',
    shell: false,
  })
}

afterEach(() => {
  rmSync(join(REPO, PLANTED), { force: true })
})

describe('the root TypeScript project is inside the gate', () => {
  it('has the aggregate typecheck script run the root project, not packages alone', () => {
    const pkg = JSON.parse(read('package.json'))
    // Both halves named, so neither can be dropped without this failing: the root project
    // and the recursive package walk.
    expect(pkg.scripts.typecheck).toContain('typecheck:root')
    expect(pkg.scripts.typecheck).toContain('pnpm -r')
    expect(pkg.scripts['typecheck:root']).toBe('tsc -p tsconfig.json')

    // And the root project still owns the three inputs, so a future edit that empties
    // `include` fails here rather than making the project vacuous.
    const tsconfig = read('tsconfig.json')
    expect(tsconfig).toContain('browser/**/*.ts')
    expect(tsconfig).toContain('vitest.config.ts')
    expect(tsconfig).toContain('playwright.config.ts')
  })

  it('type-checks the browser suite, the vitest config and the playwright config', () => {
    // --listFiles is the project's own answer about its reach. A glob that silently matches
    // nothing -- a renamed directory, a changed extension -- is caught here.
    const result = tsc('--listFiles')
    expect(result.status, `tsc failed on the clean tree:\n${result.stdout}`).toBe(0)

    const files = result.stdout
      .split('\n')
      .map((line) => line.trim().replace(`${REPO}/`, ''))
      .filter(Boolean)

    for (const expected of ['browser/a11y.spec.ts', 'vitest.config.ts', 'playwright.config.ts']) {
      expect(files, `${expected} is not in the root project`).toContain(expected)
    }
    // The browser suite is the bulk of it and the reason this matters.
    expect(read('browser/a11y.spec.ts').split('\n').length).toBeGreaterThan(400)
  }, 60_000)

  it('REJECTS a planted TS2345 in browser/ -- the proof the fixture exists for', () => {
    expect(existsSync(join(REPO, PLANTED)), 'the planted file leaked from an earlier run').toBe(
      false,
    )
    copyFileSync(
      join(REPO, 'tools/gate/fixtures/planted-type-error.ts.fixture'),
      join(REPO, PLANTED),
    )

    const result = tsc()
    // Exactly 2, measured, not "non-zero": that is `tsc`'s status for "diagnostics were
    // reported" and typescript is pinned to an exact 6.0.3, so the value is knowable.
    // §8: assert exact values, never ranges.
    expect(result.status, 'tsc accepted a TS2345 in the browser suite').toBe(2)
    // The diagnostic code, not just a non-zero exit: an exit 1 from a missing config or a
    // crashed compiler would otherwise read as this proof passing.
    expect(result.stdout).toContain('error TS2345')
    expect(result.stdout).toContain('planted-type-error.generated.ts')
  }, 60_000)
})
