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
  ['pnpm', ['exec', 'vitest', 'run', '--coverage']],
  ['pnpm', ['exec', 'playwright', 'test']],
  ['pnpm', ['--filter', '@qrpager/web', 'run', 'build']],
  ['pnpm', ['--filter', '@qrpager/site', 'run', 'build']],
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
