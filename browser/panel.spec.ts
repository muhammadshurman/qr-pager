import { globSync, readFileSync } from 'node:fs'
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
 * The ratio as this project PUBLISHES it: floored at two decimals, never rounded up.
 *
 * ⚠ THIS FILE REPORTED `toFixed(2)` IN BOTH ITS CONTRAST CASES AND THAT IS THE ONE DEFECT
 * `color.ts`'s rounding note exists to prevent. Its words: *"a raw 4.4996 presented as
 * '4.50' against a 4.5 bar reads as a pass and is not one"* — and *"every contrast figure
 * this project publishes or asserts goes through this function"*, which these two did not.
 * The bar checks were on the raw ratio, so no pair ever passed wrongly; what could overstate
 * was the FIGURE in the message, which is the part a person reads and transcribes. Flooring
 * can only understate, which is the safe direction for a minimum.
 *
 * `ratioFloor2` itself cannot be called here — it takes two hex strings and this harness
 * holds engine-converted bytes — so the convention is reimplemented at the same two
 * decimals, floored on the ratio itself and not on a pre-rounded value.
 *
 * NOT A NARROWING OF EITHER CASE: both still compare the RAW ratio against 4.5, and for a
 * threshold of 4.5 the two agree on every input — `Math.floor(r * 100) / 100 >= 4.5` and
 * `r >= 4.5` differ only where a value lies strictly between 4.5 and 4.5, which is nowhere.
 * Measured on the four pairs this task moved: the floored figures are 4.01 / 4.20 / 3.41
 * for the old pairs and 5.71 / 5.64 / 9.38 / 6.70 for the new, against 4.01 / 4.21 / 3.42
 * and 5.72 / 5.64 / 9.38 / 6.71 under `toFixed(2)` — four of seven moved, which is how far
 * this was from being cosmetic. The floored seven are the figures `contrast.json`,
 * `QRPAGER-15-contrast-table.md` and `§4a` all carry, so the two instruments now agree.
 */
