/**
 * ================================================================================
 * THE FILAMENT PANEL'S TAILWIND THEME. A SIBLING OF generated/theme.css, NOT AN EDIT TO IT.
 * ================================================================================
 *
 * 8a's `generated/theme.css` clears Tailwind's palette, spacing, type and radius namespaces
 * wholesale and repopulates them from the token source. **That file cannot be used for the
 * Filament panels**, measured three ways in `QRPAGER-23 §0 SR-1`: Filament's shipped CSS
 * CONSUMES the default theme it would be asked to give up — it calls the `--spacing(…)`
 * theme function at three sites and `@apply`s default-theme utilities including `rounded-md`
 * and `bg-gray-50` — so clearing those namespaces removes the framework's own stylesheet.
 *
 * `SR-1`'s ruling: **redefine, do not clear.** Every error said a *variable does not exist*,
 * never that a value was wrong. Clearing removes the name; redefining keeps the name and
 * replaces the value, and Filament cannot tell the difference because it consumes the
 * variable rather than the default value.
 *
 * SO THIS FILE IS A SEPARATE ARTEFACT AND NOT A CHANGE TO 8a's. `PLAN §0.0`'s condition for
 * the `§6` deviation is that 8b edits no line 8a's fix diff changed, and `generated/theme.css`
 * is a file that diff CREATED — every line of it is a changed line. A sibling is the only
 * additive move, and it is also the correct one: the panel's constraints are not the apps'.
 *
 * WHAT THE PANELS GET, AND WHY EACH PART IS SHAPED AS IT IS:
 *
 *   1. Every Tailwind default colour family is CLEARED — except `gray`. Filament's own
 *      `@theme inline` block declares `--color-gray-*: var(--gray-*)`, fed by the panel
 *      provider's `->colors()`, and that block runs BEFORE this one. Clearing `gray` here
 *      would wipe Filament's entry, not Tailwind's: that is what made the first attempt fail
 *      on `bg-gray-50`. The gray ladder is already a token input through `->colors()`, which
 *      is the sanctioned extension point and needs no CSS at all.
 *
 *   2. The family list is DECLARED here and GATE-CHECKED against Tailwind's own `theme.css`
 *      by `tools/design-tokens/test/panel-theme.test.mjs`. ⚠ It is declared rather than
 *      derived at generate time for one measured reason: `tailwindcss` is NOT RESOLVABLE from
 *      this package — `hoist=false` puts it only under each app's own `node_modules` — so a generator
 *      that read it would work on a developer's machine and fail wherever the resolution
 *      differs. ⚠ AND DECLARING IT IS WHY THE LIST IS RIGHT: a hand-typed list of 21 in the
 *      `§3` answers left `olive`, `mauve`, `mist` and `taupe` LIVE, which `bg-olive-500`
 *      proved by compiling. Tailwind 4.3.3 ships 25 families, not 21.
 *
 *   3. `--text-*` and `--radius-*` are cleared and repopulated with BOTH the token names and
 *      the names Filament itself `@apply`s, each mapped onto an approved value. Those
 *      mappings are DESIGN DECISIONS — which approved step does Filament's "sm" become? —
 *      so they live here as declarations, and a PHP-side test asserts the set is complete
 *      against the vendor tree, where `vendor/` actually exists.
 *
 *   4. The bare `--spacing` key is REDEFINED and never cleared. This is the one forced
 *      exception: `p-5` = 20px compiles whatever value it holds, because in Tailwind v4 the
 *      bare key's existence is what enables the dynamic `p-<n>` utility, and Filament's
 *      `--spacing(…)` calls require it to exist. Four values were measured and all four
 *      generate `p-5`; omitting the key fails the build. Enforced instead by a compiled-CSS
 *      assertion with a hard count of the vendor's call sites, plus a source rule.
 *
 * NO LITERAL COLOUR, SPACING OR TYPE VALUE APPEARS IN THE EMITTED FILE — the three categories
 * `§12` names. Every one of those is a `var()` reference to a property
 * `generated/tokens.css` declares, so the token source stays the one source and this artefact
 * cannot drift from it without `tokens:check` going red. The only literals are
 * `STRUCTURAL_RADII`'s two — a pill and a square — which are declared with their reason
 * because neither is expressible on the approved ladder.
 */

