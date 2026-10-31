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

  it('disables setup-node automatic package-manager cache -- what broke the job', () => {
    // ⚠ THE REGRESSION TEST FOR THE FAILURE THIS JOB ACTUALLY HAD, and the shape is
    // worth keeping because nobody wrote the offending line -- it is a DEFAULT.
    //
    // setup-node v5.0.0 made dependency caching AUTOMATIC when package.json carries a
    // valid `packageManager` field. From the action's own action.yml:
    // `package-manager-cache` has `default: true`, described as "disable automatic caching
    // based on the package manager field in package.json". Ours is "pnpm@12.16.1", so the
    // action resolved pnpm to compute a cache directory and died:
    //
    //     Resolved .nvmrc as 22
    //     Found in cache @ /opt/hostedtoolcache/node/22.23.4/x64
    //     Error: Unable to locate executable file: pnpm.
    //
    // because corepack provisions pnpm in the NEXT step.
    //
    // So this asserts the OPT-OUT IS PRESENT, not merely that `cache:` is absent. The
    // first version of this test checked only the latter and would have passed the broken
    // job -- `cache:` never was in the file, which is exactly why the first diagnosis of
    // this failure was wrong.
    const setupNode = frontendJob.slice(frontendJob.indexOf('actions/setup-node'))
    const inputs = setupNode.slice(0, setupNode.indexOf('      - name:'))

    expect(
      inputs,
      'setup-node v5 caches automatically when packageManager is set, and pnpm does not ' +
        'exist until corepack runs -- package-manager-cache: false is required',
    ).toMatch(/package-manager-cache:\s*false/)
    // And the explicit input stays unset: it resolves the store at the same wrong moment.
    expect(inputs).not.toMatch(/\n\s+cache:\s*['"]?pnpm/)
    // The store IS cached, in its own step after corepack -- the only order in which the
    // path can be resolved at all.
    expect(frontendJob).toContain('pnpm store path --silent')
    expect(frontendJob.indexOf('corepack enable')).toBeLessThan(
      frontendJob.indexOf('pnpm store path --silent'),
    )
  })

  it('provisions pnpm BEFORE anything that needs it on PATH', () => {
    // The ordering the failure was about, as an assertion rather than a comment.
    const corepack = frontendJob.indexOf('corepack enable')
    expect(corepack).toBeGreaterThan(-1)
    for (const m of frontendJob.matchAll(/^\s*(?:- )?(?:run: )?(pnpm [^\n|>]*)$/gm)) {
      expect(m.index, `"${m[1].trim()}" runs before corepack has provisioned pnpm`).toBeGreaterThan(
        corepack,
      )
    }
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
