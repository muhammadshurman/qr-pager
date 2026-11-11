#!/usr/bin/env node
/**
 * The CSS half of the no-hard-coded-values rule. ESLint has no CSS parser in this
 * workspace, and Tailwind v4 keeps its theme in CSS -- so a rule that only read TypeScript
 * would leave the one file format where colours and lengths actually live unchecked.
 *
 * Reads the SAME pattern list as the ESLint plugin (../patterns.mjs). It carries no copy.
 *
 * Exit 1 on any finding, with file:line:column and the matched text, so a failure names
 * what to change rather than that something is wrong somewhere.
 */
import { existsSync, lstatSync, readFileSync, readdirSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { patternsFor, valueIsAcceptable } from '../patterns.mjs'
import { hasStyleBlock, maskBladeCss } from '../blade-css.mjs'

const ROOT = join(fileURLToPath(new URL('../../..', import.meta.url)))
const CSS_PATTERNS = patternsFor('css')
const BASELINE = join(ROOT, 'tools', 'design-tokens', 'landing-baseline.json')

/**
 * Paths this rule does not apply to, each with the reason. An exemption without a reason is
 * how a rule stops covering the thing it was written for.
 */
const EXEMPT_PREFIXES = [
  // The token source and its generated outputs ARE the values. A rule forbidding literals
  // in the file that defines them would forbid the single source itself -- and the approved
  // elevation `0 1px 2px rgba(16, 35, 27, 0.06)` genuinely is a colour function, decided
  // once, there.
  'packages/tokens/src',
  'packages/tokens/generated',
  // Task 8b: the panel palette, written by the same generator for `browser/panel.spec.ts`.
  // Exempt for the reason stated just above -- it is a generated OUTPUT of the token source,
  // so a rule forbidding literals in it forbids the single source itself. `tokens:check`
  // byte-diffs it and `PanelPaletteTest` holds it to what the panel actually serves.
  'browser/fixtures/panel-palette.css',
  // Deliberately-wrong files. The rule is PROVEN on them by the test, which runs the
  // scanner at them on purpose; sweeping them in the ordinary pass would make `pnpm lint`
  // permanently red.
  'tools/design-tokens/fixtures',
  /*
   * ⚠ THIS WAS THE BLANKET PREFIX `apps/api`, AND ITS COMMENT WAS THE DEFECT.
   *
   * It read: *"The Laravel half. Not this workspace, and `composer guard` rule 11 covers
   * it."* **Rule 11 does not cover it.** Measured at `QRPAGER-42`: the guard's `PATHS` are
   * `app, config, database, routes, tests, guard/src` — `resources/` is not among them —
   * and rule 11's own `APPLICATION_PATHS` is narrower still (`app/`, `routes/`). So the
   * sentence handed the file to a scanner that never received it, and because it named an
   * owner, nobody looked again.
   *
   * What that left unscanned was not a corner: `resources/css/filament/studio/theme.css`
   * and its platform sibling define the ENTIRE appearance of both admin panels, and
   * `scan-panel.mjs` does not read them either — its subjects come from the `@source`
   * globs, which point at the panel directory under `app/Features` and at
   * `resources/views`, never at `resources/css`. (Those globs are described rather than
   * spelled here: written out, one of them ends in the two characters that CLOSE a block
   * comment, which is the hazard `studio/theme.css` records at its own top and which this
   * very comment hit on its first run.) PROVEN on a deliberately bad file, which is now the committed fixture
   * `fixtures/panel-theme-literals.css`: a raw hex, an off-ladder padding and an off-scale
   * font size in that stylesheet were reported clean by all three scanners.
   *
   * So the Laravel half's AUTHORED stylesheets are scanned here, by the reader whose job
   * this is — `scan-panel.mjs` says so itself: *"Patterns that judge a declaration VALUE
   * belong to the CSS reader."* Two narrower prefixes replace the blanket one.
   *
   * ⚠ WHAT THIS STILL DOES NOT COVER, stated rather than implied: the two directories
   * below, and nothing else under `apps/api`. `public/` is Vite's compiled output — 700 KB
   * of Tailwind whose literals are the build's own and are regenerated on every run — and
   * `vendor/` is third-party. 243 CSS files live in those two; four are authored, and those
   * four are now read.
   */
  'apps/api/public',
  'apps/api/vendor',
  /*
   * `§11`'s REVIEWER WORKTREES, for the reason `eslint.config.mjs` records at length: the two
   * reviewers run concurrently in detached worktrees under `.claude/worktrees/`, so the
   * ordinary state of this repository DURING a review round is that two copies of it exist
   * inside it. This walk would scan all three, and its FLOOR check would pass comfortably
   * while every finding was reported three times.
   *
   * ⚠ A PREFIX AND NOT THE SEGMENT `.claude` — `§11` QA L-2. As a skipped segment it also
   * skipped the three TRACKED files under that directory (the `§11` briefs and the `task`
   * command), and a scanner quietly dropping real files is the same defect as one scanning
   * three copies of them. Only the worktrees are excluded, and only under this path.
   */
  '.claude/worktrees',
]

/**
 * Directory names skipped at ANY depth.
 *
 * ⚠ THIS WAS A ROOT-RELATIVE PREFIX LIST AND IT LEAKED. `node_modules` as a prefix matches
 * only the root's own, and pnpm with `hoist=false` puts a `node_modules` inside EVERY
 * package -- so the first real run reported 69 findings in jsdom's default stylesheet and in
 * the token file reached through a workspace symlink. A linter that reports its own
 * dependencies' code has no signal left in it.
 */
const EXEMPT_SEGMENTS = ['node_modules', '.next', 'coverage', 'dist', '.git', 'out']

export function isExempt(path) {
  const rel = relative(ROOT, path).split(sep).join('/')
  if (rel === '' || rel.startsWith('..')) return false
  const segments = rel.split('/')
  if (segments.some((segment) => EXEMPT_SEGMENTS.includes(segment))) return true
  return EXEMPT_PREFIXES.some((e) => rel === e || rel.startsWith(`${e}/`))
}

export function cssFiles(dir, found = []) {
  if (isExempt(dir)) return found
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (isExempt(full)) continue
    // lstat, not stat: pnpm links a workspace dependency into its consumer's
    // node_modules, and following that link walks the same file twice under two names.
    const st = lstatSync(full)
    if (st.isSymbolicLink()) continue
    if (st.isDirectory()) cssFiles(full, found)
    else if (/\.css$/i.test(entry)) found.push(full)
  }
  return found
}

