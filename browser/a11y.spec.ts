import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { expect, test, type Page } from '@playwright/test'

/**
 * ================================================================================
 * THE FOUR COMPUTED-STYLE CLAUSES. 8a's SHARE OF R-118, CLOSED HERE.
 * ================================================================================
 *
 * `R-118` carries four clauses of `§12`'s bar open because measuring them needs a browser:
 * **a visible focus ring, a 44px minimum touch target, WCAG AA contrast, and
 * `prefers-reduced-motion`.** All four are computed styles and jsdom computes none of them,
 * which is why asserting them in Vitest would have been one of the weak assertions `R-118`
 * exists to refuse: *"an assertion that cannot fail is worse than an absent one, because it
 * reports coverage that is not there."*
 *
 * Each check below reads a RESOLVED value out of a real engine. The CSS under test is the
 * real generated token file plus the real kit stylesheet, read from disk at run time -- not
 * a copy, so there is nothing here that could describe last week's tokens.
 */

const REPO = join(import.meta.dirname, '..')

/**
 * The fixture, with the REAL stylesheets substituted into it at run time.
 *
 * ⚠ SUBSTITUTED, NOT INJECTED, AND THAT IS A CORRECTION TO THIS HARNESS. It used
 * `page.goto(file://…)` followed by `page.addStyleTag()` per sheet, and under that four
 * `<button>` elements computed Chromium's UA colours — `rgb(239,239,239)` on `rgb(0,0,0)` —
 * while every observable said the author rules should win: the rules were parsed (CSSOM
 * `.style.backgroundColor` read back `var(--color-brand)`), matched
 * (`el.matches('.qr-button--primary')` true), valid (CDP `parsedOk` true, not disabled),
 * and the custom property resolved on the element itself. A literal injected afterwards at
 * the same specificity did not apply either, while the same declaration by `#id` did.
 *
 * The same CSS and markup through `setContent` resolve correctly: `#FFFFFF` on `#0B6B4F`.
 * So the product stylesheet was never wrong — this harness was, and the cause sits below
 * this code in the engine's handling of that load-then-inject sequence. It is not diagnosed
 * further, because the fix removes the sequence.
 *
 * WHY IT MATTERS MORE THAN THE SYMPTOM. These are the only checks in the project that can
 * see a computed style at all — `R-118` exists because jsdom cannot. A harness that
 * produced a false FAILURE could equally have produced a false PASS, and a false pass here
 * would have certified a palette the engine never renders.
 */
function fixtureHtml(theme: 'light' | 'dark'): string {
  const tokens = readFileSync(join(REPO, 'packages/tokens/generated/tokens.css'), 'utf8')
  // kit.css's @import of tokens.css and fonts.css is dropped: tokens is substituted beside
  // it, and the @font-face rules need no network for a contrast or geometry measurement.
  const kit = readFileSync(join(REPO, 'packages/ui/src/styles/kit.css'), 'utf8').replace(
    /@import[^;]+;/g,
    '',
  )
  const html = readFileSync(join(REPO, 'browser/fixtures/kit.html'), 'utf8')

  if (!html.includes('<!--STYLES-->')) {
    throw new Error('browser/fixtures/kit.html has no <!--STYLES--> placeholder to substitute')
  }
  if (!html.includes('data-theme="light"')) {
    throw new Error('browser/fixtures/kit.html must carry data-theme="light" to substitute')
  }

  return (
    html
      .replace('<!--STYLES-->', `<style>${tokens}</style>\n<style>${kit}</style>`)
      // ⚠ THE THEME GOES INTO THE MARKUP, NOT ONTO THE LIVE DOCUMENT, and that is the second
      // correction to this harness. It used `setAttribute('data-theme', …)` after load, and
      // Chromium's invalidation after that was PARTIAL: measured in the dark theme, the
      // custom properties on the element had updated to the dark values
      // (`--color-surface: #1D3028`, `--color-alert-ink: #E99176`) while the element's
      // computed `color` and `background-color` were still the LIGHT ones
      // (`rgb(255,255,255)` on `rgb(150,53,26)`). Some elements recalculated and some did
      // not, in the same document.
      //
      // That is a false-PASS generator, not just a false failure: a dark-theme assertion
      // comparing against light-theme computed values can agree with the wrong table row and
      // report a palette as verified in a theme it was never measured in. Parsing the
      // attribute with the document removes the dynamic invalidation entirely.
      .replace('data-theme="light"', `data-theme="${theme}"`)
  )
}

