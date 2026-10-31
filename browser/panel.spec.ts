import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { expect, test, type Page } from '@playwright/test'

/**
 * ================================================================================
 * R-118's FOUR CLAUSES ON THE FILAMENT PANEL — 8b's SHARE, CLOSED HERE
 * ================================================================================
 *
 * `playwright.config.ts` states the split in its own words: *"8a's SHARE IS THE KIT. The
 * admin panel's share is 8b's (QRPAGER-23), which is the task that restyles `/platform` and
 * `/studio` from these tokens — and R-118's own resolution requires the figures be taken
 * against the TOKEN-STYLED panel, not the default one, so it cannot close here."* The panel
 * is now token-styled, `SR-4` is ruled, and the figures can be taken.
 *
 * WHAT IS UNDER TEST, and it is the real thing in all three parts:
 *
 *   - FILAMENT'S OWN MARKUP, class for class, copied from a render of
 *     `/platform/restaurants` and held to it by `PanelAccessibilityFixtureTest`;
 *   - THE COMPILED PANEL THEME, read out of `apps/api/public/build` through the manifest at
 *     run time — the same bytes a browser is served;
 *   - THE GENERATED PALETTE, from the same builder that writes `design-tokens.php`.
 *
 * ⚠ WHAT IT IS NOT, said plainly. This is the panel's CSS and markup in a real engine; it is
 * not a logged-in session driving Livewire. The four clauses are all CSS — an outline on a
 * focused element, a rendered box, a resolved colour pair, a media query — so none of them
 * needs the server. A clause that DID need a session would be a fifth clause and would still
 * be open; there is not one.
 */

const REPO = join(import.meta.dirname, '..')
const API = join(REPO, 'apps/api')

/**
 * The compiled panel theme, resolved through the build manifest.
 *
 * ⚠ THROWS RATHER THAN SKIPS. `public/build` is git-ignored, so a fresh clone has none until
 * `composer assets` runs — and a browser check that quietly skipped when the stylesheet was
 * missing would report a pass for a panel it never looked at. That is the vacuity shape this
 * project has already paid for three times in one task.
 */
function panelThemeCss(panel: 'platform' | 'studio'): string {
  const manifestPath = join(API, 'public/build/manifest.json')
  let manifest: Record<string, { file?: string }>

  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as Record<string, { file?: string }>
  } catch {
    throw new Error(
      `${manifestPath} is absent, so the compiled panel theme cannot be read. Run ` +
        '`composer assets` in apps/api (a gate step) and re-run. This check FAILS rather ' +
        'than skipping: a skipped browser check reports coverage it does not have.',
    )
  }

  const entry = manifest[`resources/css/filament/${panel}/theme.css`]?.file

  if (entry === undefined) {
    throw new Error(
      `the manifest does not name the ${panel} panel's theme. It must be in ` +
        "vite.config.js's input array; `composer assets` checks that and names the fix.",
    )
  }

  return readFileSync(join(API, 'public/build', entry), 'utf8')
}

/** The fixture with the real stylesheets substituted in — never injected afterwards. */
function fixtureHtml(panel: 'platform' | 'studio' = 'platform'): string {
  const palette = readFileSync(join(REPO, 'browser/fixtures/panel-palette.css'), 'utf8')
  const theme = panelThemeCss(panel)
  const html = readFileSync(join(REPO, 'browser/fixtures/panel.html'), 'utf8')

  if (!html.includes('<!--STYLES-->')) {
    throw new Error('browser/fixtures/panel.html has no <!--STYLES--> placeholder')
  }

  // The @font-face rules reach for woff2 files that no network here serves, and a geometry
  // or colour measurement does not need them. Dropped so nothing waits on a request.
  return html.replace(
    '<!--STYLES-->',
    `<style>${palette}</style><style>${theme.replace(/@font-face\s*\{[^}]*\}/g, '')}</style>`,
  )
}

/** sRGB relative luminance, the W3C formula, as `packages/tokens/src/color.ts` writes it. */
function luminance(rgb: [number, number, number]): number {
  const channel = (c: number) => {
    const s = c / 255

    return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
  }

  // ⚠ NOT DESTRUCTURED, AND NOT `!`-ASSERTED EITHER. `noUncheckedIndexedAccess` widens a
  // tuple index to `number | undefined`, so both forms made ESLint refuse the arithmetic --
  // and a `!` would assert away precisely what that setting exists to surface. Reduced
  // instead, which needs no assertion and no exemption.
  const weights = [0.2126, 0.7152, 0.0722]

  return rgb.reduce((total, c, i) => total + channel(c) * (weights[i] ?? 0), 0)
}

function contrast(a: [number, number, number], b: [number, number, number]): number {
  // Math.max/min rather than a sorted destructure: destructuring an ARRAY widens each
  // binding to `number | undefined`, which is the same `noUncheckedIndexedAccess` refusal
  // the luminance helper above works around, two lines later.
  const first = luminance(a)
  const second = luminance(b)

  return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05)
}