/**
 * Every Blade template that carries a `<style>` element — `R-267`, closed at `QRPAGER-46`.
 *
 * ⚠ IT COLLECTS TEMPLATES WITH CSS IN THEM, NOT TEMPLATES. This repository has hundreds of
 * Blade files and one of them has a stylesheet; a walk that returned all of them would make
 * the count below meaningless and the FLOOR check unable to tell a broken walk from a tree
 * with no CSS in its templates. `hasStyleBlock()` reads the file, which is the same cost the
 * scan pays a line later.
 */
export function bladeFiles(dir, found = []) {
  if (isExempt(dir)) return found
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (isExempt(full)) continue
    const st = lstatSync(full)
    if (st.isSymbolicLink()) continue
    if (st.isDirectory()) bladeFiles(full, found)
    else if (/\.blade\.php$/i.test(entry) && hasStyleBlock(readFileSync(full, 'utf8'))) {
      found.push(full)
    }
  }
  return found
}

/**
 * The frozen pre-existing literals: `{file, id, match} -> how many are excused`.
 *
 * ⚠ A FREEZE, NOT AN EXEMPTION, AND THE DIFFERENCE IS THE COUNT. `landing-baseline.json`
 * carries its own reasoning and Muhammad's instruction verbatim; what matters here is that an
 * entry excuses EXACTLY as many findings as it recorded on 2026-11-11, so a seventeenth copy
 * of an existing literal in the same file is a new finding. An absent baseline file is not an
 * error — nothing else in this repository has one — and a baseline that excuses nothing is
 * reported, because an entry that matches nothing is a claim of debt that no longer exists.
 */
