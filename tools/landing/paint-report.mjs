#!/usr/bin/env node
/*
 * ================================================================================
 * THE REPORT — QRPAGER-49A. Sentinel, declared non-themed, or a finding.
 * ================================================================================
 *
 * `browser/theme-paint.spec.ts` collects, `paint-classify.mjs` judges, and this file reads the
 * records off disk, runs the judgement and PRINTS it. It is split from the classifier so that
 * the part that decides is a pure module with no filesystem, and the part that does I/O is thin
 * enough that `run()` can be driven in-process by a test with a temporary directory.
 *
 * THE STATED INVARIANT, in the ticket's own words:
 *
 *   Rendered under a theme whose six colour roles hold six distinct sentinel values, one
 *   unchanged tenant document produces a page on which every colour-bearing COMPUTED style
 *   resolves to a sentinel, or to a member of the declared non-themed set. At 390 and at 1280,
 *   for every template in the LandingTemplate enum.
 *
 * ================================================================================
 * ⚠ WHAT THIS PRINTS IS THE COMMITTED RECORD, SO IT PRINTS EVERYTHING THE RECORD RELIES ON
 * ================================================================================
 *
 * `§0.0g`: *a gate figure is only ever read from the committed transcript that the script
 * wrote.* Phase 1's transcript carried the totals, the findings and the dead-control table, and
 * left the figures behind its strongest supporting claims — document heights, the width the
 * page was ACTUALLY rendered at, and *"zero occurrences anywhere in the render"* — in
 * git-ignored JSON. Muhammad's blocker 3: *"state explicitly which figures are committed and
 * which are not."* Everything below is printed, so everything below is committed.
 *
 * ⚠ THE TEMPLATES ARE AN ARGUMENT AND NEVER A LIST IN THIS FILE. The orchestrator reads them
 * from `LandingTemplate::values()`, so a fourth arrangement is covered the day it exists and
 * there is no way to add one outside this report.
 *
 * USAGE:  node tools/landing/paint-report.mjs <dir> --templates rail,ember,tile [--baseline <file>]
 * EXITS:  0 when (c) is empty AND the dead controls are exactly the frozen baseline;
 *         1 otherwise — and 1 when a measurement is missing or untrustworthy, because an absent
 *         or doubtful file is not a clean result.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  CURRENTCOLOR_DERIVED,
  NON_THEMED,
  analysePage,
  compareBaseline,
  deadControls,
  validateRecord,
} from './paint-classify.mjs'

/** The two widths the ticket names, and the only two. See the PR body for what is NOT covered. */
export const WIDTHS = [390, 1280]

const HERE = dirname(fileURLToPath(import.meta.url))

export const DEFAULT_BASELINE = join(HERE, 'paint-baseline.json')

/** A measurement that is missing or cannot be trusted. Never a finding. */
class MeasurementError extends Error {}

const line = '-'.repeat(78)
const pad = (value, width) => String(value).padEnd(width)

function readJson(path, what) {
  if (!existsSync(path)) {
    throw new MeasurementError(
      `theme-paint: ${what} is missing: ${path}. A measurement that did not happen is not a ` +
        'clean result, so this is a failure rather than a smaller report.',
    )
  }

  try {
    return JSON.parse(readFileSync(path, 'utf8'))
  } catch (error) {
    throw new MeasurementError(`theme-paint: ${what} is not valid JSON: ${path} (${error.message})`)
  }
}

/**
 * Run the report over a directory of records.
 *
 * @param {{
 *   dir: string,
 *   templates: string[],
 *   baselinePath?: string,
 *   out?: (text: string) => void,
 *   write?: boolean,
 * }} options
 * @returns {{ exitCode: number, report: object | null }}
 */
export function run({
  dir,
  templates,
  baselinePath = DEFAULT_BASELINE,
  out = console.log,
  write = true,
}) {
  try {
    return execute({ dir, templates, baselinePath, out, write })
  } catch (error) {
    if (error instanceof MeasurementError) {
      out(error.message)

      return { exitCode: 1, report: null }
    }

    throw error
  }
}