/**
 * One computed colour string as sRGB bytes, converted BY THE ENGINE.
 *
 * ⚠ THIS REPLACED A PARSER AND THE PARSER WAS WRONG IN THE MOST EXPENSIVE DIRECTION. The
 * first version read three numbers out of the string, which works for `rgb(11, 107, 79)` and
 * is nonsense for `oklch(1 0 0)` -- the form Filament actually emits for its white label. It
 * read white as `[1, 0, 0]`, computed 3.23 against the brand, and reported a panel that FAILS
 * AA. The panel was fine; the harness could not read a modern colour space.
 *
 * A false FAILURE and a false PASS come from the same defect, and this is the only place in
 * the project that can see a computed style at all -- so the conversion is handed to the
 * engine, through a canvas, which is also what decides the pixels a person sees. The harness
 * is left with the W3C luminance formula and nothing else.
 */
async function srgb(page: Page, value: string): Promise<[number, number, number]> {
  const bytes = await page.evaluate((colour) => {
    const canvas = document.createElement('canvas')
    canvas.width = 1
    canvas.height = 1

    const ctx = canvas.getContext('2d')

    if (ctx === null) {
      return null
    }

    // An opaque ground first, so a colour with alpha composites rather than reading as
    // whatever the canvas was initialised to.
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, 1, 1)
    ctx.fillStyle = colour

    // An unparseable value leaves fillStyle at the previous one, which would silently
    // measure white. Checked rather than assumed.
    const accepted = ctx.fillStyle
    ctx.fillRect(0, 0, 1, 1)

    const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data

    return { rgb: [r, g, b] as [number, number, number], accepted }
  }, value)

  if (bytes === null) {
    throw new Error('the harness could not get a 2d canvas context to convert a colour')
  }

  if (bytes.accepted === '#ffffff' && value.replace(/\s/g, '') !== 'rgb(255,255,255)') {
    throw new Error(
      `the engine did not accept "${value}" as a colour, so this measurement would have ` +
        'been taken against white. A harness that cannot read a value must not report one.',
    )
  }

  return bytes.rgb
}

/**
 * THE VACUITY GUARD, run before every measurement.
 *
 * `kit.html`'s header records why: under one load sequence four buttons resolved Chromium's
 * UA colours while every observable said the author rules should win. A harness that reports
 * a false failure can report a false pass, and these are the only checks in this project that
 * can see a computed style at all.
 */
async function assertStylesApplied(page: Page): Promise<void> {
  const applied = await page.evaluate(() => {
    const root = getComputedStyle(document.documentElement)
    const button = document.querySelector('#primary-button')

    return {
      primary600: root.getPropertyValue('--primary-600').trim(),
      buttonBackground: button === null ? null : getComputedStyle(button).backgroundColor,
    }
  })

  // The palette block reached the document. Case-insensitively: the token source stores
  // upper-case hex ("so every emitted artefact is byte-comparable") and the engine reports
  // a custom property's value as AUTHORED -- `#0B6B4F`, not the lower-case Chromium uses
  // for a resolved colour. The first version of this guard compared the two and failed on
  // the case while every value was already right.
  expect(applied.primary600.toLowerCase(), 'the generated palette did not reach the document').toBe(
    '#0b6b4f',
  )
  // ...and the panel theme is resolving it onto Filament's own class, rather than the
  // element sitting at Chromium's default button colour.
  expect(applied.buttonBackground).not.toBeNull()
  expect(
    applied.buttonBackground,
    'the primary button is at the UA default, so the panel theme did not apply',
  ).not.toBe('rgb(239, 239, 239)')
}

test.describe('R-118 clause 1 — a visible focus ring on the panel', () => {
  test('draws a ring the engine reports as non-zero', async ({ page }) => {
    await page.setContent(fixtureHtml())
    await assertStylesApplied(page)

    const before = await page.evaluate(() => {
      const el = document.querySelector<HTMLElement>('#primary-button')!
      const s = getComputedStyle(el)

      return { outlineWidth: s.outlineWidth, boxShadow: s.boxShadow }
    })

    await page.locator('#primary-button').focus()

    const after = await page.evaluate(() => {
      const el = document.querySelector<HTMLElement>('#primary-button')!
      const s = getComputedStyle(el)

      return {
        outlineWidth: s.outlineWidth,
        boxShadow: s.boxShadow,
        focused: el === document.activeElement,
      }
    })

    expect(after.focused, 'the button never took focus, so nothing below measures a ring').toBe(
      true,
    )

    // A RING IS A CHANGE, which is the only form of this assertion that cannot pass
    // vacuously: an element with a permanent 2px outline would satisfy "has an outline"
    // while being invisible as a focus indicator.
    const ring = after.outlineWidth !== before.outlineWidth || after.boxShadow !== before.boxShadow

    expect(
      ring,
      `focusing changed nothing the engine reports: outline ${before.outlineWidth} -> ` +
        `${after.outlineWidth}, shadow ${before.boxShadow} -> ${after.boxShadow}`,
    ).toBe(true)
  })
})

