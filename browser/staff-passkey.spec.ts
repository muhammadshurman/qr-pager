import { expect, test } from '@playwright/test'

import { staffFixture } from './support/e2e.ts'

/**
 * ================================================================================
 * PASSKEY SIGN-IN AGAINST 10a's OWN SCREEN — `QRPAGER-25 §4`, clause 2
 * ================================================================================
 *
 * The gate clause, verbatim: *"Passkey registration and login work **through a real browser**
 * against this ticket's own screen — **not** against Task 5's harness page."*
 *
 * `browser/passkey.spec.ts` closed `R-100` against `/passkey-harness`, which is a Blade page
 * with `csrf_token()` inlined and `fetch` written by hand. **None of that is the product.**
 * This file drives the same ceremony through `apps/web`'s own sign-in screen, its own
 * `src/staff/passkey.ts` base64url conversion and its own `XSRF-TOKEN` cookie handling —
 * which is where the defects live that the harness page cannot have:
 *
 *   - a challenge decoded with `atob` and no padding restored;
 *   - an attestation encoded with a spread that overflows the argument limit;
 *   - a CSRF cookie sent back percent-encoded, which decrypts to nothing.
 *
 * ⚠ CHROMIUM ONLY, AND IT IS A PLATFORM FACT RATHER THAN A CHOICE.
 * `WebAuthn.addVirtualAuthenticator` is a CDP command; Firefox and WebKit expose no
 * equivalent in any runner, so there is no way to present a valid attestation to them. The
 * project is `staff-passkey-chromium` for that reason, and the §12 and isolation cases that
 * do NOT need an authenticator run in all three engines in `browser/staff.spec.ts`.
 *
 * ⚠ WHAT THIS DOES NOT PROVE, said rather than discovered: that a REAL authenticator's
 * attestation validates. A virtual authenticator is the engine's own CTAP2 implementation, so
 * what is proven is that this application's ceremony works against a conforming one. A
 * hardware key is a different claim and nobody has asked for it.
 */

