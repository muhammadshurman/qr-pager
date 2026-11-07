#!/usr/bin/env node
/**
 * ================================================================================
 * THE GENERATOR. ONE SOURCE IN, THREE ARTEFACTS OUT, AND IT CAN CHECK ITSELF.
 * ================================================================================
 *
 *   packages/tokens/generated/tokens.css   CSS custom properties, light + dark
 *   packages/tokens/generated/tokens.ts    the TypeScript values and their types
 *   apps/api/config/design-tokens.php      the PHP config Filament consumes in 8b (D5)
 *
 * `--check` compares instead of writing, and exits 1 on a difference. That is the same
 * mechanism `bin/openapi-check` and `bin/landing-schema-check` already established in this
 * repo, and for the reason those headers give in the same words: the artefact is GENERATED,
 * so the only way it can be wrong is by being STALE, and a stale generated file is the
 * quietest kind of wrong -- nothing fails and the file looks plausible. This is the step
 * that makes it loud.
 *
 * ⚠ THE COMPARISON LIVES HERE, beside the writer, so the two cannot disagree about
 * formatting and report a stale file forever. A separate checker that re-implemented the
 * encoding would be a second source for the ENCODING -- the same defect one level up.
 *
 * D5 asks for both a CSS-vars file and a PHP config because CSS vars alone would leave
 * panel code hand-writing the values it needs, which is the second source `§6.2` exists to
 * prevent.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  COLOUR_ROLES,
  FILL_PAIRS,
  FOREGROUND_ROLES,
  ELEVATION,
  FOCUS,
  FONTS,
  MIX_COLOUR_SPACE,
  BARS,
  HEADROOM,
  MOTION,
  ORDER_READY,
  RADIUS,
  SPACING,
  TARGET_MIN_PX,
  THEMES,
  TYPE_SCALE,
  colourProperty,
  contrastFailures,
  contrastPairs,
  describeRule,
  orderReadyPairs,
  protectedProperties,
  resolveColour,
  resolveOrderReady,
} from '../src/source.ts'

// Task 8b, ADDITIVE: the Filament panels' own theme. A sibling of generated/theme.css,
// never a change to it -- see that module's header and QRPAGER-23 §0 SR-1.
import { buildPanelTheme, buildFilamentPalette, FILAMENT_PALETTE_SOURCES } from './panel-theme.mjs'

// Task 8b, ADDITIVE, and SEPARATE import statements on purpose: SR-4's ladders are mixes of
// APPROVED tokens, so the builder needs the approved values and the token source's own mixer.
// Declared here rather than merged into the import block above because `task-8b-precoding`
// may not edit a line `a1f1263..d8c9652` changed (PLAN.md §0.0), and that block is one of them.
import { mix } from '../src/color.ts'
import { APPROVED } from '../src/source.ts'

const PKG = join(fileURLToPath(new URL('..', import.meta.url)))
const REPO = join(PKG, '..', '..')

const BANNER_LINES = [
  'GENERATED FILE -- DO NOT EDIT.',
  '',
  'Emitted from packages/tokens/src/source.ts by packages/tokens/scripts/generate.mjs.',
  'Run `pnpm tokens:generate` to refresh it. `pnpm tokens:check` byte-diffs this file',
  'against what the source produces right now and fails the gate when they differ.',
  '',
  'Editing this file by hand produces a value that disagrees with the single source and',
  'is reverted by the next generate -- which is the second-source defect §6.2 forbids.',
]

const roles = Object.keys(COLOUR_ROLES.light)

// ---------------------------------------------------------------------------------- CSS

function cssBlock(theme, indent = '  ') {
  const lines = []
  for (const role of roles) {
    const value = resolveColour(theme, role)
    lines.push(`${indent}${colourProperty(role)}: ${value}; /* ${describeRule(theme, role)} */`)
  }
  return lines.join('\n')
}

/**
 * The order-ready screen's three colours, emitted EXACTLY ONCE and outside both theme
 * blocks. That single emission is the mechanism D4 asks for: there is no second declaration
 * that could differ between themes, so "identical in light and dark" is a property of this
 * file's shape rather than a coincidence between two values.
 */
function orderReadyBlock(indent = '  ') {
  const lines = []
  for (const name of Object.keys(ORDER_READY)) {
    lines.push(
      `${indent}--${name}: ${resolveOrderReady(name)}; /* = approved '${ORDER_READY[name]}', OUTSIDE THEMING (D4) */`,
    )
  }
  return lines.join('\n')
}

function scaleBlock(indent = '  ') {
  const lines = []
  lines.push(`${indent}/* Spacing. A component names a STEP; the number lives in the source. */`)
  for (const n of SPACING) lines.push(`${indent}--space-${n}: ${n / 16}rem;`)
  lines.push('')
  lines.push(`${indent}/* Radius. */`)
  for (const n of RADIUS) lines.push(`${indent}--radius-${n}: ${n / 16}rem;`)
  lines.push('')
  lines.push(`${indent}/* Type scale -- one ratio, and deliberately no sizes between these. */`)
  for (const n of TYPE_SCALE) lines.push(`${indent}--size-${n}: ${n / 16}rem;`)
  lines.push('')
  lines.push(`${indent}/* Motion. */`)
  lines.push(`${indent}--motion-state: ${MOTION.state};`)
  lines.push(`${indent}--motion-entrance: ${MOTION.entrance};`)
  lines.push(`${indent}--motion-easing: ${MOTION.easing};`)
  lines.push('')
  lines.push(`${indent}/* Focus -- never removed. */`)
  lines.push(`${indent}--focus-width: ${FOCUS.width};`)
  lines.push(`${indent}--focus-offset: ${FOCUS.offset};`)
  lines.push('')
  lines.push(`${indent}/* One elevation. 1px and 2px are device pixels, not spacing steps. */`)
  lines.push(`${indent}--elevation-card: ${ELEVATION.card};`)
  lines.push('')
  lines.push(`${indent}/* The minimum touch target. Measured in a real browser by Playwright. */`)
  lines.push(`${indent}--target-min: ${TARGET_MIN_PX}px;`)
  lines.push('')
  lines.push(`${indent}/* Self-hosted, subset, no CDN request anywhere -- §12's cold-3G bar. */`)
  lines.push(`${indent}--font-latin: '${FONTS.latin.family}', ${FONTS.fallback};`)
  lines.push(`${indent}--font-arabic: '${FONTS.arabic.family}', ${FONTS.fallback};`)
  return lines.join('\n')
}

export function buildCss() {
  const banner = BANNER_LINES.map((l) => (l === '' ? ' *' : ` * ${l}`)).join('\n')
  const ratios = orderReadyPairs()
    .map((p) => ` *   ${p.fg} on ${p.bg} = ${p.ratio.toFixed(2)}:1  (${p.what})`)
    .join('\n')

  return `/*
${banner}
 *
 * THE ORDER-READY SCREEN IS OUTSIDE THEMING (D4). These three properties are declared
 * ONCE, below, outside both theme blocks -- so there is no second declaration that could
 * differ, and no tenant theme token can produce any of their names:
 *
 *   ${protectedProperties().join('  ')}
 *
 * Their ratios, computed from the source by WCAG 2.1 relative luminance and FLOORED at two
 * decimals -- arithmetic, not a browser measurement, which is only possible because D4
 * fixes the pair. The full table for every other pair is generated/contrast.md:
 *
${ratios}
 *
 * Every dark value is a mix of two APPROVED tokens in ${MIX_COLOUR_SPACE}.
 */

