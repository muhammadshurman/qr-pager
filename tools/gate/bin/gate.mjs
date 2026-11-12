#!/usr/bin/env node
/**
 * ================================================================================
 * THE WORKSPACE GATE. apps/api's sibling, and it KEEPS THE OUTPUT.
 * ================================================================================
 *
 * `apps/api/bin/gate` exists because a full suite run went red once at 363 passed and the
 * failing test's identity was never captured -- what was kept was a one-line summary rather
 * than the output, and "one unexplained" was the honest state of that suite for a week
 * (`PLAN.md` R-049). This is the same discipline for the other half of the repo.
 *
 * Every step's full output is written to one file under `storage/` at the repo root
 * (git-ignored), the path is printed before the first step and again at the end, and the
 * file survives the run.
 *
 * SEPARATE FROM apps/api's GATE, DELIBERATELY. `ADR-003`: *"CI gains a second job for the
 * frontend workspace."* One script spanning both halves would make a PHP failure and a
 * TypeScript failure indistinguishable in the log, which is the opposite of what a gate log
 * is for -- and the two halves have different prerequisites (Postgres and Redis versus
 * none).
 *
 * The closing line COUNTS the array rather than naming a number, because apps/api's said
 * "all six steps green" for the whole of Task 7's build while running seven.
 *
 * `playwright test` is in the list. It needs a browser, which the fresh-clone proof installs
 * with `playwright install chromium` -- and the package is pinned to an exact 1.64.1 so the
 * browser the cache holds is the browser this verifies against.
 *
 * ⚠ TASK 9's CROSS-HALF SUITE IS **NOT** IN THIS LIST, AND THAT IS `ADR-003`'s SPLIT BEING
 * RESPECTED RATHER THAN AN OMISSION.
 *
 * `pnpm run gate:e2e` stands up the real Laravel API against the composed Postgres and Redis,
 * issues a real session through the domain service, and serves the production build of
 * `apps/web` behind a TLS front. Putting it here would make this script span both halves --
 * which the header above argues against in `ADR-003`'s own words: *"One script spanning both
 * halves would make a PHP failure and a TypeScript failure indistinguishable in the log,
 * which is the opposite of what a gate log is for."* It would also make the frontend
 * fresh-clone proof depend on `apps/api` having `vendor/` and an `.env`, which a bare clone
 * does not have and should not need for the TypeScript half.
 *
 * So it is a THIRD gate, and it is enforced rather than remembered:
 * `tools/gate/test/ci-parity.test.mjs` asserts CI's `gates` job runs it -- that job already
 * has PHP, Postgres and Redis -- and that `tools/gate/bin/three-green-runs` runs it too, so
 * "three consecutive green full-suite runs" means the whole thing.
 */
import { spawnSync } from 'node:child_process'
import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const REPO = join(fileURLToPath(new URL('../../..', import.meta.url)))
const LOG_DIR = join(REPO, 'storage', 'logs')
mkdirSync(LOG_DIR, { recursive: true })

const stamp = new Date()
  .toISOString()
  .replace(/[-:]/g, '')
  .replace(/\.\d+Z$/, 'Z')
const log = join(LOG_DIR, `gate-frontend-${stamp}.log`)
writeFileSync(log, '')

const say = (line) => {
  console.log(line)
  appendFileSync(log, `${line}\n`)
}

console.log(`gate: full output -> ${log}\n`)

/**
 * The steps, in order, each as a pnpm script so this cannot drift from what CI runs.
 *
 * `tokens:check` and the three lint checks come before the tests on purpose: a stale
 * generated artefact or a hard-coded value makes a test result uninteresting, and a
 * half-read log is worse than a short one.
 */
