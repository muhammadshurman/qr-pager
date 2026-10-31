#!/usr/bin/env node
/**
 * ================================================================================
 * THE FILAMENT PANELS' HALF OF THE NO-HARD-CODED-VALUES RULE — Task 8b
 * ================================================================================
 *
 * A THIRD READER of `../patterns.mjs`, over the panels' authored PHP and Blade. It carries
 * no copy of the list, exactly as the other two do not.
 *
 * WHY A THIRD READER WAS UNAVOIDABLE:
 *
 *   - ESLint reads .ts/.tsx/.mjs/.js and cannot parse PHP or Blade;
 *   - `scan-css.mjs` reads .css and EXEMPTS `apps/api` by name, with the reason "not this
 *     workspace, and `composer guard` rule 11 covers it" -- and guard rule 11 is about a
 *     TENANT THEME overriding tokens only. Neither reaches a Blade template that writes
 *     `class="p-5 bg-white"`.
 *
 * ⚠ AND IT IS LOAD-BEARING IN A WAY THE APPS' EQUIVALENT IS NOT. The apps' theme CLEARS
 * Tailwind's namespaces, so `p-5` and `bg-red-500` do not compile there at all and the lint
 * rule is a second mechanism for something already impossible. The panels cannot do that:
 * `SR-1` measured that Filament CONSUMES the default theme, so the panel theme REDEFINES
 * instead of clearing, and two things survive by necessity --
 *
 *   - the bare `--spacing` key, which in Tailwind v4 is what enables the dynamic `p-<n>`
 *     utility, so `p-5` = 20px compiles whatever value it holds;
 *   - `--color-white` and `--color-black`, which Filament's own CSS @applies 328 times.
 *
 * -- and for those two, THIS IS THE ONLY INSTRUMENT. `panel-theme.test.mjs` asserts the
 * exception exists and counts what forces it; this refuses what the exception lets through.
 *
 * ================================================================================
 * THE SUBJECTS ARE DERIVED FROM THE PANEL THEMES' @source GLOBS
 * ================================================================================
 *
 * Not a path list maintained here. The set of files Tailwind SCANS for the panels is
 * declared exactly once -- in each panel theme's `@source` lines -- and that is the same set
 * that can contain a utility class. A panel added with its own theme is linted with no edit
 * here, and a glob narrowed to nothing fails `panel-theme.test.mjs` rather than quietly
 * shrinking this scanner's reach.
 *
 * Exit 1 on any finding, with file:line:column and the matched text, so a failure names what
 * to change rather than that something is wrong somewhere.
 */
import { globSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

import { patternsFor } from '../patterns.mjs'

const ROOT = join(fileURLToPath(new URL('../../..', import.meta.url)))
const PANEL_PATTERNS = patternsFor('panel')

/**
 * Where the panel themes live. One directory, read rather than a list of files.
 *
 * Injectable — `panelSubjects()` and `main()` both take it — because the two failure paths
 * below (no theme directory at all, and a panel directory with no `theme.css`) are only
 * reachable with a different root, and a `spawnSync` into a temporary tree proves the
 * BEHAVIOUR while contributing no coverage of these lines. The sibling `scan-css.mjs` proves
 * its floor that way and its floor lines are uncovered in the report; this one is driven
 * in-process instead, so the refusal is both proven and measured.
 */
export const THEME_DIR = join(ROOT, 'apps/api/resources/css/filament')

/** Extensions that can carry a utility class or a style value. */
const SUBJECT = /\.(php|blade\.php|js|css)$/

/**
 * Every file the panel themes tell Tailwind to scan.
 *
 * @returns {string[]} repo-relative paths, sorted and deduplicated
 */
export function panelSubjects(themeDir = THEME_DIR) {
  let panels
  try {
    panels = readdirSync(themeDir, { withFileTypes: true })
      .filter((e) => e.isDirectory())
      .map((e) => e.name)
  } catch {
    // The directory itself being absent is reported by the floor check in main(), which
    // says so in a sentence rather than throwing a stack trace at the operator.
    return []
  }

  const files = new Set()

  for (const panel of panels.sort()) {
    const theme = join(themeDir, panel, 'theme.css')
    let css
    try {
      css = readFileSync(theme, 'utf8')
    } catch {
      // A panel directory with no theme.css contributes nothing. Not a finding: the themes
      // are what declare the scan scope, so a panel without one has declared none.
      continue
    }

    for (const [, glob] of css.matchAll(/@source\s+'([^']+)'/g)) {
      // Resolved against the stylesheet, which is how Tailwind resolves it.
      for (const match of globSync(join(dirname(theme), glob), { withFileTypes: true })) {
        if (!match.isFile()) continue
        const abs = join(match.parentPath ?? match.path, match.name)
        if (!SUBJECT.test(abs)) continue
        files.add(relative(ROOT, abs))
      }
    }
  }

  return [...files].sort()
}