:root {
  color-scheme: light;

  /* Outside theming (D4). Declared here and in no theme block. */
${orderReadyBlock()}

${cssBlock('light')}

${scaleBlock()}
}

/*
 * Dark is an ATTRIBUTE, not a media query alone, so a tenant's staff can be given a choice
 * later without this file changing. The media query below opts a visitor in by preference
 * unless the attribute has already decided.
 */
[data-theme='dark'] {
  color-scheme: dark;

${cssBlock('dark')}
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme='light']) {
    color-scheme: dark;

${cssBlock('dark', '    ')}
  }
}

/*
 * prefers-reduced-motion means NO motion, not less motion (QRPAGER-15 §6, trap 3). The
 * value is literally ${MOTION.reduced}, so the browser check asserts a resolved duration of
 * exactly 0 rather than "something small".
 */
@media (prefers-reduced-motion: reduce) {
  :root {
    --motion-state: ${MOTION.reduced};
    --motion-entrance: ${MOTION.reduced};
  }
}
`
}

// ------------------------------------------------------------------ Tailwind's own theme

/**
 * ================================================================================
 * THE @theme BLOCK. IT REPLACES TAILWIND'S DEFAULT SCALES RATHER THAN SITTING BESIDE THEM.
 * ================================================================================
 *
 * ⚠ CLAUSE 1 OF THE NAMED INVARIANT FELL TWICE IN THIS TASK, so `QRPAGER-15 §8` requires a
 * redesign and not a third patch: *"if a clause of the named invariant falls a second time,
 * the second fix is not a patch on the state that was found -- it is a redesign that makes
 * the whole shape impossible."*
 *
 * The two falls: `padding: var(--space-16) 9px` passed because the value check early-returned
 * on "contains a `var()`"; and then `bg-red-500 p-4 text-sm` passed `lint:es` AND
 * `lint:tokens`, because the lint rule only ever knew about Tailwind's square-bracket escape
 * hatch (`text-[#fff]`, `p-[13px]`) and cannot see the DEFAULT PALETTE AND SCALES -- which
 * `@import 'tailwindcss'` leaves entirely reachable, including `--text-sm` at 14px, a size
 * that is not on the approved scale at all.
 *
 * A pattern that rejects default-theme-backed utilities would be the third patch. It would
 * also be a SECOND SOURCE for Tailwind's default theme: a list of every colour, spacing step
 * and type size Tailwind ships, maintained by hand, going stale at the next minor version.
 *
 * SO THE SHAPE IS REMOVED INSTEAD. Each namespace is cleared with `--<namespace>-*: initial`
 * and repopulated from the token source, so `bg-red-500`, `p-5` and `text-sm` COMPILE TO
 * NOTHING -- there is no utility to write. Measured against tailwindcss 4.3.3 in
 * `tools/design-tokens/test/theme.test.mjs`, which is the proof `§6` trap 1 asks for.
 *
 * `@theme reference`, not plain `@theme`, and the modifier is load-bearing: plain `@theme`
 * emits every variable into `:root`, which would be a SECOND DECLARATION of
 * `--color-paper` beside `generated/tokens.css`'s -- the `§6.2` defect this whole package
 * exists to prevent, and it would also break the dark theme, because a `:root` declaration
 * emitted by Tailwind would not follow `[data-theme='dark']`. Under `reference` nothing is
 * emitted and each utility resolves `var(--color-paper, var(--color-paper))`, so the value
 * comes from `tokens.css` and the theme attribute still decides it.
 *
 * Every entry is therefore `var(<the token property>)` and NO LITERAL APPEARS IN THIS FILE.
 *
 * WHAT IS DELIBERATELY NOT CLEARED: breakpoints, containers, animations and easings.
 * Those are layout structure rather than design-token values, `§12` names colour, spacing
 * and font size, and `md:` is not a hard-coded hex.
 *
 * WHAT IS DELIBERATELY NOT ADDED: the three `--ready-*` properties. The order-ready screen
 * is authored in `kit.css` and takes no utility, so giving it a `bg-ready-field` would widen
 * the surface `D4` exists to keep narrow for no gain.
 */
export function buildThemeCss() {
  const banner = BANNER_LINES.map((l) => (l === '' ? ' *' : ` * ${l}`)).join('\n')
  const roles = Object.keys(COLOUR_ROLES.light)

  const entries = (comment, pairs) =>
    [`  /* ${comment} */`, ...pairs.map(([k, v]) => `  ${k}: var(${v});`)].join('\n')

  return `/*
${banner}
 *
 * TAILWIND'S THEME, REPLACED. Each namespace is cleared and repopulated from the token
 * source, so a default-theme utility -- bg-red-500, p-5, text-sm -- compiles to NOTHING.
 * There is no utility to write, which is why this is a redesign and not a lint pattern.
 *
 * \`reference\`: no variable is emitted here, so this is not a second declaration of any
 * property generated/tokens.css already declares, and [data-theme='dark'] still decides
 * the value. Every entry below is a var() reference; no literal appears in this file.
 */

@theme reference {
  /*
   * The clears. \`initial\` removes a whole namespace, which is what makes the default
   * palette and the default scales cease to exist rather than merely be discouraged.
   *
   * \`--tracking-*\` and \`--leading-*\` are cleared and NOT repopulated: the approved set
   * carries no letter-spacing and no line-height token, so every value Tailwind ships for
   * them is a decision nobody approved. \`tracking-wide\` was the one default utility that
   * survived the first version of this block, found by the bad fixture.
   */
  --color-*: initial;
  --spacing: initial;
  --spacing-*: initial;
  --text-*: initial;
  --tracking-*: initial;
  --leading-*: initial;
  --font-*: initial;
  --radius-*: initial;
  --shadow-*: initial;
  --inset-shadow-*: initial;
  --drop-shadow-*: initial;
  --blur-*: initial;

${entries(
  'The approved palette, and nothing else. Both themes resolve through tokens.css.',
  roles.map((role) => [colourProperty(role), colourProperty(role)]),
)}

${entries(
  `The approved spacing scale: ${SPACING.join(' ')}. No in-between step exists.`,
  SPACING.map((n) => [`--spacing-${n}`, `--space-${n}`]),
)}

${entries(
  `The approved type scale: ${TYPE_SCALE.join(' ')} -- one ratio, no in-between sizes.`,
  TYPE_SCALE.map((n) => [`--text-${n}`, `--size-${n}`]),
)}

${entries(
  `The approved radii: ${RADIUS.join(' ')}.`,
  RADIUS.map((n) => [`--radius-${n}`, `--radius-${n}`]),
)}

${entries('The two self-hosted families, subset, no CDN request anywhere.', [
  ['--font-latin', '--font-latin'],
  ['--font-arabic', '--font-arabic'],
])}

${entries('One elevation, approved 2026-10-31.', [['--shadow-card', '--elevation-card']])}
}
`
}

// ----------------------------------------------------------------------------------- TS

export function buildTs() {
  const banner = BANNER_LINES.map((l) => (l === '' ? ' *' : ` * ${l}`)).join('\n')
  const theme = (t) => roles.map((r) => `  '${r}': '${resolveColour(t, r)}',`).join('\n')

  return `/*
${banner}
 */

export const THEME_COLOURS = {
${THEMES.map((t) => `  ${t}: {\n${theme(t)}\n  },`).join('\n')}
} as const

export const SPACE = {
${SPACING.map((n) => `  '${n}': '${n / 16}rem',`).join('\n')}
} as const

export const RADIUS_SCALE = {
${RADIUS.map((n) => `  '${n}': '${n / 16}rem',`).join('\n')}
} as const

export const SIZE = {
${TYPE_SCALE.map((n) => `  '${n}': '${n / 16}rem',`).join('\n')}
} as const

export const MOTION_VALUES = {
  state: '${MOTION.state}',
  entrance: '${MOTION.entrance}',
  easing: '${MOTION.easing}',
  reduced: '${MOTION.reduced}',
} as const

export const TARGET_MIN = ${TARGET_MIN_PX}

/**
 * The order-ready screen's colours. Outside theming (D4), so this map takes no theme and
 * there is no per-theme variant of it anywhere.
 */
export const ORDER_READY_COLOURS = {
${Object.keys(ORDER_READY)
  .map((n) => `  '${n}': '${resolveOrderReady(n)}',`)
  .join('\n')}
} as const

/** The CSS custom properties the order-ready screen reads, and nothing else. */
export const PROTECTED_PROPERTIES = [
${protectedProperties()
  .map((p) => `  '${p}',`)
  .join('\n')}
] as const

/** Every CSS custom property this package emits. */
export const ALL_PROPERTIES = [
${protectedProperties()
  .map((p) => `  '${p}',`)
  .join('\n')}
${roles.map((r) => `  '${colourProperty(r)}',`).join('\n')}
${SPACING.map((n) => `  '--space-${n}',`).join('\n')}
${RADIUS.map((n) => `  '--radius-${n}',`).join('\n')}
${TYPE_SCALE.map((n) => `  '--size-${n}',`).join('\n')}
  '--motion-state',
  '--motion-entrance',
  '--motion-easing',
  '--focus-width',
  '--focus-offset',
  '--elevation-card',
  '--target-min',
  '--font-latin',
  '--font-arabic',
] as const

export type ThemeName = keyof typeof THEME_COLOURS
export type ColourRoleName = keyof (typeof THEME_COLOURS)['light']
export type ProtectedProperty = (typeof PROTECTED_PROPERTIES)[number]
`
}

// ---------------------------------------------------------------------------------- PHP

export function buildPhp() {
  const banner = BANNER_LINES.map((l) => ` * ${l}`.trimEnd()).join('\n')
  // TWELVE spaces, not eight: these entries are two array levels deep and Laravel Pint's
  // `array_indentation` fixer reformats anything shallower. A generated file that the
  // formatter then rewrites can never pass its own byte-diff staleness check -- the
  // generator emits one thing, `composer pint` emits another, and `pnpm tokens:check` fails
  // forever. Measured: Pint changed exactly these lines on the first run, so the generator
  // is corrected rather than the file excluded from formatting.
  const theme = (t) =>
    roles.map((r) => `            '${r}' => '${resolveColour(t, r)}',`).join('\n')

  return `<?php

declare(strict_types=1);

/*
${banner}
 *
 * CONSUMED IN 8b (QRPAGER-23), not here. D5 asks for both this file and the CSS-vars file:
 * CSS variables alone would leave panel code hand-writing the values it needs, which is the
 * second source §6.2 exists to prevent.
 *
 * This is CONFIG, not schema. It holds colour and length literals, is identical for every
 * tenant, carries no tenant data, and is read by nothing in Task 8a but its own staleness
 * check. Task 8a touches no table, column, role or GUC (QRPAGER-15 §1).
 *
 * The order-ready roles below are OUTSIDE THEMING (D4) and take the same value in both
 * themes. No value in tenant_landing_pages.theme can produce their CSS property names --
 * App\\Domain\\Landing\\ThemeTokens::NAMES is a closed allow-list of twelve, and the three
 * protected properties are outside the image of ThemeTokens::property() over that list.
 */

return [
    'mix_colour_space' => '${MIX_COLOUR_SPACE}',

    /*
     * The order-ready screen's three colours. OUTSIDE THEMING (D4): this list takes no
     * theme, because there is no per-theme variant of it. No value in
     * tenant_landing_pages.theme can produce any of these property names.
     */
    'order_ready' => [
${Object.keys(ORDER_READY)
  .map((n) => `        '--${n}' => '${resolveOrderReady(n)}',`)
  .join('\n')}
    ],

    'protected_properties' => [
${protectedProperties()
  .map((p) => `        '${p}',`)
  .join('\n')}
    ],

    'colours' => [
${THEMES.map((t) => `        '${t}' => [\n${theme(t)}\n        ],`).join('\n')}
    ],

    /*
     * SR-4, ruled 2026-11-01: the Filament colour families, as ELEVEN-SHADE LADDERS.
     *
     * ->colors() is Filament's only sanctioned colour extension point and it wants eleven
     * shades per family. Handed a single hex it keeps ONLY THE HUE -- Color::generatePalette()
     * imposes its own lightness and chroma at every shade -- so the approved value appears
     * nowhere, two approved colours 1.4 degrees apart collapse into one family, and the
     * resolved dark pair measured 3.43:1, which fails the AA gate clause.
     *
     * So each ladder is DERIVED, by the mechanism the dark theme was already approved under:
     * one formula, two caps, the approved value held EXACTLY at its family's anchor shade, and
     * every other entry a mix of two approved tokens. The anchor is per-family and carries the
     * ruling -- danger is built from alert-ink because FILL_PAIRS names that as the
     * danger fill, and warning sits at shade 400 because alert-fill's own approval bounds
     * it to text at >= 24px. The reasons are in packages/tokens/scripts/panel-theme.mjs.
     *
     * Filament does the WCAG arithmetic itself over whatever it is given, so the assertion
     * that matters is on ITS resolved pair: panel-palette.test.mjs drives
     * ButtonComponentColorMap over every family and requires >= 4.5 in both modes.
     */
    'filament_palette' => [
${Object.entries(buildFilamentPalette(mix, APPROVED))
  .map(
    ([
      family,
      ladder,
    ]) => `        // ${family} <- ${FILAMENT_PALETTE_SOURCES[family].token} @${FILAMENT_PALETTE_SOURCES[family].anchor}: ${FILAMENT_PALETTE_SOURCES[family].why}
        '${family}' => [
${Object.entries(ladder)
  .map(
    ([shade, hex]) =>
      `            ${shade} => '${hex}',${Number(shade) === FILAMENT_PALETTE_SOURCES[family].anchor ? ' // the approved value, exactly' : ''}`,
  )
  .join('\n')}
        ],`,
  )
  .join('\n')}
    ],

    'space' => [
${SPACING.map((n) => `        ${n} => '${n / 16}rem',`).join('\n')}
    ],

    'radius' => [
${RADIUS.map((n) => `        ${n} => '${n / 16}rem',`).join('\n')}
    ],

    'size' => [
${TYPE_SCALE.map((n) => `        ${n} => '${n / 16}rem',`).join('\n')}
    ],

    'motion' => [
        'state' => '${MOTION.state}',
        'entrance' => '${MOTION.entrance}',
        'easing' => '${MOTION.easing}',
        'reduced' => '${MOTION.reduced}',
    ],

    'focus' => [
        'width' => '${FOCUS.width}',
        'offset' => '${FOCUS.offset}',
        'role' => '${FOCUS.role}',
    ],

    'elevation' => [
        'card' => '${ELEVATION.card}',
    ],

    'target_min_px' => ${TARGET_MIN_PX},

    'fonts' => [
${['latin', 'arabic']
  .map(
    (key) => `        '${key}' => [
            'family' => '${FONTS[key].family}',
            'license' => '${FONTS[key].license}',
            'subset' => '${FONTS[key].subset}',
            'files' => [
${FONTS[key].files.map((f) => `                '${f}',`).join('\n')}
            ],
        ],`,
  )
  .join('\n')}
        'fallback' => '${FONTS.fallback}',
    ],
];
`
}

/**
 * ================================================================================
 * THE PANEL PALETTE AS A CSS BLOCK, FOR THE BROWSER CHECKS — R-118, 8b's SHARE
 * ================================================================================
 *
 * Filament emits `--primary-600: oklch(…)` and the rest into the document head at render
 * time, from `AssetManager::renderStyles()`. `browser/panel.spec.ts` measures computed styles
 * on Filament's own markup, so it needs those properties — and it runs in Node, with no
 * Laravel to render them.
 *
 * So the SAME ladders that reach `design-tokens.php` also reach here, from one builder. Two
 * artefacts, one source: `pnpm tokens:check` byte-diffs both, and `PanelPaletteTest` asserts
 * the panel's own emitted block carries these same values — so the fixture cannot describe a
 * palette the panel does not serve.
 *
 * ⚠ HEX RATHER THAN oklch, AND THE EQUIVALENCE IS ASSERTED RATHER THAN ARGUED. Filament
 * converts to oklch on the way out; this file holds the approved hex, which is what the token
 * source stores. A browser resolves both to the same computed colour, and "resolves to the
 * same" is exactly the kind of claim this project does not take on trust -- so
 * `PanelPaletteTest` drives the conversion over all 66 values and compares.
 */
export function buildPanelPaletteCss() {
  const palette = buildFilamentPalette(mix, APPROVED)
  const banner = BANNER_LINES.map((l) => ` * ${l}`.trimEnd()).join('\n')

  const families = Object.entries(palette)
    .map(([family, ladder]) => {
      const source = FILAMENT_PALETTE_SOURCES[family]
      const rows = Object.entries(ladder)
        .map(
          ([shade, hex]) =>
            `  --${family}-${shade}: ${hex};${Number(shade) === source.anchor ? ' /* the approved value, exactly */' : ''}`,
        )
        .join('\n')

      return `  /* ${family} <- ${source.token} @${source.anchor}: ${source.why} */\n${rows}`
    })
    .join('\n\n')

  return `/*
${banner}
 *
 * THE FILAMENT PANEL PALETTE, for browser/panel.spec.ts. Filament emits these properties into
 * the document head at render time; a Node harness has no Laravel to do that, so the same
 * builder that writes apps/api/config/design-tokens.php writes them here too.
 *
 * SR-4: each family is an eleven-shade ladder derived from ONE approved token, held exactly at
 * its anchor shade, every other entry a mix of two approved tokens. The reasons per family are
 * in packages/tokens/scripts/panel-theme.mjs.
 */

:root {
${families}
}
`
}

// ------------------------------------------------------------------------ contrast table

/**
 * ================================================================================
 * THE CANONICAL CONTRAST TABLE. GENERATED, SO NOBODY TRANSCRIBES A RATIO AGAIN.
 * ================================================================================
 *
 * Three figures this project published were wrong, and all three were wrong the same way:
 * typed by hand, and rounded UP against a MINIMUM bar.
 *
 *     paper on ready-field    raw 15.545990…   published 15.55   floored 15.54
 *     signal on ready-field   raw  9.729662…   published  9.73   floored  9.72
 *     alert-fill on paper     raw  3.329999…   published  3.33   floored  3.32
 *
 * (The first two were also wrong in the approval comment. `signal on paper`, 1.597793…,
 * was published as 1.60 and floors to 1.59.)
 *
 * So the table is emitted rather than written: `contrast.json` is what the tests read and
 * `contrast.md` is what a human reads, and both come from `contrastPairs()`. A figure in a
 * Jira comment or in PLAN.md is now a quote from this artefact rather than from anybody's
 * arithmetic — and `tokens:check` fails if the artefact and the source disagree.
 */
export function buildContrastJson() {
  const rows = contrastPairs()
  const ready = orderReadyPairs()
  const failures = contrastFailures()

  return `${JSON.stringify(
    {
      $generated: 'packages/tokens/scripts/generate.mjs from packages/tokens/src/source.ts',
      $doNotEdit:
        'Run `pnpm tokens:generate`. `pnpm tokens:check` byte-diffs this file against the source.',
      convention: {
        formula: 'WCAG 2.1 relative luminance',
        rounding:
          'TRUNCATED at two decimals (floored), never rounded up: every threshold is a MINIMUM, so flooring can only understate compliance and rounding up can overstate it',
        colourSpace: MIX_COLOUR_SPACE,
      },
      bars: BARS,
      headroom: HEADROOM,
      headroomReason:
        'a value clearing a minimum by 0.01 moves with the mix implementation, the rounding convention and the transfer function precision -- every derived role is solved against bar + headroom',
      orderReady: {
        note: 'outside theming (D4): these take no theme, so there is one set of figures and not two',
        pairs: ready,
      },
      pairs: rows,
      failures,
      summary: {
        pairs: rows.length,
        orderReadyPairs: ready.length,
        failures: failures.length,
        themes: THEMES,
      },
    },
    null,
    2,
  )}\n`
}

export function buildContrastMarkdown() {
  const rows = contrastPairs()
  const ready = orderReadyPairs()
  const failures = contrastFailures()

  const table = (subset) =>
    [
      '| fg | bg | fg hex | bg hex | ratio | bar | target | margin over bar | verdict |',
      '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
      ...subset.map((r) => {
        const bar = r.bar === null ? '—' : r.bar.toFixed(1)
        const target = r.target === null ? '—' : r.target.toFixed(2)
        const margin =
          r.marginOverBar === null
            ? '—'
            : `${r.marginOverBar >= 0 ? '+' : ''}${r.marginOverBar.toFixed(2)}`
        const verdict = r.bar === null ? 'no bar — decorative' : r.pass ? '✅' : '❌ FAILS'
        const note = r.rendered ? '' : ' *(not rendered — bound stated only)*'
        return `| \`${r.fg}\` | \`${r.bg}\` | \`${r.fgHex}\` | \`${r.bgHex}\` | **${r.ratio.toFixed(2)}** | ${bar} | ${target} | ${margin} | ${verdict}${note} |`
      }),
    ].join('\n')

  return `<!--
GENERATED FILE -- DO NOT EDIT.

Emitted from packages/tokens/src/source.ts by packages/tokens/scripts/generate.mjs.
\`pnpm tokens:check\` byte-diffs it against what the source produces right now.

Quote THIS FILE rather than re-deriving a figure. Three published figures were wrong
because they were typed by hand and rounded up against a minimum bar.
-->

# Contrast — the canonical table

| | |
| --- | --- |
| Formula | WCAG 2.1 relative luminance |
| Rounding | **truncated at two decimals (floored), never rounded up** |
| Why | every threshold is a MINIMUM, so flooring can only ever understate compliance; rounding up can overstate it, and a raw 4.4996 presented as \`4.50\` against a 4.5 bar reads as a pass and is not one |
| Mix colour space | \`${MIX_COLOUR_SPACE}\` |
| Bars | AAA text ${BARS.aaaText.toFixed(1)} · AA text ${BARS.aaText.toFixed(1)} · AA large text and non-text ${BARS.aaLarge.toFixed(1)} |
| Headroom | **+${HEADROOM.toFixed(2)}** over the bar. A value clearing a minimum by 0.01 moves with the mix implementation and the rounding convention, so every derived role is solved against \`bar + headroom\` |
| Pairs | **${String(rows.length)}** across ${String(THEMES.length)} themes, plus ${String(ready.length)} order-ready |
| Failures | **${String(failures.length)}** |

## The order-ready screen — outside theming (D4)

One set of figures and not two: these resolve with no theme argument, so there is no second
declaration that could differ.

| fg | bg | fg hex | bg hex | ratio | bar | target | verdict | what |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
${ready
  .map(
    (p) =>
      `| \`${p.fg}\` | \`${p.bg}\` | \`${p.fgHex}\` | \`${p.bgHex}\` | **${p.ratio.toFixed(2)}** | ${p.bar.toFixed(1)} | ${p.target.toFixed(2)} | ${p.pass ? '✅' : '❌ FAILS'} | ${p.what} |`,
  )
  .join('\n')}

