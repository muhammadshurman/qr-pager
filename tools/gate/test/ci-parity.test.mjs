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
const gatesJob = workflow.slice(workflow.indexOf('  gates:'), workflow.indexOf('  frontend:'))

/** Every `['pnpm', [...]]` step the gate script runs, as the command line it becomes. */
/**
 * ⚠ COMMENTS ARE STRIPPED BEFORE ANYTHING IS COUNTED, AND THAT IS NOT COSMETIC. The gate's
 * step array carries long docblocks, and one of them QUOTES THIS FILE'S OWN REGEX -- so an
 * occurrence count taken over the raw text includes a comment and disagrees with the parse
 * for a reason that is not a defect. Measured: 14 raw against 13 parsed.
 */
function stripComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}

function gateSteps() {
  const gate = read('tools/gate/bin/gate.mjs')
  const block = /const steps = \[([\s\S]*?)\n\]/.exec(gate)
  expect(block, 'tools/gate/bin/gate.mjs has no `const steps = [...]` to read').not.toBeNull()

  const body = stripComments(block[1])
  const parsed = [...body.matchAll(/\['pnpm',\s*\[([^\]]*)\]\]/g)]

  /**
   * ⚠ THE PARSER MUST SEE EVERY STEP, AND THIS IS WHAT MAKES THAT TRUE RATHER THAN HOPED.
   *
   * `[^\]]*` cannot match a step prettier has wrapped across lines, so a long inline flag
   * list would be INVISIBLE here -- and an invisible step is one CI is no longer required to
   * run, silently, which is exactly the hole `R-126` and this file's own `contracts:check`
   * story are about. Measured while adding Task 9's browser step: the multi-line form parsed
   * to nothing and the parity assertions below still passed.
   *
   * So the count is checked against the raw occurrences: if they disagree, a step exists that
   * this parser cannot read, and that is a failure rather than a smaller list.
   */
  const occurrences = [...body.matchAll(/\['pnpm',/g)]

  expect(
    parsed.length,
    'a `pnpm` step in gate.mjs is not readable by this parser -- keep each one on a single ' +
      'line (put long flag lists in a package.json script), or this check stops covering it',
  ).toBe(occurrences.length)

  return parsed.map(
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
  'pnpm exec playwright install --with-deps',
  'pnpm store path --silent',
]

/**
 * ================================================================================
 * THE ONE GATE STEP CI RUNS IN A DIFFERENT JOB -- AND IT IS ASSERTED, NOT EXCUSED
 * ================================================================================
 *
 * `pnpm run test:e2e` stands up the REAL Laravel API against the composed Postgres and Redis,
 * issues a real pager session through the domain service, serves the production build of
 * `apps/web`, and puts a TLS front in front of both so the origin is a secure context. The
 * `frontend` job has no PHP, no Postgres and no Redis; the `gates` job has all three.
 *
 * ⚠ SO THE STEP MOVES, AND THE ASSERTION BELOW IS WHAT MAKES THAT DIFFERENT FROM VANISHING.
 * An exception that merely removed it from the comparison would let it leave CI entirely and
 * nothing would notice -- which is `R-126`'s shape and the reason `PROVISIONING` above is a
 * named list rather than a pattern. Named here, and required to be present in `gates`.
 */
const RUN_IN_THE_GATES_JOB = ['pnpm run test:e2e']

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
    const missing = gateSteps()
      .filter((step) => !RUN_IN_THE_GATES_JOB.includes(step))
      .filter((step) => !ci.some((line) => line.startsWith(step)))
    expect(
      missing,
      'these `pnpm gate` steps are not run by CI, so the runner never checks them',
    ).toEqual([])
  })

  it('runs the one gates-job step in the GATES job, so it has not left CI', () => {
    // The other half of the exception above. Without this, naming a step here would be a way
    // to remove it from CI without removing it from the gate.
    for (const step of RUN_IN_THE_GATES_JOB) {
      expect(gateSteps(), `${step} is named as a gates-job step but is not in the gate`).toContain(
        step,
      )
      expect(
        gatesJob,
        `${step} is named as a gates-job step and the gates job does not run it`,
      ).toContain(step)
    }
  })

  it('has every CI pnpm step in the GATE', () => {
    const gate = gateSteps()
    const missing = ciSteps()
      .filter((line) => !RUN_IN_THE_GATES_JOB.some((step) => line.startsWith(step)))
      .filter((line) => !gate.some((step) => line.startsWith(step)))
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

    // ⚠ `php-version` IS EXCLUDED, AND THIS IS A NARROWING, SAID SO RATHER THAN SLIPPED IN.
    //
    // Task 8b had to give this job PHP, because the panel theme's first @import reads
    // apps/api's composer tree. `setup-php` is then configured the way the `gates` job has
    // always configured it -- `php-version: '8.4'` -- and the catch-all below matched it.
    //
    // The clause's subject is the JS toolchain: `.nvmrc` is the single source for Node and
    // `packageManager` for pnpm, and a literal of either here would be a second source. PHP
    // has no equivalent file in this repo: `composer.json` says `^8.3`, which is a MINIMUM
    // and not the pinned runner version, so it is a different fact rather than the same one
    // written twice. A `.php-version` + `php-version-file` input was considered and NOT
    // taken: this session cannot reach the network to confirm that input exists on
    // `shivammathur/setup-php`, and an input the action ignores would silently pin nothing.
    //
    // So the exclusion is narrow -- the literal `php-version` key only -- and the
    // second-source worry it would otherwise drop is picked up by the next assertion
    // instead, which is strictly more than the catch-all gave.
    expect(code.replace(/php-version:.*$/gm, '')).not.toMatch(/version:\s*['"]?\d+\.\d+/)

    const phpPins = [...workflow.matchAll(/php-version:\s*'([^']+)'/g)].map((m) => m[1])

    expect(
      phpPins.length,
      'no job provisions PHP, so the agreement below is vacuous',
    ).toBeGreaterThan(1)
    expect(new Set(phpPins).size, `the workflow pins PHP at ${phpPins.join(' and ')}`).toBe(1)
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

  it('installs apps/api’s composer tree in any job that builds its assets', () => {
    /*
     * ⚠ THE JOB COULD NOT PASS, AND THE REASON WAS AN ORDER NOBODY STATED. Task 8b gave the
     * frontend job `npm ci && npm run build` in `apps/api`, because `browser/panel.spec.ts`
     * measures the COMPILED panel theme. But the first line of each panel theme is
     *
     *     @import '../../../../vendor/filament/filament/resources/css/theme.css'
     *
     * and `/vendor` is git-ignored. Measured by moving `apps/api/vendor` aside: the build
     * dies on module resolution. The same missing tree reds four `panel-theme.test.mjs`
     * cases in that job's own Vitest step, which compiles the same entrypoint.
     *
     * THREE ENVIRONMENTS GOT THIS WRONG THE SAME WAY -- this job, `apps/api/bin/fresh-clone-proof`
     * and `tools/gate/bin/fresh-clone-proof` -- so it is one root cause with three faces, and
     * this is the clause that keeps the CI face shut. The two scripts carry their own.
     */
    for (const [name, job] of [
      ['frontend', frontendJob],
      ['gates', gatesJob],
    ]) {
      const buildsApiAssets =
        /working-directory: apps\/api[\s\S]{0,400}?npm run build/.test(job) ||
        /composer assets/.test(job)

      if (!buildsApiAssets) {
        continue
      }

      expect(
        /composer install/.test(job),
        `the ${name} job builds apps/api's assets and never installs its composer tree. ` +
          "The panel theme's first @import reads vendor/filament, which is git-ignored, so " +
          'the build cannot resolve it.',
      ).toBe(true)

      // And PHP has to be on the runner before composer can run at all.
      expect(
        /shivammathur\/setup-php/.test(job),
        `the ${name} job runs composer with no PHP provisioned`,
      ).toBe(true)
    }

    // NOT VACUOUS: at least one job must actually build those assets, or the loop above
    // skipped everything and proved nothing.
    expect(
      /working-directory: apps\/api[\s\S]{0,400}?npm run build/.test(frontendJob) ||
        /composer assets/.test(gatesJob),
    ).toBe(true)
  })

  it('asserts the working tree is unchanged by the job', () => {
    // A committed artefact that disagrees with its generator is the quietest kind of wrong,
    // and this is the step that makes it loud on the runner.
    expect(frontendJob).toContain('git status --porcelain')
  })
})

/**
 * ================================================================================
 * AND THE SAME FOR THE PHP HALF: `apps/api/bin/gate` AGAINST THE `gates` JOB
 * ================================================================================
 *
 * ⚠ THE CHECK ABOVE WAS SCOPED TO THE FRONTEND JOB, so nothing mechanically caught the PHP
 * gate drifting from CI -- and it had already drifted. `composer contracts:check` was step
 * seven of `apps/api/bin/gate` and a step of NO CI job, while the comment at the frontend
 * job's `tokens:check` asserted it was *"in the `gates` job above"*. Both §11 reviewers
 * found it independently (QA #4 and Security L-1), which is a fair measure of how visible a
 * comment is as a control.
 *
 * Same mechanism, same two directions, and the parser reads each side's own list: the
 * `steps=(…)` array in the bash gate, and the `composer …` run lines in the job.
 *
 * `php artisan test` is in both and is matched like any other step. The job's COVERAGE run
 * (`php artisan test --coverage --min=90`) is provisioning-shaped in the other direction --
 * it is CI asserting a §6 clause the local gate does not need to re-run -- so it is named in
 * `PHP_CI_ONLY` rather than pattern-matched, exactly as `PROVISIONING` is above.
 */

/** Every step in `apps/api/bin/gate`'s `steps=(...)` array, as the command line it runs. */
function phpGateSteps() {
  const gate = read('apps/api/bin/gate')
  const block = /steps=\(([\s\S]*?)\n\)/.exec(gate)
  expect(block, 'apps/api/bin/gate has no `steps=(...)` array to read').not.toBeNull()
  return block[1]
    .split('\n')
    .map((line) => line.replace(/#.*$/, '').trim().replace(/^"|"$/g, ''))
    .filter((line) => line !== '')
}

/**
 * Steps that legitimately exist only on the runner, each with its reason. Named rather than
 * pattern-matched, so a new CI-only step has to be justified here.
 */
const PHP_CI_ONLY = [
  // Provisioning: the local gate runs in an installed, bootstrapped tree.
  'composer install',
  'php artisan db:bootstrap',
  'php artisan key:generate',
  'php artisan migrate',
  // §6's coverage clause. The local gate's `php artisan test` is the same suite; running it
  // twice locally for the coverage number would double the slowest step of the gate.
  'php artisan test --coverage',
]

/** Every `composer …` / `php artisan …` command line the gates job runs. */
function phpCiSteps() {
  return [...gatesJob.matchAll(/^\s*(?:- )?(?:run: )?((?:composer|php artisan) [^\n|>]*)$/gm)]
    .map((m) => m[1].trim())
    .filter((line) => !PHP_CI_ONLY.some((p) => line.startsWith(p)))
}

describe('the PHP gate and CI’s gates job run the same steps', () => {
  it('reads a non-empty step list from each, so the comparison is not vacuous', () => {
    expect(phpGateSteps().length).toBeGreaterThanOrEqual(8)
    expect(phpCiSteps().length).toBeGreaterThanOrEqual(8)
    expect(gatesJob).toContain('Pint, PHPStan, Pest')
  })

  it('has every PHP GATE step in the gates job', () => {
    const ci = phpCiSteps()
    const missing = phpGateSteps().filter((step) => !ci.some((line) => line.startsWith(step)))
    expect(
      missing,
      'these `apps/api/bin/gate` steps are not run by CI, so the runner never checks them',
    ).toEqual([])
  })

  it('has every gates-job composer step in the PHP GATE', () => {
    const gate = phpGateSteps()
    const missing = phpCiSteps().filter((line) => !gate.some((step) => line.startsWith(step)))
    expect(
      missing,
      'these CI steps are not in `apps/api/bin/gate`, so a developer cannot reproduce a ' +
        'red locally',
    ).toEqual([])
  })

  it('runs contracts:check — the step that was missing, named explicitly', () => {
    // The class is covered by the two directions above; this is the instance, by name, so a
    // reader of a failure knows which defect it is. §6.2's PHP-sourced generated artefact.
    expect(phpGateSteps()).toContain('composer contracts:check')
    expect(gatesJob).toContain('composer contracts:check')
  })

  it('declares every composer script both sides name, so neither can invent one', () => {
    // A step list is only as good as the scripts behind it: `composer frobnicate` in the
    // gate and in CI would pass both directions above and fail on every run.
    const composer = JSON.parse(read('apps/api/composer.json'))
    for (const step of [...phpGateSteps(), ...phpCiSteps()]) {
      if (!step.startsWith('composer ')) continue
      const script = step.slice('composer '.length).split(' ')[0]
      expect(
        composer.scripts[script],
        `composer.json declares no \`${script}\` script`,
      ).toBeDefined()
    }
  })
})
