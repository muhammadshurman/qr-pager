import { spawn, spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { createServer } from 'node:net'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/** A shell script with its comments removed, so a rule is judged on what the shell would RUN. */
const code = (p) =>
  read(p)
    .split('\n')
    .filter((line) => !/^\s*#/.test(line))
    .join('\n')

/**
 * ================================================================================
 * THE TWO DEFECTS THE REVERT PROOF FOUND IN ITSELF — QRPAGER-49A
 * ================================================================================
 *
 * `§6`: *"Every bug found gets a regression test that fails without the fix."* The behavioural
 * regression for both is the proof's own run — it failed twice, in two different ways, before
 * it passed — but that run is a five-minute browser job, and nothing in the ordinary suite
 * would notice somebody reintroducing either mistake. These cases pin the two, as SOURCE
 * assertions, and say plainly that is what they are: they cannot show the behaviour, only
 * that the construct that caused it is absent and the one that cures it is present.
 *
 *   1. A FUNCTION THAT TOGGLES ERREXIT. `harness()` ran `set +e … set -e` inside itself, so on
 *      returning the harness's non-zero status it had just re-armed errexit and the script
 *      aborted — silently — on the status it exists to read.
 *   2. A RESTORE THAT KEEPS THE OLD MTIME. `cp -p` put back a template with its original
 *      timestamp, older than the compiled view made from the injected text, so Laravel kept
 *      serving the injected page from a tree that was byte-identical to HEAD.
 */
describe('theme-paint-revert-proof', () => {
  const script = 'tools/gate/bin/theme-paint-revert-proof'

  it('never toggles errexit — a status is read with `if … then … else status=$?`', () => {
    expect(code(script)).not.toMatch(/^\s*set \+e\b/m)
    expect(code(script)).not.toMatch(/^\s*set \+o errexit\b/m)
  })

  it('says WHICH line aborted it, so an abort can never again be silent', () => {
    expect(code(script)).toMatch(/set -Eeuo pipefail/)
    expect(code(script)).toMatch(/trap '.*aborted by errexit at line \$\{LINENO\}.*' ERR/)
  })

  it('restores WITHOUT preserving the old mtime, and clears the compiled views after every edit and restore', () => {
    const body = code(script)

    // every way of keeping an old mtime, not only `cp -p` (QA L12 found `cp -a` slipped past)
    expect(body).not.toMatch(/\bcp\s+-[a-zA-Z]*[pa]/)
    expect(body).not.toMatch(/\bcp\s+--(preserve|archive)/)
    expect(body).not.toMatch(/\brsync\b/)
    expect(body).not.toMatch(/\btouch\s+-[a-zA-Z]*[rd]/)

    // defined once, and called after the injection, after the in-loop revert and in the trap
    expect(body).toMatch(/invalidate_views\(\) \{/)
    expect(body.match(/^\s*invalidate_views\s*$/gm)?.length ?? 0).toBeGreaterThanOrEqual(3)
    expect(body).toMatch(/php artisan view:clear/)
  })

  it('refuses to inject into a tree that is not committed, and verifies the revert byte for byte', () => {
    const body = code(script)

    expect(body).toMatch(/git diff --quiet HEAD/)
    expect(body).toMatch(/cmp -s "\$file" <\(git show "HEAD:\$\{file\}"\)/)
  })

  it('takes its templates from the enum, and FAILS for one it cannot inject into', () => {
    const body = code(script)

    expect(body).toMatch(/publish-sentinel-theme --templates/)
    // no hard-coded arrangement names anywhere in what the shell runs
    for (const name of ['rail', 'ember', 'tile']) {
      expect(body, `a hard-coded "${name}" is a list of three`).not.toMatch(
        new RegExp(String.raw`["' ]${name}["' ]`),
      )
    }

    expect(read(script)).toMatch(/cannot cover it/)
  })
})

describe('theme-paint-proof', () => {
  const script = 'tools/gate/bin/theme-paint-proof'

  it('clears the compiled views BEFORE it seeds or publishes anything', () => {
    const body = code(script)
    const clear = body.indexOf('php artisan view:clear')
    // The SEEDING step, not the first mention: `cleanup()` also runs the seeder, to restore the
    // demo restaurant, and it is defined earlier in the file than the step that seeds.
    const seed = body.indexOf('php artisan studio:demo-tenant >/dev/null || fail')
    const publish = body.indexOf('bin/publish-sentinel-theme "$template"')

    expect(clear, 'the orchestrator does not clear the compiled views').toBeGreaterThan(-1)
    expect(seed, 'the seeding step was not found').toBeGreaterThan(-1)
    expect(publish, 'the publish step was not found').toBeGreaterThan(-1)
    expect(seed).toBeGreaterThan(clear)
    expect(publish).toBeGreaterThan(clear)
  })

  it('takes its templates from the enum and carries no list of them', () => {
    const body = code(script)

    expect(body).toMatch(/publish-sentinel-theme --templates/)

    for (const name of ['rail', 'ember', 'tile']) {
      expect(body, `a hard-coded "${name}" is a list of three`).not.toMatch(
        new RegExp(String.raw`["' ]${name}["' ]`),
      )
    }
  })

  it('exits with the reporter’s status, so the verdict is the exit code', () => {
    // The reporter is the LAST command, with nothing piped from it and nothing after it.
    const lines = code(script)
      .split('\n')
      .filter((l) => l.trim() !== '')
    const last = lines.at(-1) ?? ''

    expect(last).toMatch(/^node tools\/landing\/paint-report\.mjs "\$OUT" --templates /)
  })
})

/**
 * ================================================================================
 * THE `§11` ROUND'S FIXES TO THE TWO SCRIPTS — pinned, and where possible DRIVEN
 * ================================================================================
 *
 * QA L12 is right that the two defects the revert proof found in itself were BEHAVIOURAL and the
 * first pins above only match constructs. These cases drive what can be driven cheaply — every
 * one of them fails the script BEFORE it touches shared state, so none writes to the demo
 * restaurant or the bucket — and pin what cannot.
 */
describe('theme-paint-proof refuses before it touches anything', () => {
  const script = join(REPO, 'tools/gate/bin/theme-paint-proof')

  /** A free port, held open for the duration of `body`. */
  const holdingPort = async (body) => {
    const server = createServer()

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))

    try {
      return await body(server.address().port, server)
    } finally {
      await new Promise((resolve) => server.close(resolve))
    }
  }

  /*
   * ⚠ `APP_ENV` IS PINNED, NOT INHERITED — found by CI, not locally. The frontend job's Vitest step
   * runs BEFORE `apps/api/.env` exists, so `php artisan env` reports Laravel's own default,
   * `production`, and the orchestrator's environment guard (correctly) refused before it reached the
   * port and output-path checks these cases target. They passed on a machine whose `.env` says
   * `local`. A test whose verdict depends on a developer's dotfile is not a test; the one case that
   * WANTS `production` says so explicitly.
   */
  const run = (env) =>
    spawnSync(script, [], {
      encoding: 'utf8',
      env: { ...process.env, APP_ENV: 'testing', ...env },
      timeout: 60_000,
    })

  it('REFUSES a held port and leaves its holder ALIVE — Security 2 / QA M1', async () => {
    await holdingPort(async (port, server) => {
      // the holder answers connections, as a developer's own server would
      let connections = 0

      server.on('connection', (socket) => {
        connections += 1
        socket.end()
      })

      const result = run({ QRPAGER_PAINT_PORT: String(port) })

      expect(result.status).toBe(1)
      expect(result.stdout + result.stderr).toContain(`port ${String(port)} is already in use`)

      // refused BEFORE the seed, the cache clear and the build: none of them ran
      expect(result.stdout).not.toContain('clearing the compiled views')
      expect(result.stdout).not.toContain('seeding the demo restaurant')
      expect(result.stdout).not.toContain('building apps/site')

      // and the holder was NOT killed: the old cleanup ran `kill -9` on whatever owned the port
      expect(server.listening).toBe(true)

      const probe = spawnSync(
        process.execPath,
        [
          '-e',
          `require('net').connect(${String(port)}, '127.0.0.1').on('connect', () => process.exit(0)).on('error', () => process.exit(1))`,
        ],
        { timeout: 10_000 },
      )

      expect(probe.status, 'the holder no longer accepts connections: it was killed').toBe(0)
      // `spawnSync` blocks this process's event loop, so the holder's `connection` events fire only
      // once it returns: give them a turn before counting. The script probes the port, so >= 1.
      await new Promise((resolve) => setTimeout(resolve, 100))

      expect(connections).toBeGreaterThan(0)
    })
  })

  it('REFUSES an environment that is not local or testing, before clearing a cache — Security 6', () => {
    const result = run({ APP_ENV: 'production', QRPAGER_PAINT_PORT: '39097' })

    expect(result.status).toBe(1)
    expect(result.stdout + result.stderr).toMatch(/refusing to run: APP_ENV is 'production'/)
    expect(result.stdout).not.toContain('clearing the compiled views')
    expect(result.stdout).not.toContain('seeding the demo restaurant')
  })

  it('REFUSES an output directory outside its own trees, and deletes nothing — Security 3 / QA L8', () => {
    const docs = join(REPO, 'docs')

    for (const out of [
      docs,
      REPO,
      join(REPO, 'storage', 'theme-paint', '..', '..', 'docs'),
      join(REPO, 'apps'),
    ]) {
      const result = run({ QRPAGER_PAINT_OUT: out, QRPAGER_PAINT_PORT: '39098' })

      expect(result.status, `${out} was accepted`).toBe(1)
      expect(result.stdout + result.stderr).toContain(
        'is not storage/theme-paint or storage/theme-paint-revert',
      )
      expect(result.stdout).not.toContain('clearing the compiled views')
    }

    // the thing a `rm -rf "$OUT"` would have taken
    expect(existsSync(join(docs, 'PLAN.md'))).toBe(true)
    expect(existsSync(join(REPO, 'apps', 'api', 'artisan'))).toBe(true)
  })

  it('frees the port ONLY for a server this run started, and turns a failed restore red', () => {
    const body = code('tools/gate/bin/theme-paint-proof')

    expect(body).toMatch(/if \[ -n "\$SITE_PID" \]; then\s+free_port "\$PORT"\s+fi/)
    // …and that is the ONLY call: an unconditional one is what SIGKILLed a holder it had just refused
    expect(body.match(/free_port "\$PORT"/g)?.length).toBe(1)
    expect(body).toMatch(/restore_failed=1/)
    expect(body).toMatch(
      /if \[ "\$status" -eq 0 \] && \[ "\$restore_failed" -eq 1 \]; then\s+status=1/,
    )
  })

  it('puts the object-store secret in the process environment, never on a command line — Security 8', () => {
    const body = code('tools/gate/bin/theme-paint-proof')

    expect(body).not.toMatch(/\bsetsid env\b/)
    expect(body).not.toMatch(/\benv\s+[A-Z_]+=/)
    expect(body).toMatch(
      /AWS_SECRET_ACCESS_KEY="\$SECRET" AWS_DEFAULT_REGION="\$REGION" \\\s+setsid pnpm/,
    )
  })

  it('names the process it waits for, rather than every child — QA L15', () => {
    const body = code('tools/gate/bin/theme-paint-proof')

    expect(body).not.toMatch(/^\s*wait 2>\/dev\/null/m)
    expect(body).toMatch(/wait "\$SITE_PID"/)
  })
})

describe('the signal traps really exit — QA M2', () => {
  /** The three trap lines, cut out of the REAL script so the pin and the behaviour are one thing. */
  const trapLines = (file) =>
    read(file)
      .split('\n')
      .filter((line) => /^trap (cleanup EXIT|'exit 130' INT|'exit 143' TERM)\s*$/.test(line))

  for (const file of [
    'tools/gate/bin/theme-paint-proof',
    'tools/gate/bin/theme-paint-revert-proof',
  ]) {
    it(`${file} carries exactly the three traps, and not the one that resumed the script`, () => {
      expect(trapLines(file)).toEqual([
        'trap cleanup EXIT',
        "trap 'exit 130' INT",
        "trap 'exit 143' TERM",
      ])
      expect(code(file)).not.toMatch(/trap cleanup EXIT INT TERM/)
    })

    it(`${file}'s trap lines, run for real: SIGTERM exits 143, cleanup runs ONCE, the next step never runs`, async () => {
      const harness = [
        'cleanup() { local s=$?; echo "cleanup-ran status=$s"; kill "$SLEEPER" 2>/dev/null; }',
        ...trapLines(file),
        'echo ready',
        'sleep 5 & SLEEPER=$!',
        'wait $SLEEPER',
        'echo STEP-AFTER-THE-SIGNAL',
      ].join('\n')

      const child = spawn('bash', ['-c', harness], { stdio: ['ignore', 'pipe', 'pipe'] })
      let out = ''
      let signalled = false

      child.stdout.on('data', (chunk) => {
        out += String(chunk)

        // not at once: SIGTERM between `sleep 5 &` and `SLEEPER=$!` would orphan the sleeper, which
        // holds this pipe open and makes the case time out — a race in the HARNESS, not the trap
        if (out.includes('ready') && !signalled) {
          signalled = true
          setTimeout(() => child.kill('SIGTERM'), 400)
        }
      })

      const status = await new Promise((resolve) => child.on('close', resolve))

      expect(status).toBe(143)
      expect(out.match(/cleanup-ran/g)?.length).toBe(1)
      expect(out).toContain('cleanup-ran status=143')
      expect(out).not.toContain('STEP-AFTER-THE-SIGNAL')
    })
  }
})

describe('theme-paint-revert-proof re-seeds the demo after any abort — QA M3', () => {
  const body = code('tools/gate/bin/theme-paint-revert-proof')

  it('marks the demo as possibly polluted at the first injection and clears the mark only after the final green run', () => {
    expect(body).toMatch(/^NEEDS_RESEED=0$/m)
    expect(body).toMatch(/invalidate_views\n\s+NEEDS_RESEED=1/)
    // cleared only inside the branch where the final harness run passed
    expect(body).toMatch(/if harness after [^\n]+; then\s+NEEDS_RESEED=0/)
  })

  it('re-runs the seeder from the trap when the mark is set, and fails the run if that fails', () => {
    expect(body).toMatch(
      /if \[ "\$NEEDS_RESEED" = "1" \]; then[\s\S]*studio:demo-tenant[\s\S]*status=1/,
    )
  })

  it('injects the SAME literal into a second property, so a collector that cannot read it fails the proof', () => {
    expect(read('tools/gate/bin/theme-paint-revert-proof')).toContain('accent-color:${LITERAL}')
    expect(read('tools/gate/bin/theme-paint-revert-proof')).toMatch(
      /no finding names .* in accent-color/,
    )
  })

  it('prints its totals lines with a pattern that can match what the report prints — QA L1', () => {
    // the report prints `  totals: …` indented, and has no `collected` line
    expect(body).not.toContain('|collected|totals:)')
    expect(body).toContain('[[:space:]]+totals:')
  })
})
