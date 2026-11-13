import { expect, test } from '@playwright/test'

import { forgetStaffSession, signInAsStaff, staffFixture } from './support/e2e.ts'

/**
 * ================================================================================
 * BOTH DIRECTIONS, BY CLICKING — `QRPAGER-48` item 3
 * ================================================================================
 *
 * The ticket's invariant: *"from the staff app I reach the studio, and from the studio I
 * reach the staff app, by clicking, with the session intact."* Not by typing a URL: the
 * controls are the deliverable, so the cases drive the controls.
 *
 * ================================================================================
 * ⚠⚠⚠ WHAT A GREEN RUN HERE DOES **NOT** PROVE — READ THIS BEFORE TRUSTING IT
 * ================================================================================
 *
 * **These cases pass locally only because both surfaces are on `localhost` and cookies are
 * not scoped by port** (RFC 6265 §8.5). The staff app is on one port, the studio's panel on
 * another, and the session cookie is sent to both because they are one HOST.
 *
 * A green run proves **the link works**. It proves **nothing about the production shape**,
 * which depends on `§1`'s one-origin decision of 2026-11-13: staff and studio behind a single
 * host, routed by PATH, with `SESSION_DOMAIN` left `null` so the cookie stays host-only. If
 * anyone ever moves them to separate subdomains, **these cases keep passing locally and the
 * session silently stops arriving in production** — the failure would not appear here at all.
 *
 * So a future reader seeing this file green should not infer that the production routing is
 * covered. It is covered by that decision holding, and `apps/web/src/staff/studio.ts` is the
 * first thing that breaks if it stops.
 */
test.describe('crossing between the staff app and the studio — QRPAGER-48', () => {
  test('an owner reaches the studio by clicking, and is still signed in when they arrive', async ({
    page,
  }) => {
    await signInAsStaff(page)

    const toStudio = page.locator('[data-studio-link="true"]')

    // The control exists for this person at all -- the half that would fail if the published
    // capability stopped arriving.
    await expect(toStudio).toHaveCount(1)
    await expect(toStudio).toBeVisible()

    await toStudio.click()
    await page.waitForLoadState('domcontentloaded')

    /*
     * ⚠ THE SESSION IS THE ASSERTION, NOT THE URL. Landing on `/studio` while signed OUT
     * would redirect to the staff sign-in (`PanelSignInRedirect`), so reaching the panel's
     * own chrome is what proves the cookie travelled. The topbar's way back is the marker
     * this ticket added, and it only renders inside the authenticated panel.
     */
    await expect(page).toHaveURL(/\/studio/)
    await expect(page.locator('[data-back-to-staff="true"]')).toHaveCount(1)
  })

  test('and comes back to the orders board the same way', async ({ page }) => {
    await signInAsStaff(page)

    await page.locator('[data-studio-link="true"]').click()
    await page.waitForLoadState('domcontentloaded')

    const back = page.locator('[data-back-to-staff="true"]')

    await expect(back).toBeVisible()
    await back.click()
    await page.waitForLoadState('domcontentloaded')

    // Back on the staff shell, still authenticated: the shell only renders for a signed-in
    // person, and a lost session would land on the sign-in card instead.
    await expect(page.locator('[data-staff-shell="true"]')).toHaveCount(1)
  })

  /**
   * ⚠⚠ THE HALF THAT COSTS SOMETHING TO GET WRONG. `/studio` is gated by
   * `TenantPanelAccess::allows()` — Owner or Manager — so a STAFF-role member clicking a
   * studio button would be told 401 by a control the product drew for them. The capability
   * is published by the API from the same predicate the panel's own gate uses, and the staff
   * shell draws nothing when it is false.
   */
  test('a staff-role member is not offered the studio at all', async ({ page }) => {
    const fixture = staffFixture()

    // This case signs in as somebody else, so the cached owner session must not be reused.
    forgetStaffSession()

    await page.goto('/staff')
    await page.locator('input[name="email"]').fill(fixture.colleague.email)
    await page.locator('input[name="password"]').fill(fixture.colleague.password)
    await page.locator('[data-password-signin="true"]').click()

    // Signed in: the shell is what a successful password sign-in lands on. Asserting the
    // link's absence before this would pass against a page that never loaded.
    await expect(page.locator('[data-staff-shell="true"]')).toHaveCount(1, { timeout: 60_000 })

    // ⚠ ABSENT, not merely disabled: a visibly locked door still invites the question.
    await expect(page.locator('[data-studio-link="true"]')).toHaveCount(0)

    /*
     * And the gate agrees with the absence -- so the control is missing because the panel
     * would REFUSE, not because the markup happens to be missing today.
     *
     * ⚠ THE PANEL'S OWN ORIGIN, NOT `baseURL`. This project's `baseURL` is the STAFF app, so
     * a relative `/studio` would ask the Next.js server for a route it does not serve and
     * get a 404 -- which would satisfy a loose assertion while measuring nothing. The studio
     * is a Filament panel on the API's TLS front.
     */
    const apiBase = process.env.QRPAGER_E2E_API_BASE

    expect(apiBase, 'QRPAGER_E2E_API_BASE is unset, so the panel could not be reached').toBeTruthy()

    const refused = await page.request.get(`${String(apiBase).replace(/\/$/, '')}/studio`, {
      maxRedirects: 0,
    })

    expect([401, 403, 302, 303]).toContain(refused.status())

    // Leave no session behind for the next case, which expects the owner's.
    forgetStaffSession()
  })
})
