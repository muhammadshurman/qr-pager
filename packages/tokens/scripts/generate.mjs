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
  APPROVED,
  COLOUR_ROLES,
  ELEVATION,
  FOCUS,
  FONTS,
  MIX_COLOUR_SPACE,
  MOTION,
  ORDER_READY,
  ORDER_READY_PAIRS,
  RADIUS,
  SPACING,
  TARGET_MIN_PX,
  THEMES,
  TYPE_SCALE,
  colourProperty,
  describeRule,
  protectedProperties,
  resolveColour,
  resolveOrderReady,
} from '../src/source.ts'
import { ratioTo2dp } from '../src/color.ts'

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
  const ratios = ORDER_READY_PAIRS.map(
    (p) =>
      ` *   --${p.fg} on --${p.bg} = ${ratioTo2dp(resolveOrderReady(p.fg), resolveOrderReady(p.bg)).toFixed(2)}:1  (${p.what})`,
  ).join('\n')

  return `/*
${banner}
 *
 * THE ORDER-READY SCREEN IS OUTSIDE THEMING (D4). These three properties are declared
 * ONCE, below, outside both theme blocks -- so there is no second declaration that could
 * differ, and no tenant theme token can produce any of their names:
 *
 *   ${protectedProperties().join('  ')}
 *
 * Their ratios, computed from the source by WCAG 2.1 relative luminance -- arithmetic,
 * not a browser measurement, which is only possible because D4 fixes the pair:
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

// ----------------------------------------------------------------------------------- TS

export function buildTs() {
  const banner = BANNER_LINES.map((l) => (l === '' ? ' *' : ` * ${l}`)).join('\n')
  const theme = (t) =>
    roles.map((r) => `  '${r}': '${resolveColour(t, r)}',`).join('\n')

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
${Object.keys(ORDER_READY).map((n) => `  '${n}': '${resolveOrderReady(n)}',`).join('\n')}
} as const

/** The CSS custom properties the order-ready screen reads, and nothing else. */
export const PROTECTED_PROPERTIES = [
${protectedProperties().map((p) => `  '${p}',`).join('\n')}
] as const

/** Every CSS custom property this package emits. */
export const ALL_PROPERTIES = [
${protectedProperties().map((p) => `  '${p}',`).join('\n')}
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
  const theme = (t) => roles.map((r) => `        '${r}' => '${resolveColour(t, r)}',`).join('\n')

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
${Object.keys(ORDER_READY).map((n) => `        '--${n}' => '${resolveOrderReady(n)}',`).join('\n')}
    ],

    'protected_properties' => [
${protectedProperties().map((p) => `        '${p}',`).join('\n')}
    ],

    'colours' => [
${THEMES.map((t) => `        '${t}' => [\n${theme(t)}\n        ],`).join('\n')}
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
        'latin' => [
            'family' => '${FONTS.latin.family}',
            'license' => '${FONTS.latin.license}',
            'subset' => '${FONTS.latin.subset}',
            'file' => '${FONTS.latin.file}',
        ],
        'arabic' => [
            'family' => '${FONTS.arabic.family}',
            'license' => '${FONTS.arabic.license}',
            'subset' => '${FONTS.arabic.subset}',
            'file' => '${FONTS.arabic.file}',
        ],
        'fallback' => '${FONTS.fallback}',
    ],
];
`
}

// --------------------------------------------------------------------------------- main

/** The three artefacts: where each goes and what produces it. */
export const ARTEFACTS = [
  { path: join(PKG, 'generated', 'tokens.css'), build: buildCss, what: 'CSS custom properties' },
  { path: join(PKG, 'generated', 'tokens.ts'), build: buildTs, what: 'TypeScript values and types' },
  {
    path: join(REPO, 'apps', 'api', 'config', 'design-tokens.php'),
    build: buildPhp,
    what: 'the PHP config Filament consumes in 8b (D5)',
  },
]

function run() {
  const check = process.argv.includes('--check')
  const stale = []

  for (const artefact of ARTEFACTS) {
    const expected = artefact.build()
    const rel = relative(REPO, artefact.path)

    if (check) {
      let actual = null
      try {
        actual = readFileSync(artefact.path, 'utf8')
      } catch {
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
      process.exit(0)
    }
    console.error('\ntokens: STALE generated artefact(s). Run `pnpm tokens:generate`.\n')
    for (const s of stale) {
      console.error(`  ${s.rel}: ${s.reason}`)
      if (s.expected !== undefined) {
        console.error(`    source produces: ${String(s.expected).trim()}`)
        console.error(`    file contains  : ${String(s.actual).trim()}`)
      }
    }
    process.exit(1)
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) run()
