/*
 * GENERATED FILE -- DO NOT EDIT.
 *
 * Emitted from packages/tokens/src/source.ts by packages/tokens/scripts/generate.mjs.
 * Run `pnpm tokens:generate` to refresh it. `pnpm tokens:check` byte-diffs this file
 * against what the source produces right now and fails the gate when they differ.
 *
 * Editing this file by hand produces a value that disagrees with the single source and
 * is reverted by the next generate -- which is the second-source defect §6.2 forbids.
 */

export const THEME_COLOURS = {
  light: {
  'paper': '#F2F4F1',
  'surface': '#FFFFFF',
  'surface-2': '#E7EBE6',
  'hairline': '#D3DAD2',
  'border-strong': '#7D8981',
  'ink': '#10231B',
  'ink-mid': '#5A6A62',
  'brand': '#0B6B4F',
  'signal': '#3DDC97',
  'alert-fill': '#E4572E',
  'alert-ink': '#96351A',
  },
  dark: {
  'paper': '#0A1F17',
  'surface': '#1D3028',
  'surface-2': '#2A3D36',
  'hairline': '#42524B',
  'border-strong': '#798580',
  'ink': '#F2F4F1',
  'ink-mid': '#9DA7A1',
  'brand': '#3DDC97',
  'signal': '#3DDC97',
  'alert-fill': '#E4572E',
  'alert-ink': '#E4572E',
  },
} as const

export const SPACE = {
  '4': '0.25rem',
  '8': '0.5rem',
  '12': '0.75rem',
  '16': '1rem',
  '24': '1.5rem',
  '32': '2rem',
  '48': '3rem',
  '64': '4rem',
  '96': '6rem',
} as const

export const RADIUS_SCALE = {
  '8': '0.5rem',
  '16': '1rem',
  '24': '1.5rem',
} as const

export const SIZE = {
  '13': '0.8125rem',
  '16': '1rem',
  '20': '1.25rem',
  '28': '1.75rem',
  '44': '2.75rem',
  '64': '4rem',
  '96': '6rem',
} as const

export const MOTION_VALUES = {
  state: '160ms',
  entrance: '240ms',
  easing: 'cubic-bezier(.32,.72,0,1)',
  reduced: '0ms',
} as const

export const TARGET_MIN = 44

/**
 * The order-ready screen's colours. Outside theming (D4), so this map takes no theme and
 * there is no per-theme variant of it anywhere.
 */
export const ORDER_READY_COLOURS = {
  'ready-field': '#0A1F17',
  'ready-ink': '#F2F4F1',
  'ready-signal': '#3DDC97',
} as const

/** The CSS custom properties the order-ready screen reads, and nothing else. */
export const PROTECTED_PROPERTIES = [
  '--ready-field',
  '--ready-ink',
  '--ready-signal',
] as const

/** Every CSS custom property this package emits. */
export const ALL_PROPERTIES = [
  '--ready-field',
  '--ready-ink',
  '--ready-signal',
  '--color-paper',
  '--color-surface',
  '--color-surface-2',
  '--color-hairline',
  '--color-border-strong',
  '--color-ink',
  '--color-ink-mid',
  '--color-brand',
  '--color-signal',
  '--color-alert-fill',
  '--color-alert-ink',
  '--space-4',
  '--space-8',
  '--space-12',
  '--space-16',
  '--space-24',
  '--space-32',
  '--space-48',
  '--space-64',
  '--space-96',
  '--radius-8',
  '--radius-16',
  '--radius-24',
  '--size-13',
  '--size-16',
  '--size-20',
  '--size-28',
  '--size-44',
  '--size-64',
  '--size-96',
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