async function openKit(page: Page, theme: 'light' | 'dark' = 'light') {
  await page.setContent(fixtureHtml(theme))

  // THE VACUITY GUARD, and it is wider than it was. It used to check that one background
  // was not transparent, which the broken harness PASSED: the card was token-styled while
  // four buttons were not. So it now checks a token-driven value on an element of each kind
  // this suite measures, because "the stylesheets applied" turned out to be true of some
  // elements and false of others.
  const applied = await page.evaluate(() => {
    const read = (sel: string) => {
      const el = document.querySelector(sel)
      if (!el) return null
      const s = getComputedStyle(el)
      return { bg: s.backgroundColor, color: s.color }
    }
    return {
      theme: document.documentElement.getAttribute('data-theme'),
      brand: getComputedStyle(document.documentElement).getPropertyValue('--color-brand').trim(),
      card: read('#card'),
      button: read('#primary'),
      input: read('#name'),
    }
  })

  expect(applied.theme, 'the fixture is not in the theme under test').toBe(theme)
  expect(applied.brand, 'the token stylesheet did not apply').not.toBe('')
  // The theme actually took: brand is the one role whose value differs between themes by a
  // whole hue (#0B6B4F light, #3DDC97 dark), so this cannot pass in the wrong theme.
  expect(applied.brand, `the ${theme} theme did not take`).toBe(
    theme === 'light' ? '#0B6B4F' : '#3DDC97',
  )
  expect(applied.card?.bg, 'the card is unstyled').not.toBe('rgba(0, 0, 0, 0)')
  // The UA button pair. If this is what a button computes, the author rules lost and every
  // contrast figure below would describe a pair the page does not render.
  expect(
    `${applied.button?.bg ?? ''} / ${applied.button?.color ?? ''}`,
    'a native button resolved to the user-agent pair -- the author rules did not win',
  ).not.toBe('rgb(239, 239, 239) / rgb(0, 0, 0)')
  expect(applied.input?.bg, 'the input is unstyled').not.toBe('rgba(0, 0, 0, 0)')

  return page
}

/**
 * ⚠ THERE IS NO CONTRAST FORMULA IN THIS FILE, AND THERE WAS ONE.
 *
 * It carried its own `ratioFromRgb` — a second implementation of WCAG 2.1 relative
 * luminance, in a file whose job is to measure a browser. That is the duplicated-logic
 * shape this project has paid for repeatedly (QA H-1; guard rule 11c parses `ThemeTokens`
 * rather than copying it), and it is worse here than usual: two implementations of the same
 * formula can disagree about a pass, and the one in the browser suite would have been the
 * one nobody noticed drifting.
 *
 * So the arithmetic lives in `packages/tokens` and is emitted to
 * `packages/tokens/generated/contrast.json`. What this file does instead is the half only a
 * browser can do: assert that the ENGINE resolves each token to the hex the source says,
 * and then read that pair's verdict out of the table. The engine proves the wiring; the
 * table proves the ratio.
 */
const CONTRAST = JSON.parse(
  readFileSync(join(REPO, 'packages/tokens/generated/contrast.json'), 'utf8'),
) as {
  bars: { aaaText: number; aaText: number; aaLarge: number }
  headroom: number
  orderReady: {
    pairs: { fg: string; bg: string; fgHex: string; bgHex: string; ratio: number; pass: boolean }[]
  }
  pairs: {
    theme: string
    fg: string
    bg: string
    fgHex: string
    bgHex: string
    ratio: number
    bar: number | null
    pass: boolean
  }[]
}

function parseRgb(value: string): [number, number, number] {
  const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(value)
  expect(m, `could not parse a colour from ${value}`).not.toBeNull()
  return [Number(m![1]), Number(m![2]), Number(m![3])]
}

/** The engine's resolved `rgb(…)` as the upper-case `#RRGGBB` the token source emits. */
function toHex(value: string): string {
  const [r, g, b] = parseRgb(value)
  return `#${[r, g, b]
    .map((c) => Math.round(c).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()}`
}

