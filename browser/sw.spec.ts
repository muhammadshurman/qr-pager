import { join } from 'node:path'

import { expect, test, type Page } from '@playwright/test'

import { origin, scan, sessions } from './support/e2e.ts'

/**
 * The two shapes the page-side code shares with the node-side code.
 *
 * `declare global` rather than an import: `addInitScript` and `evaluate` bodies are serialised
 * and run in the BROWSER, so they can reference a type but never a value from this module.
 */
declare global {
  interface CapturedLike {
    notifications: { title: string; options: Record<string, unknown> }[]
    fetches: { url: string; method: string; body: string }[]
    messages: unknown[]
    subscribes: number
  }

  interface DriveOptionsLike {
    payload?: unknown
    rawText?: string
    credential?: Record<string, unknown> | null
    harnessCredential?: Record<string, unknown> | null
    oldSubscription?: { endpoint: string } | null
  }
}

/**
 * ================================================================================
 * THE SERVICE WORKER, AND THE NAMED INVARIANT's HANDLER
 * ================================================================================
 *
 * The invariant: *"a rotated subscription is re-registered by the `pushsubscriptionchange`
 * handler and the next notification still arrives."*
 *
 * ⚠ TWO INSTRUMENTS, AND THE SPLIT IS STATED BECAUSE IT BOUNDS WHAT IS PROVEN.
 *
 * (1) **ALL THREE ENGINES, the real file's real logic.** `public/sw.js` is loaded into a
 *     worker-shaped shim in the page, its listeners are captured, and real events are
 *     dispatched at them. What this proves is every branch of the handler, in the engine, on
 *     the shipped bytes. What it does NOT prove is the engine's own delivery.
 *
 * (2) **CHROMIUM, a real registration and a real push over CDP.** `ServiceWorker.deliverPushMessage`
 *     makes the browser deliver a push to a genuinely registered worker, so the whole path
 *     from the engine's push plumbing to a visible notification is exercised once. No such
 *     API exists for Firefox or WebKit in any runner.
 *
 * ⚠ AND WHAT NEITHER PROVES IS THE TRANSPORT. A push has to come from Apple's, Google's or
 * Mozilla's push service, and no test runner can make one deliver. **That half is a physical
 * device**, which is `QRPAGER-24` and `R-073` -- and it is why `task-9` is not tagged.
 */

/** The shipped worker, loaded as a real script rather than evaluated from a string. */
const SW_PATH = join(import.meta.dirname, '..', 'apps', 'web', 'public', 'sw.js')

interface Captured {
  readonly notifications: { title: string; options: Record<string, unknown> }[]
  readonly fetches: { url: string; method: string; body: string }[]
  readonly messages: unknown[]
  readonly subscribes: number
}

interface DriveOptions {
  readonly payload?: unknown
  readonly rawText?: string
  readonly credential?: Record<string, unknown> | null
  readonly harnessCredential?: Record<string, unknown> | null
  readonly oldSubscription?: { endpoint: string } | null
}

/**
 * Install a worker-shaped `self` on the page, then load the SHIPPED `sw.js` as a script, then
 * dispatch one real event at the listener it registered.
 *
 * ⚠ A SCRIPT TAG, NOT `new Function(source)`. The first version evaluated the file from a
 * string, which `@typescript-eslint/no-implied-eval` refuses -- correctly: a string-evaluated
 * module is not the file the browser would run, it has a different scope chain and a different
 * error provenance. Loading it with `addScriptTag` runs the actual bytes through the engine's
 * own parser, which is the whole point of driving it in a browser at all.
 *
 * ⚠ AND `self === window` IN A PAGE, which is what makes this work: `sw.js` registers its
 * listeners with `self.addEventListener`, so in a page they land on `window` and a dispatched
 * event reaches them. What the init script below provides is the rest of a worker's `self` --
 * `registration`, `clients`, `skipWaiting` -- plus recording wrappers over `caches` and
 * `fetch`. Nothing in the file is rewritten.
 */