## Light

${table(rows.filter((r) => r.theme === 'light'))}

## Dark

${table(rows.filter((r) => r.theme === 'dark'))}

## What each foreground role is

${Object.entries(FOREGROUND_ROLES)
  .map(
    ([role, spec]) => `- \`${role}\` — ${spec.what}${spec.kind === null ? '' : ` (${spec.kind})`}`,
  )
  .join('\n')}

### Text on a fill

A role-against-background table has no row for this shape, and that is where the danger
button's defect hid: its label is \`--size-16\`, so its bar is AA's ${BARS.aaText.toFixed(1)} and not large
text's ${BARS.aaLarge.toFixed(1)}. \`surface\` on \`alert-fill\` is 3.68 — a pass for a 24px label and a failure
for this one — so the button's fill is \`alert-ink\`. Both tokens are approved; only the
pairing changed, and no new value was invented.

${FILL_PAIRS.map((p) => `- \`${p.fg}\` on \`${p.bg}\` — ${p.what}`).join('\n')}
`
}

// ------------------------------------------------------------- the panel theme (Task 8b)

/**
 * ADDITIVE. Hands the token source's own exports to `panel-theme.mjs`, so neither this file
 * nor that one carries a second copy of any scale.
 */
export function buildPanelThemeCss() {
  return buildPanelTheme({
    colourRoles: COLOUR_ROLES,
    spacing: SPACING,
    radius: RADIUS,
    typeScale: TYPE_SCALE,
    banner: BANNER_LINES.map((l) => (l === '' ? ' *' : ` * ${l}`)).join('\n'),
  })
}

// ------------------------------------------------------------------ the PWA icon (Task 9)

/**
 * ================================================================================
 * THE CUSTOMER PWA's HOME-SCREEN ICON, GENERATED. Task 9, ADDITIVE.
 * ================================================================================
 *
 * WHY IT IS GENERATED AND NOT DRAWN. An icon is a design artefact carrying two approved
 * colours, and `§12` is *"No hard-coded hex, spacing or font size in any component"*. An SVG
 * with typed hexes would also escape every control this repository has: `lint:tokens` scans
 * `.css` and the ESLint rule scans TypeScript, so nothing reads an `.svg` at all.
 *
 * ⚠ AND THAT GAP IS NOT HYPOTHETICAL -- IT IS IN THE TREE. `apps/web/public/harness-icon.svg`
 * (QRPAGER-24) carries `fill="#0b6b4f"` and `fill="#f7faf8"`, and the second is NOT an
 * approved value: the approved `paper` is `#F2F4F1` and `surface` is `#FFFFFF`. Nothing
 * caught it because nothing looks in there. The harness is deliberately unstyled so that is
 * not a defect of this task to fix, but it is exactly why this icon is emitted rather than
 * typed. Recorded as a §5 row with an owner.
 *
 * THE PAIR IS THE ORDER-READY PAIR, not the brand on paper, and that is deliberate: the icon
 * a customer taps on their Home Screen should look like the screen it leads to. It is fixed
 * by D4 in the token source and takes no theme, so the icon has one form in both themes --
 * which is also what a Home-Screen icon has to be, since the OS renders it with no page
 * around it.
 */