/**
 * Every colour family Tailwind 4.3.3 ships a `-50` shade for, EXCEPT `gray`.
 *
 * `gray` is excluded deliberately — see (1) in the header. The list is held to Tailwind's own
 * `theme.css` by the gate; if Tailwind adds a family, that test reddens rather than the panel
 * silently gaining a palette.
 */
export const CLEARED_COLOUR_FAMILIES = [
  'amber',
  'blue',
  'cyan',
  'emerald',
  'fuchsia',
  'green',
  'indigo',
  'lime',
  'mauve',
  'mist',
  'neutral',
  'olive',
  'orange',
  'pink',
  'purple',
  'red',
  'rose',
  'sky',
  'slate',
  'stone',
  'taupe',
  'teal',
  'violet',
  'yellow',
  'zinc',
]

/** The family Filament's own `@theme inline` shadows, so it must survive the clear. */
export const FILAMENT_OWNED_FAMILY = 'gray'

/**
 * Default-palette colour names Filament's vendor CSS references DIRECTLY, outside its own
 * families — so they must be re-supplied after their family is cleared.
 *
 * Mapped to an approved token. `sky-400` is a focus/accent hairline in Filament's own CSS.
 */
export const FILAMENT_DIRECT_COLOURS = { 'sky-400': 'signal' }

/**
 * Which approved type step each of Filament's own size names becomes.
 *
 * A DESIGN DECISION, not a derivation: Filament `@apply`s these seven and the approved scale
 * has seven steps, but the mapping is a judgement about what "sm" means on a dense admin
 * surface. `sm` and `xs` both land on 13 because the scale has no step between 13 and 16, and
 * inventing one is what `§12`'s "one ratio, no in-between sizes" forbids.
 */
export const FILAMENT_TEXT_SIZES = {
  xs: 13,
  sm: 13,
  base: 16,
  lg: 20,
  xl: 20,
  '2xl': 28,
  '3xl': 44,
}

/**
 * Which approved radius each of Filament's own radius names becomes.
 *
 * ⚠ MAPPED BY ROLE AS OF 2026-11-08 (Rail, `QRPAGER-36`), where it used to name the numeric
 * steps `{ sm: 8, md: 8, lg: 16, xl: 24 }`. Filament's `sm` and `md` dress CONTROLS -- its
 * buttons, inputs and selects -- so they take the control radius; `lg` dresses panels and
 * cards, so it takes the surface radius.
 *
 * `xl` HAS NO CONSUMER in this repository (no `rounded-xl` in any blade, component or
 * stylesheet) and is KEPT rather than dropped, mapped to `surface`: Filament's own CSS may
 * `@apply rounded-xl` in a release we have not read, and an undefined custom property there
 * would fall back to Tailwind's cleared namespace -- no radius at all. Keeping it costs one
 * declaration and removes a failure nobody would see until a Filament upgrade.
 */
export const FILAMENT_RADII = { sm: 'control', md: 'control', lg: 'surface', xl: 'surface' }

/**
 * The two radii that are STRUCTURAL rather than scale steps, and the only literals in the
 * emitted file.
 *
 * `rounded-full` means *fully round* and `rounded-none` means *square* — neither is a design
 * decision on the approved 8/16/24 ladder, and neither can be expressed as one. Filament
 * `@apply`s both. They are declared here with that reason rather than being quietly absent,
 * because the alternative is Filament's pill-shaped badges rendering as 24px rectangles.
 *
 * `§12`'s rule is about hard-coded **colour, spacing and font size**; a pill is none of the
 * three. The panel lint rule exempts these two names and nothing else.
 */
export const STRUCTURAL_RADII = { full: '9999px', none: '0' }

/**
 * Build the panel theme.
 *
 * Takes the token source's own exports so there is no second copy of any scale here.
 */