async function driveWorker(
  page: Page,
  event: 'push' | 'pushsubscriptionchange',
  options: DriveOptions = {},
): Promise<Captured> {
  await page.addInitScript((raw: string) => {
    const parsed = JSON.parse(raw) as DriveOptionsLike
    const captured: CapturedLike = {
      notifications: [],
      fetches: [],
      messages: [],
      subscribes: 0,
    }
    const stores = new Map<string, Map<string, unknown>>()

    if (parsed.credential !== null && parsed.credential !== undefined) {
      stores.set('qrpager-pager-credential', new Map([['/pager-credential', parsed.credential]]))
    }

    if (parsed.harnessCredential !== null && parsed.harnessCredential !== undefined) {
      stores.set('qrpager-harness', new Map([['/harness-token', parsed.harnessCredential]]))
    }

    const scope = window as unknown as Record<string, unknown>

    scope.__qrpagerCaptured = captured
    scope.skipWaiting = () => undefined
    scope.registration = {
      showNotification: (title: string, opts: Record<string, unknown>) => {
        captured.notifications.push({ title, options: opts })

        return Promise.resolve()
      },
      pushManager: {
        subscribe: () => {
          captured.subscribes += 1

          return Promise.resolve({
            endpoint: 'https://push.example/rotated',
            toJSON: () => ({
              endpoint: 'https://push.example/rotated',
              keys: { p256dh: 'P', auth: 'A' },
            }),
          })
        },
      },
    }
    scope.clients = {
      claim: () => Promise.resolve(),
      matchAll: () =>
        Promise.resolve([
          {
            url: `${location.origin}/s`,
            focus: () => Promise.resolve(),
            postMessage: (message: unknown) => {
              captured.messages.push(message)
            },
          },
        ]),
      openWindow: () => Promise.resolve(null),
    }
    /**
     * ⚠ `defineProperty`, NOT AN ASSIGNMENT, AND THAT COST FIVE RED CASES. `window.caches` is
     * a READ-ONLY accessor, so `window.caches = {…}` is silently ignored in sloppy mode -- the
     * shim looked installed, `sw.js` read the real `CacheStorage`, found no credential, and
     * the rotation handler correctly did nothing. The assertions then reported
     * `Array []` against an expected `['DELETE','POST']`, which names the symptom and not the
     * cause. `fetch`, `registration`, `clients` and `skipWaiting` are writable or new, so only
     * this one needed it -- and it is the one that decides whether the handler can read
     * anything at all.
     */
    const cacheShim = {
      keys: () => Promise.resolve([...stores.keys()]),
      delete: (name: string) => Promise.resolve(stores.delete(name)),
      open: (name: string) => {
        const store = stores.get(name) ?? new Map<string, unknown>()
        stores.set(name, store)

        return Promise.resolve({
          put: (key: string, value: unknown) => {
            store.set(key, value)

            return Promise.resolve()
          },
          match: (key: string) => {
            const held = store.get(key)

            return Promise.resolve(
              held === undefined ? undefined : { json: () => Promise.resolve(held) },
            )
          },
          delete: (key: string) => Promise.resolve(store.delete(key)),
        })
      },
    }
    Object.defineProperty(window, 'caches', { configurable: true, value: cacheShim })

    scope.fetch = (url: string, init?: { method?: string; body?: string }) => {
      captured.fetches.push({
        url,
        method: init?.method ?? 'GET',
        body: init?.body ?? '',
      })

      return Promise.resolve({ ok: true, status: 201 })
    }
  }, JSON.stringify(options))

  // A fresh document per drive, so each case sees a worker that has just installed.
  await page.goto(`${origin()}/s`)
  await page.addScriptTag({ path: SW_PATH })

  return page.evaluate(
    async ([kind, raw]) => {
      const parsed = JSON.parse(raw) as DriveOptionsLike
      const scope = window as unknown as Record<string, unknown>
      const captured = scope.__qrpagerCaptured as CapturedLike
      const waits: Promise<unknown>[] = []

      // A `CustomEvent` with the worker-event members attached as own properties: the
      // listener receives this exact object, so `event.waitUntil` and `event.data` are the
      // ones it reads. The TYPE is the engine's own Event, dispatched by the engine.
      const event = new CustomEvent(kind) as CustomEvent & Record<string, unknown>

      event.waitUntil = (promise: Promise<unknown>) => {
        waits.push(promise)
      }

      if (kind === 'push') {
        event.data =
          parsed.payload === undefined && parsed.rawText === undefined
            ? null
            : {
                json: () => {
                  if (parsed.payload === undefined) throw new SyntaxError('not json')

                  return parsed.payload
                },
                text: () => parsed.rawText ?? '',
              }
      } else if (parsed.oldSubscription !== null && parsed.oldSubscription !== undefined) {
        event.oldSubscription = parsed.oldSubscription
      }

      const dispatched = window.dispatchEvent(event)

      if (!dispatched && waits.length === 0) {
        throw new Error(`sw.js registered no ${kind} listener, or it did nothing`)
      }

      await Promise.all(waits)

      return captured
    },
    [event, JSON.stringify(options)] as const,
  )
}