test.describe('R-118 clause 2 — a measured 44px minimum touch target', () => {
  test('gives every interactive element at least 44px in both directions', async ({ page }) => {
    await page.setContent(fixtureHtml())
    await assertStylesApplied(page)

    const small: string[] = []

    for (const id of ['primary-button', 'input']) {
      const box = await page.locator(`#${id}`).boundingBox()

      expect(box, `#${id} is not in the layout at all`).not.toBeNull()

      if (box !== null && (box.height < 44 || box.width < 44)) {
        small.push(`#${id} is ${String(Math.round(box.width))}x${String(Math.round(box.height))}`)
      }
    }

    // ⚠ THE ICON BUTTON IS MEASURED AT A PHONE WIDTH, AND NOT SKIPPED. It is Filament's
    // `fi-topbar-open-sidebar-btn` -- the sidebar toggle -- which the theme hides above the
    // `lg` breakpoint, so at the desktop viewport `boundingBox()` is null and the first
    // version of this case failed with "not in the layout at all". A control that only
    // exists on a phone is exactly where a 44px target matters most, so the viewport moves
    // to where it exists rather than the assertion moving out of its way.
    await page.setViewportSize({ width: 390, height: 844 })

    const toggle = await page.locator('#icon-button').boundingBox()

    expect(
      toggle,
      'the sidebar toggle is in the layout at NO width, so it was never measured',
    ).not.toBeNull()

    if (toggle !== null && (toggle.height < 44 || toggle.width < 44)) {
      small.push(
        `#icon-button is ${String(Math.round(toggle.width))}x${String(Math.round(toggle.height))} at 390px`,
      )
    }

    expect(small, 'these panel controls are under §12’s 44px minimum target').toEqual([])
  })
})

test.describe('R-118 clause 3 — the engine resolves an AA pair on the panel', () => {
  test('puts the primary button’s own colours at 4.5 or better', async ({ page }) => {
    await page.setContent(fixtureHtml())
    await assertStylesApplied(page)

    const pair = await page.evaluate(() => {
      const s = getComputedStyle(document.querySelector<HTMLElement>('#primary-button')!)

      return { background: s.backgroundColor, text: s.color }
    })

    const ratio = contrast(await srgb(page, pair.background), await srgb(page, pair.text))

    // `PanelPaletteTest` asserts this for all six families through Filament's own resolver,
    // in PHP, at the same 4.5 bar. THIS is the engine agreeing: the shades Filament chose,
    // resolved through the compiled stylesheet, measured as rendered pixels.
    expect(
      ratio,
      `the primary button resolved ${pair.text} on ${pair.background} = ${ratio.toFixed(2)}`,
    ).toBeGreaterThanOrEqual(4.5)
  })
})

test.describe('R-118 clause 4 — prefers-reduced-motion on the panel', () => {
  test('carries a reduced-motion rule in the compiled theme at all', () => {
    // The precondition, separated on purpose: a computed `0s` means nothing if the
    // stylesheet has no rule to produce it — every element whose transition was never set
    // also computes `0s`.
    const theme = panelThemeCss('platform')

    expect(theme).toContain('prefers-reduced-motion')
  })

  test('suppresses the button’s transition when the user asks for less motion', async ({
    page,
  }, testInfo) => {
    await page.setContent(fixtureHtml())
    await assertStylesApplied(page)

    const duration = await page.evaluate(
      () =>
        getComputedStyle(document.querySelector<HTMLElement>('#primary-button')!)
          .transitionDuration,
    )

    const seconds = Math.max(
      ...duration.split(',').map((d) => Number.parseFloat(d.trim().replace('s', '')) || 0),
    )

    if (testInfo.project.name === 'chromium-reduced-motion') {
      // ⚠ NOT `toBe(0)`, AND THE REASON IS IN THE VENDOR'S OWN CSS. Measured in the compiled
      // theme: `@media (prefers-reduced-motion:reduce){*,:before,:after{transition-duration:
      // .01ms!important;animation-duration:.01ms!important}}`. `.01ms` is 1e-05s, which is
      // what the engine reported and what the first version of this assertion rejected.
      //
      // A near-zero rather than zero is deliberate upstream: a duration of exactly 0 does not
      // fire `transitionend`, so JS waiting on that event would hang. The bar is therefore
      // IMPERCEPTIBLE and not literally zero -- 10ms, three orders of magnitude above what
      // the vendor sets and far below anything a person can see.
      expect(
        seconds,
        `the button still animates for ${duration} under reduced motion`,
      ).toBeLessThanOrEqual(0.01)

      return
    }

    // THE POSITIVE CONTROL, and without it the clause above is satisfied by a panel that
    // never animates anything: the default project must show a NON-zero duration, or
    // "reduced motion removed it" is true of nothing.
    expect(
      seconds,
      'the button has no transition to suppress, so clause 4 is vacuous',
    ).toBeGreaterThan(0)
  })
})