function buildPagerIconSvg() {
  const field = resolveOrderReady('ready-field')
  const signal = resolveOrderReady('ready-signal')

  // The three finder squares of a QR code, which is what a customer is about to scan or has
  // just scanned. Geometry only -- the viewBox is a coordinate system and not a magnitude,
  // so there is no spacing token to reference and none is wanted.
  return [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<!-- GENERATED FILE -- DO NOT EDIT. Emitted from packages/tokens/src/source.ts by',
    '     packages/tokens/scripts/generate.mjs. Run `pnpm tokens:generate` to refresh it;',
    '     `pnpm tokens:check` byte-diffs it and fails the gate when it is stale. -->',
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="Your order">',
    '  <title>Your order</title>',
    `  <rect width="64" height="64" rx="12" fill="${field}" />`,
    `  <path fill="${signal}" d="M12 12h16v16H12zm3 3v10h10V15z" />`,
    `  <path fill="${signal}" d="M36 12h16v16H36zm3 3v10h10V15z" />`,
    `  <path fill="${signal}" d="M12 36h16v16H12zm3 3v10h10V39z" />`,
    `  <rect x="38" y="38" width="6" height="6" fill="${signal}" />`,
    `  <rect x="46" y="46" width="6" height="6" fill="${signal}" />`,
    '</svg>',
    '',
  ].join('\n')
}

