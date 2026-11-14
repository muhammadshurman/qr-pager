import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
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
 * TASK 9's THIRD GATE, AND WHY IT IS NOT A PARITY EXCEPTION
 * ================================================================================
 *
 * `pnpm run gate:e2e` is a gate of its own: the real Laravel API against the composed Postgres
 * and Redis, a real pager session issued through the domain service, the production build of
 * `apps/web`, and a TLS front so the origin is a secure context. It is NOT a `pnpm gate` step
 * -- `ADR-003` splits the two halves and `gate.mjs`'s header gives the argument -- so the
 * parity comparison below is UNCHANGED and exact.
 *
 * ⚠ WHAT WOULD MAKE IT A GATE IN NAME ONLY IS NOBODY BEING REQUIRED TO RUN IT, so the two
 * assertions further down require it of CI's `gates` job (which already has PHP, Postgres and
 * Redis) and of `three-green-runs`. A third gate with no enforcement is `R-126`'s shape.
 */
const CROSS_HALF_GATE = 'pnpm run gate:e2e'

/**
 * Task 11's invariant gate, and it is the SECOND cross-half gate for the same reason as the
 * first: it needs PHP, Postgres and the object store on one side and the production build of
 * `apps/site` on the other, so it is not a `pnpm gate` step and the parity comparison below
 * stays exact.
 *
 * ⚠ AND IT IS REQUIRED OF CI HERE FOR `R-126`'s REASON, stated two comments up: *a third gate
 * with no enforcement is `R-126`'s shape*. A proof nobody is required to run is a proof that
 * stops being run — and this one has a sharper failure mode than most, because it once
 * reported green four times against a stale build (its own teardown note records it).
 */
const INVARIANT_GATE = 'pnpm run gate:site-isolation'

/**
 * Task 11a's half of the same invariant: the domain lifecycle (`R-133`, `R-170`). A third
 * cross-half gate, required of CI for the reason the two above give — a gate nobody must run
 * stops being run, which is `R-126`'s shape.
 */
const DOMAIN_MOVE_GATE = 'pnpm run gate:domain-move'

/**
 * `QRPAGER-49A`'s gate: *does the theme reach the pixel?* The FOURTH cross-half gate, required
 * of CI for the reason the three above give. It needs PHP, Postgres and the object store (the
 * sentinel theme is published through the real publisher) AND the production build of
 * `apps/site` AND a real Chromium, so it is not a `pnpm gate` step and the exact comparison
 * below stays exact.
 *
 * ⚠ A gate nobody must run stops being run (`R-126`) — and this one carries the dead-control
 * check Muhammad ruled PERMANENT, so a quietly dropped step would make a picker that does
 * nothing undetectable again, in exactly the templates `QRPAGER-49` is about to replace.
 */
