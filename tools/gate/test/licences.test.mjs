import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * EVERY DEPENDENCY'S LICENCE IS DISPOSITIONED — §11.7, AND §10's INHERITED OBLIGATION
 * ================================================================================
 *
 * ⚠ THE TICKET'S §4.3 LISTED 13 PACKAGES AND SAID THEY WERE ALL MIT OR APACHE-2.0.
 * Measured over the installed tree: 229 packages, and four licence families are neither --
 * `@img/sharp-libvips-linux-x64` is LGPL-3.0-or-later through `sharp`, `lightningcss` and
 * `lightningcss-linux-x64-gnu` are MPL-2.0 through Tailwind v4, `caniuse-lite` is CC-BY-4.0
 * through `next`, and `mdn-data` is CC0-1.0. None of them is a problem; all four were
 * UNEXAMINED, which is the finding.
 *
 * `§10` is why it matters beyond this repository: the client receives the repository by
 * GitHub Transfer Ownership, so this inventory becomes an obligation they inherit. A table
 * of 13 hand-typed rows is not an inventory of 229 packages.
 *
 * WHY A LICENCE-FAMILY ALLOW-LIST AND NOT A PACKAGE LIST. A per-package list goes stale at
 * every `pnpm install` and differs by PLATFORM: `lightningcss-linux-x64-gnu` and
 * `@img/sharp-libvips-linux-x64` are optional platform binaries, and a macOS clone resolves
 * `-darwin-arm64` instead. A byte-diffed table of package names would therefore be red on
 * one machine and green on another, which is the "works on the builder's machine" shape the
 * fresh-clone proof exists for. A licence-FAMILY list is platform-robust -- a new platform
 * binary carries its siblings' licence -- and a genuinely new licence family is exactly the
 * event that wants a human.
 *
 * `docs/contracts/dependency-licences.json` holds the allow-list, the four dispositions and
 * a refusal list. This reads the installed tree and holds it to that file.
 */

const inventory = () => JSON.parse(read('docs/contracts/dependency-licences.json'))

/**
 * Every installed package and its declared licence, read from the pnpm store.
 *
 * The store is walked rather than the lockfile parsed, because the lockfile carries no
 * licence at all -- the fact lives in each package's own `package.json`, which is also what
 * a lawyer or a client would read.
 */
function installedLicences() {
  const store = join(REPO, 'node_modules', '.pnpm')
  const out = new Map()
  if (!existsSync(store)) return out

  const record = (dir) => {
    const manifest = join(dir, 'package.json')
    if (!existsSync(manifest)) return
    try {
      const pkg = JSON.parse(readFileSync(manifest, 'utf8'))
      if (typeof pkg.name !== 'string' || typeof pkg.version !== 'string') return
      // `license` is the current field; `licenses` is the deprecated array form and is
      // normalised rather than skipped, because skipping it would hide a package.
      const licence =
        typeof pkg.license === 'string'
          ? pkg.license
          : Array.isArray(pkg.licenses)
            ? pkg.licenses.map((l) => l.type ?? String(l)).join(' OR ')
            : '(none declared)'
      out.set(`${pkg.name}@${pkg.version}`, licence)
    } catch {
      // A package.json that does not parse is not a licence finding; `pnpm install` would
      // have failed on it first.
    }
  }

  for (const entry of readdirSync(store, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name === 'node_modules') continue
    const inner = join(store, entry.name, 'node_modules')
    if (!existsSync(inner)) continue
    for (const scope of readdirSync(inner, { withFileTypes: true })) {
      if (!scope.isDirectory() && !scope.isSymbolicLink()) continue
      if (scope.name.startsWith('@')) {
        for (const pkg of readdirSync(join(inner, scope.name), { withFileTypes: true })) {
          if (pkg.isDirectory() || pkg.isSymbolicLink()) record(join(inner, scope.name, pkg.name))
        }
        continue
      }
      record(join(inner, scope.name))
    }
  }
  return out
}