test.describe('the push handler, on the shipped bytes', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${origin()}/s`)
  })

  test('derives the notification tag from the payload -- R-075', async ({ page }) => {
    /**
     * `R-075`'s acceptance clause, verbatim: *"the service worker shows a notification with a
     * `tag` derived from the payload, so a duplicate replaces the visible notification rather
     * than adding one -- and a test drives two identical pushes and asserts one notification."*
     */
    const payload = { v: 1, event: 'pager.ready', order_reference: 'A-42' }
    const first = await driveWorker(page, 'push', { payload })
    const second = await driveWorker(page, 'push', { payload })

    expect(first.notifications.length).toBe(1)
    expect(first.notifications[0]?.title).toBe('Your order is ready')
    expect(first.notifications[0]?.options.body).toContain('A-42')
    expect(first.notifications[0]?.options.tag).toBe('qrpager:pager.ready:A-42')
    // Two identical pushes carry the SAME tag, which is what makes the second REPLACE the
    // first rather than stack. The browser-level consequence is asserted in the CDP case.
    expect(second.notifications[0]?.options.tag).toBe(first.notifications[0]?.options.tag)
    // `renotify`: without it a replacement is silent, so a renotify staff sent deliberately
    // would arrive with no buzz.
    expect(first.notifications[0]?.options.renotify).toBe(true)
  })

  test('gives a renotify a DIFFERENT tag, so two deliberate alerts cannot collapse', async ({
    page,
  }) => {
    // The same distinction the server's `Topic` makes: *"a renotify and the ready it repeats
    // get DIFFERENT topics, so two notifications staff genuinely asked for cannot collapse
    // into one."*
    const ready = await driveWorker(page, 'push', {
      payload: { v: 1, event: 'pager.ready', order_reference: 'A-42' },
    })
    const renotify = await driveWorker(page, 'push', {
      payload: { v: 1, event: 'pager.renotify', order_reference: 'A-42' },
    })

    expect(renotify.notifications[0]?.options.tag).not.toBe(ready.notifications[0]?.options.tag)
    expect(renotify.notifications[0]?.options.tag).toBe('qrpager:pager.renotify:A-42')
  })

  test('shows the cancellation as a cancellation, not as an order being ready', async ({
    page,
  }) => {
    const captured = await driveWorker(page, 'push', {
      payload: { v: 1, event: 'pager.cancelled', order_reference: 'A-42' },
    })

    expect(captured.notifications[0]?.title).toBe('Your order was cancelled')
    expect(captured.notifications[0]?.options.tag).toBe('qrpager:pager.cancelled:A-42')
  })

  test('still shows something for a payload it cannot read -- QRPAGER-24s own clause', async ({
    page,
  }) => {
    // *"a push that arrived and could not be read is still the fact QRPAGER-24 is trying to
    // establish, and swallowing it would turn a successful delivery into a silent failure the
    // operator cannot tell from no delivery at all."* And the harness's own tag is preserved.
    const unparseable = await driveWorker(page, 'push', { rawText: 'not json' })

    expect(unparseable.notifications.length).toBe(1)
    expect(unparseable.notifications[0]?.options.tag).toBe('qrpager-harness')

    const empty = await driveWorker(page, 'push')

    expect(empty.notifications.length).toBe(1)
    expect(empty.notifications[0]?.options.body).toContain('no payload')
  })

  test('refuses a payload from a version it does not know, rather than guessing', async ({
    page,
  }) => {
    const captured = await driveWorker(page, 'push', {
      payload: { v: 99, event: 'pager.ready', order_reference: 'A-42' },
    })

    expect(captured.notifications[0]?.options.tag).toBe('qrpager-harness')
  })

  test('tells an open page, with NO content it could render', async ({ page }) => {
    /**
     * ⚠ A NUDGE, NOT A SOURCE. The page's reducer renders the reference from its own snapshot,
     * so a message from any other script on this origin cannot put attacker-chosen text on
     * the one screen a customer reads from across a room. Asserted here on the message's own
     * SHAPE: it carries a type and nothing else.
     */
    const captured = await driveWorker(page, 'push', {
      payload: { v: 1, event: 'pager.ready', order_reference: 'A-42' },
    })

    expect(captured.messages).toEqual([{ type: 'qrpager/push' }])
  })
})

test.describe('the rotation handler -- the named invariants own clause', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${origin()}/s`)
  })

  test('DELETES the old endpoint and REGISTERS the new one, in that order', async ({ page }) => {
    /**
     * The two hardenings QRPAGER-24 named for Task 9 by name. The DELETE matters more than it
     * looks: WebKit bug 249766, closed WONTFIX, records that a push to an EXPIRED Safari
     * endpoint still answers **200**, so Task 4's 404/410 pruning never fires there and an
     * explicit DELETE is the only mechanism that removes the dead row.
     */
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        endpoint: 'https://push.example/old',
      },
    })

    expect(captured.fetches.map((f) => f.method)).toEqual(['DELETE', 'POST'])
    expect(captured.fetches[0]?.body).toContain('https://push.example/old')
    expect(captured.fetches[1]?.body).toContain('https://push.example/rotated')
    expect(captured.subscribes).toBe(1)
  })

  test('uses the REMEMBERED endpoint when the engine supplies no oldSubscription', async ({
    page,
  }) => {
    // Firefox: MDN marks its `pushsubscriptionchange` `partial_implementation` -- *"The event
    // does not have the `oldSubscription` and `newSubscription` properties"*. A handler built
    // on them works in Chromium and does nothing there.
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        endpoint: 'https://push.example/remembered',
      },
      oldSubscription: null,
    })

    expect(captured.fetches[0]?.method).toBe('DELETE')
    expect(captured.fetches[0]?.body).toContain('https://push.example/remembered')
  })

  test('prefers the events own oldSubscription when the engine DOES supply one', async ({
    page,
  }) => {
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        endpoint: 'https://push.example/remembered',
      },
      oldSubscription: { endpoint: 'https://push.example/from-the-event' },
    })

    expect(captured.fetches[0]?.body).toContain('https://push.example/from-the-event')
  })

  test('does NOT give up silently when its cache holds no token', async ({ page }) => {
    /**
     * ⚠ THE FIRST OF THE TWO THINGS QRPAGER-24 NAMED. Its own handler *"gives up silently"*
     * when the credential entry is absent -- which the harness itself called *"exactly the
     * failure Task 9's gate clause is about"*. It now subscribes anyway and tells every open
     * client, so `reconcileSubscription()` completes the registration at the next launch.
     */
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: { applicationServerKey: 'AQAB', apiBase: '' },
    })

    expect(captured.subscribes).toBe(1)
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
    // And it posted nothing, because it could not authenticate.
    expect(captured.fetches).toEqual([])
  })

  test('tells the page when it has no application server key either', async ({ page }) => {
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: { token: 'AbCdEfGhIjKlMnOpQrStUv', apiBase: '' },
    })

    expect(captured.subscribes).toBe(0)
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
  })

  test('reads QRPAGER-24s own cache entry as well as Task 9s', async ({ page }) => {
    // The operator script still drives this file, so a credential written by the HARNESS must
    // keep working. Asserted by giving the shim only that cache and no Task 9 one.
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: null,
      harnessCredential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
      },
    })

    expect(captured.fetches.some((f) => f.method === 'POST')).toBe(true)
    expect(captured.subscribes).toBe(1)
  })
})