function execute({ dir, templates, baselinePath, out, write }) {
  if (templates.length === 0) {
    throw new MeasurementError('theme-paint: the template list is empty, so nothing was measured')
  }

  const baseline = readJson(baselinePath, 'the dead-control baseline')

  if (!Array.isArray(baseline.dead)) {
    throw new MeasurementError(`theme-paint: ${baselinePath} has no \`dead\` list`)
  }

  const manifests = Object.fromEntries(
    templates.map((template) => [
      template,
      readJson(join(dir, `manifest-${template}.json`), `the manifest for ${template}`),
    ]),
  )

  /*
   * One sentinel map for the whole run, asserted rather than assumed: three publishes under
   * three different themes would make every comparison below mean something different.
   */
  const theme = manifests[templates[0]].theme
  const roleNames = Object.keys(theme)

  for (const [template, manifest] of Object.entries(manifests)) {
    if (JSON.stringify(manifest.theme) !== JSON.stringify(theme)) {
      throw new MeasurementError(`theme-paint: ${template} was published under a different theme`)
    }
  }

  const sentinels = new Map(
    Object.entries(theme).map(([role, value]) => [value.toLowerCase(), role]),
  )

  if (sentinels.size !== roleNames.length) {
    throw new MeasurementError(
      `theme-paint: the published theme holds ${String(sentinels.size)} distinct values for ` +
        `${String(roleNames.length)} roles. Two roles sharing a value would make every ` +
        'attribution below ambiguous.',
    )
  }

  const pages = {}
  const problems = []
  const builds = new Set()

  for (const template of templates) {
    pages[template] = {}

    for (const width of WIDTHS) {
      const what = `the ${template} @ ${String(width)} record`
      const page = readJson(join(dir, `paint-${template}-${String(width)}.json`), what)

      problems.push(...validateRecord(page, { template, width }))

      if (typeof page.browser?.version === 'string') {
        builds.add(`${String(page.browser.name)} ${page.browser.version}`)
      }

      pages[template][width] = page
    }
  }

  if (builds.size > 1) {
    problems.push(
      `the records were produced by more than one browser build: ${[...builds].join('; ')}`,
    )
  }

  if (problems.length > 0) {
    out(line)
    out('theme-paint: THE MEASUREMENT IS NOT TRUSTWORTHY — nothing below was classified')
    out(line)

    for (const problem of problems) out(`  ${problem}`)

    return { exitCode: 1, report: null }
  }

  const findings = []
  const totals = { samples: 0, sentinel: 0, nonThemed: 0, other: 0, derived: 0 }
  const breakdown = []
  const inline = new Map()
  const emitted = {}
  const byTemplate = {}

  for (const template of templates) {
    byTemplate[template] = {}
    emitted[template] = {}

    for (const width of WIDTHS) {
      const page = pages[template][width]
      const analysis = analysePage(page, sentinels)

      byTemplate[template][width] = analysis.roles

      for (const key of Object.keys(totals)) totals[key] += analysis.counts[key]

      for (const finding of analysis.findings) findings.push({ template, width, ...finding })

      breakdown.push({ template, width, page, analysis })

      for (const [role, value] of Object.entries(theme)) {
        const property = `--${role.replace(/[._]/g, '-')}`
        const resolved = (page.customProperties?.[property] ?? '').trim().toLowerCase()

        emitted[template][role] = resolved

        // The first link in the chain: what the root resolved, before any rule referenced it.
        if (resolved !== value.toLowerCase()) {
          findings.push({
            template,
            width,
            shape: 'html',
            property,
            value: resolved === '' ? '(absent)' : resolved,
            raw: resolved,
            paints: false,
            reason: 'the root custom property does not hold the published value',
            why: 'the custom property is the first link in the chain',
            path: 'html',
            count: 1,
            derived: [],
          })
        }
      }

      /*
       * ⚠ THE `style` ATTRIBUTES, RECORDED BECAUSE THE TICKET NAMES THEM — *"and anything set
       * via a style attribute"*. A colour written inline necessarily appears in that element's
       * COMPUTED value and is already partitioned above; listing them lets a reader see WHAT
       * the page sets inline rather than inferring it from the absence of a finding.
       */
      for (const attribute of page.styleAttributes ?? []) {
        const key = `${attribute.path}  ${attribute.style}`

        inline.set(key, (inline.get(key) ?? 0) + 1)
      }
    }
  }

  const dead = deadControls(byTemplate, roleNames)
  const verdict = compareBaseline(dead, baseline, templates, roleNames)

  const report = {
    templates,
    widths: WIDTHS,
    browser: [...builds][0],
    theme,
    nonThemed: NON_THEMED,
    totals,
    findings,
    dead,
    baseline: verdict,
    inline: Object.fromEntries(inline),
    emitted,
  }

  if (write) writeFileSync(join(dir, 'theme-paint-report.json'), JSON.stringify(report, null, 2))

  print({
    out,
    templates,
    theme,
    roleNames,
    builds,
    breakdown,
    totals,
    findings,
    dead,
    verdict,
    baseline,
    inline,
    pages,
  })

  const red =
    findings.length > 0 ||
    verdict.unexpected.length > 0 ||
    verdict.stale.length > 0 ||
    verdict.gone.length > 0

  return { exitCode: red ? 1 : 0, report }
}

