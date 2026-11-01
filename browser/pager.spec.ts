import { expect, test } from '@playwright/test'

import { answerPermissionWith, origin, recordRequests, scan, sessions } from './support/e2e.ts'

/**
 * ================================================================================
 * `§3` ROW 9's ACCEPTANCE GATE: scan -> wait -> ready -> alert, ON THREE ENGINES
 * ================================================================================
 *
 * Every case below drives the REAL Laravel API over a real socket, at a token a real
 * `PagerSessions` issued, against the composed Postgres and Redis, from the PRODUCTION build
 * of `apps/web`. `tools/browsers/bin/e2e` is what stands that up.
 *
 * ⚠ WHAT IS *NOT* REAL HERE, SAID RATHER THAN DISCOVERED: the push TRANSPORT. A push has to
 * come from a push service -- Apple's, Google's, Mozilla's -- and no test runner can make one
 * deliver. So the alert is driven by dispatching a real `push` event into the real service
 * worker (`sw.spec.ts`), which exercises every line this project owns and stops exactly where
 * somebody else's infrastructure begins. **The remaining half is a physical device**, which
 * is `QRPAGER-24` and `R-073`, and it is the reason this task is not tagged.
 */

test.describe('the scan landing', () => {
  test('resolves a real token from the fragment and shows the real order reference', async ({
    page,
  }) => {
    await scan(page, sessions().waiting.token)

    await expect(page.locator('[data-screen="landing"][data-state="valid"]')).toBeVisible()
    // The reference came from Postgres, through the resolve endpoint, over HTTP.
    await expect(page.locator('.pg-reference')).toHaveText(sessions().waiting.order_reference)
    await expect(page.getByRole('button', { name: /enable alerts/i })).toBeVisible()
  })

  test('strips the fragment and leaves the token in NO history entry -- §3 row 9 (a)', async ({
    browser,
  }) => {
    /**
     * The clause, verbatim: *"the handler reads `location.hash` once and immediately strips it
     * with `history.replaceState`, proven by asserting `location.hash` is empty after first
     * paint and that the token is in no history entry."*
     *
     * ⚠ THE HISTORY HALF IS A DELTA, NOT AN ABSOLUTE. A fresh context starts on `about:blank`,
     * so `history.length` after one `goto` is 2 and asserting `1` fails on a page that is
     * behaving perfectly -- which is what the first version of this did. What the clause is
     * about is whether the STRIP added an entry, so the measurement is the same navigation
     * with and without a fragment: identical lengths means `replaceState` replaced rather
     * than pushed.
     */
    const token = sessions().waiting.token

    const plain = await browser.newContext()
    const plainPage = await plain.newPage()
    await plainPage.goto(`${origin()}/s`)
    await expect(plainPage.locator('[data-screen]')).toBeVisible()
    const baseline = await plainPage.evaluate(() => history.length)
    await plain.close()

    const scanned = await browser.newContext()
    const page = await scanned.newPage()
    await scan(page, token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    const seen = await page.evaluate(() => ({
      hash: window.location.hash,
      href: window.location.href,
      url: document.URL,
      entries: history.length,
      referrer: document.referrer,
    }))

    expect(seen.hash).toBe('')
    expect(seen.href).not.toContain(token)
    expect(seen.url).not.toContain(token)
    expect(seen.referrer).not.toContain(token)
    // Identical to a navigation that never carried a token: the strip pushed nothing.
    expect(seen.entries).toBe(baseline)

    // ⚠ AND GOING BACK REACHES NO TOKEN-BEARING URL, which is the clause's own concern:
    // *"it is written to browser history, which syncs to a cloud profile by default"*.
    await page.goBack().catch(() => null)
    expect(page.url()).not.toContain(token)
    await scanned.close()
  })

  test('sends the token in NO request line, across the whole journey', async ({ page }) => {
    const urls = recordRequests(page)

    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()
    await page.getByRole('button', { name: /just show me the status/i }).click()
    await expect(page.locator('[data-screen="waiting"]')).toBeVisible()
    // Long enough for at least one real poll at the 5.6-8.4 s jittered interval.
    await page.waitForTimeout(9000)

    expect(urls.length).toBeGreaterThan(3)

    for (const url of urls) {
      expect(url, url).not.toContain(sessions().waiting.token)
    }
  })

  test('loads NO third-party origin at RUNTIME -- §3 row 9 (b), the other half', async ({
    page,
  }) => {
    // `build-surface.test.ts` asserts this against the BUILT OUTPUT; this asserts it against
    // what the engine actually fetched, which is the claim a reviewer cares about. A script
    // injected at run time would pass the first and fail this one.
    const urls = recordRequests(page)
    const base = origin()

    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()
    await page.getByRole('button', { name: /just show me the status/i }).click()
    await page.waitForTimeout(9000)

    for (const url of urls) {
      expect(url.startsWith(base) || url.startsWith('data:') || url.startsWith('blob:'), url).toBe(
        true,
      )
    }
  })

  test('announces nothing through Nexts own route announcer, because nothing navigates', async ({
    page,
  }) => {
    // See the long note in the poll-driven case. `§3.0`: all six screens are states of one
    // route, so Next's client-side route announcer is never filled -- which is what keeps
    // the order-ready screen the only thing on this page that interrupts a screen reader.
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()
    await page.getByRole('button', { name: /just show me the status/i }).click()
    await expect(page.locator('[data-screen="waiting"]')).toBeVisible()

    await expect(page.locator('#__next-route-announcer__')).toBeEmpty()
    // And ours is the only OTHER assertive region, and it is absent until the order is ready.
    await expect(page.locator('.qr-visually-hidden[aria-live="assertive"]')).toHaveCount(0)
  })
})

test.describe('the four failure paths the gate names', () => {
  test('expired token -- a real 409 from the real API', async ({ page }) => {
    await scan(page, sessions().expired.token)

    await expect(page.locator('[data-screen="ended"][data-state="expired"]')).toBeVisible()
    await expect(page.getByText(/expired/i).first()).toBeVisible()
  })

  test('invalid token -- a well-formed token the server has never seen', async ({ page }) => {
    // 22 valid base64url characters that resolve to nothing: the shape check passes on the
    // client and the server answers 404. That is the only way to reach this path with a real
    // request, which is what distinguishes it from the malformed case below.
    await scan(page, 'ZZZZZZZZZZZZZZZZZZZZZZ')

    await expect(page.locator('[data-screen="landing"][data-state="invalid"]')).toBeVisible()
  })

  test('malformed fragment -- and it costs the server NOTHING', async ({ page }) => {
    const urls = recordRequests(page)

    await page.goto(`${origin()}/s#not-a-token`)
    await expect(page.locator('[data-screen="landing"][data-state="invalid"]')).toBeVisible()

    // Asserted on the request log: no `/v1/` call was made at all. This is what bounds a
    // garbage-traffic loop on the highest-volume carrier of the scan path.
    expect(urls.filter((url) => url.includes('/v1/'))).toEqual([])
  })

  test('offline -- mid-wait, and the session is NOT abandoned', async ({ page, context }) => {
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()
    await page.getByRole('button', { name: /just show me the status/i }).click()
    await expect(page.locator('[data-screen="waiting"][data-state="healthy"]')).toBeVisible()

    await context.setOffline(true)
    // Long enough for one real poll to fail.
    await expect(page.locator('[data-screen="waiting"][data-state="offline"]')).toBeVisible({
      timeout: 15_000,
    })
    // ⚠ STILL THE WAITING SCREEN, with the order reference on it. Losing the connection
    // mid-wait must not throw a customer out of their session.
    await expect(page.locator('.pg-reference')).toHaveText(sessions().waiting.order_reference)

    await context.setOffline(false)
    await page.getByRole('button', { name: /try again/i }).click()
    await expect(page.locator('[data-screen="waiting"][data-state="healthy"]')).toBeVisible({
      timeout: 15_000,
    })
  })

  test('permission denied -- no retry offered, and polling CONTINUES', async ({ page }) => {
    await answerPermissionWith(page, 'denied')
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    await page.getByRole('button', { name: /enable alerts/i }).click()

    /**
     * ⚠ THE EXPECTED STATE IS DERIVED FROM THE ENGINE, NOT GUESSED FROM THE UI.
     *
     * This read `if (ask.isVisible()) … else expect(unsupported)`, i.e. it assumed that an
     * engine with no "Allow notifications" button must be one with no `Notification` at all.
     * There is a THIRD real shape and Chromium is in it: headless Chromium reports
     * `Notification.permission === 'denied'` with no prompt ever shown, so the app correctly
     * renders `alerts.denied` -- which has no ask button either, because a retry would be a
     * lie. The `else` branch then demanded `unsupported` and failed against a page that was
     * behaving exactly as specified. One engine, every run, since the case was written.
     *
     * So the engine is ASKED what it supports and what it has already decided, and the one
     * state that follows is asserted exactly. No disjunction, nothing branching on the UI
     * the assertion is about.
     */
    const shape = await page.evaluate(() => {
      if (!('Notification' in globalThis)) return 'unsupported'

      return (globalThis as unknown as { Notification: { permission: string } }).Notification
        .permission
    })

    const expected = shape === 'unsupported' ? 'unsupported' : 'denied'

    if (shape === 'default') {
      // The engine will prompt, and the stub answers `denied`. This is the asked-and-refused
      // transition; the two shapes below never reach `requestPermission` at all.
      await page.getByRole('button', { name: /allow notifications/i }).click()
    }

    await expect(page.locator(`[data-screen="alerts"][data-state="${expected}"]`)).toBeVisible()

    // The only control is a way ONWARD. A retry would be a lie: the browser will not ask
    // again. On `unsupported` the iOS install flow is also on screen -- it is the state an
    // iOS tab reaches -- so the count is asserted only where the screen is just the refusal.
    if (expected === 'denied') {
      await expect(page.getByRole('button')).toHaveCount(1)
    }

    await expect(page.getByRole('button', { name: /continue/i })).toBeVisible()

    // Either way the customer reaches the status screen, because O2 made polling the
    // fallback and a denial removes the buzz rather than the product.
    await page.getByRole('button', { name: /continue/i }).click()
    await expect(page.locator('[data-screen="waiting"]')).toBeVisible()
  })
})

test.describe('the three ended states, each from a real session', () => {
  for (const status of ['completed', 'cancelled', 'expired'] as const) {
    test(`${status} -- resolved from the real API and polls no further`, async ({ page }) => {
      const urls = recordRequests(page)

      await scan(page, sessions()[status].token)
      await expect(page.locator(`[data-screen="ended"][data-state="${status}"]`)).toBeVisible()

      const after = urls.length
      await page.waitForTimeout(9000)

      // ⚠ NOTHING RUNS ON A FINISHED SESSION. A product that keeps polling one spends a
      // customer's battery to learn nothing. Asserted on the request log, over a window
      // longer than the whole jittered interval.
      expect(urls.length).toBe(after)
    })
  }

  test('clears the token from the device when the session is over', async ({ page }) => {
    await scan(page, sessions().completed.token)
    await expect(page.locator('[data-screen="ended"]')).toBeVisible()

    await expect
      .poll(async () => page.evaluate(() => window.localStorage.getItem('qrpager.pager.token')))
      .toBeNull()
  })
})

test.describe('scan -> wait -> ready, driven by the REAL poller', () => {
  test('discovers ready on a real poll and moves the customer', async ({ page }) => {
    /**
     * ⚠ EXACTLY ONE RESPONSE IS REWRITTEN, AND HERE IS WHY AND WHAT.
     *
     * The session is a real one whose status is `ready`. The FIRST `/v1/pager/session`
     * response -- and only that one -- has its `status` rewritten to `waiting`, so the journey
     * starts on the waiting screen; every subsequent request, including every poll, reaches
     * the real server and returns the real `ready`.
     *
     * The alternative would be marking a live session ready from inside the browser, and
     * there is no customer route that can: `markReady` is staff-authenticated (Task 3), and
     * adding a control route to `apps/api` is what `QRPAGER-16 §2` puts out of scope. So the
     * choice is one rewritten field or no poll-driven transition at all, and the rewrite is
     * named here rather than buried in a helper.
     *
     * What stays real: the poll itself, its cadence, its 200, its body, the status machine's
     * monotone guard, and the screen.
     */
    let resolves = 0

    await page.route(`${origin()}/v1/pager/session`, async (route) => {
      resolves += 1
      const response = await route.fetch()
      const body = (await response.json()) as Record<string, unknown>

      await route.fulfill({
        response,
        json: resolves === 1 ? { ...body, status: 'waiting' } : body,
      })
    })

    await scan(page, sessions().ready.token)
    await expect(page.locator('[data-screen="landing"][data-state="valid"]')).toBeVisible()

    /**
     * ⚠ NO CLICK, AND THAT IS ITSELF THE PROPERTY. Polling starts the moment a session
     * resolves -- it has to, or a customer lingering on "Enable alerts" would not learn their
     * order is ready until they tapped something. So the transition below arrives on a REAL
     * poll while the customer is still on the landing screen.
     *
     * The first version clicked through to the waiting screen and asserted `waiting.healthy`
     * on the way, which is a race it lost: the real poll can answer `ready` before the click
     * lands. Asserting an intermediate state that the product is allowed to skip is a test
     * defect, not a product one.
     */
    await expect(page.locator('[data-screen="ready"][data-state="fresh"]')).toBeVisible({
      timeout: 20_000,
    })
    await expect(page.locator('.qr-ready__reference')).toHaveText(sessions().ready.order_reference)

    /**
     * The alert's accessible half.
     *
     * ⚠ AND THERE ARE TWO ASSERTIVE REGIONS ON THIS PAGE, WHICH IS A MEASURED FACT RATHER
     * THAN A CHOICE. `a11y.tsx` argues that the order-ready screen is *"the one place in the
     * product that earns"* `assertive` -- and **Next injects its own**,
     * `#__next-route-announcer__`, into every page it serves. It was found by this assertion
     * failing with *"strict mode violation: resolved to 2 elements"*.
     *
     * It is harmless HERE and the reason is asserted below rather than assumed: Next fills
     * that element only on a client-side route CHANGE, and this app performs none -- all six
     * screens are states of one route (`§3.0`), which is the property that makes `§3` row 9's
     * clause (a) hold for the whole journey. So the announcer stays empty and announces
     * nothing. If a future task adds a client-side navigation here, the assertion below is
     * what notices.
     */
    await expect(page.locator('.qr-visually-hidden[aria-live="assertive"]')).toContainText(
      sessions().ready.order_reference,
    )
    await expect(page.locator('#__next-route-announcer__')).toBeEmpty()

    await page.getByRole('button', { name: /got it/i }).click()
    await expect(page.locator('[data-screen="ready"][data-state="acknowledged"]')).toBeVisible()
    // The reference is still there: it is what the customer is holding up at the counter.
    await expect(page.locator('.qr-ready__reference')).toHaveText(sessions().ready.order_reference)
  })
})

test.describe('the accessibility bar, in a real engine', () => {
  test('is keyboard reachable with a visible focus ring on every control', async ({ page }) => {
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    const controls = await page.locator('button, a[href], summary, input').count()

    expect(controls).toBeGreaterThan(0)

    for (let index = 0; index < controls; index += 1) {
      await page.keyboard.press('Tab')

      const ring = await page.evaluate(() => {
        const active = document.activeElement

        if (active === null || active === document.body) return null

        const style = getComputedStyle(active)

        return {
          tag: active.tagName,
          outlineWidth: style.outlineWidth,
          outlineStyle: style.outlineStyle,
          outlineColor: style.outlineColor,
        }
      })

      if (ring === null) continue

      // ⚠ A COMPUTED WIDTH, WHICH IS WHY THIS IS HERE AND NOT IN jsdom. `§12` requires a
      // *visible focus ring*, and a declaration is not a ring: only an engine resolves
      // `outline-width`. A `0px` or a `none` here is the clause failing.
      expect(ring.outlineStyle, ring.tag).not.toBe('none')
      expect(Number.parseFloat(ring.outlineWidth), ring.tag).toBeGreaterThan(0)
    }
  })

  test('sets lang and dir on the same element, from the browsers own locale', async ({ page }) => {
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    const themed = page.locator('[data-theme]').first()

    await expect(themed).toHaveAttribute('lang', /^(en|ar)$/)
    await expect(themed).toHaveAttribute('dir', /^(ltr|rtl)$/)
  })

  test('respects prefers-reduced-motion: every duration resolves to zero', async ({ page }) => {
    // ⚠ NO MOTION, NOT LESS -- `QRPAGER-15 §6` trap 3. A duration token that resolved to
    // `0.01s` would satisfy "reduced" and fail this.
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    const durations = await page.evaluate(() =>
      [...document.querySelectorAll('*')].flatMap((element) => {
        const style = getComputedStyle(element)

        return [
          ...style.transitionDuration.split(',').map((d) => d.trim()),
          ...style.animationDuration.split(',').map((d) => d.trim()),
        ]
      }),
    )

    expect(durations.length).toBeGreaterThan(0)

    for (const duration of durations) {
      expect(Number.parseFloat(duration)).toBe(0)
    }
  })
})