/**
 * Methods whose ARGUMENT IS COPY SHOWN TO A USER, not a style value.
 *
 * ⚠ THE ONE EXEMPTION, AND IT IS A CONTEXT RULE RATHER THAN A SILENCING. `StudioForm` has
 *
 *     TextInput::make($key)->placeholder('#1a1a1a')->helperText('A hex colour, for example #1a1a1a.')
 *
 * on the field where a TENANT TYPES THEIR OWN HEX. That hex is not a design decision this
 * codebase is making -- it is an example of the input format, and `§12`'s clause is that no
 * component carries a hard-coded colour, spacing or font size *as a value it renders with*.
 * Removing the example would make the field worse for exactly the reader `§12`'s
 * zero-technical-background clause is about, and would not remove a literal from any
 * stylesheet.
 *
 * NARROW ON PURPOSE: only the argument of these four methods, and only the span inside the
 * parentheses. `->color('#1a1a1a')` is still a finding, and so is a hex anywhere else on the
 * same line. A blanket per-file or per-line exemption would have covered both.
 */
const COPY_METHODS = /->(placeholder|helperText|hint|description)\(/g

/**
 * Character spans on one line that are the argument of a copy method.
 *
 * @returns {{start: number, end: number}[]} 0-indexed, end exclusive
 */
function copySpans(text) {
  const spans = []

  for (const match of text.matchAll(COPY_METHODS)) {
    // From the opening parenthesis to its match, tracking quotes so a `)` inside a string
    // does not close the call early.
    let depth = 0
    let quote = null
    const open = (match.index ?? 0) + match[0].length - 1

    for (let i = open; i < text.length; i++) {
      const c = text[i]

      if (quote !== null) {
        if (c === '\\') i++
        else if (c === quote) quote = null
        continue
      }

      if (c === "'" || c === '"') {
        quote = c
      } else if (c === '(') {
        depth++
      } else if (c === ')') {
        depth--
        if (depth === 0) {
          spans.push({ start: open, end: i })
          break
        }
      }
    }
  }

  return spans
}

/** Every finding in one file. */
function scanFile(file) {
  const source = readFileSync(join(ROOT, file), 'utf8')
  const lines = source.split('\n')
  const findings = []

  for (const pattern of PANEL_PATTERNS) {
    // Patterns that judge a declaration VALUE belong to the CSS reader; this reader sees
    // class attributes and PHP strings, so it applies the textual patterns only. Stated
    // rather than filtered silently.
    if (pattern.valueGuard || pattern.colourGuard || pattern.styleProperties) continue

    for (const [index, text] of lines.entries()) {
      const copy = copySpans(text)

      for (const match of text.matchAll(new RegExp(pattern.regex.source, pattern.regex.flags))) {
        const at = match.index ?? 0

        if (copy.some((span) => at > span.start && at < span.end)) continue

        findings.push({
          file,
          line: index + 1,
          column: at + 1,
          rule: pattern.rule,
          message: pattern.message.replace('{{match}}', match[0]),
        })
      }
    }
  }

  return findings
}

export function main(explicit = [], themeDir = THEME_DIR) {
  const targets = explicit.length > 0 ? explicit : panelSubjects(themeDir)

  /**
   * ⚠ AN EMPTY TARGET LIST IS A BROKEN SCANNER, NOT A CLEAN TREE -- the vacuity shape both
   * sibling scanners were already fixed for in Task 8a (`scan-css.mjs` printed "0 CSS
   * file(s) scanned" and exited 0; `check-shell-evidence` did the same). Written in from
   * the start here rather than discovered a third time.
   *
   * The floor is the two panels' own page classes and their Blade views: seven routes over
   * two panels, so a run that sees fewer than eight files has lost a glob.
   */
  const FLOOR = 8
  if (explicit.length === 0 && targets.length < FLOOR) {
    console.error(
      `design tokens (panel): the @source globs resolved to ${String(targets.length)} file(s), ` +
        `fewer than the ${String(FLOOR)} the two panels are known to carry. A scanner with ` +
        `nothing to scan cannot report a finding, so this is a FAILURE and not a clean tree.`,
    )
    return 1
  }

  const findings = targets.flatMap((f) => scanFile(f))

  if (findings.length === 0) {
    console.log(
      `design tokens (panel): ${targets.length} panel file(s) scanned, no hard-coded values.`,
    )
    return 0
  }

  for (const f of findings) {
    console.error(`${f.file}:${f.line}:${f.column}  ${f.rule}  ${f.message}`)
  }
  console.error(
    `\ndesign tokens (panel): ${findings.length} finding(s) in ${targets.length} file(s).`,
  )
  return 1
}

// Run as a script. Imported by the test, which is why the work above is exported.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(main(process.argv.slice(2)))
}
