/**
 * ================================================================================
 * THE SINGLE SOURCE. EVERY DESIGN VALUE IN THIS PRODUCT IS DECIDED HERE, ONCE.
 * ================================================================================
 *
 * `§12`: "Design tokens are a single source. No hard-coded hex, spacing or font size in any
 * component." `ADR-003`: one package, consumed by the PWA, the staff app, the Filament
 * panel and the public renderer.
 *
 * Four artefacts are GENERATED from this file and none of them is ever edited:
 *
 *   packages/tokens/generated/tokens.css   CSS custom properties, light + dark
 *   packages/tokens/generated/tokens.ts    the TypeScript values and their types
 *   apps/api/config/design-tokens.php      the PHP config Filament consumes in 8b (D5)
 *   -- and `pnpm tokens:check` byte-diffs all three against what this file produces.
 *
 * ================================================================================
 * THE APPROVED VALUES, AND WHY THE DARK THEME IS A DERIVATION RATHER THAN A LIST
 * ================================================================================
 *
 * Direction B (SERVICE) was approved in writing on 2026-10-31 and fixes ELEVEN colours.
 * Those eleven are `APPROVED` below, verbatim, and nothing else in this file is a typed
 * colour.
 *
 * The dark theme needs roles none of the eleven can fill. Measured: a card on the dark
 * ground (`ink` on `ready-field`) is **1.05:1** -- invisible -- and `ink-mid` is **3.01:1**
 * where AA needs 4.5:1. That was raised as a stop-and-report rather than resolved by
 * inventing a hue, and the ruling (2026-10-31, seven conditions) was a COMPUTED MIX LADDER:
 * every dark value is a stated percentage mix of two APPROVED tokens, in a stated colour
 * space, emitted by the generator.
 *
 * ⚠ SO THE DERIVATION IS WHAT IS STORED, NEVER THE RESULT. `surface` in dark mode is
 * `{ kind: 'mix', from: 'ready-field', to: 'paper', amount: 0.08 }` -- not `#1D3028`. That
 * was condition 1 of the approval, and the reason is `§6.2`: a committed dark hex would
 * survive a change to `paper` and would then be a second source for that colour, agreeing
 * on the day it was written and drifting silently after.
 *
 * ================================================================================
 * THE ORDER-READY SCREEN IS OUTSIDE THEMING, AND THAT IS A MECHANISM
 * ================================================================================
 *
 * Decision D4. The screen's job is to be read across a room, so theme flexibility there is
 * a failure surface rather than a feature. `PROTECTED_ROLES` below names the three roles it
 * reads. They take the same value in both themes, no tenant token can produce any of their
 * property names, and **there is no clamp and no contrast-adjustment code anywhere in this
 * package** -- if any appears, the design has drifted from the decision (QRPAGER-15 §6,
 * trap 5).
 */
import { mix, type Hex } from './color.ts'

// ---------------------------------------------------------------------------------------
// The eleven approved colours. The ONLY typed hexes in this package.
// ---------------------------------------------------------------------------------------

export const APPROVED = {
  paper: '#F2F4F1',
  surface: '#FFFFFF',
  'surface-2': '#E7EBE6',
  hairline: '#D3DAD2',
  ink: '#10231B',
  'ink-mid': '#5A6A62',
  brand: '#0B6B4F',
  signal: '#3DDC97',
  'ready-field': '#0A1F17',
  'alert-fill': '#E4572E',
  'alert-ink': '#96351A',
} as const satisfies Record<string, Hex>

export type ApprovedName = keyof typeof APPROVED

/** The colour space every mix in this file runs in. Condition 2 of the approval. */
export const MIX_COLOUR_SPACE = 'srgb-8bit-component-linear-round-half-up' as const

/**
 * How one colour role gets its value.
 *
 * `approved` is a role that takes an approved value unchanged -- a role SWAP, which is what
 * makes most of the dark theme free. `mix` is the ladder.
 */
export type ColourRule =
  | { readonly kind: 'approved'; readonly token: ApprovedName; readonly why: string }
  | {
      readonly kind: 'mix'
      readonly from: ApprovedName
      readonly to: ApprovedName
      readonly amount: number
      readonly why: string
    }

