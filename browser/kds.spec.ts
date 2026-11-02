import { expect, test } from '@playwright/test'

import {
  aaBar,
  contrastPairs,
  focusRings,
  resetTabOrder,
  tagControls,
  targets,
} from './support/a11y.ts'
import { openKds, staffFixture } from './support/e2e.ts'

/**
 * ================================================================================
 * `§12` IN FULL ON THE KITCHEN SCREEN — 10c (`QRPAGER-27`), THE THIRD SURFACE
 * ================================================================================
 *
 * `QRPAGER-27 §6`: *"Staff surfaces, tokens exist as of Task 8: RTL, AA contrast, 44 px
 * targets, `prefers-reduced-motion`, visible focus, no library default look,
 * zero-technical-background bar. **A kitchen screen is greasy, bright and viewed from two
 * metres — contrast and target size are functional requirements here.**"*
 *
 * Everything measured below is a COMPUTED value — a rendered box, a resolved colour pair, an
 * outline — and jsdom computes none of them, which is why these cases are here and not in
 * Vitest. Three engines, both directions, on a wall tablet.
 *
 * ================================================================================
 * AND IT IS A DIFFERENT CREDENTIAL, WHICH IS WHY IT IS A DIFFERENT FILE
 * ================================================================================
 *
 * `staff.spec.ts` signs in with a password and carries a first-party session cookie. This
 * surface holds a **device token** in `localStorage` and sends `credentials: 'omit'` — `§4 ›
 * Tenancy`'s *two authentication surfaces, never confused*, and `KdsApp`'s docblock lists the
 * three things that would have to be defeated to serve both from one component.
 *
 * So the setup is different, the chrome is different (there is none), and the type scale is
 * different. What is identical is the measurement, which is why it lives in
 * `support/a11y.ts` rather than being written twice.
 */

