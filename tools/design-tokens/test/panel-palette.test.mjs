import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import {
  DARK_CAP,
  FILAMENT_PALETTE_SOURCES,
  FILAMENT_SHADES,
  LIGHT_CAP,
  buildFilamentLadder,
  buildFilamentPalette,
} from '../../../packages/tokens/scripts/panel-theme.mjs'
import { contrast, mix } from '../../../packages/tokens/src/color.ts'
import { APPROVED } from '../../../packages/tokens/src/source.ts'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * THE FILAMENT LADDERS — SR-4's RULING, AND EVERY PROPERTY IT RESTS ON
 * ================================================================================
 *
 * `->colors()` wants eleven shades per family for six families, and the approved palette has
 * one brand, one signal and one alert pair. Handed a single hex Filament keeps only the HUE,
 * so the approved value appears at no shade and the resolved dark pair measured **3.43:1**,
 * which fails the AA gate clause. The ruling: derive the ladder, by the mechanism 8a's dark
 * theme was already approved under.
 *
 * ⚠ WHAT THIS FILE CAN AND CANNOT PROVE, stated so neither is mistaken for the other. The
 * ladders are built here, in Node, from the token source — so their SHAPE is provable here:
 * every entry a mix of two approved tokens, the approved value held exactly at its anchor,
 * monotone lightness, and both caps load-bearing. **The assertion the ruling actually asked
 * for — "assert the resulting pair" — is Filament's own resolver's verdict, and that resolver
 * is PHP.** `PanelPaletteTest` drives `ButtonComponentColorMap` over every registered family
 * and requires >= 4.5 in both modes. Neither half is the other, and a reader of one should
 * know the other exists.
 */

const palette = buildFilamentPalette(mix, APPROVED)
const luminance = (hex) => contrast(hex, '#000000')