export const THEMES = ['light', 'dark'] as const
export type Theme = (typeof THEMES)[number]

/**
 * Every colour role, per theme, as a RULE.
 *
 * The role names are the product's vocabulary; the approved token names happen to coincide
 * with most of them in light mode, which is what makes the light theme almost entirely
 * `approved` rules.
 */
export const COLOUR_ROLES = {
  light: {
    paper: { kind: 'approved', token: 'paper', why: 'the approved ground' },
    surface: { kind: 'approved', token: 'surface', why: 'raised: a card, lighter than paper' },
    'surface-2': {
      kind: 'approved',
      token: 'surface-2',
      why: 'recessed: a well or a table stripe, darker than paper',
    },
    hairline: {
      kind: 'approved',
      token: 'hairline',
      why: 'DECORATIVE SEPARATION ONLY -- 1.29:1 on paper, below 1.4.11’s 3:1, so it may never be a control’s only boundary',
    },
    'border-strong': {
      kind: 'mix',
      from: 'hairline',
      to: 'ink',
      amount: 0.44,
      why: 'control and input boundaries, >= 3:1 against every light background (WCAG 1.4.11). Added 2026-10-31, condition 6',
    },
    ink: { kind: 'approved', token: 'ink', why: 'body and headings' },
    'ink-mid': { kind: 'approved', token: 'ink-mid', why: 'secondary text only' },
    brand: { kind: 'approved', token: 'brand', why: 'primary action' },
    signal: {
      kind: 'approved',
      token: 'signal',
      why: 'DARK FIELDS ONLY -- 1.60:1 on paper, so never text on paper',
    },
    'alert-fill': {
      kind: 'approved',
      token: 'alert-fill',
      why: 'fills and >= 24px text only -- 3.33:1 on paper fails 4.5:1 as body text',
    },
    'alert-ink': { kind: 'approved', token: 'alert-ink', why: 'alert text -- 6.73:1 on paper' },
  },
  dark: {
    paper: {
      kind: 'approved',
      token: 'ready-field',
      why: 'the darkest approved value becomes the dark ground',
    },
    surface: {
      kind: 'mix',
      from: 'ready-field',
      to: 'paper',
      amount: 0.08,
      why: 'raised. NOT derivable from the approved eleven: ink on ready-field is 1.05:1, so a card would be invisible',
    },
    'surface-2': {
      kind: 'mix',
      from: 'ready-field',
      to: 'paper',
      amount: 0.14,
      why: 'the second raised step, mirroring light’s surface/surface-2 pair',
    },
    hairline: {
      kind: 'mix',
      from: 'ready-field',
      to: 'paper',
      amount: 0.24,
      why: 'DECORATIVE SEPARATION ONLY -- 2.08:1 on the dark ground, below 1.4.11’s 3:1',
    },
    'border-strong': {
      kind: 'mix',
      from: 'ready-field',
      to: 'paper',
      amount: 0.48,
      why: 'control and input boundaries, >= 3:1 against every dark background (WCAG 1.4.11). Added 2026-10-31, condition 6',
    },
    ink: { kind: 'approved', token: 'paper', why: 'the role swap: paper becomes the text colour' },
    'ink-mid': {
      kind: 'mix',
      from: 'ink-mid',
      to: 'paper',
      amount: 0.44,
      why: 'ink-mid itself is 3.01:1 on the dark ground and fails AA. Raised until the GENERATED value clears >= 4.6:1 against the HARDEST dark background, not just the ground -- condition 3',
    },
    brand: {
      kind: 'approved',
      token: 'signal',
      why: 'brand is 2.64:1 on the dark ground; signal is 9.73:1',
    },
    signal: { kind: 'approved', token: 'signal', why: 'unchanged -- the dark theme IS its field' },
    'alert-fill': {
      kind: 'approved',
      token: 'alert-fill',
      why: 'unchanged -- a fill is read against its own text, not against the ground, so the theme does not move it. 4.67:1 on the dark ground',
    },
    'alert-ink': {
      kind: 'approved',
      token: 'alert-fill',
      why: 'alert-ink is 2.31:1 on the dark ground; alert-fill is 4.67:1, so the FILL becomes the text role',
    },
  },
} as const satisfies Record<Theme, Record<string, ColourRule>>