test.describe('10a — a passkey registered and used through the staff screen', () => {
  test.skip(
    ({ browserName }) => browserName !== 'chromium',
    'WebAuthn.addVirtualAuthenticator is a CDP command; Firefox and WebKit expose no equivalent',
  )

  test('signs in with a password, adds a passkey, then signs in with the passkey alone', async ({
    page,
    context,
  }) => {
    const fixture = staffFixture()

    const cdp = await context.newCDPSession(page)

    await cdp.send('WebAuthn.enable')

    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
      },
    })

    expect(authenticatorId).toBeTruthy()

    /*
     * ⚠ THE PAGE's OWN ERRORS ARE CAPTURED, because `R-100` cost a round to exactly this:
     * `navigator.credentials.create()` rejected with a `SecurityError` about the relying
     * party id, the harness page did not catch it, and the failure named nothing. This app's
     * `registerPasskey()` DOES catch it -- and returns `cancelled`, which is deliberately
     * indistinguishable from the person dismissing the prompt. So the only place the real
     * reason exists is the console, and a failure here has to carry it or it names nothing
     * again.
     */
    const pageErrors: string[] = []

    page.on('pageerror', (error) => {
      pageErrors.push(error.message)
    })
    page.on('console', (message) => {
      if (message.type() === 'error') pageErrors.push(message.text())
    })

    // ⚠ THE CEREMONY IS AVAILABLE AT ALL, asserted first. `navigator.credentials` is
    // undefined outside a secure context, and `passkeysAvailable()` would then answer false
    // and the screen would render `unsupported` -- which is correct behaviour and would make
    // every assertion below fail for a reason unrelated to the ceremony.
    await page.goto('/staff')

    expect(
      await page.evaluate(() => {
        const probe = navigator as unknown as Record<string, unknown>
        const credentials = probe.credentials as Record<string, unknown> | undefined

        return typeof credentials?.create === 'function'
      }),
      'the staff origin is not a secure context, so no ceremony can run',
    ).toBe(true)

    // 1. Sign in with the password, through the screen's own form. The passkey does not
    //    exist yet -- which is why "passkey-first" needs a way to create one and why
    //    `registerPasskey()` is in 10a at all. ⚠ NO DISCLOSURE STEP SINCE `QRPAGER-38`:
    //    this clicked *"Use a password instead"* first, and the rebuilt card has both
    //    fields on screen from the first paint, so the control is gone rather than renamed.
    await page.locator('input[name="email"]').fill(fixture.owner.email)
    await page.locator('input[name="password"]').fill(fixture.owner.password)
    await page.locator('[data-password-signin="true"]').click()

    await expect(
      page.locator('[data-staff-shell="true"]'),
      `the password sign-in did not reach the shell. page errors: ${JSON.stringify(pageErrors)}`,
    ).toBeVisible({ timeout: 30_000 })

    // The shell names the restaurant, which is the §12 property and also the proof that a
    // tenant actually resolved rather than the screen merely changing.
    await expect(page.getByRole('heading', { name: fixture.alpha.name })).toBeVisible()

    // 2. Add a passkey. This goes through `src/staff/passkey.ts`: `GET .../passkeys/options`,
    //    base64url → ArrayBuffer, `navigator.credentials.create`, ArrayBuffer → base64url,
    //    `POST /v1/auth/passkeys` with the `X-XSRF-TOKEN` read from `document.cookie`.
    await page.locator('[data-add-passkey="true"]').click()

    /*
     * ⚠ THE SCREEN SAYS IT WORKED, AND THAT IS ASSERTED FIRST BECAUSE THE FIRST VERSION OF
     * THIS CASE COULD NOT TELL. It polled `WebAuthn.getCredentials` and required one
     * credential -- but `navigator.credentials.create()` writes to the AUTHENTICATOR whether
     * or not our POST succeeds, so that count is 1 even when the server refused the
     * attestation. On 10a's first full `gate:e2e` run it was exactly that: the engine held a
     * credential, the server had stored nothing (an origin mismatch), and the poll passed
     * while the registration had failed.
     *
     * Three checks now, in this order, because they can disagree and the disagreement is the
     * interesting case: the SCREEN's report, then the SERVER's record, then the engine's.
     */
    await expect(
      page.locator('[data-passkey-notice="added"]'),
      `the screen did not report a registered passkey. page errors: ${JSON.stringify(pageErrors)}`,
    ).toBeVisible({ timeout: 30_000 })

    // THE SERVER's OWN RECORD. `AuthController::identity()` reports `passkeys_enabled` from
    // the user's stored credentials, so this is true only if the attestation was accepted and
    // persisted -- the half the CDP credential count cannot see.
    expect(
      await page.evaluate(async () => {
        const response = await fetch('/v1/auth/me', { headers: { Accept: 'application/json' } })
        const body = (await response.json()) as { user?: { passkeys_enabled?: boolean } }

        return body.user?.passkeys_enabled === true
      }),
      'the server stored no passkey, so the registration was refused',
    ).toBe(true)

    // And the authenticator holds exactly one, read from the engine. Kept as a THIRD check
    // rather than the first: it rules out a server that recorded a credential nobody made.
    const { credentials } = await cdp.send('WebAuthn.getCredentials', { authenticatorId })

    expect(credentials.length).toBe(1)

    // 3. Lock the screen. This destroys the SERVER session, so step 4 cannot pass on the
    //    password session -- which is the same reason `passkey.spec.ts` signs out between
    //    its own steps, done here through the product's own control.
    await page.locator('[data-sign-out="true"]').click()
    await expect(page.locator('[data-locked="true"]')).toBeVisible({ timeout: 30_000 })

    // 4. SIGN IN WITH THE PASSKEY ALONE. A fresh document, so no in-page state survives and
    //    the ceremony starts from nothing but the credential in the authenticator.
    await page.goto('/staff')
    await page.locator('[data-passkey-signin="true"]').click()

    await expect(
      page.locator('[data-staff-shell="true"]'),
      `the passkey sign-in did not reach the shell. page errors: ${JSON.stringify(pageErrors)}`,
    ).toBeVisible({ timeout: 30_000 })

    // ⚠ AND THE SHELL IS REALLY AUTHENTICATED, not merely rendered: the restaurant's name
    // came from `GET /v1/tenant`, which answers 401 without a session. A screen that showed
    // the chrome on a failed ceremony would pass a "the shell is visible" assertion and fail
    // this one.
    await expect(page.getByRole('heading', { name: fixture.alpha.name })).toBeVisible()

    expect(await page.evaluate(async () => (await fetch('/v1/tenant')).status)).toBe(200)

    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId })
  })

  test('does not reach the shell when no authenticator can produce an assertion', async ({
    page,
    context,
  }) => {
    /*
     * ⚠ THE CONTROL THAT STOPS THE CASE ABOVE PASSING FOR THE WRONG REASON — the same one
     * `browser/passkey.spec.ts` carries, and it is needed MORE here, not less: that file
     * asserts a status code in a `<pre>`, and this one asserts that a screen changed. "The
     * shell appeared" is satisfiable by a client that navigates optimistically on any
     * outcome, which is a plausible bug and the reason `signInWithPasskey()` returns a
     * discriminated result rather than a boolean.
     *
     * Here the virtual authenticator is enabled and holds NO credential, so the ceremony
     * cannot produce an assertion. The screen must stay on sign-in.
     */
    const cdp = await context.newCDPSession(page)

    await cdp.send('WebAuthn.enable')

    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
      },
    })

    await page.goto('/staff')
    await page.locator('[data-passkey-signin="true"]').click()

    // The screen says the one thing it says for every cause, and the shell never appears.
    await expect(page.getByText(/that did not work/i)).toBeVisible({ timeout: 30_000 })
    await expect(page.locator('[data-staff-shell="true"]')).toHaveCount(0)

    // And no session was established, asserted at the server rather than at the screen.
    expect(await page.evaluate(async () => (await fetch('/v1/tenant')).status)).toBe(401)

    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId })
  })
})
