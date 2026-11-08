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
 * "RAIL" (السِجِل) is the approved direction as of 2026-11-07, and it SUPERSEDES Direction B
 * (SERVICE). Those eleven are `APPROVED` below, verbatim, and nothing else in this file is
 * a typed colour.
 *
 * RAIL'S PRINCIPLE IS A CONSTRAINT ON THE MAPPING BELOW, NOT DECORATION: ink on paper, and
 * THE ACCENT IS RESERVED FOR THE "READY" STATE ALONE -- never a button, a link, a border, a
 * focus ring or a heading. That is why `brand` carries `ink`'s value and why dark `brand`
 * resolves to `paper` rather than to `signal`, which it did until 2026-11-08: a dark theme
 * whose primary action was `signal` would have broken Rail's only rule INSIDE the token
 * structure, where no diff would show it.
 *
 * THE HISTORY STANDS RATHER THAN BEING DELETED (the owner's instruction): Direction B was
 * approved in writing on 2026-10-31 and Direction A (SIGNAL) was rejected the same day.
 * Rail replaces B's eleven values; it does not retract that either happened.
 *
 * TWO OF RAIL'S OWN PROPOSED VALUES WERE WITHDRAWN BY ARITHMETIC, AND THE RECORD SAYS SO:
 * a pale ready ground (#E6F2EC) with deep-green text (#0C6341) measured 6.35:1 as a pair,
 * under §12's AAA floor of 7.0 for the order-ready screen -- so `ready-field` KEEPS the
 * value it has had since Direction B, and on-ready text stays `paper`. `signal` could not
 * be #0F7B4F either: AAA + headroom on `ready-field` puts a FLOOR on signal's luminance
 * (>= 0.383552) while any text bar on paper puts a CEILING on it (<= 0.177152 at 4.5,
 * <= 0.290728 at 3.0), and those windows do not overlap -- the most signal can ever reach
 * on paper while clearing the ready floor is 2.36:1. #0F7B4F measures 3.24 on `ready-field`
 * against a 7.10 target. So signal stays DARK FIELDS ONLY, which its role rule already said.
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
import { mix, ratioFloor2, type Hex } from './color.ts'

// ---------------------------------------------------------------------------------------
// The eleven approved colours. The ONLY typed hexes in this package.
// ---------------------------------------------------------------------------------------

export const APPROVED = {
  paper: '#FCFCFA',
  surface: '#FFFFFF',
  'surface-2': '#F7F7F3',
  hairline: '#DFDFD7',
  ink: '#191C22',
  'ink-mid': '#6B6F7A',
  brand: '#191C22',
  signal: '#18C880',
  'ready-field': '#0A1F17',
  'alert-fill': '#8C2F27',
  'alert-ink': '#8C2F27',
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
      why: 'DECORATIVE SEPARATION ONLY -- 1.30:1 on paper under Rail (1.29 under Direction B), below 1.4.11’s 3:1, so it may never be a control’s only boundary',
    },
    'border-strong': {
      kind: 'mix',
      from: 'hairline',
      to: 'ink',
      amount: 0.47,
      why: 'control and input boundaries (WCAG 1.4.11). RAISED from 44% to 47% on 2026-10-31: at 44% the worst background gave 3.02, a 0.02 margin over the 3:1 bar -- the same brittleness that had already been refused for ink-mid, applied to one role and not the other. 47% gives 3.51 on the hardest light background under Rail (3.24 under Direction B)',
    },
    ink: { kind: 'approved', token: 'ink', why: 'body and headings' },
    'ink-mid': { kind: 'approved', token: 'ink-mid', why: 'secondary text only' },
    brand: { kind: 'approved', token: 'brand', why: 'primary action' },
    signal: {
      kind: 'approved',
      token: 'signal',
      why: 'DARK FIELDS ONLY -- 2.12:1 on paper under Rail (1.60 under Direction B), so never text on paper. Rail reserves this colour for the ready state alone, and the arithmetic in this file’s header is why it CANNOT also be legible on paper: the ready screen’s AAA floor and any text bar on paper have no overlapping window',
    },
    'alert-fill': {
      kind: 'approved',
      token: 'alert-fill',
      why: 'fills and >= 24px text only. ⚠ UNDER RAIL THE RATIO NO LONGER FORCES THIS BOUND: alert-fill and alert-ink are both #8C2F27, so this measures 8.01:1 on paper and would pass as body text, where Direction B’s #E4572E measured 3.33 and failed. The bound is KEPT because it is the approval’s own, not because contrast compels it -- stated so a future reader does not take the passing figure as permission',
    },
    'alert-ink': {
      kind: 'approved',
      token: 'alert-ink',
      why: 'alert text -- 8.01:1 on paper under Rail (6.73 under Direction B). It is also the danger button’s FILL, by the ruling of 2026-10-31',
    },
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
      why: 'raised. NOT derivable from the approved eleven: ink on ready-field is 1.00:1 under Rail (1.05 under Direction B) -- Rail’s ink is so close to the field in luminance that a card would be invisible, which is the same argument with a starker figure',
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
      why: 'DECORATIVE SEPARATION ONLY -- 2.14:1 on the dark ground under Rail (2.08 under Direction B), below 1.4.11’s 3:1',
    },
    'border-strong': {
      kind: 'mix',
      from: 'ready-field',
      to: 'paper',
      amount: 0.5,
      why: 'control and input boundaries (WCAG 1.4.11). RAISED from 48% to 50% on 2026-10-31: 48% gave 3.01 on the worst background, a 0.01 margin. 49% would have given exactly 3.10 -- clearing the 3.1 target by 0.00, which is worse than the margin being complained about -- so 50% was taken, at 3.34 on the hardest dark background under Rail (3.21 under Direction B)',
    },
    ink: { kind: 'approved', token: 'paper', why: 'the role swap: paper becomes the text colour' },
    'ink-mid': {
      kind: 'mix',
      from: 'ink-mid',
      to: 'paper',
      amount: 0.44,
      why: 'ink-mid itself is 3.42:1 on the dark ground under Rail (3.01 under Direction B) and fails AA. Raised until the GENERATED value clears >= 4.6:1 against the HARDEST dark background, not just the ground -- condition 3',
    },
    brand: {
      kind: 'approved',
      token: 'paper',
      why: "REPOINTED OFF `signal` ON 2026-11-08, AND THIS IS RAIL'S ONLY RULE HELD STRUCTURALLY. It read `signal` because signal was the one approved value legible on the dark ground -- which made the accent the dark theme's button fill, exactly what Rail forbids, in a place no screenshot and no diff would show. `paper` is the mirror of dark `ink`, which is also `paper`: a dark primary action is a paper fill with a dark label, as light's is an ink fill with a paper label",
    },
    signal: { kind: 'approved', token: 'signal', why: 'unchanged -- the dark theme IS its field' },
    'alert-fill': {
      kind: 'mix',
      from: 'alert-fill',
      to: 'paper',
      amount: 0.35,
      why: "DERIVED AS OF 2026-11-08, and it was `= approved alert-fill` until then. That held while alert-fill was a BRIGHT #E4572E; Rail's muted oxblood #8C2F27 is too dark to be a fill in the dark theme -- the pair FILL_PAIRS actually checks is the dark `surface` colour as its >= 24px text, and raw #8C2F27 measured 1.67:1 against a 3.0 bar. 35% toward paper is the first step that clears 3.0 + 0.10 headroom",
    },
    'alert-ink': {
      kind: 'mix',
      from: 'alert-fill',
      to: 'paper',
      amount: 0.57,
      why: 'RAISED FROM 37% TO 57% ON 2026-11-08, because Rail moved alert-fill from a bright #E4572E to a muted oxblood #8C2F27 and 37% of a darker source lands darker: it measured 3.27 against dark surface-2, failing the 4.5 bar it had cleared before. 57% is the first step where all three dark backgrounds clear 4.5 + 0.10. The original note stands as history: DERIVED, and it was `= approved alert-fill` until 2026-10-31. alert-ink itself is 2.31 on the dark ground, so the fill was promoted to the text role -- but alert-ink is a TEXT role, so its bar is 4.5, and alert-fill measured 4.66 / 3.78 / 3.13 against the three dark backgrounds: it FAILED AA on two of the three. It escaped because the bars table covered ink, ink-mid and border-strong only. 37% gives 4.82 on the hardest dark background',
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
// THE CONTRAST CONTRACT. Every pair the kit renders, its bar, and why that bar.
// ---------------------------------------------------------------------------------------

/**
 * ================================================================================
 * WHY THIS TABLE IS DECLARED RATHER THAN SPOT-CHECKED
 * ================================================================================
 *
 * The task's first report carried a "every bar met" table covering `ink`, `ink-mid` and
 * `border-strong`. Three defects hid behind the roles it did not list:
 *
 *   - dark `alert-ink` is a TEXT role at 4.5, and measured 3.78 and 3.13 on two of the
 *     three dark backgrounds. It was outside the table, so nothing looked at it.
 *   - `border-strong` cleared its bar by 0.02 and 0.01, a brittleness that had already
 *     been refused for `ink-mid` — the rule was applied to one role and not the other.
 *   - the danger button renders its label ON a fill, and no "role against background"
 *     table has a row for that shape at all.
 *
 * So the table is now the SOURCE and the figures are derived from it, rather than the
 * figures being written down and a table assembled from whichever ones someone remembered.
 * `generated/contrast.json` is emitted from these declarations, and the tests read that
 * file — nobody transcribes a ratio by hand anywhere.
 *
 * TOTALITY IS MECHANICAL: `contrastPairs()` below produces the cross product of every
 * declared foreground role with every declared background, so a role added to
 * `COLOUR_ROLES` with a `bar` appears in the table without anyone remembering to add it.
 */

/** The WCAG 2.1 minimums. Each is "at least", which is why figures are floored. */
export const BARS = {
  /** Body text, AAA. */
  aaaText: 7.0,
  /** Text below 24px (or 18.66px bold), AA. */
  aaText: 4.5,
  /** Text at or above 24px, AA — and non-text contrast under 1.4.11. */
  aaLarge: 3.0,
} as const

/**
 * The headroom required over a bar, on top of the bar itself.
 *
 * A value that clears a minimum by 0.01 moves with the mix implementation, the rounding
 * convention and the sRGB transfer function's own precision — this project's `F10` shape,
 * where a figure is only right under the method that produced it. Every derived role is
 * solved against `bar + HEADROOM`, and the generated table reports both margins so neither
 * can be eyeballed again.
 */
export const HEADROOM = 0.1

export type PairKind = 'text-aaa' | 'text-aa' | 'large-text' | 'non-text'

/** The bar a kind of pair has to clear, before headroom. */
export function barFor(kind: PairKind): number {
  switch (kind) {
    case 'text-aaa':
      return BARS.aaaText
    case 'text-aa':
      return BARS.aaText
    case 'large-text':
    case 'non-text':
      return BARS.aaLarge
  }
}

/**
 * Foreground roles that are checked against EVERY background, with the kind of pair each
 * forms. A role here cannot be forgotten: the cross product is computed, not listed.
 *
 * `hairline` has `null` and is reported without a bar — it is decorative separation only,
 * which is itself the finding of 2026-10-31: at 1.28 light and 2.07 dark it qualifies as
 * neither text nor a non-text boundary, so `border-strong` exists.
 */
export const FOREGROUND_ROLES = {
  ink: { kind: 'text-aaa', what: 'body and headings' },
  'ink-mid': { kind: 'text-aa', what: 'secondary text only' },
  'alert-ink': { kind: 'text-aa', what: 'alert and validation text' },
  brand: { kind: 'text-aa', what: 'quiet button label and link text' },
  'border-strong': { kind: 'non-text', what: "an interactive control's only boundary" },
  hairline: { kind: null, what: 'decorative separation only — never a control boundary' },
} as const satisfies Record<string, { kind: PairKind | null; what: string }>

/** The three surfaces text and boundaries sit on. */
export const BACKGROUND_ROLES = ['paper', 'surface', 'surface-2'] as const

/**
 * Text ON a fill — the shape a role-against-background table has no row for, and the one
 * the danger button's defect hid in.
 *
 * ⚠ THE DANGER BUTTON'S FILL IS `alert-ink`, NOT `alert-fill`, AND THAT IS THIS TABLE'S
 * DOING. The approval allows `alert-fill` for *"fills and >= 24px text only"*, and the
 * button's label is `--size-16` — not large text, so its bar is 4.5 and not 3.0.
 * `surface` on `alert-fill` is **3.68**: a pass for a 24px label and a failure for this
 * one. `surface` on `alert-ink` is 7.44 light and 5.82 dark, so the PAIRING changed rather
 * than any value — both tokens are approved and no new one was invented.
 *
 * `alert-fill` is therefore rendered by nothing in this kit. It stays in the token set
 * because it is an approved value, and the last row below keeps its bound stated so a
 * future use cannot rediscover this.
 */
export const FILL_PAIRS = [
  {
    fg: 'surface',
    bg: 'brand',
    kind: 'text-aa',
    what: 'primary button label on its fill',
    rendered: true,
  },
  {
    fg: 'surface',
    bg: 'alert-ink',
    kind: 'text-aa',
    what: 'danger button label on its fill',
    rendered: true,
  },
  {
    fg: 'surface',
    bg: 'alert-fill',
    kind: 'large-text',
    what: 'alert-fill carries TEXT only at >= 24px — the approval\u2019s own bound, stated so a future use cannot rediscover the danger button\u2019s defect',
    rendered: false,
  },
] as const satisfies readonly {
  fg: ColourRole
  bg: ColourRole
  kind: PairKind
  what: string
  rendered: boolean
}[]

// ---------------------------------------------------------------------------------------
// The scales. Approved 2026-10-31; one ratio each, with no in-between steps.
// ---------------------------------------------------------------------------------------

/** Spacing, in px. A component names a STEP; the number appears only here. */
export const SPACING = [4, 8, 12, 16, 24, 32, 48, 64, 96] as const

/**
 * Radius, BY ROLE rather than by number -- Rail, 2026-11-08 (`QRPAGER-36`).
 *
 * ⚠ THIS WAS `[8, 16, 24]`, A NUMERIC SCALE WHOSE PROPERTY NAMES WERE ITS OWN VALUES
 * (`--radius-${n}`), AND THAT IS WHY IT HAD TO CHANGE SHAPE RATHER THAN VALUES. Rail names
 * two radii, a surface and a control. Writing them into the old scale would have renamed
 * every emitted property -- and worse, `--radius-8` would have SURVIVED while meaning the
 * opposite: the smallest step under Direction B, the largest under Rail. Every
 * `var(--radius-8)` on a button and an input would have silently painted the biggest radius
 * in the system. No error, just wrong geometry. The owner lifted the no-new-property-set
 * rule for this ticket on exactly that evidence (`§1`, 2026-11-08).
 *
 * THE RULE THE TWO NAMES ENCODE: **radius signals interactivity.** A control carries 4px
 * because that is the affordance; everything else -- cards, sheets, rows, chips, tracks --
 * carries the surface 2px. Rail is ink on paper, so a radius is a concession, not a style.
 *
 * The third step, `--radius-24`, is GONE rather than carried: it had no consumer anywhere
 * outside the generated files, which is why nothing had to be remapped to lose it.
 */
export const RADIUS = {
  surface: '2px',
  control: '4px',
} as const satisfies Record<string, string>

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
  state: '120ms',
  /**
   * ⚠ RENAMED FROM `entrance` ON 2026-11-08, AND THE NAME WAS THE DEFECT. Its only consumer
   * is `animation: qr-spin var(--motion-spin) linear infinite` in `kit.css` -- the spinner's
   * ROTATION PERIOD, never an entrance transition. Read as an entrance it would have taken
   * Rail's `120ms` and spun about eight times a second.
   *
   * `800ms` is the owner's design call: Rail is quiet, this is the only continuous motion in
   * the system, and 1.67 rev/s still reads busy against everything else on the screen.
   */
  spin: '800ms',
  easing: 'linear',
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
  card: '0 1px 2px rgba(25, 28, 34, 0.06)',
} as const

/** The minimum touch target, in px. §12, and measured in a real browser by Playwright. */
export const TARGET_MIN_PX = 44

/**
 * Fonts. Both OFL-1.1, both SELF-HOSTED from this package, both subset.
 *
 * No CDN request anywhere: `§12`'s cold-3G bar is a gate clause, a third-party font request
 * is a dependency on someone else's uptime, and on a page a customer reaches by scanning a
 * QR code it also discloses their IP to that third party.
 *
 * ⚠ `files` IS A LIST, AND IT USED TO BE A SINGULAR `file` NAMING A FILE THAT DOES NOT
 * EXIST. The arabic entry said `tajawal-arabic.woff2`; the faces on disk are
 * `-400` and `-700`, because Tajawal is not a variable font and two weights are two files.
 * The name was emitted into `apps/api/config/design-tokens.php` and into nothing that
 * opens it, so every staleness check agreed the artefact was CURRENT -- which it was.
 * `tokens:check` can only prove an artefact matches its source; it cannot prove the source
 * is true of the disk. `artefacts.test.ts` is the check that was missing: every file path
 * named in every generated artefact must resolve.
 *
 * A list is also the shape the fact has: Plus Jakarta Sans is variable and serves one file
 * for both weights, Tajawal serves one per weight. A singular `file` could only ever have
 * been right for one of the two families.
 */
export const FONTS = {
  latin: {
    family: 'Archivo',
    license: 'OFL-1.1',
    subset: 'latin',
    files: ['archivo-latin.woff2'],
  },
  arabic: {
    family: 'Tajawal',
    license: 'OFL-1.1',
    subset: 'arabic',
    files: ['tajawal-arabic-400.woff2', 'tajawal-arabic-700.woff2'],
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

/** One row of the canonical contrast table. */
export interface ContrastRow {
  readonly theme: Theme
  readonly fg: ColourRole
  readonly bg: ColourRole
  readonly fgHex: Hex
  readonly bgHex: Hex
  /** Floored at two decimals. Never rounded up — see `ratioFloor2`. */
  readonly ratio: number
  readonly kind: PairKind | null
  readonly bar: number | null
  readonly target: number | null
  /** `ratio - bar`, floored. Null when the pair has no bar. */
  readonly marginOverBar: number | null
  readonly pass: boolean
  readonly what: string
  /** False for a pair that is declared to bound a token the kit does not render. */
  readonly rendered: boolean
}

function row(
  theme: Theme,
  fg: ColourRole,
  bg: ColourRole,
  kind: PairKind | null,
  what: string,
  rendered: boolean,
): ContrastRow {
  const fgHex = resolveColour(theme, fg)
  const bgHex = resolveColour(theme, bg)
  const ratio = ratioFloor2(fgHex, bgHex)
  const bar = kind === null ? null : barFor(kind)
  const target = bar === null ? null : Math.round((bar + HEADROOM) * 100) / 100
  return {
    theme,
    fg,
    bg,
    fgHex,
    bgHex,
    ratio,
    kind,
    bar,
    target,
    marginOverBar: bar === null ? null : Math.round((ratio - bar) * 100) / 100,
    // A pair with no bar cannot fail; a pair with one must clear bar + HEADROOM.
    pass: target === null ? true : ratio >= target,
    what,
    rendered,
  }
}

/**
 * EVERY pair, in both themes — the cross product plus the fill pairs.
 *
 * This is what `generated/contrast.json` is emitted from and what the tests read. Nothing
 * downstream transcribes a ratio, which is the point: three published figures were wrong
 * because they were typed by hand.
 */
export function contrastPairs(): ContrastRow[] {
  const rows: ContrastRow[] = []
  for (const theme of THEMES) {
    for (const fg of Object.keys(FOREGROUND_ROLES) as (keyof typeof FOREGROUND_ROLES)[]) {
      const spec = FOREGROUND_ROLES[fg]
      for (const bg of BACKGROUND_ROLES) {
        rows.push(row(theme, fg, bg, spec.kind, spec.what, true))
      }
    }
    for (const pair of FILL_PAIRS) {
      rows.push(row(theme, pair.fg, pair.bg, pair.kind, pair.what, pair.rendered))
    }
  }
  return rows
}

/** The order-ready pairs, which take no theme because the screen is outside theming (D4). */
export function orderReadyPairs(): {
  fg: string
  bg: string
  fgHex: Hex
  bgHex: Hex
  ratio: number
  bar: number
  target: number
  pass: boolean
  what: string
}[] {
  return ORDER_READY_PAIRS.map((p) => {
    const fgHex = resolveOrderReady(p.fg)
    const bgHex = resolveOrderReady(p.bg)
    const ratio = ratioFloor2(fgHex, bgHex)
    const target = Math.round((p.bar + HEADROOM) * 100) / 100
    return {
      fg: `--${p.fg}`,
      bg: `--${p.bg}`,
      fgHex,
      bgHex,
      ratio,
      bar: p.bar,
      target,
      pass: ratio >= target,
      what: p.what,
    }
  })
}

/** Every row that does not clear its target. The gate asserts this is empty. */
export function contrastFailures(): ContrastRow[] {
  return contrastPairs().filter((r) => !r.pass)
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
    ...Object.keys(RADIUS).map((role) => `--radius-${role}`),
    ...TYPE_SCALE.map((n) => `--size-${String(n)}`),
    '--motion-state',
    '--motion-spin',
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
  const names = Object.keys(ORDER_READY) as (keyof typeof ORDER_READY)[]
  // The callback's return type is ANNOTATED rather than asserted. Under this package's own
  // tsconfig the template literal infers to OrderReadyProperty and a trailing `as` is
  // flagged as an unnecessary assertion; under Next's compiler the same expression widens
  // to string. A contextual return type is correct in both and is not an assertion, so
  // neither tool has anything to complain about.
  return names.map((name): OrderReadyProperty => `--${name}`)
}