const steps = [
  ['pnpm', ['run', 'lint:es']],
  ['pnpm', ['run', 'lint:tokens']],
  // The $? hazard, mechanised. Three evidence readings in one session reported a command
  // as having succeeded when it had exited 1; this is the step that stops that depending
  // on anyone remembering the rule. Proven to fail on
  // tools/gate/fixtures/bad-evidence.sh.fixture.
  // The Filament panels' authored PHP and Blade, Task 8b. A THIRD reader of the one pattern
  // list: ESLint cannot parse PHP or Blade, and scan-css.mjs exempts apps/api by name. It is
  // load-bearing where the apps' equivalent is not -- the panel theme must REDEFINE rather
  // than clear (SR-1), so `p-5` and `bg-white` still compile there and a lint rule is the
  // only instrument that can refuse them.
  ['pnpm', ['run', 'lint:panel']],
  ['pnpm', ['run', 'lint:shell']],
  ['pnpm', ['run', 'format:check']],
  ['pnpm', ['run', 'typecheck']],
  ['pnpm', ['run', 'tokens:check']],
  // §11.7's dependency clause, and it DID NOT EXIST. The ticket's §4.3 said `pnpm audit`
  // "is run as part of the gate and its output is committed" and it was in no script, no
  // workflow and no evidence file -- while the first run returned five advisories (2 high,
  // 3 moderate) against a diff that adds 3,184 lockfile lines. `--audit-level low` so
  // nothing is filtered out silently: every severity is a finding, which is what that
  // section says. The two overrides that close today's five are in pnpm-workspace.yaml
  // with their advisory ids and the reason an override was the only instrument.
  //
  // ⚠ AND THIS IS THE ONE GATE STEP THAT NEEDS THE NETWORK, which is worth stating because
  // this repository argues the other way elsewhere: `fetch-fonts.mjs` is deliberately NOT a
  // gate step, on the grounds that "a gate step that needs the network fails for reasons
  // that have nothing to do with the code". An advisory database is not something a
  // repository can carry, so there is no offline form of this check -- and the §11 review
  // that found the control missing required it as a gate step AND a CI step, not as one or
  // the other. So the trade-off is taken deliberately: a registry outage reddens this gate,
  // and the failure says so rather than looking like a code defect.
  ['pnpm', ['run', 'audit']],
  /**
   * ⚠ THE BUILDS RUN BEFORE VITEST, AND THE ORDER IS LOAD-BEARING. `§11` QA C-1.
   *
   * They used to be the last two steps, and `build-surface.test.ts` reads
   * `apps/web/.next/server/app/s.html` -- deliberately FAILING rather than skipping when it is
   * absent, because a skipped load-invariant clause is a clause nobody checked. So on a tree
   * with no `.next` the gate died at Vitest with 10 red `build-surface` cases, and
   * `tools/gate/bin/fresh-clone-proof` measured exactly that at `a6f9a47`:
   * `gate: FAILED at 'pnpm exec vitest run --coverage'`. The builder's own runs passed only
   * because an earlier `gate:e2e` had left a build behind.
   *
   * ⚠ AND IT IS THE THIRD TIME THIS FAMILY HAS COST A ROUND -- 8a's pnpm-before-corepack, 8b's
   * vendor-before-build, this. `§7` says a recurrence means the regression test was missing, so
   * the fix is not only this reordering: `ci-parity.test.mjs` now asserts the ordering in BOTH
   * halves, and `fresh-clone-proof` is a CI step on every push rather than a thing a builder
   * remembers at the end of a task.
   */
  ['pnpm', ['--filter', '@qrpager/web', 'run', 'build']],
  ['pnpm', ['--filter', '@qrpager/site', 'run', 'build']],
  ['pnpm', ['exec', 'vitest', 'run', '--coverage']],
  /**
   * ⚠ THE KIT AND ENGINE PROJECTS ONLY -- the journey suite is `pnpm run gate:e2e`.
   *
   * These need nothing running: every fixture is loaded from the filesystem with
   * `setContent`, and `engines.spec.ts` starts its own `node:http` server in process. That is
   * what keeps them runnable from a fresh clone with nothing built, which is the property
   * `playwright.config.ts`'s header states and `bin/fresh-clone-proof` relies on.
   *
   * ⚠ THE PROJECT FLAGS LIVE IN `package.json`, NOT HERE, AND THAT IS NOT TIDYING. The
   * parity check reads this array with `/\['pnpm',\s*\[([^\]]*)\]\]/`, which cannot match a
   * step prettier has wrapped across lines -- so a long inline flag list would be INVISIBLE
   * to it and CI would stop being required to run this step, silently. One short line here,
   * the flags in a script both halves share. `ci-parity.test.mjs` now also asserts the parsed
   * count against the raw occurrence count, so the next parser miss is a failure rather than
   * a hole.
   */
  ['pnpm', ['run', 'test:browser']],
  /**
   * ⚠ THE ONE CONTROL THAT CAUGHT `QRPAGER-46`'s TWO SHIPPED DEFECTS WAS A HUMAN LOOKING AT A
   * RENDER, AND THIS IS THAT CONTROL MADE REPEATABLE. A section heading computing at the page
   * title's size in all three new arrangements, and one of them rendering 6,618px tall — both
   * COMPUTED values, with the structure correct in both cases, so the ticket's structural
   * tests were right and blind at the same time (*"assert on structure … never on pixels"*).
   * The `e2e` suite is no help: its fixture document names no template, so every preview it
   * renders is `classic` (`R-303`).
   *
   * Two halves in one script because the first cannot run in the browsers container (PHP 8.4
   * against its 8.2) and the second cannot run outside one on this host: `php artisan
   * landing:render-arrangements` writes one document under every arrangement, and the
   * `landing-templates` project measures the two properties and captures the twelve
   * photographs. Muhammad's instruction of 2026-11-11: *"keep the capture step as a command,
   * not a one-off."*
   */
  ['pnpm', ['run', 'gate:templates']],
  /**
   * ⚠ THE REPOSITORY'S ACCOUNT OF ITSELF, CHECKED RATHER THAN TRUSTED. `§11` round one's
   * rulings (b) and (c), and both were real at `a6f9a47`: `§0` said `§5` held 171 rows while
   * the table held 205 and still said Task 9 must not start, and a committed `gate:e2e`
   * transcript recorded `EXIT=1` while `§4a` claimed all three gates green. `CLAUDE.md` makes
   * `§0` the one input a cold session trusts, so a stale `§0` is not a documentation lapse.
   * `--self-test` proves each check on a deliberately bad input.
   *
   * ⚠⚠ IT IS THE **LAST** STEP AS OF 2026-11-02, AND IT WAS STEP 8 OF 13 — WHICH DEADLOCKED
   * EVERY NEW TASK'S FIRST MEASUREMENT. `§11` QA H-3 found 10a's `§0` figures resting on an
   * UNTRACKED placeholder transcript; this is why that placeholder existed, and the finding's
   * instance was fixed before its cause was understood.
   *
   * The deadlock, exactly: `(b4)` compares every total `§0` states against the committed
   * transcript for the in-flight ticket, and the transcript IS this run's own output. Run
   * early, the check reads a `§0` that cannot yet be right — the figures come from steps that
   * have not happened — so the gate dies at step 8, the test steps never run, and the
   * transcript never acquires the totals that would let the check pass. The only ways out were
   * a hand-made placeholder (what Task 9 and 10a both did, and what QA H-3 correctly called
   * evidence that is not evidence) or deleting the figures from `§0`, which leaves the cell's
   * `pnpm gate` claim matching a SUPERSEDED clause's number — history read as a live claim,
   * the hazard `checkStatedCounts` was written for.
   *
   * Run last, the loop converges honestly in two passes and neither pass states anything it
   * has not measured: pass one produces a transcript carrying the real totals and reddens HERE
   * if `§0` disagrees; `§0` is then written from that transcript and pass two is green with the
   * same numbers. No placeholder, nothing edited by hand.
   *
   * ⚠ THE COST, STATED: a stale `§0` is now a red that arrives ~10 minutes in rather than one
   * minute in, which is against this file's own cheapest-first ordering. That ordering is a
   * convenience; a check that cannot be satisfied without fabricating its own evidence is a
   * defect, so the convenience loses. `ci-parity.test.mjs` asserts membership in both halves
   * rather than position, so CI is unaffected — and the CI job runs it after its own test
   * steps for the same reason.
   */
  ['pnpm', ['run', 'records:check']],
]

for (const [command, args] of steps) {
  const label = `=== ${command} ${args.join(' ')}`
  say(label)

  const result = spawnSync(command, args, { cwd: REPO, encoding: 'utf8', shell: false })
  const output = `${result.stdout ?? ''}${result.stderr ?? ''}`
  process.stdout.write(output)
  appendFileSync(log, output)

  if (result.status !== 0) {
    const message = `\ngate: FAILED at '${command} ${args.join(' ')}' (exit ${String(result.status)}). Full output: ${log}`
    say(message)
    process.exit(1)
  }
  say('')
}

say(`gate: all ${String(steps.length)} steps green. Full output: ${log}`)
