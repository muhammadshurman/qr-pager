#!/usr/bin/env node
/*
 * ================================================================================
 * THE PARTITION — QRPAGER-49A. Sentinel, declared non-themed, or a finding.
 * ================================================================================
 *
 * `browser/theme-paint.spec.ts` collects; this judges. The split exists so that the ruling —
 * which values are allowed not to be the tenant's — is a list in one readable place rather
 * than a condition inside an `expect` that prints one line when it fails.
 *
 * THE STATED INVARIANT, in the ticket's own words:
 *
 *   Rendered under a theme whose six colour roles hold six distinct sentinel values, one
 *   unchanged tenant document produces a page on which every colour-bearing COMPUTED style
 *   resolves to a sentinel, or to a member of the declared non-themed set. At 390 and at 1280,
 *   for every template in the LandingTemplate enum.
 *
 * ⚠ **THE NON-THEMED SET IS DECLARED BEFORE THE MEASUREMENT AND DOES NOT GROW TO FIT IT.**
 * The ticket: *"A set that grows to fit the findings is accommodating a symptom instead of
 * bounding its cause. Adding a member is a ruling I make, not an exemption you write."* So the
 * list below carries exactly what can be justified without looking at a single result, each
 * with the reason it is not a colour a tenant could have chosen. Everything else is a finding,
 * however ordinary it looks.
 *
 * USAGE:  node tools/landing/paint-report.mjs <dir> --templates rail,ember,tile
 * EXITS:  0 when category (c) is empty, 1 otherwise — and 1 when a measurement is missing,
 *         because an absent file is not a clean result.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * THE DECLARED NON-THEMED SET. Two members, each one a reason rather than an exception.
 *
 * ⚠ Neither is a COLOUR. That is the test a member has to pass to be here at all: a value that
 * paints no pixel cannot be the wrong colour, and a value that is an alias for one already
 * being measured is not a second value. Anything that IS a colour — a grey, a black, an AAA
 * floor value, a piece of product chrome — belongs in category (c) where somebody has to look
 * at it and rule, which is the whole point of this file.
 */
const NON_THEMED = {
  transparent: {
    reason:
      'the absence of paint rather than a colour. A computed `rgba(0, 0, 0, 0)` draws nothing, ' +
      'so there is no pixel for a theme to reach — and the channels in front of the zero alpha ' +
      'are not a colour anyone can see.',
  },
  currentcolor: {
    reason:
      "an alias for the element's own `color`, which this same report measures on this same " +
      'element. It carries no value of its own, so counting it would be counting one value twice.',
  },
}

/** The six roles, in the order the studio's theme tab shows them. */
const ROLES = [
  'color.background',
  'color.surface',
  'color.text',
  'color.muted',
  'color.accent',
  'color.accent_text',
]

const WIDTHS = ['390', '1280']

const [dir, ...rest] = process.argv.slice(2)
const templatesFlag = rest.indexOf('--templates')

if (dir === undefined || templatesFlag === -1 || rest[templatesFlag + 1] === undefined) {
  console.error('usage: node tools/landing/paint-report.mjs <dir> --templates rail,ember,tile')
  process.exit(64)
}

/*
 * ⚠ THE TEMPLATES ARE AN ARGUMENT AND NEVER A LIST IN THIS FILE. The orchestrator reads them
 * from `LandingTemplate::values()` and passes them through, so a fourth arrangement is covered
 * the day it exists and there is no way to add one outside this report.
 */
const templates = rest[templatesFlag + 1].split(',').filter((name) => name !== '')

const read = (path) => {
  if (!existsSync(path)) {
    console.error(
      `theme-paint: ${path} is missing. A measurement that did not happen is not a clean ` +
        'result, so this is a failure rather than a smaller report.',
    )
    process.exit(1)
  }

  return JSON.parse(readFileSync(path, 'utf8'))
}

