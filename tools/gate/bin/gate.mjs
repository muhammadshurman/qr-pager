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
  ['pnpm', ['run', 'format:check']],
  ['pnpm', ['run', 'typecheck']],
  ['pnpm', ['run', 'tokens:check']],
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