/** One row of the generated table, by theme and roles. Fails loudly if it is absent. */
function tableRow(theme: string, fg: string, bg: string) {
  const row = CONTRAST.pairs.find((r) => r.theme === theme && r.fg === fg && r.bg === bg)
  expect(row, `${theme} ${fg} on ${bg} is not in the generated contrast table`).toBeDefined()
  return row!
}

// ============================================================ 1. the visible focus ring

test.describe('R-118 clause 1 -- a visible focus ring', () => {
  const focusable = ['#primary', '#secondary', '#quiet', '#danger', '#name', '#ready-ack']

  for (const selector of focusable) {
    test(`${selector} has a non-zero computed outline when focused`, async ({ page }) => {
      await openKit(page)
      // Keyboard focus, not `.focus()`: the rule is `:focus-visible`, and a programmatic
      // focus on a button does not necessarily match it. Driving the keyboard is the only
      // way this measures what a keyboard user sees.
      await page.locator(selector).evaluate((el: HTMLElement) => {
        el.setAttribute('tabindex', '0')
      })
      await page.keyboard.press('Tab')
      await page.locator(selector).focus()
      await page.locator(selector).evaluate((el: HTMLElement) => {
        // focus-visible is set by the engine on keyboard interaction; dispatching a real
        // key on the focused element is what flips it.
        el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
      })

      const ring = await page.locator(selector).evaluate((el) => {
        const s = getComputedStyle(el)
        return { width: s.outlineWidth, style: s.outlineStyle, color: s.outlineColor }
      })

      // EXACT value, not "greater than zero": the approved direction fixes the ring at 2px
      // (§12 and the 2026-10-31 approval), so 2px is the assertion and a 1px ring is a
      // failure rather than a pass that happens to be visible.
      expect(ring.width).toBe('2px')
      expect(ring.style).not.toBe('none')
      expect(parseRgb(ring.color)).toBeTruthy()
    })
  }

  test('the ring is actually VISIBLE -- its colour differs from what it sits on', async ({
    page,
  }) => {
    // ⚠ THIS CASE REPLACED A BROKEN ONE, and the broken one is worth recording because it
    // looked right. It asserted that no element computes `outline-style: none` with a
    // non-zero `outline-width` -- and in Chromium that describes EVERY unfocused element,
    // because `outline-style: none` is the initial value while `outline-width` initialises
    // to `medium`. So it reported the whole document and could never have passed.
    //
    // The source-level half of that claim -- no `outline: none` declared in our own
    // stylesheets -- cannot be measured in a browser at all, because the default is
    // indistinguishable from the declaration. It lives in the Vitest invariant suite, where
    // a source scan belongs and where it can genuinely fail.
    //
    // What a browser CAN add beyond "the ring has a width" is that the ring is
    // distinguishable from its surroundings. A 2px ring in the background colour is a
    // compliant declaration and an invisible ring.
    await openKit(page)
    await page.locator('#secondary').focus()
    await page.locator('#secondary').evaluate((el: HTMLElement) => {
      el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
    })

    const measured = await page.locator('#secondary').evaluate((el) => {
      const s = getComputedStyle(el)
      return {
        outline: s.outlineColor,
        background: s.backgroundColor,
        pageBackground: getComputedStyle(document.body).backgroundColor,
      }
    })

    // The ring must differ from what it sits on: a 2px ring in the background colour is a
    // compliant declaration and an invisible ring. The RATIO is not recomputed here --
    // `--color-brand` against `--color-surface` is a row in the generated table, and the
    // formula lives in one place.
    expect(toHex(measured.outline)).not.toBe(toHex(measured.background))
    const row = tableRow('light', 'brand', 'surface')
    expect(toHex(measured.outline)).toBe(row.fgHex)
    expect(toHex(measured.background)).toBe(row.bgHex)
    expect(row.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaLarge + CONTRAST.headroom)
  })
})

// ============================================================= 2. the 44px touch target

