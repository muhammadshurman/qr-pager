import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

const TICKET = 'docs/tickets/QRPAGER-15.md'

/**
 * ================================================================================
 * THE TICKET'S TABLES ARE CHECKED AGAINST THEIR SOURCES
 * ================================================================================
 *
 * ⚠ FIVE RECORD FINDINGS WERE ONE DEFECT: the ticket's tables are hand-typed and had
 * drifted from the code. The security and QA reviews found four of them independently:
 *
 *   · the contrast table (QA #5) -- a planted 19.99 AAA figure passed everything, because
 *     `docs/tickets/QRPAGER-15-contrast-table.md` carried the "GENERATED -- DO NOT EDIT"
 *     banner and was in no array any checker walked. FIXED BY GENERATION: it is an entry of
 *     `ARTEFACTS` now, one `build` emitting two paths, so `pnpm tokens:check` byte-diffs it.
 *   · the §6.1 disjointness table (Security L-4) -- it named
 *     `--color-ready-field / --color-paper / --color-signal` while the measured protected
 *     set is `--ready-field / --ready-ink / --ready-signal`. It described the PRE-REDESIGN
 *     namespace that this round's own defect 1 removed, and two of its three names are now
 *     in neither set.
 *   · the dependency licence table (Security L-6) -- `tools/gate/test/licences.test.mjs`.
 *   · the version table (Security L-7) -- the eslint row said 10.14.0 and `package.json`
 *     pins 10.13.0. The downgrade was deliberate and is reasoned in `pnpm-workspace.yaml`;
 *     the table was simply not updated.
 *
 * `§8`'s instruction was *"generate each from its source, or check it in the gate"*. The
 * contrast table is GENERATED. These two are CHECKED, and the choice is not arbitrary: both
 * sit inside a hand-written prose document that answers the `§6` pre-coding questions, and
 * rewriting regions of that by machine would make the document's authorship ambiguous for
 * the sake of two tables. Checking holds the same property -- a row that disagrees with the
 * code reddens the gate -- without a generator owning a file a human writes.
 */

