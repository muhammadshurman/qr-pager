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

/** Which approved radius each of Filament's own radius names becomes. */
export const FILAMENT_RADII = { sm: 8, md: 8, lg: 16, xl: 24 }

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
  const radii = Object.entries(FILAMENT_RADII).map(([name, step]) =>
    line(`--radius-${name}`, `var(--radius-${step})`),
  )
  const structuralRadii = Object.entries(STRUCTURAL_RADII).map(([name, value]) =>
    line(`--radius-${name}`, value),
  )
  const tokenRadii = radius.map((n) => line(`--radius-${n}`, `var(--radius-${n})`))
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