function published(ratio: number): string {
  return (Math.floor(ratio * 100) / 100).toFixed(2)
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
  // a custom property's value as AUTHORED -- `#191C22` under Rail, `#0B6B4F` under
  // Direction B before 2026-11-08 -- not the lower-case Chromium uses for a resolved
  // colour. The first version of this guard compared the two and failed on the case while
  // every value was already right.
  expect(applied.primary600.toLowerCase(), 'the generated palette did not reach the document').toBe(
    '#191c22',
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

    // ⚠ `narrow-button` IS WHY THIS LIST GREW. Removing `.fi-btn`'s `min-width` left every
    // check green, because the only button here carried "New restaurant" and measured 112px
    // wide — no element was ever under the bar on that axis (QA L1).
    for (const id of ['primary-button', 'narrow-button', 'input']) {
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

test.describe('every spacing class the panel blades use renders the px it names', () => {
  /*
   * ⚠ WRITTEN BECAUSE A RENAME CHANGED FOURTEEN RENDERED SIZES AND NOTHING NOTICED.
   *
   * Under the panel theme a spacing class's number is a TOKEN STEP IN PX, not Tailwind's
   * 0.25rem multiplier: `panel-theme.css` declares `--spacing-4 … --spacing-96` from the
   * approved ladder. Tasks 6-7 wrote these blades under the MULTIPLIER semantics, where
   * `p-4` meant 4 × 0.25rem = 16px — and under the new theme it means 4px. The two readings
   * collide on exactly one number, `4`, because 4 is both a valid multiplier and a valid
   * token step, so the lint rule cannot tell them apart and did not.
   *
   * Measured in this engine: fourteen pre-existing `p-4` / `px-4` / `gap-4` sites were
   * rendering **4px** where 16px was authored. They are now `-16`, and `mt-4` ×2 and
   * `space-y-4` ×1 — 8b's own renames from `mt-1`/`space-y-1`, where 4px IS the intent —
   * stay at 4.
   *
   * The intent behind a number is not mechanically decidable, which is why this is a
   * MEASUREMENT and not an intent check: the class list is derived from the blades and the
   * rendered px is pinned per class. A rename that changes a size reddens here with both
   * numbers, for a human to read.
   *
   * ⚠ AND IT IS PER PANEL, which is load-bearing and surprising: each theme carries
   * `source(none)` plus its own `@source` globs, so a class used only in the studio blades
   * is NOT EMITTED in the platform theme. Measured: `p-16` resolves to 16px against the
   * studio theme and to nothing against the platform one. A single-theme check would have
   * reported half of these as broken.
   */
  const EXPECTED: Record<'platform' | 'studio', Record<string, string>> = {
    platform: { 'gap-16': '16px', 'mt-4': '4px', 'mt-8': '8px' },
    studio: {
      'gap-12': '12px',
      'p-16': '16px',
      'px-12': '12px',
      'px-16': '16px',
      'py-8': '8px',
      'space-y-4': '4px',
      'space-y-8': '8px',
      'space-y-12': '12px',
      'space-y-24': '24px',
    },
  }

  /** The spacing classes one panel's own views use, derived rather than listed. */
  function spacingClasses(panel: 'platform' | 'studio'): string[] {
    const dir = join(API, 'resources/views', panel)
    const prefixes =
      'p|px|py|pt|pr|pb|pl|ps|pe|m|mx|my|mt|mr|mb|ml|ms|me|gap|gap-x|gap-y|space-x|space-y'
    const found = new Set<string>()

    for (const file of globSync(join(dir, '*.blade.php'))) {
      for (const match of readFileSync(file, 'utf8').matchAll(
        new RegExp(String.raw`\b(${prefixes})-(\d+)\b`, 'g'),
      )) {
        found.add(match[0])
      }
    }

    return [...found].sort()
  }

  for (const panel of ['platform', 'studio'] as const) {
    test(`${panel}: every class resolves to the px its number names`, async ({ page }) => {
      const classes = spacingClasses(panel)

      // The derivation is the subject list, so an empty one is a broken check and not a
      // clean panel.
      expect(classes.length, `no spacing class was found in the ${panel} views`).toBeGreaterThan(2)
      // And it must be exactly what is pinned: a class added to a blade without a measured
      // value here would otherwise go unmeasured.
      expect(classes).toEqual(Object.keys(EXPECTED[panel]).sort())

      const theme = panelThemeCss(panel)
      const markup = classes
        .map((c) => `<div id="${c}" class="${c} flex"><span>a</span><span>b</span></div>`)
        .join('')

      await page.setContent(
        `<!doctype html><html class="fi"><head><style>${readFileSync(
          join(REPO, 'browser/fixtures/panel-palette.css'),
          'utf8',
        )}</style><style>${theme.replace(/@font-face\s*\{[^}]*\}/g, '')}</style></head>` +
          `<body class="fi-body fi-panel-${panel}">${markup}</body></html>`,
      )

      const measured: Record<string, string> = {}

      for (const c of classes) {
        measured[c] = await page.evaluate((id) => {
          const s = getComputedStyle(document.getElementById(id)!)
          const first = document.querySelector(`#${CSS.escape(id)} > span`)

          if (id.startsWith('gap')) return s.columnGap
          // ⚠ marginBlockEND, not start. Tailwind v4's `space-y-n` emits
          // `> :not(:last-child) { --tw-space-y-reverse: 0; margin-block-start:
          // calc(var(--spacing-n) * var(--tw-space-y-reverse)); margin-block-end:
          // calc(var(--spacing-n) * calc(1 - var(--tw-space-y-reverse))) }` — so with the
          // reverse flag at 0 the START margin is 0 by construction and the space is on the
          // END. Reading start gave `0px` for all four and would have been read as a
          // failure of the rename rather than of this probe.
          if (id.startsWith('space-y')) return getComputedStyle(first!).marginBlockEnd || '0px'
          if (id.startsWith('px')) return s.paddingLeft
          if (id.startsWith('py')) return s.paddingTop
          if (id.startsWith('p')) return s.paddingTop
          if (id.startsWith('mt')) return s.marginTop
          return s.marginTop
        }, c)
      }

      expect(measured).toEqual(EXPECTED[panel])
    })
  }
})

test.describe('the restyle mirrors under RTL — §3', () => {
  /*
   * ⚠ `§3`'s "RTL mirrors correctly" had NO evidence for the restyled panel, which is the
   * §11 QA round's M-6: the only thing asserted was Task 6's pre-existing `<html dir>`
   * attribute, the fixture was `dir="ltr"`, and no project set RTL.
   *
   * WHAT THIS CAN AND CANNOT SHOW, said plainly. Arabic is a launch language and the panel's
   * LAYOUT is Filament's; a full mirroring audit of a vendor stylesheet is Task 9's harness
   * against a running panel. What IS this task's own is the CSS this task ADDED, and the one
   * way added CSS breaks mirroring is a PHYSICAL direction property — `padding-left`,
   * `margin-right`, `left`, `border-left` — which does not flip. So:
   *
   *   - every box this task floors is measured at `dir="rtl"` and must be unchanged, because
   *     `min-height` and `min-width` are direction-agnostic by construction;
   *   - and the authored CSS is read for physical properties, which is the falsifiable half:
   *     plant a `padding-left` and this reddens.
   */
  test('renders every floored control the same size at dir=rtl', async ({ page }) => {
    await page.setContent(fixtureHtml().replace('dir="ltr"', 'dir="rtl"'))
    await assertStylesApplied(page)

    const direction = await page.evaluate(
      () => getComputedStyle(document.documentElement).direction,
    )

    // The precondition: a run that silently stayed LTR would measure nothing.
    expect(direction).toBe('rtl')

    const small: string[] = []

    // ⚠ EVERY FLOORED ID IN THE FIXTURE, not two of them. The comment above says "every box
    // this task floors is measured at dir=rtl" and the first version iterated
    // `['primary-button', 'input']` while the fixture has four — QA L7. `icon-button` is the
    // mobile sidebar toggle and is hidden above `lg`, so it is measured at a phone width
    // here for the same reason the LTR case does it.
    for (const id of ['primary-button', 'narrow-button', 'input']) {
      const box = await page.locator(`#${id}`).boundingBox()

      expect(box, `#${id} is not in the layout at dir=rtl`).not.toBeNull()

      if (box !== null && (box.height < 44 || box.width < 44)) {
        small.push(
          `#${id} is ${String(Math.round(box.width))}x${String(Math.round(box.height))} at dir=rtl`,
        )
      }
    }

    await page.setViewportSize({ width: 390, height: 844 })

    const toggle = await page.locator('#icon-button').boundingBox()

    expect(toggle, 'the sidebar toggle is in the layout at NO width under rtl').not.toBeNull()

    if (toggle !== null && (toggle.height < 44 || toggle.width < 44)) {
      small.push(`#icon-button is ${String(Math.round(toggle.height))}px tall at dir=rtl`)
    }

    expect(small, 'the 44px floor does not survive mirroring').toEqual([])
  })

  test('adds no physical-direction property in any CSS this task authored', () => {
    // The authored files, named: the two panel entrypoints, the shared floor, and the
    // generated panel theme. Filament's own stylesheet is NOT in scope -- it is the vendor's,
    // it is 693 kB, and auditing it is Task 9's harness against a running panel.
    const authored = [
      'apps/api/resources/css/filament/targets.css',
      'apps/api/resources/css/filament/platform/theme.css',
      'apps/api/resources/css/filament/studio/theme.css',
      'packages/tokens/generated/panel-theme.css',
      'apps/api/resources/views/errors/panel-problem.blade.php',
    ]

    const physical =
      /(?:^|[^-\w])(?:padding|margin|border)-(?:left|right)\s*:|(?:^|[^-\w])(?:left|right)\s*:/

    const offenders: string[] = []

    for (const file of authored) {
      const source = readFileSync(join(REPO, file), 'utf8')
      // Comments stripped: this file's own prose names `padding-left` as the thing it
      // refuses, and a check that read its own documentation would refuse itself -- the
      // shape the shell checker's pipefail grep was already fixed for.
      const code = source.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\{\{--[\s\S]*?--\}\}/g, ' ')

      if (physical.test(code)) {
        offenders.push(file)
      }
    }

    expect(
      offenders,
      'these files set a PHYSICAL direction property, which does not mirror under RTL. ' +
        'Use the logical form: padding-inline-start, margin-inline-end, inset-inline-start.',
    ).toEqual([])

    // NOT VACUOUS: the files must exist and carry CSS, or "no physical property" is true of
    // nothing. The floor is the one that matters, so it is named rather than indexed --
    // `authored[0]` is `string | undefined` under `noUncheckedIndexedAccess` and a `!` there
    // would assert away what that setting exists to surface.
    expect(
      readFileSync(join(REPO, 'apps/api/resources/css/filament/targets.css'), 'utf8'),
    ).toContain('min-height')
    expect(authored).toContain('apps/api/resources/css/filament/targets.css')
  })
})

test.describe('R-118 clause 3 — the engine resolves an AA pair on the panel', () => {
  test('publishes every ratio floored, on the three figures that caught the convention', () => {
    // `color.ts`'s rounding note names three figures this project had already published
    // wrongly, with their raw values. They are the characterisation of the convention, so
    // they are what this reporter is held to — a `toFixed(2)` returns the middle column and
    // the whole point of the note is that the middle column is the wrong answer.
    //
    //     raw 15.545990…   round 15.55   FLOOR 15.54
    //     raw  1.597793…   round  1.60   FLOOR  1.59
    //     raw  3.329999…   round  3.33   FLOOR  3.32
    expect(published(15.54599)).toBe('15.54')
    expect(published(1.597793)).toBe('1.59')
    expect(published(3.329999)).toBe('3.32')

    // The bar case the note argues from, stated as its own assertion: the figure must not
    // claim the bar it does not clear.
    expect(published(4.4996)).toBe('4.49')

    // And a value already at two decimals is unchanged, so flooring costs nothing where
    // there is nothing to floor.
    expect(published(4.5)).toBe('4.50')
  })

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
      `the primary button resolved ${pair.text} on ${pair.background} = ${published(ratio)}`,
    ).toBeGreaterThanOrEqual(4.5)
  })
})