test.describe('R-118 clause 2 -- a measured 44px minimum touch target', () => {
  const interactive = ['#primary', '#secondary', '#quiet', '#danger', '#name', '#ready-ack']

  for (const selector of interactive) {
    test(`${selector} measures at least 44px on BOTH axes`, async ({ page }) => {
      await openKit(page)
      const box = await page.locator(selector).boundingBox()
      expect(box, `${selector} is not rendered`).not.toBeNull()
      // The RENDERED box, from the engine's layout -- not the `min-block-size` declaration,
      // which is what a source-level check would have measured. §12's clause is about what
      // a thumb can hit.
      expect(box!.width).toBeGreaterThanOrEqual(44)
      expect(box!.height).toBeGreaterThanOrEqual(44)
    })
  }

  test('the token itself resolves to 44px, so the floor has one source', async ({ page }) => {
    await openKit(page)
    const value = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--target-min').trim(),
    )
    expect(value).toBe('44px')
  })
})

// ================================================================== 3. resolved contrast

test.describe('R-118 clause 3 — the engine resolves the tokens the table was computed from', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`body text on a card resolves to the table's pair in the ${theme} theme`, async ({
      page,
    }) => {
      await openKit(page, theme)
      const measured = await page.locator('#card').evaluate((el) => {
        const s = getComputedStyle(el)
        return { color: s.color, background: s.backgroundColor }
      })

      // The engine resolved --color-ink and --color-surface. If these match the table's
      // hexes then the CSS is wired to the tokens the arithmetic was done on -- which is
      // the thing a browser can establish and the token tests cannot.
      const row = tableRow(theme, 'ink', 'surface')
      expect(toHex(measured.color)).toBe(row.fgHex)
      expect(toHex(measured.background)).toBe(row.bgHex)
      expect(row.pass).toBe(true)
      expect(row.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaaText + CONTRAST.headroom)
    })

    test(`secondary text resolves to the table's pair in the ${theme} theme`, async ({ page }) => {
      await openKit(page, theme)
      await page.evaluate(() => {
        const p = document.createElement('p')
        p.className = 'qr-field__hint'
        p.id = 'hint'
        p.textContent = 'hint'
        document.querySelector('.qr-field')!.append(p)
      })
      const colour = await page.locator('#hint').evaluate((el) => getComputedStyle(el).color)

      const row = tableRow(theme, 'ink-mid', 'paper')
      expect(toHex(colour)).toBe(row.fgHex)
      expect(row.pass).toBe(true)
      expect(row.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaText + CONTRAST.headroom)
    })

    test(`the control border resolves to border-strong in the ${theme} theme`, async ({ page }) => {
      await openKit(page, theme)
      const measured = await page.locator('#name').evaluate((el) => {
        const s = getComputedStyle(el)
        return { border: s.borderTopColor, background: s.backgroundColor }
      })

      // THE CLAUSE border-strong EXISTS FOR (WCAG 1.4.11). hairline would be 1.28 here and
      // this input would have had no perceivable boundary.
      const row = tableRow(theme, 'border-strong', 'surface')
      expect(toHex(measured.border)).toBe(row.fgHex)
      expect(toHex(measured.background)).toBe(row.bgHex)
      expect(row.pass).toBe(true)
      expect(row.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaLarge + CONTRAST.headroom)
    })

    test(`the DANGER button's label clears AA on its fill in the ${theme} theme`, async ({
      page,
    }) => {
      // ⚠ A REGRESSION TEST FOR A DEFECT THE FIRST REPORT MISSED. The fill was
      // `alert-fill`, and `surface` on `alert-fill` is 3.68 — a pass for the >= 24px text
      // the approval allows it for, and a FAILURE for this button's --size-16 label. It
      // escaped because a role-against-background table has no row for text ON a fill.
      // The fill is `alert-ink` now: 7.44 light, 5.82 dark.
      await openKit(page, theme)
      const measured = await page.locator('#danger').evaluate((el) => {
        const s = getComputedStyle(el)
        return { color: s.color, background: s.backgroundColor }
      })

      const row = tableRow(theme, 'surface', 'alert-ink')
      expect(toHex(measured.color)).toBe(row.fgHex)
      expect(toHex(measured.background)).toBe(row.bgHex)
      expect(row.pass).toBe(true)
      expect(row.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaText + CONTRAST.headroom)
    })

    test(`the order-ready pair is IDENTICAL under the ${theme} theme`, async ({ page }) => {
      await openKit(page, theme)
      const measured = await page.locator('#ready').evaluate((el) => {
        const s = getComputedStyle(el)
        const ref = getComputedStyle(document.getElementById('ready-reference')!)
        const ack = getComputedStyle(document.getElementById('ready-ack')!)
        return {
          field: s.backgroundColor,
          ink: s.color,
          reference: ref.color,
          ackBg: ack.backgroundColor,
          ackInk: ack.color,
        }
      })

      // D4, measured in the engine: outside theming means these resolve to the same bytes
      // under both themes. The hexes come from the table, so there is no literal here either.
      const ready = Object.fromEntries(
        CONTRAST.orderReady.pairs.flatMap((p) => [
          [p.fg, p.fgHex],
          [p.bg, p.bgHex],
        ]),
      ) as Record<string, string>

      expect(toHex(measured.field)).toBe(ready['--ready-field'])
      expect(toHex(measured.ink)).toBe(ready['--ready-ink'])
      expect(toHex(measured.reference)).toBe(ready['--ready-signal'])
      expect(toHex(measured.ackBg)).toBe(ready['--ready-signal'])
      expect(toHex(measured.ackInk)).toBe(ready['--ready-field'])

      for (const p of CONTRAST.orderReady.pairs) {
        expect(p.pass, `${p.fg} on ${p.bg} fails its AAA target`).toBe(true)
        expect(p.ratio).toBeGreaterThanOrEqual(CONTRAST.bars.aaaText + CONTRAST.headroom)
      }
    })
  }

  // Not async: this one reads the generated artefact and opens no page. The table's own
  // verdict is asserted in this job too, so the browser gate fails on a palette regression
  // even if the Vitest project were skipped.
  test('every barred pair in the generated table passes, in both themes', () => {
    const failures = CONTRAST.pairs.filter((r) => r.bar !== null && !r.pass)
    expect(failures.map((r) => `${r.theme} ${r.fg} on ${r.bg} = ${String(r.ratio)}`)).toEqual([])
  })
})

