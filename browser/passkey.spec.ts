import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { expect, test } from '@playwright/test'

/**
 * ================================================================================
 * `R-100` — TASK 5's ONE UNMET GATE CLAUSE, CLOSED WITH TASK 9's BROWSER TOOLCHAIN
 * ================================================================================
 *
 * The row, verbatim: *"Task 5's passkey gate clause is NOT met: registration and login are
 * not proven through a browser. `§3` row 5 requires 'passkey registration + login proven end
 * to end **through the browser** against the harness page'. The credential ceremony lives in
 * `navigator.credentials`, not in HTTP, so a valid attestation needs a real authenticator or
 * a virtual one driven over CDP."*
 *
 * Its owner cell names **Task 9** -- *"which creates the Playwright harness … so closing this
 * on its own means standing up a browser toolchain for one test"* -- and its acceptance
 * clause is *"at Task 9's acceptance gate, in the same change as its Playwright setup. The
 * assertion to add: register a passkey against a virtual authenticator, sign out, sign in
 * with it, and assert 201 then 200."* That is exactly what this file does, following the
 * recipe `QRPAGER-12-handover.md §6.1` wrote down.
 *
 * ⚠ CHROMIUM ONLY, AND THAT IS A PLATFORM FACT RATHER THAN A CHOICE. `WebAuthn.enable` and
 * `WebAuthn.addVirtualAuthenticator` are CDP commands; Firefox and WebKit expose no
 * equivalent in any runner, so there is no way to present a valid attestation to them. The
 * row asks for a virtual authenticator *"driven over CDP"* in those words.
 *
 * ⚠ AND IT NEEDS A SECURE CONTEXT, like everything else in this task. `navigator.credentials`
 * is undefined outside one, which is why `tools/browsers/bin/e2e` runs a second TLS front in
 * front of the API -- the harness page is at `/passkey-harness`, outside the `/v1` rewrite.
 *
 * ⚠ WHAT THIS DOES NOT PROVE, said rather than discovered: that a REAL authenticator's
 * attestation validates. A virtual authenticator is the engine's own implementation of CTAP2,
 * so what is proven is that this application's ceremony, its challenge handling and its two
 * endpoints work against a conforming authenticator. A hardware key is a different claim and
 * nobody has asked for it.
 */

interface HarnessUser {
  readonly id: string
  readonly email: string
  readonly password: string
}

function harnessUser(): HarnessUser {
  const path = process.env.QRPAGER_E2E_HARNESS_USER

  if (path === undefined || path === '') {
    throw new Error(
      'QRPAGER_E2E_HARNESS_USER is unset. This spec needs an account it can sign in as -- ' +
        'run it through `tools/browsers/bin/e2e`, which creates one with ' +
        '`apps/api/bin/issue-harness-user`. It does not skip: R-100 is a gate clause with ' +
        'no other evidence behind it.',
    )
  }

  const repo = join(import.meta.dirname, '..')

  return JSON.parse(readFileSync(join(repo, path), 'utf8')) as HarnessUser
}

function apiOrigin(): string {
  const base = process.env.QRPAGER_E2E_API_BASE

  if (base === undefined || base === '') {
    throw new Error('QRPAGER_E2E_API_BASE is unset. Run through `tools/browsers/bin/e2e`.')
  }

  return base
}

