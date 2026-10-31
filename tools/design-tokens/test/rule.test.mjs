import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { Linter } from 'eslint'
import tseslint from 'typescript-eslint'
import { describe, expect, it } from 'vitest'

import plugin from '../index.mjs'
import { PATTERNS, RULE_IDS, patternsFor, valueIsAcceptable } from '../patterns.mjs'
import { scanSource } from '../bin/scan-css.mjs'

const HERE = fileURLToPath(new URL('.', import.meta.url))
const fixture = (name) => readFileSync(join(HERE, '..', 'fixtures', name), 'utf8')

/**
 * ================================================================================
 * THE PROOF. An unproven rule is not a rule (QRPAGER-15 §6, trap 1).
 * ================================================================================
 *
 * Task 0's git hooks and `composer guard` rules 7-11 are the precedent in this repo: each
 * was shown RED on a file written to make it red, and each fixture also carries legitimate
 * shapes that must NOT fire. Both halves are here, because a rule that fires on everything
 * gets disabled and is then no rule at all.
 *
 * The first half of `§5.1`'s named invariant is this file. The second half -- no tenant
 * theme value reaching the order-ready pair -- is in packages/tokens and packages/ui.
 */

/** Lint a source string with the three rules, through the real ESLint Linter. */
function lint(code, filename = 'component.tsx') {
  const linter = new Linter()
  return linter.verify(code, {
    files: ['**/*.tsx', '**/*.ts'],
    languageOptions: {
      parser: tseslint.parser,
      parserOptions: { ecmaFeatures: { jsx: true }, sourceType: 'module' },
    },
    plugins: { '@qrpager/design-tokens': plugin },
    rules: {
      '@qrpager/design-tokens/no-raw-color': 'error',
      '@qrpager/design-tokens/no-raw-spacing': 'error',
      '@qrpager/design-tokens/no-raw-font-size': 'error',
    },
  }, filename)
}

describe('the rule exists in one place and both readers see all of it', () => {
  it('defines exactly the three rules the gate clause names', () => {
    expect(RULE_IDS).toEqual(['no-raw-color', 'no-raw-spacing', 'no-raw-font-size'])
    expect(Object.keys(plugin.rules).sort()).toEqual([
      'no-raw-color',
      'no-raw-font-size',
      'no-raw-spacing',
    ])
  })

  it('applies every pattern through one reader or the other, so none is orphaned', () => {
    // A pattern nobody applies is a pattern that silently stopped working. Exact counts,
    // never a range: a `toBeGreaterThan` here would pass on a list that had shrunk.
    expect(PATTERNS).toHaveLength(10)
    expect(patternsFor('js')).toHaveLength(7)
    expect(patternsFor('css')).toHaveLength(8)

    const applied = new Set([
      ...patternsFor('js').map((p) => p.id),
      ...patternsFor('css').map((p) => p.id),
    ])
    expect(applied.size).toBe(PATTERNS.length)
  })
})

describe('the ESLint half fails on the committed bad fixture', () => {
  const messages = lint(fixture('bad-component.tsx.fixture'))

  it('produces findings, and every one is one of our three rules', () => {
    expect(messages.length).toBeGreaterThan(0)
    // No parse error: a syntax error would produce `ruleId: null` and would mean the
    // fixture never reached the rules at all. A red counts as evidence only when it is the
    // assertion failing, never a harness exception (QRPAGER-15 §8).
    const parseErrors = messages.filter((m) => m.ruleId === null)
    expect(parseErrors).toEqual([])
    for (const m of messages) {
      expect(m.ruleId).toMatch(/^@qrpager\/design-tokens\/no-raw-(color|spacing|font-size)$/)
      expect(m.severity).toBe(2)
    }
  })

  it('fires every one of the three rules, so none is proven by its neighbours', () => {
    const fired = new Set(messages.map((m) => m.ruleId.replace('@qrpager/design-tokens/', '')))
    expect([...fired].sort()).toEqual(['no-raw-color', 'no-raw-font-size', 'no-raw-spacing'])
  })

  it.each([
    ['#0B6B4F', 'no-raw-color'],
    ['#fff', 'no-raw-color'],
    ['#0B6B4FCC', 'no-raw-color'],
    ['rgb(', 'no-raw-color'],
    ['oklch(', 'no-raw-color'],
    ['color-mix(', 'no-raw-color'],
    ['text-[#fff]', 'no-raw-color'],
    ['bg-[rgb(0,0,0)]', 'no-raw-color'],
    ['border-[#D3DAD2]', 'no-raw-color'],
    ['p-[13px]', 'no-raw-spacing'],
    ['gap-[7px]', 'no-raw-spacing'],
    ['m-[1.5rem]', 'no-raw-spacing'],
    ['text-[13px]', 'no-raw-font-size'],
  ])('catches %s under %s', (needle, rule) => {
    const hits = messages.filter(
      (m) => m.message.includes(needle) && m.ruleId.endsWith(rule),
    )
    expect(hits.length).toBeGreaterThan(0)
  })

  it.each([
    ['color: #10231B', 'no-raw-color'],
    ['fontSize: 13', 'no-raw-font-size'],
    ['padding: 13px', 'no-raw-spacing'],
    ['marginTop: 24', 'no-raw-spacing'],
    ['gap: 1.5rem', 'no-raw-spacing'],
    ['lineHeight: 19', 'no-raw-font-size'],
  ])('catches the inline style %s under %s', (needle, rule) => {
    const hits = messages.filter(
      (m) => m.message.includes(needle) && m.ruleId.endsWith(rule),
    )
    expect(hits.length).toBeGreaterThan(0)
  })
})