export function buildPanelTheme({ colourRoles, spacing, radius, typeScale, banner }) {
  const roles = Object.keys(colourRoles.light)
  const indent = '  '
  const line = (k, v) => `${indent}${k}: ${v};`

  const clears = CLEARED_COLOUR_FAMILIES.map((f) => line(`--color-${f}-*`, 'initial'))
  const direct = Object.entries(FILAMENT_DIRECT_COLOURS).map(([name, token]) =>
    line(`--color-${name}`, `var(--color-${token})`),
  )
  const textSizes = Object.entries(FILAMENT_TEXT_SIZES).map(([name, step]) =>
    line(`--text-${name}`, `var(--size-${step})`),
  )
  const tokenText = typeScale.map((n) => line(`--text-${n}`, `var(--size-${n})`))
  const radii = Object.entries(FILAMENT_RADII).map(([name, role]) =>
    line(`--radius-${name}`, `var(--radius-${role})`),
  )
  const structuralRadii = Object.entries(STRUCTURAL_RADII).map(([name, value]) =>
    line(`--radius-${name}`, value),
  )
  const tokenRadii = Object.keys(radius).map((role) =>
    line(`--radius-${role}`, `var(--radius-${role})`),
  )
  const tokenColours = roles.map((r) => line(`--color-${r}`, `var(--color-${r})`))
  const tokenSpacing = spacing.map((n) => line(`--spacing-${n}`, `var(--space-${n})`))

  return `/*
${banner}
 *
 * THE FILAMENT PANELS' THEME. A SIBLING of generated/theme.css, never a replacement.
 *
 * generated/theme.css CLEARS the palette, spacing, type and radius namespaces. That removes
 * Filament's own stylesheet, because Filament CONSUMES the default theme: three --spacing(…)
 * calls and @applyd defaults including rounded-md and bg-gray-50. Measured in
 * QRPAGER-23 §0 SR-1. So the panels REDEFINE instead of clearing: the name survives, the
 * value becomes a token, and Filament cannot tell the difference.
 *
 * \`gray\` is NOT cleared. Filament's own @theme inline declares --color-gray-*: var(--gray-*)
 * from the provider's ->colors(), and that block runs first; clearing gray here would wipe
 * FILAMENT's entry rather than Tailwind's.
 *
 * Every value below is a var() reference to a property generated/tokens.css declares. No
 * literal appears in this file.
 */

/*
 * The clears and the re-supplies. A plain @theme, because none of these names is declared by
 * generated/tokens.css, so emitting them is not a second declaration of anything.
 */
@theme {
${clears.join('\n')}

${indent}/* The one default-palette name Filament's own CSS still references directly. */
${direct.join('\n')}

${indent}/* ⚠ CLEARED, then repopulated. Without the clear, Tailwind's own off-scale sizes
${indent}   survive -- text-9xl and the rest -- and §12's "one ratio, no in-between sizes"
${indent}   would be true of what the panel AUTHORS and false of what it can compile. */
${line('--text-*', 'initial')}
${textSizes.join('\n')}

${indent}/* ⚠ CLEARED, then repopulated -- same reason as the type scale. The BARE key is
${indent}   re-supplied too: Filament @applies plain \`rounded\`, which reads --radius, and
${indent}   the wildcard clear takes the bare key with it. */
${line('--radius-*', 'initial')}
${radii.join('\n')}
${structuralRadii.join('\n')}
${line('--radius', `var(--radius-${radius[0]})`)}

${indent}/* The approved scales under their own names, for panel-authored utilities. */
${tokenText.join('\n')}
${tokenRadii.join('\n')}
${tokenSpacing.join('\n')}

${indent}/* ⚠ THE BARE SPACING KEY, REDEFINED AND NEVER CLEARED — the one forced exception.
${indent}   Filament calls --spacing(8) twice and --spacing(7) once, so the key must exist;
${indent}   and in Tailwind v4 its existence is what enables the dynamic p-<n> utility, so
${indent}   p-5 = 20px compiles whatever value this holds. Four values were measured and all
${indent}   four generate it; omitting the key fails the build. Enforced instead by a
${indent}   compiled-CSS assertion with a hard count of the vendor's three call sites. */
${line('--spacing', `var(--space-${spacing[0]})`)}
}

/*
 * The token palette, as REFERENCE entries so nothing is emitted: generated/tokens.css already
 * declares every one of these, and a plain @theme here would be a second declaration of it —
 * the §6.2 defect — as well as self-referential.
 */
@theme reference {
${tokenColours.join('\n')}
}
`
}