test.describe('R-100 — a passkey registered and used through a real browser', () => {
  test.skip(
    ({ browserName }) => browserName !== 'chromium',
    'WebAuthn.addVirtualAuthenticator is a CDP command; Firefox and WebKit expose no equivalent',
  )

  test('registers against a virtual authenticator and then signs in with it', async ({
    page,
    context,
  }) => {
    const user = harnessUser()
    const origin = apiOrigin()

    // The recipe from `QRPAGER-12-handover.md §6.1`, verbatim in its options.
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

    /**
     * ⚠ THE PAGE's OWN ERRORS ARE CAPTURED AND REPORTED, because the harness page does not
     * catch a rejection from `navigator.credentials.create()` -- so the first time this failed,
     * `#out` still said `login -> 200` and the timeout named nothing. The cause was a relying
     * party ID that did not match the serving origin, which only the rejection says.
     */
    const pageErrors: string[] = []

    page.on('pageerror', (error) => {
      pageErrors.push(error.message)
    })
    page.on('console', (message) => {
      if (message.type() === 'error') pageErrors.push(message.text())
    })

    await page.goto(`${origin}/passkey-harness`)
    await expect(page.getByRole('heading', { name: 'Passkey harness' })).toBeVisible()

    // ⚠ THE CEREMONY IS AVAILABLE AT ALL, asserted first. `navigator.credentials` is
    // undefined outside a secure context, and a failure there would otherwise surface as the
    // register step quietly doing nothing.
    // Read through an index signature: `lib.dom.d.ts` declares `navigator.credentials` as
    // always present, and it is ABSENT outside a secure context -- which is the condition
    // this assertion exists to catch, so a guard written against the declared type is a check
    // the rule correctly calls impossible.
    expect(
      await page.evaluate(() => {
        const probe = navigator as unknown as Record<string, unknown>
        const credentials = probe.credentials

        return typeof (credentials as Record<string, unknown> | undefined)?.create === 'function'
      }),
    ).toBe(true)

    // 1. Sign in with the password, which is what the harness's step 1 exists for.
    await page.locator('#login input[name="email"]').fill(user.email)
    await page.locator('#login input[name="password"]').fill(user.password)
    await page.locator('#login button[type="submit"]').click()
    await expect(page.locator('#out')).toContainText('login -> 2', { timeout: 15_000 })

    // 2. Register a passkey. The page drives `navigator.credentials.create` against the
    // virtual authenticator and POSTs the attestation to Task 5's real endpoint.
    await page.locator('#register input[name="name"]').fill('r100-virtual')
    await page.locator('#register button[type="submit"]').click()

    // ⚠ 201, THE FIGURE THE ROW ASKS FOR. Anything else -- a 422 from the vendor
    // FormRequest, a 500 from the challenge store -- is the clause failing.
    await expect(
      page.locator('#out'),
      `the register step did not answer 201. page errors: ${JSON.stringify(pageErrors)}`,
    ).toContainText('register -> 201', { timeout: 20_000 })

    // The authenticator really holds a credential now, read from the engine rather than
    // inferred from the response.
    const { credentials } = await cdp.send('WebAuthn.getCredentials', { authenticatorId })

    expect(credentials.length).toBe(1)

    // 3. Sign out, so the next step cannot pass on the password session.
    const signedOut = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

      const response = await fetch('/v1/auth/logout', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf ?? '',
        },
        credentials: 'same-origin',
      })

      return response.status
    })

    expect(signedOut).toBeLessThan(400)

    // 4. Sign in WITH THE PASSKEY. A fresh document, so no in-page state survives.
    await page.goto(`${origin}/passkey-harness`)
    await page.locator('#passkey-login').click()

    // ⚠ 200, THE SECOND FIGURE THE ROW ASKS FOR -- and it is the whole point: this response
    // was produced by a credential the browser's own authenticator signed, with no password
    // in the request.
    await expect(page.locator('#out')).toContainText('passkey login -> 200', { timeout: 20_000 })

    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId })
  })

  test('refuses a passkey assertion when no authenticator can produce one', async ({
    page,
    context,
  }) => {
    /**
     * ⚠ THE CONTROL THAT STOPS THE CASE ABOVE PASSING FOR THE WRONG REASON. Without it,
     * "signs in with a passkey" would be satisfied by an endpoint that accepts anything --
     * and `R-100`'s own text records that the rejected alternative was *"to fabricate an
     * attestation and call it end-to-end"*.
     *
     * Here the virtual authenticator is enabled and holds NO credential, so the ceremony
     * cannot produce an assertion and the page must not report a 200.
     */
    const origin = apiOrigin()
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

    await page.goto(`${origin}/passkey-harness`)
    await page.locator('#passkey-login').click()

    // Either the ceremony throws, or the endpoint refuses. What must NOT happen is a 200.
    await expect(page.locator('#out')).not.toContainText('passkey login -> 200', {
      timeout: 10_000,
    })

    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId })
  })
})