describe('the ESLint half stays silent on the committed good fixture', () => {
  it('reports nothing at all', () => {
    const messages = lint(fixture('good-component.tsx.fixture'))
    // The message list, not just its length, so a failure says WHICH legitimate shape fired.
    expect(messages.map((m) => `${m.line}: ${m.ruleId} ${m.message}`)).toEqual([])
  })
})

describe('the CSS half', () => {
  it('fails on the bad stylesheet, with every forbidden shape named', () => {
    const findings = scanSource(fixture('bad-styles.css.fixture'), 'bad.css')
    expect(findings.length).toBe(12)

    const fired = new Set(findings.map((f) => f.rule))
    expect([...fired].sort()).toEqual(['no-raw-color', 'no-raw-font-size', 'no-raw-spacing'])

    const ids = new Set(findings.map((f) => f.id))
    expect([...ids].sort()).toEqual([
      'css-font-size-declaration',
      'css-spacing-declaration',
      'color-function',
      'hex',
      'named-color',
    ].sort())
  })

  it('catches a shorthand whose parts are MIXED, which it once did not', () => {
    // THE REGRESSION. valueIsAcceptable early-returned on "the value contains a var()",
    // so one token laundered the literal beside it. The bad fixture predicted this line as
    // a finding, the rule did not produce it, and that is how the hole was found. This case
    // fails without the fix.
    const findings = scanSource('.a { padding: var(--space-16) 9px; }', 'mixed.css')
    expect(findings).toHaveLength(1)
    expect(findings[0].rule).toBe('no-raw-spacing')
  })

  it('stays silent on the good stylesheet', () => {
    const findings = scanSource(fixture('good-styles.css.fixture'), 'good.css')
    expect(findings.map((f) => `${f.line}: ${f.rule} ${f.match}`)).toEqual([])
  })

  it('does not fire on a value named inside a comment', () => {
    const findings = scanSource('/* #0B6B4F rgb(1,2,3) white 13px */ .a { color: var(--x); }', 'c.css')
    expect(findings).toEqual([])
  })
})

describe('valueIsAcceptable, the judgement both readers share', () => {
  it.each([
    'var(--space-16)',
    'var(--color-ink)',
    '0',
    '0px',
    '100%',
    'auto',
    'inherit',
    '1px',
    '1.5',
    'calc(var(--space-16) / 2)',
    'clamp(var(--space-8), var(--space-16), var(--space-24))',
    'var(--space-24) var(--space-24)',
    'min(var(--space-16), var(--space-24))',
  ])('accepts %s', (v) => {
    expect(valueIsAcceptable(v)).toBe(true)
  })

  it.each([
    '13px',
    '1.5rem',
    '9px',
    'var(--space-16) 9px',
    '5px 6px',
    'calc(var(--space-16) + 9px)',
    // A literal hidden in a var() fallback is what renders when the token is absent, so it
    // is a value typed at the use site.
    'var(--space-16, 13px)',
    'clamp(var(--space-8), 13px, var(--space-24))',
  ])('refuses %s', (v) => {
    expect(valueIsAcceptable(v)).toBe(false)
  })
})