describe("§6.1's disjointness table", () => {
  const ticket = read(TICKET)
  const section = ticket.slice(ticket.indexOf('### 6.1'), ticket.indexOf('### 6.2'))

  /** The themeable names, parsed out of Task 7's PHP -- never copied (guard rule 11c). */
  function themeableNames() {
    const php = read('apps/api/app/Domain/Landing/ThemeTokens.php')
    const block = /public const NAMES = \[([\s\S]*?)\];/.exec(php)
    expect(block, 'ThemeTokens::NAMES could not be parsed').not.toBeNull()
    return [...block[1].matchAll(/'([^']+)'\s*=>/g)].map((m) => m[1])
  }

  /** The protected properties, parsed out of the token source -- never copied. */
  function protectedProperties() {
    const source = read('packages/tokens/src/source.ts')
    const block = /export const ORDER_READY = \{([^}]*)\}/.exec(source)
    expect(block, 'ORDER_READY could not be parsed').not.toBeNull()
    return [...block[1].matchAll(/'([a-z0-9-]+)'\s*:/g)].map((m) => `--${m[1]}`)
  }

  it('reads a §6.1 section with a table in it, so nothing below is vacuous', () => {
    expect(section.length).toBeGreaterThan(500)
    expect(section).toContain('intersection')
  })

  it('names every themeable token the PHP allow-list holds, and no other', () => {
    const names = themeableNames()
    expect(names).toHaveLength(12)

    const row = /themeable names \(all 12\) \|([^|]*)\|/.exec(section)
    expect(row, "the ticket has no 'themeable names (all 12)' row").not.toBeNull()
    const listed = [...row[1].matchAll(/`([^`]+)`/g)].map((m) => m[1]).sort()
    expect(listed, "the ticket's themeable-name list disagrees with ThemeTokens::NAMES").toEqual(
      [...names].sort(),
    )
  })

  it('names every property those twelve can produce, through the real transform', () => {
    // `ThemeTokens::property()`: `return '--'.str_replace(['.', '_'], '-', $name);`
    const produced = themeableNames()
      .map((n) => `--${n.replace(/[._]/g, '-')}`)
      .sort()

    const row = /the properties they can produce \|([^|]*)\|/.exec(section)
    expect(row, 'the ticket has no produced-property row').not.toBeNull()
    const listed = [...row[1].matchAll(/`([^`]+)`/g)].map((m) => m[1]).sort()
    expect(listed, "the ticket's produced-property list disagrees with the transform").toEqual(
      produced,
    )
  })

  it('names the THREE properties the order-ready screen actually reads', () => {
    // The drifted row, and the one the security review named: it said
    // `--color-ready-field / --color-paper / --color-signal`, which is the namespace the
    // redesign removed. Two of those three are in neither set now.
    const prot = protectedProperties()
    expect(prot).toEqual(['--ready-field', '--ready-ink', '--ready-signal'])

    const row = /the properties the order-ready screen reads \|([^|]*)\|/.exec(section)
    expect(row, 'the ticket has no protected-property row').not.toBeNull()
    const listed = [...row[1].matchAll(/`([^`]+)`/g)].map((m) => m[1]).sort()
    expect(
      listed,
      "the ticket's protected-property list disagrees with the token source -- this is the " +
        'row that described the pre-redesign namespace',
    ).toEqual([...prot].sort())
  })

  it('has an EMPTY intersection, computed here rather than quoted from the ticket', () => {
    // The table's own claim, re-derived. A table can say "empty" and be wrong.
    const themeable = themeableNames().map((n) => `--${n.replace(/[._]/g, '-')}`)
    const prot = protectedProperties()
    expect(themeable.filter((p) => prot.includes(p))).toEqual([])
    // And no themeable name can even produce the `--ready-` prefix, which is the structural
    // reason rather than a coincidence of these twelve.
    for (const property of themeable) expect(property.startsWith('--ready-')).toBe(false)
  })
})

describe("§4.3's version table", () => {
  const ticket = read(TICKET)
  const section = ticket.slice(ticket.indexOf('### 4.3'), ticket.indexOf('### 4.4'))

  /** Every version this workspace pins, from every package.json that pins one. */
  function pinned() {
    const out = new Map()
    for (const manifest of [
      'package.json',
      'apps/web/package.json',
      'apps/site/package.json',
      'packages/ui/package.json',
      'packages/tokens/package.json',
    ]) {
      const pkg = JSON.parse(read(manifest))
      for (const field of ['dependencies', 'devDependencies']) {
        for (const [name, range] of Object.entries(pkg[field] ?? {})) {
          if (range.startsWith('workspace:')) continue
          out.set(name, range)
        }
      }
    }
    return out
  }

  it('reads a §4.3 section with a table in it', () => {
    expect(section).toContain('| Package | Version |')
    expect([...section.matchAll(/^\| `/gm)].length).toBeGreaterThanOrEqual(10)
  })

  it('states the version each package is ACTUALLY pinned to', () => {
    /**
     * The Security L-7 row, as a check. Every `| \`name\` | version |` row in the ticket is
     * held to the pin. A row naming several packages (`react`, `react-dom`) is split on the
     * comma, because that is how the ticket writes a pair that moves together.
     */
    const versions = pinned()
    const wrong = []
    let checked = 0

    for (const m of section.matchAll(/^\|\s*(`[^|]+`)\s*\|\s*\*{0,2}([^|*]+?)\*{0,2}\s*\|/gm)) {
      const names = [...m[1].matchAll(/`([^`]+)`/g)].map((p) => p[1])
      const stated = m[2].trim().replace(/,.*$/, '')
      // A row whose "version" column is prose rather than a version is not a pin claim.
      if (!/^\d+\.\d+\.\d+$/.test(stated)) continue
      for (const name of names) {
        const actual = versions.get(name)
        if (actual === undefined) {
          wrong.push(`${name}: the ticket names it and no package.json pins it`)
          continue
        }
        checked += 1
        if (actual !== stated)
          wrong.push(`${name}: ticket says ${stated}, package.json pins ${actual}`)
      }
    }

    // A bound, so a regex change that stops matching rows cannot report nothing wrong.
    expect(checked, 'no version row was checked -- the table shape changed').toBeGreaterThanOrEqual(
      10,
    )
    expect(
      wrong,
      "these rows of the ticket's version table disagree with what the workspace pins",
    ).toEqual([])
  })

  it('states the ESLint pin that was wrong, by exact value', () => {
    // §8: assert exact values. The instance as well as the class, so a reader of a failure
    // knows which finding it is.
    expect(pinned().get('eslint')).toBe('10.13.0')
    expect(section).toContain('10.13.0')
    expect(section, 'the superseded 10.14.0 is still presented as the pin').not.toMatch(
      /\|\s*\*{0,2}10\.14\.0\*{0,2}\s*\|/,
    )
  })
})

describe("§2.3's final-values table", () => {
  const ticket = read(TICKET)
  const section = ticket.slice(ticket.indexOf('### 2.3'), ticket.indexOf('### 2.4'))

  /** Every role, resolved in both themes, from the token source itself. */
  async function resolved() {
    const source = await import('../../../packages/tokens/src/source.ts')
    const out = new Map()
    for (const role of Object.keys(source.COLOUR_ROLES.light)) {
      out.set(role, {
        light: source.resolveColour('light', role),
        dark: source.resolveColour('dark', role),
        lightRule: source.describeRule('light', role),
        darkRule: source.describeRule('dark', role),
      })
    }
    return out
  }

  it('states the hex each role ACTUALLY resolves to, in both themes', async () => {
    /**
     * ⚠ QA #6. This table carried `border-strong`'s FIRST solution -- light `#7D8981` at
     * 44% and dark `#798580` at 48%, the pair that cleared 3:1 by 0.02 and 0.01 -- and dark
     * `alert-ink` as `= alert-fill` `#E4572E`, which is the shipped AA breach the generated
     * contrast table then found at 3.78 and 3.13. All three were replaced in the code the
     * same day; the table was not, so it contradicted its own ratios table twenty lines
     * below.
     *
     * Checked rather than generated, for the reason at the head of this file: the ticket is
     * a prose document a human writes.
     */
    const truth = await resolved()
    const wrong = []
    let checked = 0

    for (const m of section.matchAll(
      /^\|\s*`([a-z0-9-]+)`\s*\|\s*`(#[0-9A-Fa-f]{6})`\s*\|\s*`(#[0-9A-Fa-f]{6})`\s*\|/gm,
    )) {
      const [, role, light, dark] = m
      const actual = truth.get(role)
      if (actual === undefined) {
        wrong.push(`${role}: the table names a role the token source does not have`)
        continue
      }
      checked += 1
      if (actual.light !== light)
        wrong.push(`${role} light: table ${light}, source ${actual.light}`)
      if (actual.dark !== dark) wrong.push(`${role} dark: table ${dark}, source ${actual.dark}`)
    }

    // Every role the source has must appear, so a row cannot be dropped to pass.
    expect(checked, 'too few rows matched -- the table shape changed').toBe(truth.size)
    expect(
      wrong,
      "these cells of the ticket's final-values table disagree with the source",
    ).toEqual([])
  })

  it('states the DERIVATION each role actually has, mix percentage included', async () => {
    // The percentage is the part that moves: both `border-strong` solutions and the
    // `alert-ink` fix were percentage changes, and a stale percentage in the record is how
    // somebody re-derives the superseded hex and believes it.
    const truth = await resolved()
    const wrong = []
    for (const [role, actual] of truth) {
      // The row, found by SPLITTING rather than by a regex. ⚠ The first version built the
      // pattern with `\\\\|`, which is an escaped backslash followed by an ALTERNATION in
      // the compiled regex -- so it matched the section's first line and every derivation
      // was then checked against prose. Found by the check failing on two roles whose
      // rows were correct.
      const row = section.split('\n').find((line) => line.startsWith(`| \`${role}\` | \``))
      expect(row, `no final-values row for ${role}`).toBeDefined()
      for (const [label, rule] of [
        ['light', actual.lightRule],
        ['dark', actual.darkRule],
      ]) {
        // `describeRule` returns `mix(a, b, 47%) in <space>` or `= approved 'x'`; the table
        // writes the mix without the space and the approval without the quotes.
        const expected = rule.startsWith('mix(')
          ? rule.slice(0, rule.indexOf(') in') + 1)
          : `= ${/'([^']+)'/.exec(rule)[1]}`
        if (!row.includes(expected)) {
          wrong.push(`${role} ${label}: the table does not state "${expected}"`)
        }
      }
    }
    expect(wrong, 'these derivations in the ticket disagree with the source').toEqual([])
  })

  it('no longer presents the superseded values as current', () => {
    /*
     * The exact cells, by value. §8: assert exact values.
     *
     * ⚠ UPDATED 2026-11-08 BY RAIL, AND THE CASE'S PURPOSE IS UNCHANGED: a superseded
     * value must never sit in this table as if it were current. The first two are
     * Direction B's own superseded border-strong values from the 2026-10-31 correction.
     * `#E99176` now JOINS them rather than being required: it was dark alert-ink under
     * Direction B (mix at 37%), and Rail's is `#CCA49F` (mix at 57%), so the very value
     * this case used to demand is now the kind of value it exists to exclude.
     */
    for (const superseded of ['#7D8981', '#798580', '#E99176']) {
      expect(section, `${superseded} is a superseded value`).not.toContain(`| \`${superseded}\` |`)
    }
    expect(section).toContain('`#CCA49F`')
  })
})