test.describe('§12 on the kitchen screen', () => {
  test('gives every interactive box at least 44px on both axes', async ({ page }) => {
    await openKds(page)

    const boxes = await targets(page)

    /*
     * Not vacuous, and asked a SECOND WAY so a sweep that covered a subset fails. The screen
     * has at least the *Remove this screen's code* control; with orders it has only that one,
     * because a kitchen screen offers no action on an order at all —
     * `Ability::issuableToDevice()` is a closed list of one and `KdsBoard`'s docblock says why
     * a *Tell them it is ready* button here would be a control the server refuses.
     */
    const declared = await page.locator('button:visible, a[href]:visible, input:visible').count()

    expect(declared, 'the kitchen screen rendered no controls at all').toBeGreaterThanOrEqual(1)
    expect(boxes.length, 'the 44px sweep did not see every control on the page').toBe(declared)

    for (const box of boxes) {
      expect(box.width, `"${box.name}" is ${String(box.width)}px wide`).toBeGreaterThanOrEqual(44)
      expect(box.height, `"${box.name}" is ${String(box.height)}px tall`).toBeGreaterThanOrEqual(44)
    }
  })

  test('draws a visible focus ring on every control reachable by keyboard', async ({ page }) => {
    await openKds(page)

    /*
     * ⚠ THE CONFIRMATION IS OPENED FIRST, so its two controls are in the sweep. They are the
     * only destructive pair on this surface and are exactly the ones a person reaches with the
     * keyboard if they reach anything — `§11` QA M-15's lesson one surface over, where the
     * detail panel's controls went unmeasured because the sweep never opened it.
     */
    const forget = page.locator('[data-kds-forget="open"]')

    if ((await forget.count()) > 0) {
      await forget.click()
      await page.waitForSelector('[data-kds-forget="confirm"]', { timeout: 60_000 })
    }

    // The heading is plain text at the top of the document -- see `resetTabOrder`'s note on
    // why a click on a non-interactive element is what returns the engine's starting point.
    await resetTabOrder(page, '.qr-kds__title')

    const ids = await tagControls(page)

    expect(
      ids.length,
      'no controls were tagged, so the sweep would assert nothing',
    ).toBeGreaterThan(0)

    // A generous budget: the assertion is the SET reached, not the number of presses, so a
    // press spent on a scrollable container costs nothing. `support/a11y.ts` has the two e2e
    // rounds that taught this.
    const seen = await focusRings(page, ids.length * 3 + 6)
    const missed = ids.filter((id) => !seen.has(id))

    expect(
      missed,
      `Tab never reached, or drew no ring on, ${String(missed.length)} of ${String(ids.length)} controls: ${missed.join(', ')}`,
    ).toEqual([])
  })

  test('meets AA contrast on its own type scale, measured from the engine’s resolved colours', async ({
    page,
  }) => {
    await openKds(page)

    /*
     * The selectors are `kds.css`'s own, not `staff.css`'s. That sheet exists precisely because
     * the type scale differs — the order reference is `--size-64` for a two-metre reading
     * distance — and a contrast sweep that measured the staff classes would measure a screen
     * this surface does not render.
     */
    const pairs = await contrastPairs(page, [
      '.qr-kds__title',
      '.qr-kds__ref',
      '.qr-kds__meta',
      '.qr-kds__waiting',
      '.qr-kds__stale',
      '.qr-staff-state__title',
      '.qr-staff-state__description',
    ])

    // Not vacuous, and NAMED rather than counted -- `§11` QA L-4's lesson: a count of two
    // passes if the two that matched were the two state selectors, which is a different
    // measurement wearing the same number. The title is on every view of this screen.
    expect(
      pairs.map((pair) => pair.selector),
      'the kitchen screen did not render its own title',
    ).toContain('.qr-kds__title')

    for (const pair of pairs) {
      const bar = aaBar(pair.size)

      expect(
        pair.ratio,
        `${pair.selector} is ${pair.ratio.toFixed(2)}:1 at ${String(pair.size)}px, under ${String(bar)}:1`,
      ).toBeGreaterThanOrEqual(bar)
    }
  })

  test('mirrors in Arabic without a horizontal scroll, and keeps the order reference unflipped', async ({
    page,
  }, testInfo) => {
    await openKds(page)

    const direction = await page.locator('[dir]').first().getAttribute('dir')
    const rtl = testInfo.project.name.endsWith('-rtl')

    expect(direction, `the document direction is ${String(direction)}`).toBe(rtl ? 'rtl' : 'ltr')

    // No horizontal overflow in either direction. A mirrored layout that scrolls sideways on a
    // wall tablet is one nobody can read the right-hand column of.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    expect(overflow, 'the kitchen screen scrolls horizontally').toBeLessThanOrEqual(1)

    /*
     * ⚠ THE ORDER REFERENCE IS LEFT-TO-RIGHT INSIDE RIGHT-TO-LEFT TEXT, which is
     * `QRPAGER-15 §6` trap 2 — *logos and numbers do not mirror* — and matters more here than
     * anywhere else in the product, because this is the one place a reference is rendered at
     * `--size-64` where the direction is unmistakable from two metres.
     */
    const reference = page.locator('.qr-kds__ref').first()

    if ((await reference.count()) > 0) {
      const resolved = await reference.evaluate((element) => ({
        direction: getComputedStyle(element).direction,
        bidi: getComputedStyle(element).unicodeBidi,
      }))

      expect(resolved.direction).toBe('ltr')
      // `isolate` rather than `embed`: direction alone does not isolate the box from the
      // surrounding Arabic run, which is what makes the rendered string identical in both
      // directions. `kds.css` carries the argument.
      expect(resolved.bidi).toBe('isolate')
    }
  })

  test('renders no identifier of any kind — §12’s zero-technical-background clause', async ({
    page,
  }) => {
    const fixture = await openKds(page)
    const alpha = staffFixture().alpha

    const everything = await page.evaluate(() => document.documentElement.outerHTML)

    /*
     * Three things that must not be on a wall screen, and the first is the one that matters:
     * the DEVICE TOKEN is a thirty-day credential for a whole restaurant's order board, and a
     * screen that printed it would make a photograph of the wall a working credential.
     */
    expect(everything, 'the kitchen screen renders its own device token').not.toContain(
      fixture.token,
    )
    expect(everything, 'the kitchen screen renders a session id').not.toContain(alpha.session_id)
    expect(everything, 'the kitchen screen renders the tenant id').not.toContain(alpha.id)
    // And no slug: `§12` forbids a DNS label on a screen, and `Ability::TenantRead` is
    // deliberately not held by `Principal::Device` so the restaurant's NAME is unavailable too
    // -- which is why this screen names no restaurant at all.
    expect(everything, 'the kitchen screen renders the tenant slug').not.toContain(alpha.slug)
  })

  test('is not installable: no manifest and no iOS app-capable tag', async ({ page }) => {
    /*
     * 10c's own decision, which `app/staff/layout.tsx` explicitly handed it and which
     * `app/kds/layout.tsx` takes with its reason: a manifest's `start_url` would be a screen
     * whose credential lives in `localStorage`, and an installed app is a thing people share,
     * screenshot and reinstall. The benefit — full-screen without browser chrome — is already
     * available on every kiosk tablet and signage player this runs on.
     *
     * `kds-build-surface.test.ts` asserts the same against the BUILT HTML. This asserts it
     * against the LIVE document, which is the one a browser would read an install prompt from.
     */
    await openKds(page)

    expect(await page.locator('link[rel="manifest"]').count()).toBe(0)
    expect(await page.locator('meta[name="apple-mobile-web-app-capable"]').count()).toBe(0)
    expect(await page.locator('meta[name="mobile-web-app-capable"]').count()).toBe(0)
  })

  test('offers no action on an order, because a device token cannot notify', async ({ page }) => {
    /*
     * The `§12` half of 10c's first invariant clause, from the browser: a screen that offered
     * *Tell them it is ready* would be a control the server refuses with a 403, which is a
     * screen that lies to the person using it. `KdsEndpointsTest` proves the refusal server-side
     * and counts the outbox across five attempts; this proves the button is not there to press.
     */
    await openKds(page)

    for (const action of ['ready', 'renotify', 'complete', 'cancel'] as const) {
      expect(
        await page.locator(`[data-action="${action}"]`).count(),
        `the kitchen screen offers "${action}"`,
      ).toBe(0)
    }
  })
})
