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
import { lstatSync, readFileSync, readdirSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { patternsFor, valueIsAcceptable } from '../patterns.mjs'

const ROOT = join(fileURLToPath(new URL('../../..', import.meta.url)))
const CSS_PATTERNS = patternsFor('css')

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
    findings.push(...scanSource(source, relative(ROOT, path).split(sep).join('/')))
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
  const targets = explicit.length > 0 ? explicit : cssFiles(ROOT)

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

  const findings = scanPaths(targets)

  if (findings.length === 0) {
    console.log(`design tokens: ${targets.length} CSS file(s) scanned, no hard-coded values.`)
    return 0
  }
  for (const f of findings) {
    console.error(`${f.file}:${f.line}:${f.column}  ${f.rule}  ${f.message}`)
  }
  console.error(`\ndesign tokens: ${findings.length} finding(s) in ${targets.length} file(s).`)
  return 1
}

// Run as a script. Imported by the test, which is why the work above is exported.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(main(process.argv.slice(2)))
}