const manifests = Object.fromEntries(
  templates.map((template) => [template, read(join(dir, `manifest-${template}.json`))]),
)

/*
 * One sentinel map for the whole run, asserted rather than assumed: three publishes with three
 * different themes would make every comparison below mean something different per template.
 */
const theme = manifests[templates[0]].theme
const sentinels = new Map(Object.entries(theme).map(([role, value]) => [value.toLowerCase(), role]))

for (const [template, manifest] of Object.entries(manifests)) {
  if (JSON.stringify(manifest.theme) !== JSON.stringify(theme)) {
    console.error(`theme-paint: ${template} was published under a different theme`)
    process.exit(1)
  }
}

if (sentinels.size !== ROLES.length) {
  console.error(
    `theme-paint: the published theme holds ${String(sentinels.size)} distinct values for ` +
      `${String(ROLES.length)} roles. Two roles sharing a value would make every attribution ` +
      'below ambiguous.',
  )
  process.exit(1)
}

const findings = []
const painted = {}
const emitted = {}
const inline = new Map()
const totals = { samples: 0, sentinel: 0, nonThemed: 0, other: 0 }

for (const template of templates) {
  painted[template] = {}
  emitted[template] = {}

  for (const role of ROLES) painted[template][role] = []

  for (const width of WIDTHS) {
    const page = read(join(dir, `paint-${template}-${width}.json`))

    // The first link in the chain: what the root resolved, before any rule referenced it.
    for (const [role, value] of Object.entries(theme)) {
      const property = '--' + role.replace(/[._]/g, '-')
      const resolved = (page.customProperties[property] ?? '').trim().toLowerCase()

      emitted[template][role] = resolved
      if (resolved !== value.toLowerCase()) {
        findings.push({
          kind: 'root',
          template,
          width,
          property,
          value: resolved === '' ? '(absent)' : resolved,
          expected: value,
          shape: 'html',
          path: 'html',
          paints: false,
          reason: 'the root custom property does not hold the published value',
          count: 1,
        })
      }
    }

    /*
     * ⚠ THE `style` ATTRIBUTES, RECORDED SEPARATELY BECAUSE THE TICKET NAMES THEM — *"and
     * anything set via a style attribute"*. A colour written inline necessarily appears in that
     * element's COMPUTED value and is therefore already partitioned above; this section exists
     * so a reader can see WHAT the page sets inline rather than inferring it from the absence
     * of a finding, which is the difference between a claim and a measurement.
     */
    for (const attribute of page.styleAttributes) {
      const key = `${attribute.path}  ${attribute.style}`

      inline.set(key, (inline.get(key) ?? 0) + 1)
    }

    const grouped = new Map()

    for (const sample of page.samples) {
      totals.samples += 1

      const token = sample.token.toLowerCase()
      const role = sentinels.get(token)

      if (role !== undefined) {
        totals.sentinel += 1
        if (sample.paints) {
          painted[template][role].push({ width, property: sample.property, shape: sample.shape })
        }
        continue
      }

      if (Object.hasOwn(NON_THEMED, token)) {
        totals.nonThemed += 1
        continue
      }

      totals.other += 1

      /*
       * Grouped by what a rule would address — the element's shape, the property and the value
       * — rather than per element, because `article.card` appearing five times is one defect
       * and five lines of it is a report nobody reads to the end.
       */
      const key = [sample.shape, sample.property, token, sample.paints].join('|')
      const seen = grouped.get(key)

      if (seen === undefined) {
        grouped.set(key, {
          kind: 'computed',
          template,
          width,
          shape: sample.shape,
          property: sample.property,
          named: sample.named,
          value: token,
          raw: sample.value,
          paints: sample.paints,
          reason: sample.reason,
          path: sample.path,
          text: sample.text,
          count: 1,
        })
      } else {
        seen.count += 1
      }
    }

    findings.push(...grouped.values())
  }
}