// --------------------------------------------------------------------------------- main

/** The three artefacts: where each goes and what produces it. */
export const ARTEFACTS = [
  { path: join(PKG, 'generated', 'tokens.css'), build: buildCss, what: 'CSS custom properties' },
  {
    path: join(PKG, 'generated', 'theme.css'),
    build: buildThemeCss,
    what: "Tailwind's theme, REPLACED by the token set so the default scales cease to exist",
  },
  {
    path: join(PKG, 'generated', 'tokens.ts'),
    build: buildTs,
    what: 'TypeScript values and types',
  },
  {
    path: join(REPO, 'apps', 'api', 'config', 'design-tokens.php'),
    build: buildPhp,
    what: 'the PHP config Filament consumes in 8b (D5)',
  },
  {
    path: join(PKG, 'generated', 'contrast.json'),
    build: buildContrastJson,
    what: 'the canonical contrast table the tests read',
  },
  {
    path: join(PKG, 'generated', 'contrast.md'),
    build: buildContrastMarkdown,
    what: 'the canonical contrast table a human reads',
  },
  /**
   * ⚠ THE TICKET'S COPY, AND IT WAS AN UNCHECKED ONE. `docs/tickets/QRPAGER-15-contrast-table.md`
   * carried the banner *"GENERATED FILE -- DO NOT EDIT"* and the sentence *"`pnpm tokens:check`
   * byte-diffs it against what the source produces right now"*, and it was in no array any
   * checker walked -- so a figure edited there, a planted 19.99 AAA row for instance, passed
   * `lint`, `tokens:check`, every Vitest case and every Playwright case.
   *
   * It is the SAME bytes as `generated/contrast.md` by construction rather than by
   * agreement: one `build`, two paths. A reader finds the table where the ticket's evidence
   * list points, and the gate proves it is the table.
   */
  {
    path: join(REPO, 'docs', 'tickets', 'QRPAGER-15-contrast-table.md'),
    build: buildContrastMarkdown,
    what: "the ticket's evidence copy of the same table -- one build, two paths",
  },
  /**
   * Task 8b, ADDITIVE. The Filament panels cannot use generated/theme.css: Filament's own
   * stylesheet CONSUMES the default theme that file clears, so clearing it removes the
   * framework's CSS (QRPAGER-23 §0 SR-1, measured three ways). This artefact REDEFINES
   * instead of clearing. A new entry rather than an edit, which is PLAN §0.0's condition.
   */
  {
    // R-118, 8b's share: the palette as a CSS block, so the browser checks can measure the
    // panel's own colours without a Laravel render. Same builder as the PHP config.
    path: join(REPO, 'browser', 'fixtures', 'panel-palette.css'),
    build: buildPanelPaletteCss,
    what: 'the panel palette, for the browser checks',
  },
  {
    path: join(PKG, 'generated', 'panel-theme.css'),
    build: buildPanelThemeCss,
    what: "the Filament panels' Tailwind theme -- redefines rather than clears",
  },
  /**
   * Task 9, ADDITIVE. The customer PWA's Home-Screen icon, so the one design asset this
   * task ships is a generated output of the token source like every other one.
   */
  {
    path: join(REPO, 'apps', 'web', 'public', 'pager-icon.svg'),
    build: buildPagerIconSvg,
    what: "the customer PWA's Home-Screen icon (Task 9)",
  },
]

