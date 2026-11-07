import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'

import { expect, test } from '@playwright/test'

/**
 * ================================================================================
 * WHAT THE THREE ENGINES ACTUALLY SEND AND ACTUALLY HAVE -- `R-194` AND `R-195`
 * ================================================================================
 *
 * `R-194`'s owner cell: **Task 9** *"owns it with the E2E harness -- it is the task that
 * first drives real engines and can therefore measure which of them send the header."* The
 * header is `Sec-Fetch-Dest: document`, which `PanelProblemPage::wants()` requires before it
 * will render HTML instead of `application/problem+json`.
 *
 * ⚠ IT IS MEASURED ON THE SERVER's SIDE, AND THAT IS NOT A STYLE CHOICE. Playwright's
 * `request.headers()` is the pre-flight snapshot it took BEFORE the browser added its own,
 * and `request.allHeaders()` does not expose `sec-fetch-*` for a main-frame navigation the
 * runner initiated either -- measured, in chromium: both answer `undefined` while the server
 * receives the header. So the only honest instrument is a server that records what arrived,
 * which is what this file is.
 *
 * ⚠ AND THE SERVER RUNS INSIDE THIS PROCESS, so this spec needs no stack and no host
 * networking: it is in the `kit` projects and runs in `pnpm gate`. It is also why `R-194`'s
 * answer stops being a transcript and becomes a gate-checked fact.
 *
 * `R-195` is the sibling row -- three branches of that representation decision with no case.
 * Those three cases belong to `apps/api`'s own suite and are added there; what this file owes
 * is the ENGINE half, which is the one thing `apps/api` cannot measure.
 */

let server: Server
let base = ''
const received: { url: string; headers: Record<string, string | string[] | undefined> }[] = []

test.beforeAll(async () => {
  server = createServer((request: IncomingMessage, response: ServerResponse) => {
    received.push({ url: request.url ?? '', headers: request.headers })
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
    response.end(
      '<!doctype html><html lang="en"><head><title>engine probe</title></head>' +
        '<body><h1 id="probe">engine probe</h1></body></html>',
    )
  })

  await new Promise<void>((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve()
    })
  })

  const address = server.address()

  if (address === null || typeof address === 'string') throw new Error('no port')

  base = `http://127.0.0.1:${String(address.port)}`
})

test.afterAll(async () => {
  await new Promise<void>((resolve) => {
    server.close(() => {
      resolve()
    })
  })
})

test.describe('R-194 — Sec-Fetch-Dest on a genuine top-level navigation', () => {
  test('is sent as `document`, with `Sec-Fetch-Mode: navigate`', async ({ page, browserName }) => {
    const before = received.length
    await page.goto(`${base}/probe`)
    await expect(page.locator('#probe')).toBeVisible()

    const navigation = received[before]

    expect(navigation, 'the probe server received no request').toBeDefined()
    // ⚠ THE FIGURE `R-194` ASKS FOR, per engine, by name. Chromium, Firefox and WebKit all
    // send it -- so the narrowing that row describes is real only for engines none of these
    // is (Safari before 16.4, older embedded webviews, anything scripted). The row is
    // therefore NARROWED by this measurement and not closed by it.
    expect(navigation?.headers['sec-fetch-dest'], browserName).toBe('document')
    expect(navigation?.headers['sec-fetch-mode'], browserName).toBe('navigate')
    expect(navigation?.headers['sec-fetch-site'], browserName).toBe('none')
  })

  test('sends an Accept that `S-2` measured is NOT sufficient on its own', async ({
    page,
    browserName,
  }) => {
    /**
     * `R-194` records security `S-2`'s measurement: an `Accept` of any-type, and of
     * `text/html`, are also
     * what a browser sends for an image and a stylesheet, so without the header a binary
     * endpoint answered 2 kB of HTML on its error path."* This is the other half of that --
     * the navigation's own `Accept` really does contain `text/html`, so a decision made on
     * `Accept` alone would fire for subresources too.
     */
    const before = received.length
    await page.goto(`${base}/probe`)
    await expect(page.locator('#probe')).toBeVisible()

    const accept = received[before]?.headers.accept

    expect(typeof accept === 'string' ? accept : '', browserName).toContain('text/html')
  })
})

test.describe('the PWA capabilities each engine reports', () => {
  test('has a service worker, a PushManager and a Notification constructor', async ({
    page,
    browserName,
  }) => {
    // The three independent checks `pushCapability()` makes, measured per engine so the
    // `unsupported` state's reachability is a fact rather than an assumption.
    await page.goto(`${base}/probe`)

    const caps = await page.evaluate(() => ({
      serviceWorker: 'serviceWorker' in navigator,
      pushManager: 'PushManager' in globalThis,
      notification: typeof (globalThis as unknown as Record<string, unknown>).Notification,
      wakeLock: 'wakeLock' in navigator,
      vibrate: 'vibrate' in navigator,
      caches: 'caches' in globalThis,
      displayMode: typeof matchMedia('(display-mode: standalone)').matches,
    }))

    expect(caps.serviceWorker, browserName).toBe(true)
    expect(caps.pushManager, browserName).toBe(true)
    expect(caps.notification, browserName).toBe('function')
    expect(caps.caches, browserName).toBe(true)
    expect(caps.displayMode, browserName).toBe('boolean')
  })

  test('does NOT have navigator.vibrate in firefox or webkit -- why the alert only attempts it', async ({
    page,
    browserName,
  }) => {
    /**
     * ⚠ THE MEASUREMENT THAT MADE VIBRATION AN ENHANCEMENT RATHER THAN PART OF THE ALERT.
     * `§4.1` requires the order-ready screen to carry *"sound + vibration"*; `navigator.vibrate`
     * is absent in two of the three engines this gate drives and has never existed in Safari.
     * A screen whose alert depended on it would be silent on every iPhone.
     *
     * Asserted as an EXACT expectation per engine, so a future engine that gains it changes
     * this figure deliberately rather than silently.
     */
    await page.goto(`${base}/probe`)

    const vibrate = await page.evaluate(() => 'vibrate' in navigator)

    expect(vibrate, browserName).toBe(browserName === 'chromium')
  })

  test('reports navigator.wakeLock as PRESENT, which the compat data says is not capability', async ({
    page,
    browserName,
  }) => {
    /**
     * ⚠ PRESENCE IS NOT CAPABILITY, AND THIS IS THE ASSERTION THAT SAYS SO. MDN's compat data
     * puts `WakeLock` at safari_ios **18.4** full and **16.4-18.4 partial**, with the note
     * verbatim: *"Does not work in standalone Home Screen Web Apps."* -- which is the exact
     * configuration this product requires for notifications to work on iOS at all.
     *
     * All three engines here report it present. That is why `wake.ts` renders the toggle from
     * the OUTCOME of a real `request('screen')` and not from a detection: a detection says
     * yes on every engine available to test and lies about the one that matters.
     */
    await page.goto(`${base}/probe`)

    const wakeLock = await page.evaluate(() => 'wakeLock' in navigator)

    expect(wakeLock, browserName).toBe(true)
  })
})
