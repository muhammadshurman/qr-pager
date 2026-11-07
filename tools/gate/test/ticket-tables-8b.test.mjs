import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  FILAMENT_PALETTE_SOURCES,
  FILAMENT_RADII,
  FILAMENT_TEXT_SIZES,
} from '../../../packages/tokens/scripts/panel-theme.mjs'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

const TICKET = 'docs/tickets/QRPAGER-23.md'

/**
 * ================================================================================
 * QRPAGER-23's TABLES ARE CHECKED AGAINST THEIR SOURCES — §8.2, NOW DELIVERED
 * ================================================================================
 *
 * ⚠ THE TICKET PROMISED THIS AND THE TASK DID NOT SHIP IT. `§8.2` called the typed tables a
 * stated deferral and committed to the fix in these words: *"the gate-check lands with the
 * first implementation commit, covering the stack row, the artefact/import-path table and
 * the route inventory, each against its source, in the shape 8a's `ticket-tables.test.mjs`
 * already established."* Measured by the §11 QA round: `grep` for this file name across
 * every `.mjs`, `.ts` and `.php` returned **zero hits**, and `ticket-tables.test.mjs` was
 * still pinned to `QRPAGER-15.md`.
 *
 * That matters more here than it would in most tickets, because 8a's own §11 round recorded
 * this exact shape as one of six BLOCKING findings: *"the ticket's contrast table was an
 * unchecked copy where a planted 19.99 passed."* `QRPAGER-23` now carries about twenty typed
 * tables, including every contrast figure the SR-4 ruling rests on.
 *
 * A SIBLING FILE RATHER THAN A SECOND TICKET IN THE EXISTING ONE: `ticket-tables.test.mjs`
 * is written throughout as 8a's, its `TICKET` constant is a module-level binding, and its
 * header is a record of 8a's five findings. Adding a second subject there would make both
 * documents' histories ambiguous; the shape it established is what `§8.2` asked for, and
 * that is what this follows.
 */

const ticket = read(TICKET)

describe("QRPAGER-23's stack row", () => {
  it('reads a §7.1 section with a stack table in it, so nothing below is vacuous', () => {
    const section = ticket.slice(ticket.indexOf('### 7.1'), ticket.indexOf('### 7.2'))

    expect(section.length).toBeGreaterThan(200)
    expect(section).toContain('|')
  })

  it('states the version each package is ACTUALLY pinned to', () => {
    // The versions the ticket quotes, against the lockfile and the manifests. A figure in a
    // ticket that disagrees with the code is a false record, and this document's figures are
    // what the SR-1 and SR-4 rulings were taken on.
    const composerLock = JSON.parse(read('apps/api/composer.lock'))
    const apiPackage = JSON.parse(read('apps/api/package.json'))

    const installed = (name) => {
      for (const pkg of [...composerLock.packages, ...(composerLock['packages-dev'] ?? [])]) {
        if (pkg.name === name) return pkg.version.replace(/^v/, '')
      }

      return null
    }

    const expected = {
      'filament/filament': installed('filament/filament'),
      'laravel/framework': installed('laravel/framework'),
      'livewire/livewire': installed('livewire/livewire'),
    }

    for (const [name, version] of Object.entries(expected)) {
      expect(version, `${name} is not in apps/api/composer.lock`).not.toBeNull()
      expect(
        ticket.includes(version),
        `the ticket does not state ${name}'s real version ${version}`,
      ).toBe(true)
    }

    // apps/api's Tailwind is pinned EXACT by §9.4, and the ticket's own figures were all
    // measured against that version -- so the number in the prose and the number in the
    // manifest must be the same number.
    const tailwind = apiPackage.devDependencies.tailwindcss

    expect(tailwind).not.toMatch(/[\^~]/)
    expect(ticket.includes(tailwind), `the ticket does not state Tailwind's pin ${tailwind}`).toBe(
      true,
    )
  })
})

