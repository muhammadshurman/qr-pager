import { readFileSync } from 'node:fs'
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

    expect(body).not.toMatch(/\bcp\s+-[a-zA-Z]*p/)
    expect(body).not.toMatch(/\bcp\s+--preserve/)

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