/**
 * ================================================================================
 * THE FILAMENT COLOUR FAMILIES — `SR-4`, RULED 2026-11-01
 * ================================================================================
 *
 * `->colors()` is Filament's only sanctioned colour extension point and it wants **eleven
 * shades per family for six families**. The approved palette has one brand, one signal and
 * one alert pair. Neither horn was available as stated, and the one-liner is worse than it
 * looks — measured in `vendor/`:
 *
 *   `ColorManager::getColors()` passes a STRING through `Color::generatePalette()`, and that
 *   function keeps **only the hue**. Lightness and chroma are Filament's own constants at
 *   every shade, so `brand #0B6B4F` appears at NO shade; `ink` (hue 165.122) and `brand`
 *   (166.529) are 1.4° apart and produce indistinguishable ladders; and a chroma below 0.03
 *   collapses to grey. Driven through Filament's own resolver, `->colors(['primary' =>
 *   '#0B6B4F'])` put the light background on shade **400** — a value in no approved set —
 *   and gave a dark pair of **3.43:1, which fails WCAG AA for text** and is a gate clause.
 *
 * THE RULING: a ladder DERIVED from the approved value, by the mechanism 8a's dark theme was
 * already approved under — `mix(from, to, amount%) in srgb-8bit-component-linear-round-half-up`,
 * generated, never typed.
 *
 * ONE FORMULA, TWO CAPS, AND NOTHING ELSE. The anchor shade holds the approved value
 * EXACTLY; lighter shades mix toward `paper` and darker shades toward `ink`, linearly in the
 * shade number:
 *
 *     s < anchor:  mix(base, paper, (anchor - s) / (anchor - 50)  * LIGHT_CAP)
 *     s > anchor:  mix(base, ink,   (s - anchor) / (950 - anchor) * DARK_CAP)
 *
 * The two caps are the only tuned numbers in this file and each has a reason: `LIGHT_CAP`
 * stops shade 50 reaching `paper` itself, where a tint would be indistinguishable from the
 * page; `DARK_CAP` stops shade 950 reaching `ink`, where the darkest shades would flat-line
 * into one value. Measured: with no dark cap, an `ink`-anchored ladder was IDENTICAL from 600
 * to 950 — five shades, one colour — which is how that cap was found rather than chosen.
 *
 * ⚠ AND THE ANCHOR SHADE IS PER-FAMILY, WHICH IS THE PART THAT CARRIES THE RULING. Filament
 * does the WCAG arithmetic itself: `ButtonComponentColorMap` runs `calculateContrastRatio`
 * over the whole palette at the 4.5 bar and picks bg/hover/text from candidate triplets.
 * Moving the anchor moves which shade the approved value sits on, and therefore which shade
 * the resolver reaches for — WITHOUT inventing a value, because every entry is still a mix
 * of two approved tokens. `panel-palette.test.mjs` asserts the resolver's own resolved pair
 * clears AA for every family, which is the assertion the ruling asked for.
 */

/** The shades Filament's palettes carry. Its resolver indexes these by number. */
export const FILAMENT_SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]

/** Stops shade 50 from reaching `paper`, where a tint is invisible against the page. */
export const LIGHT_CAP = 0.96

/**
 * Stops shade 950 from reaching `ink`.
 *
 * Found by measurement rather than taste: with no cap, a ladder anchored on `ink` was the
 * SAME VALUE at 600, 700, 800, 900 and 950, because mixing a token toward itself is a no-op.
 */
export const DARK_CAP = 0.8

/**
 * Which approved token each Filament family is built from, and at which shade it sits.
 *
 * ⚠ EVERY ROW IS A DESIGN DECISION WITH A MEASUREMENT, so every row carries its reason and
 * the ratio Filament's own resolver produced for it. These are not interchangeable.
 */