describe('the Filament colour ladders', () => {
  it('builds exactly the six families Filament defaults, and no others', () => {
    // `ColorManager::DEFAULT_COLORS` is danger, gray, info, primary, success, warning. A
    // family we did not supply keeps Filament's own default -- amber, red, blue -- which is
    // the panel looking restyled while carrying none of the approved values.
    expect(Object.keys(palette).sort()).toEqual([
      'danger',
      'gray',
      'info',
      'primary',
      'success',
      'warning',
    ])

    for (const ladder of Object.values(palette)) {
      expect(Object.keys(ladder).map(Number)).toEqual([...FILAMENT_SHADES])
    }
  })

  it('holds the approved value EXACTLY at each family’s anchor shade', () => {
    // THE PROPERTY THE WHOLE RULING TURNS ON. The one-liner's defect is that the approved
    // value appears nowhere; if this case ever passes vacuously the ladder has become
    // decoration.
    for (const [family, source] of Object.entries(FILAMENT_PALETTE_SOURCES)) {
      const approved = APPROVED[source.token]

      expect(
        approved,
        `${family} names '${source.token}', which is not an approved token`,
      ).toBeTypeOf('string')
      expect(palette[family][source.anchor]).toBe(approved)
    }
  })

  it('names only approved tokens as sources, so no shade is a mix of a mix', () => {
    // A derived ROLE -- `border-strong`, say -- is itself a mix, and anchoring on one would
    // put a mix of a mix in the panel with no single approved value behind it. Measured
    // while choosing `info`: `border-strong` is NOT in APPROVED and the builder threw,
    // which is how that boundary was found rather than assumed.
    for (const [family, source] of Object.entries(FILAMENT_PALETTE_SOURCES)) {
      expect(Object.keys(APPROVED)).toContain(source.token)
      expect(source.why.length).toBeGreaterThan(20)
      expect(FILAMENT_SHADES).toContain(source.anchor)
      expect(family.length).toBeGreaterThan(2)
    }

    expect(() =>
      buildFilamentLadder(mix, APPROVED, { token: 'border-strong', anchor: 600 }),
    ).toThrow(/not an approved token/)
  })

  it('darkens monotonically across all eleven shades, in every family', () => {
    // Filament's resolver walks the palette looking for a text shade >= 800 and a background
    // among 300-600. A ladder that doubled back would make those picks arbitrary.
    for (const [family, ladder] of Object.entries(palette)) {
      const lum = FILAMENT_SHADES.map((s) => luminance(ladder[s]))

      for (let i = 1; i < lum.length; i++) {
        expect(
          lum[i] <= lum[i - 1] + 1e-9,
          `${family} brightens from ${FILAMENT_SHADES[i - 1]} to ${FILAMENT_SHADES[i]}`,
        ).toBe(true)
      }
    }
  })

  it('needs BOTH caps, which is why they are not 1', () => {
    // ⚠ THE DARK CAP WAS FOUND BY MEASUREMENT, NOT TASTE. Mixing a token toward itself is a
    // no-op, so with no cap a ladder anchored on `ink` was the SAME VALUE at 600, 700, 800,
    // 900 and 950 -- five shades, one colour. Driven here rather than described.
    const uncapped = {}
    for (const shade of FILAMENT_SHADES) {
      uncapped[shade] =
        shade === 600
          ? APPROVED.ink
          : shade < 600
            ? mix(APPROVED.ink, APPROVED.paper, (600 - shade) / 550)
            : mix(APPROVED.ink, APPROVED.ink, (shade - 600) / 350)
    }

    const dark = [600, 700, 800, 900, 950].map((s) => uncapped[s])
    expect(new Set(dark).size).toBe(1)

    // And the real ladder, with the cap, separates them.
    const real = [600, 700, 800, 900, 950].map((s) => palette.gray[s])
    expect(new Set(real).size).toBe(5)

    // The light cap keeps shade 50 off the ground itself: a tint equal to `paper` is
    // invisible on the page, which is what `bg-gray-50` is used for.
    for (const [family, ladder] of Object.entries(palette)) {
      expect(ladder[50], `${family}-50 is the ground itself`).not.toBe(APPROVED.paper)
    }

    expect(LIGHT_CAP).toBeLessThan(1)
    expect(DARK_CAP).toBeLessThan(1)
  })

  it('reaches the PHP config, byte for byte, where Filament reads it', () => {
    // The ladders are built here and CONSUMED in PHP, so the generated config is the only
    // thing that matters at run time. `pnpm tokens:check` byte-diffs the whole file; this
    // asserts the specific values, so a generator that emitted a DIFFERENT ladder than this
    // module builds would be caught by the figure rather than by the diff.
    const php = read('apps/api/config/design-tokens.php')

    for (const [family, ladder] of Object.entries(palette)) {
      expect(php).toContain(`'${family}' => [`)

      for (const shade of FILAMENT_SHADES) {
        expect(php, `${family}-${shade} is not in the PHP config`).toContain(
          `${shade} => '${ladder[shade]}',`,
        )
      }
    }

    // And the anchor rows are marked, so a reader of the config can see which value is the
    // approved one without recomputing the mix.
    expect((php.match(/\/\/ the approved value, exactly/g) ?? []).length).toBe(6)
  })

  it('records danger’s source as the one FILL_PAIRS names, not the fill it looks like', () => {
    // ⚠ SR-4's RULING, pinned. `alert-fill` is the colour whose NAME says danger, and 8a's
    // FILL_PAIRS marks it `large-text` -- "alert-fill carries TEXT only at >= 24px, the
    // approval's own bound, stated so a future use cannot rediscover the danger button's
    // defect". The danger button's fill is `alert-ink`, with `surface` as its label.
    //
    // Measured through Filament's resolver: `alert-fill` at the anchor gives a dark pair of
    // 3.68 (FAIL) and `alert-ink` gives 7.54 in both modes. So this is not a preference.
    expect(FILAMENT_PALETTE_SOURCES.danger.token).toBe('alert-ink')
    expect(FILAMENT_PALETTE_SOURCES.warning.token).toBe('alert-fill')

    // And `warning`'s anchor is NOT the canonical 600, which is the approval being honoured:
    // a shade a small label sits on cannot be the value whose approval forbids small text.
    expect(FILAMENT_PALETTE_SOURCES.warning.anchor).toBe(400)
    expect(FILAMENT_PALETTE_SOURCES.primary.anchor).toBe(600)

    // FILL_PAIRS itself is the authority for that reading, parsed rather than quoted.
    const source = read('packages/tokens/src/source.ts')
    const pairs = /export const FILL_PAIRS = \[([\s\S]*?)\n\] as const/.exec(source)

    expect(pairs).not.toBeNull()
    expect(pairs[1]).toContain("bg: 'alert-ink'")
    expect(pairs[1]).toContain('danger button label on its fill')
    expect(pairs[1]).toContain("kind: 'large-text'")
  })
})
