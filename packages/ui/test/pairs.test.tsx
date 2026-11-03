import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { THEMES, type Theme } from '@qrpager/tokens'

import {
  Button,
  Card,
  Field,
  OrderReady,
  StateView,
  StatusBadge,
  ThemeProvider,
} from '../src/index.ts'

afterEach(cleanup)

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p: string) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * THE PAIRINGS THE TEMPLATES RENDER, DERIVED — NOT A LISTED SET
 * ================================================================================
 *
 * ⚠ THE TEMPLATES HAD NO PAIR CHECK AT ALL, and they are a gate clause of their own:
 * *"Three landing templates ship, each rendering from tokens only, each fillable from Task
 * 7's block schema, each passing the CONTRAST and RTL bars."*
 *
 * What existed: a browser walk that derives every pairing the KIT renders, from
 * `browser/fixtures/kit.html`; and a VOCABULARY check in `invariant.test.tsx` asserting that
 * every token used as `color` in either stylesheet is a declared foreground role and every
 * token used as `background-color` is a declared background or fill.
 *
 * A vocabulary check is not a pair check, and the gap is exactly one level out — the same
 * level "text on a fill" hid at. `brand` is a declared foreground; `alert-ink` is a declared
 * fill. So a template rule reading
 *
 *     .qr-lp__link { color: var(--color-brand); background-color: var(--color-alert-ink); }
 *
 * uses two declared tokens, passes the vocabulary check, passes `lint:tokens`, passes every
 * Vitest case and every Playwright case — and renders at **1.14:1**.
 *
 * SO THIS DERIVES THE PAIRS, from the stylesheets and the rendered DOM, and never from a
 * list anybody wrote:
 *
 *   1. both stylesheets are parsed into ordered rules, with the `color` and
 *      `background-color` token each declares;
 *   2. every template — and every kit component — is rendered;
 *   3. for each element that paints text of its own, the effective foreground is the
 *      nearest self-or-ancestor declaring `color` and the effective background the nearest
 *      declaring a non-transparent `background-color`, which is CSS inheritance;
 *   4. every pair that falls out must be a row of `generated/contrast.json`, in BOTH
 *      themes, and must pass its bar.
 *
 * WHY HERE AND NOT IN PLAYWRIGHT. The browser walk needs a static fixture; these are React
 * components, and a second hand-written fixture of them would be the "only as complete as
 * the fixture" problem the static checks exist to close. jsdom cannot resolve `var()`, so
 * the resolution is done over the stylesheet text instead — which is why the rules are
 * parsed rather than read from `getComputedStyle`.
 *
 * WHAT THIS DELIBERATELY DOES NOT MODEL: specificity. Later source order wins, which is the
 * cascade's tie-break and is true of every rule in these two files (they are authored flat,
 * one class per rule). Where it is wrong it is wrong in the direction of reporting MORE
 * pairs than render, and an extra pair that is in the table and passes costs nothing.
 */

interface Rule {
  readonly selector: string
  readonly colour?: string
  readonly background?: string
}

/** `var(--color-ink)` -> `ink`; `var(--ready-ink)` -> `--ready-ink`; anything else -> null. */
function tokenOf(value: string): string | null {
  const trimmed = value.trim()
  if (/^(?:transparent|currentcolor|inherit|initial|unset|none)$/i.test(trimmed))
    return 'transparent'
  const m = /^var\(\s*(--[a-z0-9-]+)\s*\)$/i.exec(trimmed)
  if (m === null) return null
  const name = m[1]!
  return name.startsWith('--color-') ? name.slice('--color-'.length) : name
}