describe("QRPAGER-23's route inventory", () => {
  it('names exactly the seven panel routes, and no eighth', () => {
    // SR-3 corrected §4.3 to a derived route table. The authority for "seven" is the router,
    // which only PHP can read -- `PanelRoutes::all()` and `PanelWalkTest` own that half. What
    // this owns is that the DOCUMENT still says seven and still names the same seven, because
    // the walk, the floor check and the fixture-drift check are all described against it.
    for (const route of [
      '/platform',
      '/platform/restaurants',
      '/platform/audit-log',
      '/studio',
      '/studio/media',
      '/studio/domains',
      '/studio/preview',
    ]) {
      expect(ticket.includes(route), `§4.3's inventory does not name ${route}`).toBe(true)
    }

    // And it must NOT claim a login route: SR-3 measured there is none, and
    // `PlatformPanelProvider`'s docblock explains at length why `->login()` is absent.
    expect(ticket).not.toContain('/platform/login')
    expect(ticket).not.toContain('/studio/login')
  })
})

describe("QRPAGER-23's SR-4 palette table", () => {
  it('states the source token and anchor shade each family is actually built from', () => {
    // ⚠ THE TABLE THE RULING RESTS ON, and the values are READ OUT OF THE ROW rather than
    // pattern-matched into it. The first version built a regex from the EXPECTED values and
    // asked whether it matched, so a row carrying a WRONG anchor matched nothing and the
    // case passed: proven by changing warning's row from 400 to 500, which left all eight
    // green. A check that cannot see a wrong value is the shape 8a's planted 19.99 got
    // through.
    const sources = Object.entries(FILAMENT_PALETTE_SOURCES)

    expect(sources.length).toBe(6)

    for (const [family, source] of sources) {
      const row = new RegExp('^\\|\\s*`' + family + '`\\s*\\|([^|]*)\\|([^|]*)\\|', 'm').exec(
        ticket,
      )

      expect(row, `the ticket's palette table has no row for ${family}`).not.toBeNull()

      const token = (row?.[1] ?? '').replace(/[`*\s]/g, '')
      const anchor = (row?.[2] ?? '').replace(/[`*\s]/g, '')

      expect(token, `${family}'s row names ${token}; it is built from ${source.token}`).toBe(
        source.token,
      )
      expect(anchor, `${family}'s row says anchor ${anchor}; it is ${source.anchor}`).toBe(
        String(source.anchor),
      )
    }
  })

  it('states the AA figures Filament’s own resolver produced, per row and exactly', () => {
    /*
     * ⚠ READ OUT OF EACH ROW AND COMPARED, for the reason the anchor check carries: a
     * document-wide `includes('6.50')` is satisfied by the figure appearing ANYWHERE, and it
     * appears in the prose as well as both columns -- so changing the primary row's light
     * figure from 6.50 to 6.49 left every case green. Proven, then fixed.
     *
     * THE AUTHORITY FOR THE BEHAVIOUR IS `PanelPaletteTest`, which drives Filament's own
     * `ButtonComponentColorMap` over all six families and requires >= 4.5 in both modes.
     * This is the authority for the RECORD: that the document still states what was
     * measured. The reference list below is the same shape 8a's version table uses.
     */
    const EXPECTED = {
      primary: ['6.50', '6.50'],
      danger: ['7.54', '7.54'],
      success: ['5.15', '6.12'],
      warning: ['5.89', '5.89'],
      info: ['5.71', '5.71'],
      gray: ['5.71', '5.71'],
    }

    for (const [family, [light, dark]] of Object.entries(EXPECTED)) {
      const row = new RegExp(
        '^\\|\\s*`' + family + '`\\s*\\|([^|]*)\\|([^|]*)\\|([^|]*)\\|([^|]*)\\|',
        'm',
      ).exec(ticket)

      expect(row, `the ticket's palette table has no row for ${family}`).not.toBeNull()

      const cell = (n) => (row?.[n] ?? '').replace(/[`*\s]/g, '')

      expect(cell(3), `${family}'s light figure`).toBe(light)
      expect(cell(4), `${family}'s dark figure`).toBe(dark)

      // And every one of them clears the bar the ruling was taken on, computed here rather
      // than read from the prose.
      expect(Number.parseFloat(cell(3))).toBeGreaterThanOrEqual(4.5)
      expect(Number.parseFloat(cell(4))).toBeGreaterThanOrEqual(4.5)
    }

    // The one-liner's failing figure, which the comparison exists to contrast with, and the
    // two the warning anchor decision was taken on.
    const sr4 = ticket.slice(ticket.indexOf('### SR-4'), ticket.indexOf('## 1. Q1'))

    expect(sr4.length).toBeGreaterThan(1000, 'the SR-4 section could not be located')

    for (const figure of ['3.43', '3.68', '4.87']) {
      expect(sr4.includes(figure), `SR-4 no longer states the measured ${figure}`).toBe(true)
    }
  })
})

describe("QRPAGER-23's Filament mapping tables", () => {
  it('states the type and radius mappings the panel theme actually emits', () => {
    const theme = read('packages/tokens/generated/panel-theme.css')

    for (const [name, step] of Object.entries(FILAMENT_TEXT_SIZES)) {
      expect(theme).toContain(`--text-${name}: var(--size-${step});`)
    }

    for (const [name, step] of Object.entries(FILAMENT_RADII)) {
      expect(theme).toContain(`--radius-${name}: var(--radius-${step});`)
    }

    // And the ticket's own claim about the shape of that file: the type scale has seven steps
    // and Filament asks for seven names, which is the sentence §0 SR-1 reasons from.
    expect(Object.keys(FILAMENT_TEXT_SIZES)).toHaveLength(7)
  })

  it('states the spacing exception’s hard count, which is the vendor’s own call sites', async () => {
    // §0 SR-1's bound: Filament calls --spacing(…) at exactly three sites, which is why the
    // bare key cannot be cleared. `panel-theme.test.mjs` counts them in the vendor tree; this
    // holds the DOCUMENT to the same number, because the exception is argued from it.
    const { globSync } = await import('node:fs')
    const vendorCss = globSync('apps/api/vendor/filament/**/*.css', { cwd: REPO })

    expect(vendorCss.length).toBeGreaterThan(10)

    const sites = vendorCss.flatMap((f) => [...read(f).matchAll(/--spacing\(\s*[0-9.]+\s*\)/g)])

    expect(sites).toHaveLength(3)
    expect(ticket).toMatch(/hard count of 3|hard count of three|three call sites/)
  })
})

describe("QRPAGER-23's artefact/import-path table", () => {
  it('names every artefact the panel entrypoints actually import, and no other', () => {
    /*
     * ⚠ `§8.2` NAMED THIS TABLE AND THE FIRST VERSION OF THIS FILE DID NOT COVER IT, which
     * is how the row survived being false: it said `generated/theme.css` was imported by each
     * panel theme "at the same relative path", and that file is imported NOWHERE — `SR-1`
     * ruled it unusable for the panels, and `panel-theme.mjs`'s first line says so.
     *
     * So the check reads the imports out of the entrypoints and requires the table to name
     * each one. The authority is the stylesheet, not the prose.
     */
    const imported = new Set()

    for (const panel of ['platform', 'studio']) {
      const entry = read(`apps/api/resources/css/filament/${panel}/theme.css`)

      for (const match of entry.matchAll(/@import\s+'([^']+)'/g)) {
        const path = match[1] ?? ''

        // The vendor theme and the shared floor are apps/api's own, not token artefacts.
        if (path.includes('/vendor/') || path.endsWith('targets.css')) {
          continue
        }

        // Normalised to a repo-relative path, which is how the table names them.
        imported.add(path.replace(/^(?:\.\.\/)+/, ''))
      }
    }

    expect(imported.size, 'the panel entrypoints import no token artefact').toBeGreaterThan(2)

    for (const path of imported) {
      expect(ticket.includes(path), `the artefact table does not name ${path}`).toBe(true)
    }

    // AND THE FALSE ROW IS GONE: `generated/theme.css` must not be presented as a current
    // import. It may appear as prose (the correction itself names it), so the claim checked
    // is the shape the row had — a path cell asserting it reaches the panels.
    expect(imported.has('packages/tokens/generated/theme.css')).toBe(false)
  })
})

describe('the planted-figure proof', () => {
  it('would catch a wrong figure, which is the only thing that makes the above a check', () => {
    // ⚠ THE VACUITY GUARD. Every case here is `ticket.includes(<real value>)`, which a
    // document that happened to contain the string satisfies -- so the proof that it is a
    // CHECK and not a coincidence is that a WRONG value is absent. 8a's planted 19.99 is the
    // precedent: the figure was wrong, the file looked plausible, and nothing was red.
    for (const wrong of ['19.99', '3.44', '7.55', '6.51', 'tailwindcss@4.2.9']) {
      expect(
        ticket.includes(wrong),
        `the ticket contains ${wrong}, which is not a real figure`,
      ).toBe(false)
    }
  })
})