/**
 * Generate, or `--check`, and RETURN the exit status rather than calling `process.exit`.
 *
 * Returning the status is what lets a test drive both modes in-process -- which is the only
 * way they count towards `§6`'s coverage clause, since a spawned process is instrumented by
 * nothing. The CLI at the bottom of this file is what turns the status into an exit code.
 */
export function run({ check = false } = {}) {
  const stale = []

  for (const artefact of ARTEFACTS) {
    const expected = artefact.build()
    const rel = relative(REPO, artefact.path)

    if (check) {
      /** @type {string | null} */
      let actual
      try {
        actual = readFileSync(artefact.path, 'utf8')
      } catch {
        // A missing file is staleness, not an error to propagate: a fresh clone of a branch
        // that forgot to commit the artefact looks exactly like this.
        actual = null
      }
      if (actual === null) {
        stale.push({ rel, reason: 'missing' })
      } else if (actual !== expected) {
        // Name the first differing line, so a failure says WHERE rather than that
        // something differs somewhere in a 200-line file.
        const a = actual.split('\n')
        const e = expected.split('\n')
        let line = 0
        while (line < Math.max(a.length, e.length) && a[line] === e[line]) line += 1
        stale.push({
          rel,
          reason: `differs at line ${line + 1}`,
          actual: a[line] ?? '(end of file)',
          expected: e[line] ?? '(end of file)',
        })
      } else {
        console.log(`tokens: ${rel} is current`)
      }
    } else {
      mkdirSync(dirname(artefact.path), { recursive: true })
      writeFileSync(artefact.path, expected, 'utf8')
      console.log(`tokens: wrote ${rel}  (${artefact.what})`)
    }
  }

  if (check) {
    if (stale.length === 0) {
      console.log(`tokens: all ${ARTEFACTS.length} generated artefacts are current.`)
      return 0
    }
    console.error('\ntokens: STALE generated artefact(s). Run `pnpm tokens:generate`.\n')
    for (const s of stale) {
      console.error(`  ${s.rel}: ${s.reason}`)
      if (s.expected !== undefined) {
        console.error(`    source produces: ${String(s.expected).trim()}`)
        console.error(`    file contains  : ${String(s.actual).trim()}`)
      }
    }
    return 1
  }
  return 0
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(run({ check: process.argv.includes('--check') }))
}