export const FILAMENT_PALETTE_SOURCES = {
  /**
   * The brand, at the canonical anchor. Filament's light-mode button asks for shade 600, so
   * the approved value is literally what a primary button renders. 6.50:1 in both modes.
   */
  primary: {
    token: 'brand',
    anchor: 600,
    why: 'the approved brand, on the shade a primary button uses',
  },

  /**
   * ⚠ `alert-ink` AND NOT `alert-fill`, which is the ruling's own instruction and which 8a's
   * `FILL_PAIRS` had already decided: *"danger button label on its fill"* is `surface` on
   * **`alert-ink`**, and `alert-fill` is marked `large-text` — *"alert-fill carries TEXT only
   * at >= 24px — the approval's own bound, stated so a future use cannot rediscover the
   * danger button's defect"*. Anchoring `danger` on the fill would have rediscovered it:
   * measured, `alert-fill` at 600 gives a dark pair of **3.68, FAIL**, while `alert-ink`
   * gives **7.54 in both modes**. The token existed for exactly this.
   */
  danger: {
    token: 'alert-ink',
    anchor: 600,
    why: 'FILL_PAIRS names this as the danger fill, with surface as its label',
  },

  /** The signal colour, at the canonical anchor. 5.15 light / 6.12 dark. */
  success: { token: 'signal', anchor: 600, why: 'the approved signal colour' },

  /**
   * ⚠ ANCHORED AT 400, AND THAT IS THE APPROVAL BEING HONOURED RATHER THAN WORKED AROUND.
   * `alert-fill`'s own approval bounds it to text at >= 24px, and Filament's notification and
   * badge text is 13-16px — so the approved value must NOT be the shade a small label sits
   * on. At anchor 600 the resolver put white on it and measured **3.68, FAIL**; at 400 the
   * approved value stays in the ladder, the resolver reaches past it to a darker shade for
   * the fill, and the pair is **5.89 in both modes**.
   *
   * 500 was measured too and gives 4.87 — a 0.37 margin over the bar. 400 was taken for the
   * same reason the dark theme's mixes were raised twice on 2026-10-31: this project does not
   * ship a ratio that scrapes its bar.
   */
  warning: {
    token: 'alert-fill',
    anchor: 400,
    why: 'alert-fill carries small text on no shade, by its own approval',
  },

  /**
   * ⚠ THE APPROVED PALETTE HAS NO INFORMATIONAL COLOUR, so `info` is the NEUTRAL ramp — the
   * same ladder as `gray`, deliberately and identically.
   *
   * The two alternatives were both a collapse: anchoring on `brand` makes an info badge
   * indistinguishable from a primary one, and inventing a hue is the in-between-value
   * invention `§12` forbids one category over. Measured, our panels request `info` NOWHERE
   * (`->danger()`, `->success()` and `->warning()` are the three they use), so the collapse
   * has no visible consequence today. A screen that needs a distinct informational colour is
   * a PALETTE question for a human, not a value for this file to choose.
   */
  info: {
    token: 'ink-mid',
    anchor: 600,
    why: 'no approved info colour exists; informational emphasis is neutral',
  },

  /**
   * The chrome. `gray` is what Filament paints surfaces, borders and body text with, and its
   * own `@theme inline` maps `--color-gray-*` onto whatever this supplies — which is why
   * `panel-theme.css` must NOT clear the `gray` family (see (1) in this file's header).
   *
   * Anchored on `ink-mid`, the approved mid-neutral, so the ramp runs from near-`paper` at 50
   * to near-`ink` at 950 and every step is a mix of two approved tokens.
   */
  gray: {
    token: 'ink-mid',
    anchor: 600,
    why: 'the approved mid-neutral, ramped between paper and ink',
  },
}

/**
 * The eleven-shade ladder for one family.
 *
 * @param {(from: string, to: string, amount: number) => string} mix the token source's own mixer
 * @param {Record<string, string>} approved the approved tokens, by name
 * @param {{token: string, anchor: number}} source one row of FILAMENT_PALETTE_SOURCES
 * @returns {Record<number, string>} shade -> hex
 */
export function buildFilamentLadder(mix, approved, source) {
  const base = approved[source.token]

  if (typeof base !== 'string') {
    throw new Error(
      `[tokens] FILAMENT_PALETTE_SOURCES names '${source.token}', which is not an approved ` +
        'token. Every shade must be a mix of APPROVED values -- a derived role would be a mix ' +
        'of a mix, and an unknown name would silently produce no ladder at all.',
    )
  }

  const first = FILAMENT_SHADES[0]
  const last = FILAMENT_SHADES[FILAMENT_SHADES.length - 1]
  const ladder = {}

  for (const shade of FILAMENT_SHADES) {
    if (shade === source.anchor) {
      ladder[shade] = base
      continue
    }

    ladder[shade] =
      shade < source.anchor
        ? mix(base, approved.paper, ((source.anchor - shade) / (source.anchor - first)) * LIGHT_CAP)
        : mix(base, approved.ink, ((shade - source.anchor) / (last - source.anchor)) * DARK_CAP)
  }

  return ladder
}

/**
 * Every family's ladder.
 *
 * @param {(from: string, to: string, amount: number) => string} mix
 * @param {Record<string, string>} approved
 * @returns {Record<string, Record<number, string>>}
 */
export function buildFilamentPalette(mix, approved) {
  const palette = {}

  for (const [family, source] of Object.entries(FILAMENT_PALETTE_SOURCES)) {
    palette[family] = buildFilamentLadder(mix, approved, source)
  }

  return palette
}