test.describe('the offline shell -- §4.1s offline screen, with a real worker', () => {
  /**
   * ⚠ CHROMIUM ONLY, AND IT IS A HARNESS LIMIT RATHER THAN A PRODUCT ONE, SAID PLAINLY.
   *
   * These two cases need a REAL `navigator.serviceWorker.register()`, and the harness serves a
   * self-signed certificate because the engines reach the host under a name that is on no
   * secure-origin allow-list. A worker script is fetched by the browser's own network stack,
   * not by the page, so Playwright's `ignoreHTTPSErrors` does not reach it -- measured:
   * *"An SSL certificate error occurred when fetching the script."* Chromium has
   * `--ignore-certificate-errors`; Firefox and WebKit have no equivalent in any runner.
   *
   * WHAT IS THEREFORE *NOT* PROVEN IN FIREFOX AND WEBKIT: that a cached document boots the app
   * with the network down. What IS proven in all three is every other offline clause --
   * `context.setOffline(true)` mid-wait keeps the customer in their session with the order
   * reference on screen (`pager.spec.ts`), and the `offline` state's own copy, controls and
   * geometry are asserted in jsdom and in the one-handed measurement. The gap is the engine's
   * cache serving a navigation, which is the engine's behaviour rather than this project's.
   */
  test.skip(
    ({ browserName }) => browserName !== 'chromium',
    'a REAL service worker registration needs a trusted certificate; only Chromium has a flag for it',
  )

  test('boots the app from the cache with the network down', async ({ page, context }) => {
    /**
     * ⚠ A REAL REGISTRATION AND A REAL RELOAD. The shim above cannot answer this: whether a
     * cached document actually serves a navigation is the engine's decision, not the
     * handler's.
     *
     * `§4.1`: *"Offline / error -- honest message, retry, no dead end."* A customer who
     * installed this to the Home Screen and walked into a basement has to reach SOMETHING.
     */
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    // Register the worker directly: the app registers it only once notification permission is
    // granted, and granting one is not available in every engine.
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js', { scope: '/' })
      await navigator.serviceWorker.ready
    })

    // Warm the shell through a real navigation, which is what populates the cache.
    await page.reload()
    await expect(page.locator('[data-screen]')).toBeVisible()

    await context.setOffline(true)
    await page.reload()

    // The document came from the cache and the app booted. It then renders its own offline
    // state, because every request it makes fails.
    await expect(page.locator('[data-screen]')).toBeVisible({ timeout: 20_000 })
    await expect(page.locator('.pg-mark')).toBeVisible()

    await context.setOffline(false)
  })

  test('caches NO API response, ever', async ({ page }) => {
    /**
     * `QRPAGER-16 §3` question 4's answer, asserted: a poll response is per-session data
     * behind a per-session secret, and a cached copy is that answer left on a device the
     * customer may hand to someone else.
     */
    await scan(page, sessions().waiting.token)
    await expect(page.locator('.pg-reference')).toBeVisible()

    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js', { scope: '/' })
      await navigator.serviceWorker.ready
    })

    await page.getByRole('button', { name: /just show me the status/i }).click()
    await expect(page.locator('[data-screen="waiting"]')).toBeVisible()
    // Long enough for at least one real poll to have happened and been cacheable.
    await page.waitForTimeout(9000)

    const cached = await page.evaluate(async () => {
      const names = await caches.keys()
      const urls: string[] = []

      for (const name of names) {
        const cache = await caches.open(name)

        for (const request of await cache.keys()) urls.push(request.url)
      }

      return urls
    })

    for (const url of cached) {
      expect(url, `${url} is in a cache`).not.toContain('/v1/')
    }
  })
})