test.describe('every colour-family text utility the blades use clears AA', () => {
  /*
   * ⚠ A LIVE WCAG AA FAILURE, CAUSED BY THIS TASK, THAT EVERY OTHER CONTROL WAS BLIND TO.
   * The §11 QA round measured `text-sm text-gray-500 dark:text-gray-400` on the platform
   * dashboard at **4.01:1 light and 4.20:1 dark at 13px**, against AA's 4.5 for normal text.
   *
   * It is 8b's: `.text-gray-500` does not exist in Filament's precompiled stylesheet, so
   * before `->viteTheme()` those classes resolved to nothing and the text inherited
   * `--gray-950`. 8b's `->colors()` is what makes `--gray-500` `#75827B`. And it was handed
   * to this task explicitly — `PlatformPanelAccessibilityTest`'s own header says that usage
   * "is fine — §12 exempts Tasks 6-7 from the visual bar and Task 8 restyles from the
   * tokens", and §12 exempts those tasks from the VISUAL bar, "never from the accessibility
   * bar".
   *
   * ⚠ AND MEASURING THE SECOND FAMILY FOUND A SECOND FAILURE THE REVIEW DID NOT NAME:
   * `dark:text-danger-400` on the dark card is **3.41:1**, in four places. Both are moved —
   * gray to 600/300 (5.71 / 5.64, and `gray-600` IS `ink-mid`, the pair the contrast table
   * already records as "secondary text only") and danger's dark half to 200 (6.70).
   *
   * WHY NOTHING SAW IT: `contrast.json`'s 42 pairs are token-on-token, and the AA checks
   * either side of this one cover the BUTTON pairs Filament's resolver picks. A
   * `text-<family>-<shade>` utility an author writes in a blade is neither. This case is
   * that third thing: the utilities are DERIVED from the blades, rendered inside Filament's
   * own section markup, and measured in both themes against the background they actually
   * land on.
   */
  const SECTION = 'fi-section'

  /** Every `text-<family>-<shade>` (and its dark: pair) one panel's views use. */
  function textUtilities(panel: 'platform' | 'studio'): { light: string; dark: string }[] {
    const families = 'gray|primary|danger|success|warning|info'
    const pairs = new Map<string, { light: string; dark: string }>()

    for (const file of globSync(join(API, 'resources/views', panel, '*.blade.php'))) {
      const source = readFileSync(file, 'utf8')

      for (const match of source.matchAll(
        new RegExp(
          String.raw`\btext-(?:${families})-\d+(?:\s+dark:text-(?:${families})-\d+)?`,
          'g',
        ),
      )) {
        const [light, dark] = match[0].split(/\s+dark:/)
        pairs.set(match[0], {
          light: light ?? '',
          dark: (dark ?? light ?? '').replace(/^text-/, 'text-'),
        })
      }
    }

    return [...pairs.values()]
  }

  for (const panel of ['platform', 'studio'] as const) {
    test(`${panel}: every text utility clears 4.5 in both themes`, async ({ page }) => {
      const utilities = textUtilities(panel)

      // The derivation is the subject list: an empty one is a broken check, not a clean
      // panel. Both panels use at least one.
      expect(utilities.length, `no text utility found in the ${panel} views`).toBeGreaterThan(0)

      const theme = panelThemeCss(panel)
      const palette = readFileSync(join(REPO, 'browser/fixtures/panel-palette.css'), 'utf8')

      const markup = utilities
        .map(
          (u, i) =>
            `<div class="${SECTION}"><p id="t${String(i)}" class="text-sm ${u.light} ${u.dark === u.light ? '' : `dark:${u.dark}`}">x</p></div>`,
        )
        .join('')

      const failures: string[] = []

      for (const mode of ['light', 'dark'] as const) {
        await page.setContent(
          `<!doctype html><html class="fi${mode === 'dark' ? ' dark' : ''}">` +
            `<head><style>${palette}</style>` +
            `<style>${theme.replace(/@font-face\s*\{[^}]*\}/g, '')}</style></head>` +
            `<body class="fi-body fi-panel-${panel}">${markup}</body></html>`,
        )
        await assertStylesApplied(page).catch(() => undefined)

        for (const [i, u] of utilities.entries()) {
          const pair = await page.evaluate(
            (id) => {
              const el = document.getElementById(id)!
              const text = getComputedStyle(el).color
              // The background the text actually lands on: walk up until something paints.
              let node: HTMLElement | null = el
              let background = 'rgba(0, 0, 0, 0)'

              while (node !== null) {
                const value = getComputedStyle(node).backgroundColor

                if (value !== 'rgba(0, 0, 0, 0)' && value !== 'transparent') {
                  background = value
                  break
                }

                node = node.parentElement
              }

              return { text, background, size: getComputedStyle(el).fontSize }
            },
            `t${String(i)}`,
          )

          const ratio = contrast(
            await srgb(page, pair.text),
            await srgb(
              page,
              pair.background === 'rgba(0, 0, 0, 0)' ? 'rgb(255,255,255)' : pair.background,
            ),
          )

          if (ratio < 4.5) {
            failures.push(
              `${mode}: ${mode === 'dark' ? u.dark : u.light} at ${pair.size} resolved ` +
                `${pair.text} on ${pair.background} = ${published(ratio)}`,
            )
          }
        }
      }

      expect(
        failures,
        'these text utilities are below WCAG AA for normal text, which §12 does not waive ' +
          'for the panel — only the VISUAL bar is waived, and only for Tasks 6-7',
      ).toEqual([])
    })
  }
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