/** Every rule in a stylesheet, in source order, with its two colour declarations. */
function rulesOf(css: string): Rule[] {
  const code = css
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    // At-rule bodies: the only one carrying rules is the reduced-motion block, which
    // declares no colour. Removing the wrapper keeps the inner rules visible.
    .replace(/@media[^{]*\{/g, '')
    .replace(/@(?:import|charset)[^;]*;/g, '')

  const rules: Rule[] = []
  for (const m of code.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = m[1]!.trim()
    const body = m[2]!
    if (selector.startsWith('@') || selector === '') continue

    const colour = /(?:^|[;{\s])color\s*:\s*([^;}]+)/.exec(body)
    const background = /(?:^|[;{\s])background-color\s*:\s*([^;}]+)/.exec(body)
    if (colour === null && background === null) continue

    const rule: Rule = {
      selector,
      ...(colour === null ? {} : { colour: colour[1]!.trim() }),
      ...(background === null ? {} : { background: background[1]!.trim() }),
    }
    rules.push(rule)
  }
  return rules
}

/**
 * Does this element match the rule's selector?
 *
 * A selector carrying a pseudo-class jsdom cannot evaluate (`:focus-visible`) is retried
 * with the pseudo-classes stripped, so the rule still CONTRIBUTES rather than being skipped.
 * Skipping is the direction that hides a defect; over-matching is the direction that
 * reports an extra pair, which is harmless if the pair is in the table.
 */
function matchesSelector(el: Element, selector: string): boolean {
  for (const part of selector.split(',').map((s) => s.trim())) {
    if (part === '') continue
    try {
      if (el.matches(part)) return true
    } catch {
      const stripped = part.replace(/:{1,2}[a-z-]+(?:\([^)]*\))?/gi, '')
      try {
        if (stripped !== '' && el.matches(stripped)) return true
      } catch {
        // A selector neither form can evaluate contributes nothing, and the count assertion
        // below is what stops that from going unnoticed at scale.
      }
    }
  }
  return false
}

/** The last rule in source order that declares `property` for this element. */
function declaredOn(el: Element, rules: Rule[], property: 'colour' | 'background'): string | null {
  let found: string | null = null
  for (const rule of rules) {
    const value = rule[property]
    if (value === undefined) continue
    if (matchesSelector(el, rule.selector)) found = value
  }
  return found
}

/** CSS inheritance for `color`: the nearest self-or-ancestor that declares one. */
function effectiveForeground(start: Element, rules: Rule[]): string | null {
  let el: Element | null = start
  while (el !== null) {
    const value = declaredOn(el, rules, 'colour')
    if (value !== null) return tokenOf(value)
    el = el.parentElement
  }
  return null
}

/** The nearest self-or-ancestor that actually paints a background. */
function effectiveBackground(start: Element, rules: Rule[]): string | null {
  let el: Element | null = start
  while (el !== null) {
    const value = declaredOn(el, rules, 'background')
    if (value !== null) {
      const token = tokenOf(value)
      if (token !== null && token !== 'transparent') return token
    }
    el = el.parentElement
  }
  return null
}

/** Every pairing a rendered tree paints, keyed so each is reported once. */
function pairsIn(root: Element, rules: Rule[]): { fg: string; bg: string; sample: string }[] {
  const out = new Map<string, { fg: string; bg: string; sample: string }>()
  for (const el of root.querySelectorAll('*')) {
    const ownText = [...el.childNodes].some(
      (n) => n.nodeType === 3 && (n.textContent ?? '').trim() !== '',
    )
    if (!ownText) continue
    // Visually hidden content is not read by eye, so no contrast bar applies to it.
    if (el.classList.contains('qr-visually-hidden')) continue

    const fg = effectiveForeground(el, rules)
    const bg = effectiveBackground(el, rules)
    if (fg === null || bg === null || fg === 'transparent') continue

    const key = `${fg}/${bg}`
    if (!out.has(key)) {
      const first = el.classList.item(0)
      out.set(key, { fg, bg, sample: first ?? el.tagName.toLowerCase() })
    }
  }
  return [...out.values()]
}

interface ContrastTable {
  readonly pairs: readonly {
    readonly theme: Theme
    readonly fg: string
    readonly bg: string
    readonly ratio: number
    readonly pass: boolean
  }[]
}

const table = JSON.parse(read('packages/tokens/generated/contrast.json')) as ContrastTable
const protectedPrefix = '--ready-'

const RULES = [
  ...rulesOf(read('packages/ui/src/styles/kit.css')),
  ...rulesOf(read('packages/ui/src/styles/templates.css')),
]

// -------------------------------------------------------------- the sample page

// ⚠ THE LANDING-BLOCK FIXTURE AND ITS `page` WERE HERE AND ARE RETIRED WITH THE WALK THAT
// READ THEM -- `QRPAGER-33`. They existed only to render the three templates this ruling
// retired; the kit derivation below builds its own subjects.
//
// `BADGE_LABELS` stays, and it was nearly taken with them: it sat between the two and is
// read by the KIT case below, which is the half that survives. Caught by running the suite
// (`ReferenceError: BADGE_LABELS is not defined`) rather than by reading the diff.
const BADGE_LABELS = {
  waiting: 'Waiting',
  ready: 'Ready',
  completed: 'Collected',
  cancelled: 'Cancelled',
  expired: 'Expired',
} as const

describe('the rendered pairings are derived from the stylesheets', () => {
  it('parses a non-empty rule set from BOTH stylesheets, so nothing is vacuous', () => {
    const kit = rulesOf(read('packages/ui/src/styles/kit.css'))
    const templates = rulesOf(read('packages/ui/src/styles/templates.css'))
    // Both halves, separately: a parser change that stops seeing one file would otherwise
    // leave the combined count plausible.
    expect(kit.length, 'no colour rule parsed from kit.css').toBeGreaterThanOrEqual(10)
    expect(templates.length, 'no colour rule parsed from templates.css').toBeGreaterThanOrEqual(10)
    expect(table.pairs.length).toBe(42)
  })

  /*
   * ⚠ THE PER-TEMPLATE WALK WAS HERE AND IS RETIRED WITH ITS SUBJECT -- `QRPAGER-33`,
   * Muhammad's ruling of 2026-11-03. It rendered each of `packages/ui/src/templates`'
   * three templates and required every pairing it painted to be a passing row of
   * `generated/contrast.json`. That directory is gone: only its own tests imported it and
   * `@qrpager/ui/templates` did not resolve at all, so it was never a consumed feature.
   *
   * ⚠ WHAT IS NARROWED, STATED RATHER THAN LEFT TO BE NOTICED. `R-175` and `R-179` are
   * about this file and `invariant.test.tsx`, and both are RESTATED rather than closed: the
   * derivation below still runs over the KIT, and the rule-set case above still parses both
   * stylesheets. What no longer happens is a walk of a rendered template.
   *
   * ⚠ AND `src/styles/templates.css` IS STILL HERE, which is the other half of the same
   * orphan and is DELIBERATELY NOT REMOVED IN THIS CHANGE. Nothing imports it -- not
   * `kit.css`, not any app, and its `qr-lp__` classes reach no built bundle (measured) --
   * but removing it regenerates `generated/contrast.json`, moves this file's 42-pair count,
   * and edits two tooling tests that read it by name. The ruling named
   * `packages/ui/src/templates`; this is a sibling decision and `R-240` carries it with the
   * cost, so it is Muhammad's to take rather than mine to fold in.
   */

  it('has every pairing the KIT components render in the table too', () => {
    // The same derivation over the component kit, in Node. It does not replace the browser
    // walk -- that one reads what the ENGINE resolved and is the only thing that can catch
    // a UA blend -- but it runs in both halves of CI and covers components the static
    // fixture would have to be edited to include.
    const { container } = render(
      <ThemeProvider>
        <StateView state="error" title="Something went wrong" description="Try again." />
        <Card title="A card">
          <Field label="Your name" hint="As it appears on the order" />
          <StatusBadge status="ready" labels={BADGE_LABELS} />
          <Button variant="primary">Primary</Button>
          <Button variant="danger">Danger</Button>
          <Button variant="quiet">Quiet</Button>
        </Card>
        <OrderReady
          reference="A-114"
          heading="Your order is ready"
          instruction="Come to the counter"
          acknowledgeLabel="On my way"
          onAcknowledge={() => undefined}
        />
      </ThemeProvider>,
    )

    const pairs = pairsIn(container, RULES)
    expect(pairs.length, 'the kit walk derived too few pairs').toBeGreaterThanOrEqual(4)

    const missing: string[] = []
    for (const pair of pairs) {
      // The order-ready screen's own pairs are outside theming and are asserted by exact
      // value in invariant.test.tsx; its properties are the protected ones.
      if (pair.fg.startsWith(protectedPrefix) || pair.bg.startsWith(protectedPrefix)) continue
      for (const theme of THEMES) {
        const row = table.pairs.find(
          (r) => r.theme === theme && r.fg === pair.fg && r.bg === pair.bg,
        )
        if (row?.pass !== true) {
          missing.push(`${theme}: ${pair.fg} on ${pair.bg} (.${pair.sample})`)
        }
      }
    }
    expect(missing, 'these kit pairings are not a passing row of the generated table').toEqual([])
  })
})