// =========================================================== 4. prefers-reduced-motion

test.describe('R-118 clause 4 -- prefers-reduced-motion suppresses motion', () => {
  test('without the preference, the state token is the approved 160ms', async ({ page }) => {
    // The positive control. Without it, the zero below could be a stylesheet that never
    // loaded rather than a preference being honoured -- and a check that passes on an
    // unstyled page is the vacuous-assertion shape this project has a proof script for.
    test.skip(
      test.info().project.name === 'chromium-reduced-motion',
      'this project sets the preference',
    )
    await openKit(page)
    const value = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--motion-state').trim(),
    )
    expect(value).toBe('160ms')

    const transition = await page
      .locator('#primary')
      .evaluate((el) => getComputedStyle(el).transitionDuration)
    expect(transition).toContain('0.16s')

    const animation = await page
      .locator('#spinner svg')
      .evaluate((el) => getComputedStyle(el).animationName)
    expect(animation).toBe('qr-spin')
  })

  test('with the preference, motion is ZERO -- not merely shorter', async ({ page }) => {
    test.skip(test.info().project.name === 'chromium', 'this project does not set the preference')
    await openKit(page)

    // "prefers-reduced-motion means no motion, not less motion" -- QRPAGER-15 §6, trap 3.
    // EXACT zero, so a 50ms "gentler" transition fails rather than passing as reduced.
    const tokens = await page.evaluate(() => {
      const s = getComputedStyle(document.documentElement)
      return {
        state: s.getPropertyValue('--motion-state').trim(),
        entrance: s.getPropertyValue('--motion-entrance').trim(),
      }
    })
    expect(tokens.state).toBe('0ms')
    expect(tokens.entrance).toBe('0ms')

    const transition = await page
      .locator('#primary')
      .evaluate((el) => getComputedStyle(el).transitionDuration)
    expect(transition.split(',').every((d) => d.trim() === '0s')).toBe(true)

    // And the spinner's ANIMATION is removed outright, not shortened: an animation with an
    // infinite iteration count can still run at a zero duration in some engines, which a
    // duration token alone would not stop.
    const animation = await page
      .locator('#spinner svg')
      .evaluate((el) => getComputedStyle(el).animationName)
    expect(animation).toBe('none')

    const progress = await page
      .locator('#fill')
      .evaluate((el) => getComputedStyle(el).transitionDuration)
    expect(progress.split(',').every((d) => d.trim() === '0s')).toBe(true)
  })
})