const report = {
  measured: new Date().toISOString(),
  templates,
  widths: WIDTHS,
  theme,
  nonThemed: NON_THEMED,
  inline: Object.fromEntries(inline),
  totals,
  findings,
  painted,
  emitted,
}

writeFileSync(join(dir, 'theme-paint-report.json'), JSON.stringify(report, null, 2))

const line = '-'.repeat(78)

console.log(line)
console.log('QRPAGER-49A — does the theme reach the pixel?')
console.log(line)
console.log(`templates : ${templates.join(', ')}   (from LandingTemplate::values())`)
console.log(`widths    : ${WIDTHS.join(', ')}`)
console.log('sentinels :')
for (const role of ROLES) console.log(`    ${role.padEnd(20)} ${theme[role]}`)
console.log('')
console.log(
  `collected : ${String(totals.samples)} colour-bearing computed values — ` +
    `${String(totals.sentinel)} sentinel, ${String(totals.nonThemed)} declared non-themed, ` +
    `${String(totals.other)} other`,
)
console.log('')

console.log(line)
console.log('(c) EVERYTHING ELSE — the finding')
console.log(line)

if (findings.length === 0) {
  console.log('none. Every colour-bearing computed value is a sentinel or a declared member.')
} else {
  for (const template of templates) {
    for (const width of WIDTHS) {
      const here = findings.filter((f) => f.template === template && f.width === width)

      console.log(`\n${template} @ ${width}  — ${String(here.length)} distinct`)

      if (here.length === 0) {
        console.log('    none')
        continue
      }

      for (const finding of here.sort((a, b) => b.count - a.count)) {
        console.log(
          `    ${finding.value.padEnd(12)} ${finding.property.padEnd(24)} ` +
            `${finding.shape}  ×${String(finding.count)}  ` +
            `${finding.paints ? 'PAINTS' : 'does not paint'}`,
        )
        console.log(`        ${finding.path}`)
        console.log(`        ${finding.reason}`)
      }
    }
  }
}

console.log('')
console.log(line)
console.log('(3) STYLE ATTRIBUTES — what the page sets inline, across all six renders')
console.log(line)

for (const [attribute, count] of inline) {
  const [path, ...style] = attribute.split('  ')

  console.log(`  ×${String(count)}  ${path}`)
  console.log(`        style="${style.join('  ').slice(0, 220)}"`)
}

console.log('')
console.log(line)
console.log('(6) DEAD CONTROL CHECK — which of the six roles each template actually paints with')
console.log(line)

const dead = []

for (const template of templates) {
  console.log(`\n${template}`)

  for (const role of ROLES) {
    const uses = painted[template][role]
    const where = new Set(uses.map((use) => `${use.property} on ${use.shape}`))

    if (uses.length === 0) {
      dead.push({ template, role, emitted: emitted[template][role] })
      console.log(
        `    ${role.padEnd(20)} DEAD — emitted as ${emitted[template][role]}, painted nowhere`,
      )
      continue
    }

    const examples = [...where].slice(0, 3).join('; ')

    console.log(
      `    ${role.padEnd(20)} paints ×${String(uses.length)} — ${examples}` +
        (where.size > 3 ? ` (+${String(where.size - 3)} more)` : ''),
    )
  }
}

console.log('')
console.log(line)

if (dead.length > 0) {
  console.log(
    `${String(dead.length)} dead picker(s): ` +
      dead.map((row) => `${row.template}/${row.role}`).join(', '),
  )
  console.log(
    'A role a template never paints with is a picker a restaurant owner can move with no ' +
      'effect — `§12`. Reported, not narrowed: the studio keeps its six rows.',
  )
}

console.log(
  findings.length === 0
    ? 'theme-paint: the invariant holds for every template at both widths.'
    : `theme-paint: ${String(findings.length)} finding(s) in category (c).`,
)

process.exit(findings.length === 0 ? 0 : 1)