export type ColourRole = keyof typeof COLOUR_ROLES.light

/**
 * ================================================================================
 * THE ORDER-READY SCREEN'S THREE COLOURS. A SEPARATE NAMESPACE, NOT THREE ROLES.
 * ================================================================================
 *
 * ⚠ THIS WAS THREE ENTRIES IN `COLOUR_ROLES` AND THAT WAS WRONG -- the test caught it
 * before any reviewer did, and the fix is a REDESIGN rather than a patch.
 *
 * The defect: naming the protected colours as THEME ROLES put them inside the theme system
 * by construction. `paper` resolves to `#F2F4F1` in light and `#0A1F17` in dark -- the role
 * swap that makes the dark theme work -- so "the order-ready pair is identical in both
 * themes" was FALSE for two of the three. D4 says that screen "is EXCLUDED from theming";
 * a colour declared once per theme is included in theming whatever the comment beside it
 * claims.
 *
 * The shape that makes it impossible: these three are their own properties, in their own
 * `--ready-*` namespace, resolved straight from `APPROVED`, and emitted EXACTLY ONCE --
 * outside both theme blocks. There is no second declaration that could differ, so
 * "identical in both themes" is a property of the emission rather than an assertion about
 * two values that happen to agree.
 *
 * It also widens the gap `§5.1`'s second clause rests on: a tenant theme can only ever
 * produce a `--color-*` name (Task 7's `ThemeTokens::property()` prefixes every token with
 * `--color-`, `--font-`, `--radius-`, `--space-` or `--size-`), and no tenant token name
 * can produce a `--ready-*` property at all.
 *
 * There is no clamp and no contrast-adjustment code here or anywhere in this package. If
 * any appears, the design has drifted from the decision (QRPAGER-15 §6, trap 5).
 */
export const ORDER_READY = {
  'ready-field': 'ready-field',
  'ready-ink': 'paper',
  'ready-signal': 'signal',
} as const satisfies Record<string, ApprovedName>

export type OrderReadyProperty = `--${keyof typeof ORDER_READY}`

/** Resolve one order-ready colour. Takes no theme argument -- that is the mechanism. */
export function resolveOrderReady(name: keyof typeof ORDER_READY): Hex {
  return APPROVED[ORDER_READY[name]]
}

/**
 * The three order-ready pairs and the bar each must clear.
 *
 * AAA on this screen is `§12`'s clause and it is ARITHMETIC over two values rather than a
 * browser measurement, which is only possible because D4 fixes the pair in this source.
 * Asserted to exact values; a change to any of the three would fail.
 */
export const ORDER_READY_PAIRS = [
  { fg: 'ready-ink', bg: 'ready-field', bar: 7, what: 'the page text' },
  { fg: 'ready-signal', bg: 'ready-field', bar: 7, what: 'the order number' },
  { fg: 'ready-field', bg: 'ready-signal', bar: 7, what: 'the acknowledge button' },
] as const satisfies readonly {
  fg: keyof typeof ORDER_READY
  bg: keyof typeof ORDER_READY
  bar: number
  what: string
}[]

// ---------------------------------------------------------------------------------------
// The scales. Approved 2026-10-31; one ratio each, with no in-between steps.
// ---------------------------------------------------------------------------------------

/** Spacing, in px. A component names a STEP; the number appears only here. */
export const SPACING = [4, 8, 12, 16, 24, 32, 48, 64, 96] as const

/** Radius, in px. */
export const RADIUS = [8, 16, 24] as const

/** The type scale, in px. One ratio, and deliberately no sizes between these. */
export const TYPE_SCALE = [13, 16, 20, 28, 44, 64, 96] as const

/**
 * Motion. `0ms` under `prefers-reduced-motion`, not a shorter duration.
 *
 * "prefers-reduced-motion means no motion, not less motion" -- QRPAGER-15 §6, trap 3. The
 * reduced value is literally zero, so the browser check can assert a resolved duration of
 * exactly 0 rather than "something small".
 */