export function readBaseline(path = BASELINE) {
  if (!existsSync(path)) return { entries: [], budget: new Map() }

  /** @type {{entries: Array<{file: string, id: string, match: string, count: number}>}} */
  const doc = JSON.parse(readFileSync(path, 'utf8'))
  const budget = new Map()

  for (const entry of doc.entries ?? []) {
    budget.set([entry.file, entry.id, entry.match].join('\u0000'), entry.count)
  }

  return { entries: doc.entries ?? [], budget }
}

/**
 * Split findings into the ones the baseline excuses and the ones it does not.
 *
 * Returns the unexcused findings and the baseline entries that matched nothing, so `main()`
 * can report both: a new literal, and a frozen literal that has since been fixed.
 */
export function applyBaseline(findings, baseline, scannedFiles = null) {
  const remaining = new Map(baseline.budget)
  const used = new Set()
  const reported = []

  for (const finding of findings) {
    const key = [finding.file, finding.id, finding.match].join('\u0000')
    const left = remaining.get(key) ?? 0

    if (left > 0) {
      remaining.set(key, left - 1)
      used.add(key)
      continue
    }

    reported.push(finding)
  }

  /*
   * ⚠ AN ENTRY IS ONLY STALE IF ITS FILE WAS ACTUALLY SCANNED, and the first version left
   * that out: `pnpm lint` on one stylesheet reported every landing-page entry as stale and
   * exited 1, because nothing had read the template they are about. Two committed cases
   * failed on it — the explicit-list ones — which is the guard working for the right reason.
   */
  const stale = baseline.entries.filter((entry) => {
    if (scannedFiles !== null && !scannedFiles.has(entry.file)) return false

    const key = [entry.file, entry.id, entry.match].join('\u0000')

    return (remaining.get(key) ?? 0) > 0 || !used.has(key)
  })

  return { reported, stale, excused: findings.length - reported.length }
}

/** Strip /* … *\/ comments, so a hex in a comment is not a finding. */
function stripComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
}

export function scanSource(source, label) {
  const findings = []
  const text = stripComments(source)
  for (const pattern of CSS_PATTERNS) {
    if (!pattern.regex) continue
    const regex = new RegExp(pattern.regex.source, pattern.regex.flags)
    let match
    while ((match = regex.exec(text)) !== null) {
      if (pattern.valueGuard && valueIsAcceptable(match[2] ?? '')) continue
      const before = text.slice(0, match.index)
      const line = before.split('\n').length
      const column = match.index - before.lastIndexOf('\n')
      findings.push({
        file: label,
        line,
        column,
        rule: pattern.rule,
        id: pattern.id,
        match: match[0].trim(),
        message: pattern.message.replace('{{match}}', match[0].trim()),
      })
      if (match[0] === '') regex.lastIndex += 1
    }
  }
  return findings
}

export function scanPaths(paths) {
  const findings = []
  for (const path of paths) {
    const source = readFileSync(path, 'utf8')
    const label = relative(ROOT, path).split(sep).join('/')

    /*
     * ⚠ A BLADE TEMPLATE IS MASKED, NEVER EXTRACTED, so the line and column a finding
     * carries are the TEMPLATE's — `R-267`'s own prescription: *"maps offsets back to the
     * template's own line numbers so a finding names a real line."* `maskBladeCss` returns a
     * string of the same length with everything that is not CSS replaced by spaces, so
     * `scanSource` does the arithmetic and there is no offset to get wrong.
     */
    findings.push(...scanSource(/\.blade\.php$/i.test(path) ? maskBladeCss(source) : source, label))
  }
  return findings
}

/**
 * The scan, as a function returning an exit status.
 *
 * ⚠ IT RETURNS THE STATUS RATHER THAN CALLING `process.exit`, for the same reason
 * `generate.mjs`'s `run` does: a spawned process is instrumented by nothing, so the CLI's
 * own logic -- the empty-target refusal, the finding report, the exit code -- counted
 * towards no coverage figure at all. `§6` asks for coverage on the code this task adds, and
 * this file is 129 lines of it. The CLI below turns the status into an exit code, and
 * `rule.test.mjs` still drives the SCRIPT as a process for the empty-tree proof, because
 * that proof is about the script's own root resolution.
 */
