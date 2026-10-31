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
import {
  TEMPLATES,
  TEMPLATE_NAMES,
  type LandingBlock,
  type LandingPage,
} from '../src/templates/index.tsx'

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

const BLOCKS: LandingBlock[] = [
  {
    type: 'hero',
    fields: {
      heading: 'Qahwa & Co',
      subheading: 'Specialty coffee on Rainbow Street',
      cta_label: 'See the menu',
      cta_url: 'https://example.test/menu',
    },
  },
  {
    type: 'how_it_works',
    fields: {
      heading: 'How the pager works',
      steps: [{ title: 'Scan', body: 'Point your camera.' }],
    },
  },
  {
    type: 'menu_highlights',
    fields: {
      heading: 'Today',
      items: [{ name: 'Flat white', description: 'Two shots.', price: '3.50 JOD' }],
    },
  },
  { type: 'gallery', fields: { heading: 'The room' } },
  {
    type: 'hours_location',
    fields: {
      heading: 'Find us',
      address: '12 Rainbow Street, Amman',
      hours: [{ day: 'Monday', hours: '08:00 - 22:00' }],
      latitude: 31.95,
      longitude: 35.93,
    },
  },
  {
    type: 'contact',
    fields: {
      heading: 'Contact',
      email: 'hello@example.test',
      phone: '+962 6 123 4567',
      links: [{ label: 'Instagram', url: 'https://example.test/ig' }],
    },
  },
  {
    type: 'footer',
    fields: { text: 'Qahwa & Co', links: [{ label: 'Terms', url: 'https://example.test/t' }] },
  },
]

/** The badge copy §12 owns; supplied by the caller, so the kit render needs a set. */
const BADGE_LABELS = {
  waiting: 'Waiting',
  ready: 'Ready',
  completed: 'Collected',
  cancelled: 'Cancelled',
  expired: 'Expired',
} as const

const page: LandingPage = {
  blocks: BLOCKS,
  theme: {},
  seo: { title: null, description: null, og_image: null },
}

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

  it.each(TEMPLATE_NAMES)('has every pairing the %s template renders in the table', (name) => {
    const Template = TEMPLATES[name]
    const { container } = render(
      <ThemeProvider>
        <Template page={page} resolve={() => undefined} />
      </ThemeProvider>,
    )

    const pairs = pairsIn(container, RULES)
    // A template renders text on at least its ground and one card; a walk returning one
    // pair means the derivation broke rather than the template being plain.
    expect(pairs.length, `the ${name} walk derived too few pairs`).toBeGreaterThanOrEqual(3)

    const missing: string[] = []
    for (const pair of pairs) {
      if (pair.fg.startsWith(protectedPrefix) || pair.bg.startsWith(protectedPrefix)) continue
      for (const theme of THEMES) {
        const row = table.pairs.find(
          (r) => r.theme === theme && r.fg === pair.fg && r.bg === pair.bg,
        )
        if (row === undefined) {
          missing.push(`${name}/${theme}: ${pair.fg} on ${pair.bg} (.${pair.sample}) is in NO row`)
        } else if (!row.pass) {
          missing.push(
            `${name}/${theme}: ${pair.fg} on ${pair.bg} (.${pair.sample}) is ${String(row.ratio)}, FAILING`,
          )
        }
      }
    }
    expect(
      missing,
      'these pairings are rendered by a landing template and are not a passing row of ' +
        'generated/contrast.json — §5 requires each template to pass the contrast bar',
    ).toEqual([])
  })

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