export const MOTION = {
  state: '160ms',
  entrance: '240ms',
  easing: 'cubic-bezier(.32,.72,0,1)',
  reduced: '0ms',
} as const

/** Focus. Never removed; `outline: none` without a replacement is a defect, not a style. */
export const FOCUS = {
  width: '2px',
  offset: '2px',
  role: 'brand',
} as const satisfies { width: string; offset: string; role: ColourRole }

/** One elevation. `1px` and `2px` are device pixels, not steps on the spacing scale. */
export const ELEVATION = {
  card: '0 1px 2px rgba(16, 35, 27, 0.06)',
} as const

/** The minimum touch target, in px. §12, and measured in a real browser by Playwright. */
export const TARGET_MIN_PX = 44

/**
 * Fonts. Both OFL-1.1, both SELF-HOSTED from this package, both subset.
 *
 * No CDN request anywhere: `§12`'s cold-3G bar is a gate clause, a third-party font request
 * is a dependency on someone else's uptime, and on a page a customer reaches by scanning a
 * QR code it also discloses their IP to that third party.
 */
export const FONTS = {
  latin: {
    family: 'Plus Jakarta Sans',
    license: 'OFL-1.1',
    subset: 'latin',
    file: 'plus-jakarta-sans-latin.woff2',
  },
  arabic: {
    family: 'Tajawal',
    license: 'OFL-1.1',
    subset: 'arabic',
    file: 'tajawal-arabic.woff2',
  },
  // The fallback stack. No quoted family, so no emitted value ever carries a quote --
  // the same property Task 7's ThemeTokens::STACKS relies on.
  fallback: 'system-ui, sans-serif',
} as const

// ---------------------------------------------------------------------------------------
// Resolution: a rule becomes a value. The ONLY place that happens.
// ---------------------------------------------------------------------------------------

/** Resolve one role in one theme to its hex. */
export function resolveColour(theme: Theme, role: ColourRole): Hex {
  const rule: ColourRule = COLOUR_ROLES[theme][role]
  return rule.kind === 'approved'
    ? APPROVED[rule.token]
    : mix(APPROVED[rule.from], APPROVED[rule.to], rule.amount)
}

/** Every colour role in one theme, resolved. */
export function resolveTheme(theme: Theme): Record<ColourRole, Hex> {
  const out = {} as Record<ColourRole, Hex>
  for (const role of Object.keys(COLOUR_ROLES[theme]) as ColourRole[]) {
    out[role] = resolveColour(theme, role)
  }
  return out
}

/** Human-readable derivation for one role, for the generated artefacts' comments. */
export function describeRule(theme: Theme, role: ColourRole): string {
  const rule: ColourRule = COLOUR_ROLES[theme][role]
  return rule.kind === 'approved'
    ? `= approved '${rule.token}'`
    : `mix(${rule.from}, ${rule.to}, ${String(Math.round(rule.amount * 1000) / 10)}%) in ${MIX_COLOUR_SPACE}`
}

/** The CSS custom property a role becomes: `surface-2` -> `--color-surface-2`. */
export function colourProperty(role: string): string {
  return `--color-${role}`
}

/** Every CSS custom property this package emits, in emission order. */
export function allProperties(): string[] {
  const roles = Object.keys(COLOUR_ROLES.light) as ColourRole[]
  return [
    ...protectedProperties(),
    ...roles.map(colourProperty),
    ...SPACING.map((n) => `--space-${String(n)}`),
    ...RADIUS.map((n) => `--radius-${String(n)}`),
    ...TYPE_SCALE.map((n) => `--size-${String(n)}`),
    '--motion-state',
    '--motion-entrance',
    '--motion-easing',
    '--focus-width',
    '--focus-offset',
    '--elevation-card',
    '--target-min',
    '--font-latin',
    '--font-arabic',
  ]
}

/**
 * The CSS custom properties the order-ready screen reads, and nothing else.
 *
 * `§5.1`'s second clause is proven against this list: it must be disjoint from every
 * property a tenant theme can produce.
 */
export function protectedProperties(): OrderReadyProperty[] {
  return (Object.keys(ORDER_READY) as (keyof typeof ORDER_READY)[]).map((name) => `--${name}`)
}