export function main(explicit = []) {
  const targets = explicit.length > 0 ? explicit : [...cssFiles(ROOT), ...bladeFiles(ROOT)]

  /**
   * ⚠ AN EMPTY TARGET LIST IS A BROKEN SCANNER, NOT A CLEAN TREE. This printed
   * "0 CSS file(s) scanned, no hard-coded values." and exited 0 -- so a walk that found
   * nothing, because `EXEMPT_SEGMENTS` grew a segment that swallowed the tree or because
   * the walk itself threw past a renamed directory, looked exactly like a pass.
   *
   * This is the SAME vacuity shape `check-shell-evidence` was already fixed for in this
   * task: *"refuses an empty target list instead of printing '0 script(s) scanned' and
   * exiting 0"*. The sibling was fixed and this one was not, which is why the QA reviewer
   * could name it from the other's commit message.
   *
   * The floor is deliberately not 1. This repository has the two apps' entry stylesheets,
   * the kit, the templates and the fonts file; a run that sees fewer than four CSS files
   * has lost something, whatever it is.
   */
  const FLOOR = 4
  if (explicit.length === 0 && targets.length < FLOOR) {
    console.error(
      `design tokens: the walk found ${String(targets.length)} CSS file(s), fewer than the ` +
        `${String(FLOOR)} this workspace is known to carry. A scanner with nothing to scan ` +
        `cannot report a finding, so this is a FAILURE and not a clean tree.`,
    )
    return 1
  }

  /*
   * ⚠ AND THE SAME FLOOR FOR THE BLADE HALF, SEPARATELY, BECAUSE A ZERO HERE IS INVISIBLE IN
   * THE TOTAL. `R-267` was open for a day with three scanners reporting clean; a Blade walk
   * that silently found nothing — a renamed directory, an exemption that grew — would restore
   * exactly that condition while the CSS count kept the line above green. One template in this
   * repository carries a stylesheet, so the floor is one.
   */
  const blades = targets.filter((path) => /\.blade\.php$/i.test(path))

  if (explicit.length === 0 && blades.length < 1) {
    console.error(
      'design tokens: the walk found NO Blade template carrying a <style> element. One does ' +
        '(apps/api/resources/views/landing/page.blade.php — the only surface a customer sees, ' +
        'R-267), so this is a FAILURE and not a clean tree.',
    )
    return 1
  }

  const baseline = readBaseline()
  const scanned = new Set(targets.map((path) => relative(ROOT, path).split(sep).join('/')))
  const { reported, stale, excused } = applyBaseline(scanPaths(targets), baseline, scanned)

  for (const entry of stale) {
    console.error(
      `${entry.file}  BASELINE  the frozen literal ${JSON.stringify(entry.match)} ` +
        `(${entry.id}) is no longer there, or appears fewer than ${String(entry.count)} time(s). ` +
        'It has been fixed — remove the entry from tools/design-tokens/landing-baseline.json ' +
        'rather than leaving it to claim debt that no longer exists.',
    )
  }

  if (reported.length === 0 && stale.length === 0) {
    console.log(
      `design tokens: ${targets.length} file(s) scanned (${blades.length} Blade template(s) ` +
        `with a <style> element), no hard-coded values` +
        (excused > 0 ? `; ${excused} pre-existing literal(s) frozen by the dated baseline.` : '.'),
    )
    return 0
  }
  for (const f of reported) {
    console.error(`${f.file}:${f.line}:${f.column}  ${f.rule}  ${f.message}`)
  }
  console.error(
    `\ndesign tokens: ${reported.length} finding(s) in ${targets.length} file(s)` +
      (stale.length > 0 ? ` and ${stale.length} stale baseline entry(ies)` : '') +
      `. ${excused} pre-existing literal(s) are frozen by the dated baseline and are not ` +
      'among them.',
  )
  return 1
}

// Run as a script. Imported by the test, which is why the work above is exported.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(main(process.argv.slice(2)))
}
