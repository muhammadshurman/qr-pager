import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * ================================================================================
 * `docs/design/public/` IS REFERENCE, NOT SOURCE — AND THAT IS A MEASUREMENT HERE
 * ================================================================================
 *
 * Muhammad's instruction of 2026-11-09: the three approved public-page designs are
 * REFERENCE ONLY and *"must never be served, imported, or linted as source"*, with the
 * directory added to whatever `lint:panel`, `scan-css` and the token guard read.
 *
 * ⚠ **IT IS ALREADY EXEMPT, AND BY CONSTRUCTION RATHER THAN BY CONFIGURATION — WHICH IS
 * WHY THIS FILE EXISTS INSTEAD OF THREE EXEMPTION ENTRIES.** Measured on 2026-11-09:
 *
 *   `lint:tokens`  (`scan-css.mjs`)    reads `.css`; reported 6 files
 *   `lint:panel`   (`scan-panel.mjs`)  reads the panels' PHP and Blade; reported 20 files
 *   `composer guard`                   reads `app config database routes tests guard/src`
 *                                      under `apps/api`
 *
 * None of their file sets can reach a `.html` under `docs/`. **An exemption entry would
 * therefore be a control that does nothing** — the dead-control shape `§4a` records four
 * instances of, and which this repository refuses on sight. So the exemption is asserted
 * rather than declared: the references DO contain hard-coded hexes, and the scanners DO
 * report clean. If a scanner is ever widened to `.html` or to `docs/`, this file goes red
 * and whoever widened it adds a real exemption with a real reason.
 */

const REPO = new URL('../../..', import.meta.url).pathname
const DIR = join(REPO, 'docs/design/public')
const run = (cmd, args) =>
  execFileSync(cmd, args, { cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })

describe('the approved public-page designs', () => {
  it('are present, and are the three the instruction names', () => {
    expect(readdirSync(DIR).sort()).toEqual(['ember.html', 'rail.html', 'tile.html'])
  })

  it('contain hard-coded values, which is what makes the exemption worth proving', () => {
    /*
     * The premise of every assertion below. If these files ever stopped carrying literals —
     * because somebody "fixed" them into tokens — the scanners would pass for a reason that
     * has nothing to do with the exemption, and this file would be green while proving
     * nothing. So the premise is asserted first, which is the vacuity guard.
     */
    for (const name of readdirSync(DIR)) {
      const hexes = readFileSync(join(DIR, name), 'utf8').match(/#[0-9A-Fa-f]{6}\b/g) ?? []

      expect(
        hexes.length,
        `${name} carries no hex, so it cannot prove an exemption`,
      ).toBeGreaterThan(10)
    }
  })

  it('are not read by the CSS token scanner', () => {
    const out = run('node', ['tools/design-tokens/bin/scan-css.mjs'])

    expect(out).toContain('no hard-coded values')
    expect(out).not.toContain('docs/design')
  })

  it('are not read by the panel token scanner', () => {
    const out = run('node', ['tools/design-tokens/bin/scan-panel.mjs'])

    expect(out).toContain('no hard-coded values')
    expect(out).not.toContain('docs/design')
  })

  it('are not served: the directory is inside no app’s web root', () => {
    /*
     * "Never served" is the half an exemption list cannot express at all. `docs/` is not a
     * public directory of any deployable, and this asserts it by looking rather than by
     * trusting the layout: a copy appearing under any app's served tree fails here.
     */
    for (const root of ['apps/api/public', 'apps/web/public', 'apps/site/public']) {
      const served = join(REPO, root)

      if (!existsSync(served)) continue

      const names = readdirSync(served, { recursive: true }).map(String)

      for (const design of ['rail.html', 'ember.html', 'tile.html']) {
        expect(names, `${design} is inside ${root}, so it is served`).not.toContain(design)
      }
    }
  })
})