function print(context) {
  const { out, templates, theme, roleNames, builds, breakdown, totals } = context
  const { findings, dead, verdict, baseline, inline, pages } = context

  out(line)
  out('QRPAGER-49A — does the theme reach the pixel?')
  out(line)
  out(`templates : ${templates.join(', ')}   (from LandingTemplate::values())`)
  out(`widths    : ${WIDTHS.join(', ')}`)
  out(
    `engine    : ${[...builds].join('; ')}   (captured by the collector, from the browser itself)`,
  )
  out('sentinels :')

  for (const role of roleNames) out(`    ${pad(role, 20)} ${theme[role]}`)

  const properties = pages[templates[0]][WIDTHS[0]].properties ?? []

  out('')
  out("properties read per element (this list IS the claim's scope):")
  out(`    ${properties.join(', ')}`)
  out('    …and `style` attributes, listed in (3) below.')

  out('')
  out(line)
  out('(1) THE MEASUREMENT — every figure the claim relies on, from the records themselves')
  out(line)
  out('  innerWidth is window.innerWidth READ FROM THE BROWSER, not the width that was asked for.')
  out('  The report refuses any record where the two differ.')
  out('')
  // The label column follows the longest label: a fixed width let `alpha @ 1280` touch the value
  // beside it (`alpha @ 12801280`), and a longer template name would have done the same.
  const labelWidth =
    Math.max(6, ...breakdown.map((b) => `${b.template} @ ${String(b.width)}`.length)) + 2

  out(
    `  ${pad('render', labelWidth)}${pad('innerWidth', 11)}${pad('innerHeight', 12)}${pad('docHeight', 10)}` +
      `${pad('min-width:48rem', 16)}${pad('elements', 9)}${pad('values', 8)}${pad('sentinel', 9)}` +
      `${pad('non-themed', 11)}other`,
  )

  for (const { template, width, page, analysis } of breakdown) {
    const { counts } = analysis

    out(
      `  ${pad(`${template} @ ${String(width)}`, labelWidth)}${pad(page.innerWidth, 11)}${pad(page.innerHeight, 12)}` +
        `${pad(page.documentHeight, 10)}${pad(String(page.minWidth48rem), 16)}${pad(page.elements, 9)}` +
        `${pad(counts.samples, 8)}${pad(counts.sentinel, 9)}${pad(counts.nonThemed, 11)}${String(counts.other)}`,
    )
  }

  out('')
  out(
    `  totals: ${String(totals.samples)} colour-bearing computed values — ${String(totals.sentinel)} sentinel, ` +
      `${String(totals.nonThemed)} declared non-themed, ${String(totals.other)} other ` +
      `(${String(totals.derived)} of the other are currentcolor-derived consequences)`,
  )

  out('')
  out(line)
  out('(2) THE DECLARED NON-THEMED SET — keyed on what an engine actually returns')
  out(line)

  for (const [member, { reason }] of Object.entries(NON_THEMED)) out(`  ${member}\n      ${reason}`)

  out('  currentcolor-derived properties, collapsed to their source `color`:')
  out(`      ${CURRENTCOLOR_DERIVED.join(', ')}`)

  out('')
  out(line)
  out('(c) EVERYTHING ELSE — the finding')
  out(line)

  if (findings.length === 0) {
    out('none. Every colour-bearing computed value is a sentinel or a declared member.')
  } else {
    for (const template of templates) {
      for (const width of WIDTHS) {
        const here = findings.filter((f) => f.template === template && f.width === width)

        out(`\n${template} @ ${String(width)}  — ${String(here.length)} distinct`)

        if (here.length === 0) {
          out('    none')

          continue
        }

        for (const finding of here.sort((a, b) => b.count - a.count)) {
          out(
            `    ${pad(finding.value, 12)} ${pad(finding.property, 24)} ${finding.shape}  ×${String(finding.count)}  ` +
              (finding.paints ? 'PAINTS' : 'does not paint'),
          )
          out(`        ${finding.path}`)
          out(`        ${finding.reason}`)

          if (finding.derived?.length > 0) {
            out(
              `        consequence — the same value, inherited via currentcolor, in: ${finding.derived.join(', ')}`,
            )
          }
        }
      }
    }
  }

  out('')
  out(line)
  out('(3) STYLE ATTRIBUTES — what the page sets inline, across all renders')
  out(line)

  for (const [attribute, count] of inline) {
    const [path, ...style] = attribute.split('  ')

    out(`  ×${String(count)}  ${path}`)
    out(`        style="${style.join('  ').slice(0, 220)}"`)
  }

  out('')
  out(line)
  out('(6) DEAD CONTROL CHECK — each of the six roles, by name, per template')
  out(line)
  out('  Each cell is  occurrences/painting  — how many colour-bearing values hold the sentinel,')
  out(
    '  and how many of those can reach a pixel. `0/0` means the role appears nowhere in the render.',
  )
  out('')
  out('  ⚠ BOUNDARY: a role painted ONLY in a state this harness does not render — hover, focus,')
  out('  active, error, empty — counts as UNPAINTED here. The harness renders one resting state at')
  out('  two widths and nothing else.')

  const byRender = Object.fromEntries(
    breakdown.map(({ template, width, analysis }) => [
      `${template}|${String(width)}`,
      analysis.roles,
    ]),
  )

  for (const template of templates) {
    out(`\n${template}`)

    for (const role of roleNames) {
      const cells = WIDTHS.map((width) => {
        const counts = byRender[`${template}|${String(width)}`][role]

        return `${String(width)}: ${String(counts.total)}/${String(counts.painting)}`
      })
      const isDead = dead.some((pair) => pair.template === template && pair.role === role)
      const allowed = baseline.dead.find((e) => e.template === template && e.role === role)
      const state = !isDead
        ? 'paints'
        : allowed === undefined
          ? 'DEAD — NOT in the baseline'
          : `DEAD — baselined, owner ${String(allowed.owner)}`

      out(`    ${pad(role, 20)} ${pad(cells.join('   '), 28)} ${state}`)
    }
  }

  out('')
  out(line)
  out('THE DEAD-CONTROL BASELINE — frozen, and it can only shrink')
  out(line)
  out(
    `  ${String(baseline.dead.length)} known-dead control(s), enumerated by name in tools/landing/paint-baseline.json:`,
  )

  for (const entry of baseline.dead) {
    out(
      `    ${entry.template} / ${entry.role}  — owner ${String(entry.owner)}, dated ${String(baseline.generated)}`,
    )
  }

  for (const pair of verdict.unexpected) {
    out(
      `  RED  a NEW dead control: ${pair.template} / ${pair.role} paints nowhere and is not in the baseline`,
    )
  }

  for (const entry of verdict.stale) {
    out(
      `  RED  STALE baseline entry: ${entry.template} / ${entry.role} now paints — delete the entry`,
    )
  }

  for (const entry of verdict.gone) {
    out(
      `  RED  baseline entry for ${entry.template} / ${entry.role} names a template or role that is ` +
        'no longer enumerated — delete the entry',
    )
  }

  out('')
  out(line)
  out(
    findings.length === 0
      ? 'theme-paint: every colour-bearing computed value is a sentinel or a declared member.'
      : `theme-paint: ${String(findings.length)} finding(s) in category (c).`,
  )

  const red = verdict.unexpected.length + verdict.stale.length + verdict.gone.length

  out(
    red === 0
      ? `theme-paint: the dead controls are exactly the frozen baseline (${String(dead.length)}).`
      : `theme-paint: ${String(red)} dead-control baseline violation(s).`,
  )
}

function main(argv) {
  const [dir, ...rest] = argv
  const flag = (name) => {
    const at = rest.indexOf(name)

    return at === -1 ? undefined : rest[at + 1]
  }
  const templates = flag('--templates')

  if (dir === undefined || templates === undefined) {
    console.error(
      'usage: node tools/landing/paint-report.mjs <dir> --templates rail,ember,tile [--baseline <file>]',
    )

    return 64
  }

  return run({
    dir,
    templates: templates.split(',').filter((name) => name !== ''),
    baselinePath: flag('--baseline') ?? DEFAULT_BASELINE,
  }).exitCode
}

if (process.argv[1] !== undefined && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(main(process.argv.slice(2)))
}