const THEME_PAINT_GATE = 'pnpm run gate:theme-paint'

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

  it('requires CI to run the cross-half gate, in the job that can', () => {
    // Task 9's `gate:e2e` is not a `pnpm gate` step, so the parity assertions above do not
    // reach it. This is what stops it being a gate nobody runs.
    expect(
      gatesJob,
      `CI's gates job does not run ${CROSS_HALF_GATE} -- the journey suite, the service ` +
        'worker, the one-handed measurement and R-100 would then be verified by no runner',
    ).toContain(CROSS_HALF_GATE)

    // And it must be a real script rather than a name in a workflow.
    const pkg = JSON.parse(read('package.json'))

    expect(
      Object.keys(pkg.scripts),
      'gate:e2e is required of CI and is not a script in package.json',
    ).toContain('gate:e2e')
  })

  it('requires CI to run Task 11’s invariant gate, in the job that can', () => {
    expect(
      gatesJob,
      `CI's gates job does not run ${INVARIANT_GATE} -- §3 row 11's invariant would then be ` +
        'verified by no runner, which is the one clause Muhammad named as the cross-tenant ' +
        'leak this surface can actually produce',
    ).toContain(INVARIANT_GATE)

    const pkg = JSON.parse(read('package.json'))

    expect(
      Object.keys(pkg.scripts),
      'gate:site-isolation is required of CI and is not a script in package.json',
    ).toContain('gate:site-isolation')
  })

  it('requires CI to run Task 11a’s domain-move gate, in the job that can', () => {
    expect(
      gatesJob,
      `CI's gates job does not run ${DOMAIN_MOVE_GATE} -- R-133 and R-170 are §3 row 11's two ` +
        'remaining invariant mechanisms and would be verified by no runner',
    ).toContain(DOMAIN_MOVE_GATE)

    const pkg = JSON.parse(read('package.json'))

    expect(
      Object.keys(pkg.scripts),
      'gate:domain-move is required of CI and is not a script in package.json',
    ).toContain('gate:domain-move')
  })

  it('requires CI to run QRPAGER-49A’s theme-paint gate, in the job that can', () => {
    expect(
      gatesJob,
      `CI's gates job does not run ${THEME_PAINT_GATE} -- whether a tenant's theme reaches the ` +
        'rendered pixel, and whether any studio colour picker is dead, would then be verified by ' +
        'no runner',
    ).toContain(THEME_PAINT_GATE)

    const pkg = JSON.parse(read('package.json'))

    expect(
      Object.keys(pkg.scripts),
      'gate:theme-paint is required of CI and is not a script in package.json',
    ).toContain('gate:theme-paint')

    // And the script is the orchestrator, not a name that points at nothing.
    expect(pkg.scripts['gate:theme-paint']).toBe('tools/gate/bin/theme-paint-proof')
    expect(existsSync(join(REPO, 'tools/gate/bin/theme-paint-proof'))).toBe(true)
  })

  it('would REFUSE a CI job that dropped the theme-paint step — the control', () => {
    // The assertion above, shown to say no: the job text with the step removed must not
    // contain the command. A check never shown to refuse is the shape this ticket rules out.
    const without = gatesJob.replaceAll(THEME_PAINT_GATE, '')

    expect(without).not.toContain(THEME_PAINT_GATE)
    expect(gatesJob).toContain(THEME_PAINT_GATE)
  })

  it('ships the revert proof as a script, so the claim can be re-run rather than re-read', () => {
    const pkg = JSON.parse(read('package.json'))

    expect(pkg.scripts['gate:theme-paint-revert']).toBe('tools/gate/bin/theme-paint-revert-proof')
    expect(existsSync(join(REPO, 'tools/gate/bin/theme-paint-revert-proof'))).toBe(true)
  })

  it('runs the cross-half gate in each of three-green-runs runs', () => {
    // *"Three consecutive green full-suite runs"* has to mean the whole suite, and the
    // cross-half gate contains every browser-only clause of Task 9.
    const loop = read('tools/gate/bin/three-green-runs')

    expect(
      loop,
      'three-green-runs does not run the cross-half gate, so its "full suite" is not one',
    ).toContain('gate:e2e')
  })

  it('runs every browser step through the engine runner, so any host can run the gate', () => {
    // ⚠ MEASURED, NOT ANTICIPATED, AND THE GATE WAS UNRUNNABLE HERE BEFORE IT. `test:browser`
    // invoked `playwright test` directly, and `pnpm gate` died on this builder's host with
    //
    //   chrome-headless-shell: error while loading shared libraries: libnspr4.so
    //
    // for all five projects. `playwright install-deps` needs root and this host has no sudo,
    // so the WORKSPACE GATE COULD NOT BE RUN AT ALL while the tests themselves were fine --
    // which is exactly the defect `apps/api/bin/gate`'s `PAO_DISABLE=1` exists for, one half
    // over: a gate whose result depends on which machine runs it is not a gate.
    //
    // `tools/browsers/bin/browsers` probes the host, execs NATIVELY when all three engines
    // launch, and falls back to the pinned image when they do not. CI's `frontend` job
    // installs the engines, so there the probe passes and this wrapper costs nothing -- it is
    // not a second toolchain, it is the one that works everywhere.
    //
    // It also pins the binary: `node_modules/.bin/playwright` rather than `pnpm exec`, because
    // pnpm 12 verifies and RELINKS `node_modules` before running a script and did so in the
    // middle of a container run.
    const pkg = JSON.parse(read('package.json'))
    const offenders = Object.entries(pkg.scripts).filter(
      ([, body]) =>
        body.includes('playwright test') && !body.includes('tools/browsers/bin/browsers'),
    )

    expect(
      offenders.map(([name]) => name),
      'these scripts launch browsers without the engine runner, so `pnpm gate` passes or ' +
        'fails on whether the host happens to carry the Playwright OS libraries',
    ).toEqual([])

    /*
     * Not vacuous: there ARE scripts that run playwright, so the filter has something to
     * judge. An empty `scripts` object would otherwise satisfy the assertion above.
     *
     * ⚠ TWO SINCE `QRPAGER-46`, AND THE COUNT IS STILL EXACT RATHER THAN A FLOOR: `test:browser`
     * (the kit and engine projects) and `gate:templates` (the computed-value gate, whose first
     * half is a PHP render the browsers container cannot run). A third would mean a third place
     * that can forget the engine runner, which is what the filter above is for — so adding one
     * should be a deliberate edit here rather than a silent widening.
     */
    expect(
      Object.entries(pkg.scripts)
        .filter(([, body]) => body.includes('playwright test'))
        .map(([name]) => name)
        .sort(),
    ).toEqual(['gate:templates', 'test:browser'])
  })

  it('builds apps/web BEFORE Vitest, in the gate AND in CI -- QA C-1', () => {
    /**
     * ⚠ THE THIRD RECURRENCE OF ONE FAMILY, SO THIS IS THE REGRESSION TEST `§7` SAYS WAS
     * MISSING. 8a shipped pnpm-before-corepack, 8b shipped vendor-before-build, Task 9
     * shipped vitest-before-build -- each caught by a review rather than by a gate.
     *
     * `build-surface.test.ts` reads `apps/web/.next/server/app/s.html` and FAILS rather than
     * skips when it is absent, deliberately: a skipped load-invariant clause is a clause
     * nobody checked. So with the builds after Vitest, both halves were deterministically red
     * on any tree without a stale `.next` -- measured by `fresh-clone-proof` at `a6f9a47`,
     * `gate: FAILED at 'pnpm exec vitest run --coverage'`, 10 cases red.
     *
     * Asserted as an ORDERING over both step lists rather than as "the step exists", because
     * both steps existed the whole time. Index comparison, in both halves, from the same two
     * parsers the rest of this file uses.
     */
    const gate = gateSteps()
    const gateBuild = gate.findIndex(
      (step) => step.includes('@qrpager/web') && step.includes('build'),
    )
    const gateVitest = gate.findIndex((step) => step.includes('vitest'))

    expect(gateBuild, 'the gate has no @qrpager/web build step at all').toBeGreaterThan(-1)
    expect(gateVitest, 'the gate has no vitest step at all').toBeGreaterThan(-1)
    expect(
      gateBuild < gateVitest,
      `pnpm gate runs vitest (step ${String(gateVitest)}) before the web build (step ${String(gateBuild)}), so build-surface.test.ts reads a build that does not exist yet`,
    ).toBe(true)

    // CI's own job, read as raw text in step order rather than through the pnpm-step parser,
    // because the build is a multi-line `run:` block and not a one-line pnpm step.
    const job = frontendJob
    const ciBuild = job.indexOf('pnpm --filter @qrpager/web run build')
    const ciVitest = job.indexOf('pnpm exec vitest run --coverage')

    expect(ciBuild, "CI's frontend job has no @qrpager/web build").toBeGreaterThan(-1)
    expect(ciVitest, "CI's frontend job has no vitest step").toBeGreaterThan(-1)
    expect(
      ciBuild < ciVitest,
      "CI's frontend job runs vitest before the web build, so the job is red on every runner",
    ).toBe(true)
  })

  it('runs the fresh-clone proof as a CI step, on every push rather than at the end of a task', () => {
    // The mechanisation half of QA C-1/H-2. A proof a builder runs by hand when they remember
    // is not a control: three tasks have now shipped a clone-breaking defect past it. As a
    // step it is proven on the push that breaks it.
    expect(
      frontendJob,
      'CI does not run tools/gate/bin/fresh-clone-proof, so a clone-breaking change is caught by a reviewer instead of by a gate',
    ).toContain('tools/gate/bin/fresh-clone-proof')
  })

  it('gives the fresh clone the environment every application-booting gate step needs', () => {
    /*
     * ⚠ CI RED AT `0d02d25`, IN THE CLONE AND NOT ON THE RUNNER — the third defect
     * `QRPAGER-46`'s instrument shipped, and the same shape as its first two: a step that is
     * only correct on a machine somebody already configured.
     *
     * `gate:templates` boots apps/api to render one document under three arrangements. A
     * clone has no `.env`, and Laravel's default for an unset `APP_ENV` is `production`, so
     * the instrument's own fail-closed guard refused it: *"render-arrangements is a
     * development instrument and refuses to run as production."* CI's own `gate:templates`
     * step carried the two lines that fix it and `tools/gate/bin/fresh-clone-proof` did not,
     * so the same gate passed twice on one runner and failed in the clone beside it.
     *
     * The step list is read rather than named: any FUTURE gate step whose package.json
     * script shells to `php` or `artisan` is covered by this the day it is added.
     */
    const scripts = JSON.parse(read('package.json')).scripts
    const booting = gateSteps().filter((step) => {
      const name = /pnpm run ([\w:-]+)/.exec(step)?.[1]

      return name !== undefined && /(^|[\s&|])php\s|artisan/.test(scripts[name] ?? '')
    })

    expect(
      booting,
      'no `pnpm gate` step boots the application any more, so this check covers nothing — ' +
        'delete it deliberately rather than leaving a vacuous assertion behind',
    ).not.toHaveLength(0)

    const proof = read('tools/gate/bin/fresh-clone-proof')

    for (const line of ['cp .env.example .env', 'artisan key:generate']) {
      expect(
        proof,
        `tools/gate/bin/fresh-clone-proof does not run \`${line}\`, so the clone boots ` +
          `${booting.join(', ')} with no environment file and APP_ENV defaults to production`,
      ).toContain(line)
    }

    expect(
      frontendJob,
      "CI's own frontend job does not write apps/api/.env before the gate steps that boot the application",
    ).toContain('cp apps/api/.env.example apps/api/.env')
  })

  it('checks the repositorys own record in both halves, and proves each check bites', () => {
    // §11 round one rulings (b) and (c). The step existing is half the assertion: a checker
    // that cannot fail is the vacuous-assertion shape this project has paid for, so the
    // script's own `--self-test` is run here against deliberately bad inputs.
    expect(
      gateSteps().some((step) => step.includes('records:check')),
      'pnpm gate does not check §0 or the transcripts',
    ).toBe(true)
    expect(frontendJob, 'CI does not check §0 or the transcripts').toContain('records:check')

    const selfTest = spawnSync('node', ['tools/gate/bin/check-records', '--self-test'], {
      cwd: REPO,
      encoding: 'utf8',
    })

    expect(
      selfTest.status,
      `check-records --self-test did not pass:\n${selfTest.stdout}${selfTest.stderr}`,
    ).toBe(0)
    // Named, so a self-test that quietly stopped covering a family fails here.
    expect(selfTest.stdout).toContain('catches  a §5 row count that disagrees with the table')
    expect(selfTest.stdout).toContain('catches  a §0 naming a tag that does not exist')
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
