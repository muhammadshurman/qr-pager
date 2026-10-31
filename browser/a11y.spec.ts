import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

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

/** The real stylesheets, with kit.css's @import dropped because tokens.css is injected. */
function stylesheets(): string[] {
  const tokens = readFileSync(join(REPO, 'packages/tokens/generated/tokens.css'), 'utf8')
  const kit = readFileSync(join(REPO, 'packages/ui/src/styles/kit.css'), 'utf8').replace(
    /@import[^;]+;/g,
    '',
  )
  return [tokens, kit]
}

async function openKit(page: Page, theme: 'light' | 'dark' = 'light') {
  await page.goto(pathToFileURL(join(REPO, 'browser/fixtures/kit.html')).href)
  for (const css of stylesheets()) await page.addStyleTag({ content: css })
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-theme', t)
  }, theme)
  // The fixture must actually have styled: a check against an unstyled page would pass
  // vacuously on the 44px floor and fail confusingly on contrast.
  const bg = await page.locator('#card').evaluate((el) => getComputedStyle(el).backgroundColor)
  expect(bg, 'the stylesheets did not apply -- every check below would be meaningless').not.toBe(
    'rgba(0, 0, 0, 0)',
  )
  return page
}

/** WCAG 2.1 relative luminance, in the browser's own resolved rgb() terms. */
function ratioFromRgb(a: [number, number, number], b: [number, number, number]): number {
  const lin = (c: number) => {
    const s = c / 255
    return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
  }
  const lum = ([r, g, bb]: [number, number, number]) =>
    0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(bb)
  const la = lum(a)
  const lb = lum(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

function parseRgb(value: string): [number, number, number] {
  const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/.exec(value)
  expect(m, `could not parse a colour from ${value}`).not.toBeNull()
  return [Number(m![1]), Number(m![2]), Number(m![3])]
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

    const ring = parseRgb(measured.outline)
    expect(ring).not.toEqual(parseRgb(measured.background))
    // 3:1 against the control's own fill, which is what makes a focus indicator perceivable
    // under WCAG 1.4.11 -- the same bar border-strong exists for.
    expect(ratioFromRgb(ring, parseRgb(measured.background))).toBeGreaterThanOrEqual(3)
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

test.describe('R-118 clause 3 -- WCAG contrast, resolved by the engine', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`body text clears AAA on a card in the ${theme} theme`, async ({ page }) => {
      await openKit(page, theme)
      const pair = await page.locator('#card').evaluate((el) => {
        const s = getComputedStyle(el)
        return { color: s.color, background: s.backgroundColor }
      })
      const ratio = ratioFromRgb(parseRgb(pair.color), parseRgb(pair.background))
      // The arithmetic in packages/tokens says 16.43 (light) and 12.61 (dark). This is the
      // same pair measured through the engine, so it also proves the CSS wires the tokens
      // the token tests assert.
      expect(ratio).toBeGreaterThanOrEqual(7)
    })

    test(`secondary text clears AA in the ${theme} theme`, async ({ page }) => {
      await openKit(page, theme)
      await page.evaluate(() => {
        const p = document.createElement('p')
        p.className = 'qr-field__hint'
        p.id = 'hint'
        p.textContent = 'hint'
        document.querySelector('.qr-field')!.append(p)
      })
      const pair = await page.locator('#hint').evaluate((el) => ({
        color: getComputedStyle(el).color,
        background: getComputedStyle(document.body).backgroundColor,
      }))
      // The hint sits on the page ground. The token tests say 5.17 (light) / 6.94 (dark);
      // the bar is AA's 4.5, and ink-mid was raised to clear 4.6 against the hardest
      // background (approval condition 3).
      const bg =
        pair.background === 'rgba(0, 0, 0, 0)'
          ? await page.evaluate(() =>
              getComputedStyle(document.documentElement).getPropertyValue('--color-paper').trim(),
            )
          : pair.background
      const resolved = bg.startsWith('#')
        ? ([
            Number.parseInt(bg.slice(1, 3), 16),
            Number.parseInt(bg.slice(3, 5), 16),
            Number.parseInt(bg.slice(5, 7), 16),
          ] as [number, number, number])
        : parseRgb(bg)
      expect(ratioFromRgb(parseRgb(pair.color), resolved)).toBeGreaterThanOrEqual(4.5)
    })

    test(`the order-ready pair is AAA and IDENTICAL in the ${theme} theme`, async ({ page }) => {
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

      // D4, measured in the engine rather than argued from the source: outside theming
      // means these resolve to the same bytes under both themes.
      expect(parseRgb(measured.field)).toEqual([10, 31, 23])
      expect(parseRgb(measured.ink)).toEqual([242, 244, 241])
      expect(parseRgb(measured.reference)).toEqual([61, 220, 151])

      expect(ratioFromRgb(parseRgb(measured.ink), parseRgb(measured.field))).toBeGreaterThanOrEqual(
        7,
      )
      expect(
        ratioFromRgb(parseRgb(measured.reference), parseRgb(measured.field)),
      ).toBeGreaterThanOrEqual(7)
      expect(
        ratioFromRgb(parseRgb(measured.ackInk), parseRgb(measured.ackBg)),
      ).toBeGreaterThanOrEqual(7)
    })
  }

  test('the control border clears 3:1 against its adjacent colour (WCAG 1.4.11)', async ({
    page,
  }) => {
    await openKit(page)
    const pair = await page.locator('#name').evaluate((el) => ({
      border: getComputedStyle(el).borderTopColor,
      background: getComputedStyle(el).backgroundColor,
    }))
    // THE CLAUSE border-strong EXISTS FOR. hairline would be 1.29:1 here and this input
    // would have had no perceivable boundary. Found at the dark theme's approval and it bit
    // the light theme too.
    expect(ratioFromRgb(parseRgb(pair.border), parseRgb(pair.background))).toBeGreaterThanOrEqual(3)
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
