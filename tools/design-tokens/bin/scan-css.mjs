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
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { patternsFor, valueIsAcceptable } from '../patterns.mjs'

const ROOT = join(fileURLToPath(new URL('../../..', import.meta.url)))
const CSS_PATTERNS = patternsFor('css')

/**
 * Paths this rule does not apply to, each with the reason. An exemption without a reason is
 * how a rule stops covering the thing it was written for.
 */
const EXEMPT = [
  // The token source and its generated outputs ARE the values. A rule forbidding literals
  // in the file that defines them would forbid the single source itself.
  'packages/tokens/src',
  'packages/tokens/generated',
  // Deliberately-wrong files. The rule is PROVEN on them by the test, which runs the
  // scanner at them on purpose; sweeping them in the ordinary pass would make `pnpm lint`
  // permanently red.
  'tools/design-tokens/fixtures',
  // Not this workspace.
  'apps/api',
  'node_modules',
  '.next',
  'coverage',
  '.git',
]

function isExempt(path) {
  const rel = relative(ROOT, path).split(sep).join('/')
  return EXEMPT.some((e) => rel === e || rel.startsWith(`${e}/`))
}

function cssFiles(dir, found = []) {
  if (isExempt(dir)) return found
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (isExempt(full)) continue
    const st = statSync(full)
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

// Run as a script. Imported by the test, which is why the work above is exported.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const explicit = process.argv.slice(2)
  const targets = explicit.length > 0 ? explicit : cssFiles(ROOT)
  const findings = scanPaths(targets)

  if (findings.length === 0) {
    console.log(`design tokens: ${targets.length} CSS file(s) scanned, no hard-coded values.`)
    process.exit(0)
  }
  for (const f of findings) {
    console.error(`${f.file}:${f.line}:${f.column}  ${f.rule}  ${f.message}`)
  }
  console.error(`\ndesign tokens: ${findings.length} finding(s) in ${targets.length} file(s).`)
  process.exit(1)
}