describe('the dependency licence inventory', () => {
  const licences = installedLicences()

  it('reads a real tree, so nothing below can pass over nothing', () => {
    // The ticket's old table had 13 rows; the tree has over two hundred packages, and that
    // gap IS the finding. A bound, not an exact count: the total varies with the platform's
    // optional dependencies.
    expect(
      licences.size,
      'the pnpm store walk found nothing -- run `pnpm install`',
    ).toBeGreaterThan(200)
    // And the four packages the review named are present, by name, so a walk that silently
    // missed the interesting ones is visible here.
    const names = [...licences.keys()].map((k) => k.slice(0, k.lastIndexOf('@')))
    for (const named of ['lightningcss', 'caniuse-lite', 'mdn-data']) {
      expect(names, `${named} is not in the walk`).toContain(named)
    }
  })

  it('carries no licence family that is in neither the allow-list nor a disposition', () => {
    const { permissive, dispositioned } = inventory()
    const known = new Set([...Object.keys(permissive), ...Object.keys(dispositioned)])

    const unknown = new Map()
    for (const [pkg, licence] of licences) {
      if (known.has(licence)) continue
      unknown.set(licence, [...(unknown.get(licence) ?? []), pkg])
    }

    expect(
      [...unknown].map(([licence, pkgs]) => `${licence}: ${pkgs.slice(0, 4).join(', ')}`),
      'these licence families are in the installed tree and in no map of ' +
        'docs/contracts/dependency-licences.json. Add each to `permissive` with a note, or ' +
        'to `dispositioned` with its reach, its obligation and a decision -- §11.7, and §10 ' +
        'makes this inventory the client’s inherited obligation.',
    ).toEqual([])
  })

  it('carries no REFUSED licence, which is the decision taken in advance', () => {
    const { refused } = inventory()
    const banned = Object.keys(refused).filter((k) => !k.startsWith('$'))
    expect(banned.length).toBeGreaterThanOrEqual(5)

    const found = []
    for (const [pkg, licence] of licences) {
      /**
       * Not equality -- `GPL-3.0-only`, `GPL-3.0-or-later` and `(MIT OR GPL-3.0)` all carry
       * the family name, and a dual licence offering GPL is still a question somebody has
       * to answer.
       *
       * ⚠ AND NOT A BARE SUBSTRING EITHER, WHICH IS WHAT THIS FIRST DID. `LGPL-3.0-or-later`
       * CONTAINS `GPL-3.0`, so `@img/sharp-libvips-linux-x64` -- a licence family this file
       * disposes of three entries above -- came back as REFUSED. LGPL and AGPL are distinct
       * families with their own meanings and their own entries; a check that cannot tell
       * them apart is a check that would be disabled the first time it fired.
       *
       * So the family name must start at a non-letter boundary.
       */
      for (const family of banned) {
        const boundary = new RegExp(`(?:^|[^A-Za-z])${family.replace(/[.]/g, '\\.')}`)
        if (boundary.test(licence)) found.push(`${pkg} -> ${licence}`)
      }
    }
    expect(found, 'these packages carry a licence family this project refuses').toEqual([])
  })

  it('tells LGPL and AGPL apart from GPL, driven on the strings themselves', () => {
    // The discriminator above, exercised. The real tree carries one LGPL package, so a
    // regression here reads as "a refused licence arrived" and would be acted on as one.
    const matches = (licence, family) =>
      new RegExp(`(?:^|[^A-Za-z])${family.replace(/[.]/g, '\\.')}`).test(licence)

    expect(matches('GPL-3.0-only', 'GPL-3.0')).toBe(true)
    expect(matches('(MIT OR GPL-3.0)', 'GPL-3.0')).toBe(true)
    expect(matches('GPL-3.0', 'GPL-3.0')).toBe(true)
    // The three that must NOT match, and the first is the live one.
    expect(matches('LGPL-3.0-or-later', 'GPL-3.0')).toBe(false)
    expect(matches('AGPL-3.0-only', 'GPL-3.0')).toBe(false)
    expect(matches('LGPL-2.1-or-later', 'GPL-2.0')).toBe(false)
    // AGPL has its own entry and must match it.
    expect(matches('AGPL-3.0-only', 'AGPL-3.0')).toBe(true)
  })

  it('disposes of each non-permissive family with a reach, an obligation and a decision', () => {
    // A disposition that says only "accepted" is the shape this whole file exists to
    // replace. Each one has to say what it reaches, what it obliges, and what was decided.
    const { dispositioned } = inventory()
    expect(Object.keys(dispositioned).length).toBeGreaterThanOrEqual(4)
    for (const [family, row] of Object.entries(dispositioned)) {
      for (const key of ['packages', 'reach', 'obligation', 'disposition']) {
        expect(row[key], `${family} has no ${key}`).toBeTruthy()
      }
      expect(Array.isArray(row.packages), `${family}.packages is not a list`).toBe(true)
      expect(row.reach.length, `${family}.reach is too short to be a reach`).toBeGreaterThan(40)
      expect(row.obligation.length).toBeGreaterThan(40)
      expect(row.disposition.length).toBeGreaterThan(40)
    }
  })

  it('names, for each disposition, a package actually in the tree', () => {
    // Both directions: a disposition for a package that is gone is stale, and a reader
    // cannot tell a stale row from a live one.
    const names = new Set([...licences.keys()].map((k) => k.slice(0, k.lastIndexOf('@'))))
    const { dispositioned } = inventory()
    const missing = []
    for (const [family, row] of Object.entries(dispositioned)) {
      for (const pkg of row.packages) {
        if (!names.has(pkg)) missing.push(`${family}: ${pkg}`)
      }
    }
    expect(
      missing,
      'these dispositioned packages are not installed, so the row describes a dependency ' +
        'the tree no longer has',
    ).toEqual([])
  })

  it('agrees with the licence each dispositioned package actually declares', () => {
    // The row says MPL-2.0; the package has to say MPL-2.0. A relicense upstream is exactly
    // the event that should reopen a disposition, and it arrives silently.
    const { dispositioned } = inventory()
    const wrong = []
    for (const [family, row] of Object.entries(dispositioned)) {
      for (const pkg of row.packages) {
        for (const [key, licence] of licences) {
          if (key.slice(0, key.lastIndexOf('@')) !== pkg) continue
          if (licence !== family)
            wrong.push(`${key} declares ${licence}, dispositioned as ${family}`)
        }
      }
    }
    expect(wrong, 'a dispositioned package has been relicensed upstream').toEqual([])
  })
})
