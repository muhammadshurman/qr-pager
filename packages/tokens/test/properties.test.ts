import { describe, expect, it } from 'vitest'

import {
  COLOUR_ROLES,
  MOTION,
  RADIUS,
  SPACING,
  TARGET_MIN_PX,
  TYPE_SCALE,
  allProperties,
  colourProperty,
  describeRule,
  protectedProperties,
  resolveTheme,
} from '../src/source.ts'

/**
 * The property namespace and the scales.
 *
 * `allProperties()` is what a consumer enumerates and what the disjointness proof in
 * packages/ui measures against, so it must be complete and must not contain a duplicate --
 * a repeated name there would make the intersection check compare the wrong sets.
 */
describe('allProperties', () => {
  const all = allProperties()

  it('lists every protected property first, so the order-ready set is never buried', () => {
    expect(all.slice(0, 3)).toEqual(protectedProperties())
  })

  it('contains no duplicate, which would corrupt the disjointness proof', () => {
    expect(new Set(all).size).toBe(all.length)
  })

  it('covers every colour role, every scale step and every single-value token', () => {
    const roles = Object.keys(COLOUR_ROLES.light)
    const expected =
      protectedProperties().length +
      roles.length +
      SPACING.length +
      RADIUS.length +
      TYPE_SCALE.length +
      // motion x3, focus x2, elevation, target, fonts x2
      9
    expect(all).toHaveLength(expected)

    for (const role of roles) expect(all).toContain(colourProperty(role))
    for (const n of SPACING) expect(all).toContain(`--space-${String(n)}`)
    for (const n of RADIUS) expect(all).toContain(`--radius-${String(n)}`)
    for (const n of TYPE_SCALE) expect(all).toContain(`--size-${String(n)}`)
    for (const name of [
      '--motion-state',
      '--motion-entrance',
      '--motion-easing',
      '--focus-width',
      '--focus-offset',
      '--elevation-card',
      '--target-min',
      '--font-latin',
      '--font-arabic',
    ]) {
      expect(all).toContain(name)
    }
  })

  it('names every property in the --token-prefix form a tenant theme cannot produce', () => {
    for (const property of all) expect(property.startsWith('--')).toBe(true)
  })
})

describe('describeRule', () => {
  it('describes an approved role as a reference to the approved token', () => {
    expect(describeRule('light', 'paper')).toBe("= approved 'paper'")
    expect(describeRule('dark', 'ink')).toBe("= approved 'paper'")
  })

  it('describes a mix with its endpoints, its percentage AND its colour space', () => {
    // The colour space is in the string because a percentage without it is not a derivation
    // anyone can reproduce -- condition 2 of the dark theme's approval.
    expect(describeRule('dark', 'surface')).toBe(
      'mix(ready-field, paper, 8%) in srgb-8bit-component-linear-round-half-up',
    )
    expect(describeRule('light', 'border-strong')).toBe(
      'mix(hairline, ink, 44%) in srgb-8bit-component-linear-round-half-up',
    )
    expect(describeRule('dark', 'ink-mid')).toBe(
      'mix(ink-mid, paper, 44%) in srgb-8bit-component-linear-round-half-up',
    )
  })

  it('describes every role in both themes without throwing', () => {
    for (const theme of ['light', 'dark'] as const) {
      for (const role of Object.keys(COLOUR_ROLES[theme])) {
        expect(describeRule(theme, role as never).length).toBeGreaterThan(5)
      }
    }
  })
})

describe('resolveTheme', () => {
  it('resolves every role in a theme to a six-digit upper-case hex', () => {
    for (const theme of ['light', 'dark'] as const) {
      const resolved = resolveTheme(theme)
      expect(Object.keys(resolved)).toEqual(Object.keys(COLOUR_ROLES[theme]))
      for (const value of Object.values(resolved)) {
        expect(value).toMatch(/^#[0-9A-F]{6}$/)
      }
    }
  })

  it('gives light and dark DIFFERENT grounds -- a theme that changes nothing is not one', () => {
    expect(resolveTheme('dark').paper).not.toBe(resolveTheme('light').paper)
  })
})

describe('the scales, as approved', () => {
  it('has the approved spacing scale and nothing between its steps', () => {
    expect([...SPACING]).toEqual([4, 8, 12, 16, 24, 32, 48, 64, 96])
  })

  it('has the approved radius and type scales', () => {
    expect([...RADIUS]).toEqual([8, 16, 24])
    expect([...TYPE_SCALE]).toEqual([13, 16, 20, 28, 44, 64, 96])
  })

  it('reduces motion to ZERO, not to something shorter', () => {
    expect(MOTION.state).toBe('160ms')
    expect(MOTION.entrance).toBe('240ms')
    // "prefers-reduced-motion means no motion, not less motion" -- §6, trap 3.
    expect(MOTION.reduced).toBe('0ms')
  })

  it('sets the minimum target at 44px', () => {
    expect(TARGET_MIN_PX).toBe(44)
  })
})
