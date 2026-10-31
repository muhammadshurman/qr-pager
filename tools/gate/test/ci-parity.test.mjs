import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * `pnpm gate` AND CI's FRONTEND JOB MUST RUN THE SAME STEPS
 * ================================================================================
 *
 * ⚠ THIS EXISTS BECAUSE THEY HAD ALREADY DRIFTED. `lint:shell` -- the `$?` hazard checker,
 * itself added because three evidence readings in one session misreported an exit code --
 * was added to `pnpm gate` and to the `lint` script and NEVER to the workflow. So it ran on
 * the builder's machine and not on the runner, which is `R-126`'s shape one step over: *a
 * green local gate and a green CI are two different claims*, and the second one being
 * ABSENT looks identical to "no news".
 *
 * `composer guard` rule 9 is the precedent -- it fails if the workflow's branch patterns can
 * exclude a pushed branch, so the hole cannot be reintroduced quietly. This is the same
 * mechanism for the step list.
 *
 * BOTH DIRECTIONS, deliberately. A gate step missing from CI means the runner never checks
 * it; a CI step missing from the gate means a developer cannot reproduce a red locally, and
 * this project has a `§5` row about exactly that (`W-14`: one CI red that was never findable
 * in the tree).
 */

const workflow = read('.github/workflows/ci.yml')
const frontendJob = workflow.slice(workflow.indexOf('  frontend:'), workflow.indexOf('  runtime:'))

/** Every `['pnpm', [...]]` step the gate script runs, as the command line it becomes. */
function gateSteps() {
  const gate = read('tools/gate/bin/gate.mjs')
  const block = /const steps = \[([\s\S]*?)\n\]/.exec(gate)
  expect(block, 'tools/gate/bin/gate.mjs has no `const steps = [...]` to read').not.toBeNull()
  return [...block[1].matchAll(/\['pnpm',\s*\[([^\]]*)\]\]/g)].map(
    (m) =>
      `pnpm ${m[1]
        .split(',')
        .map((part) => part.trim().replace(/^['"]|['"]$/g, ''))
        .filter((part) => part !== '')
        .join(' ')}`,
  )
}

/**
 * Provisioning, not gating. These exist only in CI by design: the gate runs in a tree that
 * is already installed and already has a browser, so a step that CREATES those cannot be a
 * gate step. Named explicitly rather than pattern-matched, so a new CI-only step has to be
 * justified here instead of slipping past the parity check.
 */
const PROVISIONING = [
  'pnpm --version',
  'pnpm install --frozen-lockfile',
  'pnpm exec playwright install --with-deps chromium',
  'pnpm store path --silent',
]

/** Every `pnpm …` command line the frontend job runs, across `run:` scalars and blocks. */
function ciSteps() {
  return [...frontendJob.matchAll(/^\s*(?:- )?(?:run: )?(pnpm [^\n|>]*)$/gm)]
    .map((m) => m[1].trim())
    .filter((line) => !PROVISIONING.some((p) => line.startsWith(p)))
}

describe('the workspace gate and CI run the same steps', () => {
  it('reads a non-empty step list from each, so the comparison is not vacuous', () => {
    // Both parsers could silently return [] and make every assertion below trivially true
    // -- which is the shape the vacuous-assertion proof in apps/api/bin exists for.
    expect(gateSteps().length).toBeGreaterThanOrEqual(9)
    expect(ciSteps().length).toBeGreaterThanOrEqual(9)
    expect(frontendJob).toContain('Frontend workspace')
  })

  it('has every GATE step in the CI frontend job', () => {
    const ci = ciSteps()
    const missing = gateSteps().filter((step) => !ci.some((line) => line.startsWith(step)))
    expect(
      missing,
      'these `pnpm gate` steps are not run by CI, so the runner never checks them',
    ).toEqual([])
  })

  it('has every CI pnpm step in the GATE', () => {
    const gate = gateSteps()
    const missing = ciSteps().filter((line) => !gate.some((step) => line.startsWith(step)))
    expect(
      missing,
      'these CI steps are not in `pnpm gate`, so a developer cannot reproduce a red locally',
    ).toEqual([])
  })

  it('names the Node version from .nvmrc and never as a literal', () => {
    // §7.4: one source for the version. Two -- one in .nvmrc for a developer and one
    // hard-coded here for CI -- is the duplicated-list shape QA H-1 and guard rule 11c
    // already cost this project.
    expect(frontendJob).toContain('node-version-file: .nvmrc')
    expect(frontendJob).not.toMatch(/node-version:\s*['"]?\d/)
  })

  it('names the pnpm version nowhere, taking it from packageManager via corepack', () => {
    expect(frontendJob).toContain('corepack enable')
    expect(frontendJob).toContain('corepack prepare --activate')
    // COMMENTS STRIPPED FIRST: the job's own comment explains that corepack reads
    // `"packageManager": "pnpm@12.16.1"` from package.json, and the assertion matched its
    // own documentation -- the same way the shell checker's prose defeated its pipefail
    // grep. A version in a comment is an explanation; a version in a `with:` or a `run:`
    // is a second source.
    const code = frontendJob.replace(/^\s*#.*$/gm, '')
    expect(code, 'a literal pnpm version in the job would be a second source for it').not.toMatch(
      /pnpm@\d/,
    )
    expect(code).not.toMatch(/version:\s*['"]?\d+\.\d+/)
  })

  it('does NOT use setup-node’s own pnpm cache input, which cannot work here', () => {
    // `cache: pnpm` makes the action run `pnpm store path` as part of setup-node -- before
    // corepack has put pnpm on the runner. The store is cached in its own step after
    // corepack instead, which is why that step exists.
    const setupNode = frontendJob.slice(frontendJob.indexOf('actions/setup-node'))
    const untilNextStep = setupNode.slice(0, setupNode.indexOf('      - name:'))
    expect(untilNextStep).not.toMatch(/cache:\s*['"]?pnpm/)
    expect(frontendJob).toContain('pnpm store path --silent')
  })

  it('installs with the lockfile as the authority', () => {
    expect(frontendJob).toContain('pnpm install --frozen-lockfile')
  })

  it('asserts the working tree is unchanged by the job', () => {
    // A committed artefact that disagrees with its generator is the quietest kind of wrong,
    // and this is the step that makes it loud on the runner.
    expect(frontendJob).toContain('git status --porcelain')
  })
})
