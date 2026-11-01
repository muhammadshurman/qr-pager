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
    /**
     * ⚠ `§11` round two, QA H-2 / SEC-R2 M-1: the credential the worker WRITES BACK was
     * reported by nothing, so a write that dropped `expiresAt` -- making the entry
     * self-purging on the next read and breaking the named invariant's second rotation --
     * passed every case. Read out of the shim's own store after the drive.
     */
    credentialAfter: unknown
    shellAfter: unknown
  }

  interface DriveOptionsLike {
    payload?: unknown
    rawText?: string
    credential?: Record<string, unknown> | null
    harnessCredential?: Record<string, unknown> | null
    oldSubscription?: { endpoint: string } | null
    /** `§11` round two, QA M-3: the POST-failure branch was reachable by no case. */
    fetchStatus?: number
    fetchThrows?: boolean
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
  /** What the worker LEFT BEHIND, not what it was given -- see CapturedLike above. */
  readonly credentialAfter: unknown
  readonly shellAfter: unknown
}

interface DriveOptions {
  readonly payload?: unknown
  readonly rawText?: string
  readonly fetchStatus?: number
  readonly fetchThrows?: boolean
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
      credentialAfter: null,
      shellAfter: null,
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
    scope.__qrpagerStores = stores
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

      // ⚠ THE OUTCOME IS A KNOB, AND IT WAS ALWAYS `{ok:true,status:201}`. `§11` round two,
      // QA M-3: the handler's POST-failure branch -- added in round one for QA M-2 -- could not
      // be reached by any case, while `PLAN §4a` claimed every branch was driven.
      if (parsed.fetchThrows === true) {
        return Promise.reject(new Error('planted network failure'))
      }

      const status = parsed.fetchStatus ?? 201

      return Promise.resolve({ ok: status >= 200 && status < 300, status })
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

      // The stores, after the handler has run. `stores` is the shim's own Map, so this is what
      // the worker actually left behind rather than what it was given.
      const readStore = (name: string, key: string) => {
        const held = (scope.__qrpagerStores as Map<string, Map<string, unknown>>).get(name)

        return held === undefined ? null : (held.get(key) ?? null)
      }

      captured.credentialAfter = readStore('qrpager-pager-credential', '/pager-credential')
      captured.shellAfter = readStore('qrpager-shell-v1', '/s')

      return captured
    },
    [event, JSON.stringify(options)] as const,
  )
}

/**
 * An expiry comfortably in the future, for the cases that plant a credential meant to be
 * LIVE. `sw.js` treats an absent or past `expiresAt` as expired (`§11` SEC M-2), so a case
 * that omits this is asserting the purge rather than whatever it meant to assert — which is
 * why it is named here rather than inlined seven times.
 */
const LIVE = () => new Date(Date.now() + 3_600_000).toISOString()

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
        expiresAt: LIVE(),
      },
    })

    expect(captured.fetches.map((f) => f.method)).toEqual(['DELETE', 'POST'])
    expect(captured.fetches[0]?.body).toContain('https://push.example/old')
    expect(captured.fetches[1]?.body).toContain('https://push.example/rotated')
    expect(captured.subscribes).toBe(1)
  })

  test('and the NEXT NOTIFICATION ARRIVES at the rotated endpoint -- the invariants other half', async ({
    page,
  }) => {
    /**
     * ⛔ THE SECOND HALF OF THE NAMED INVARIANT, AND IT WAS ASSERTED NOWHERE. `§11` round one,
     * QA L-3, and **RULED by Muhammad on 2026-11-01: it must be ASSERTED, not inferred.**
     *
     * `§3` row 9's middle column reads *"a rotated subscription is re-registered by the
     * `pushsubscriptionchange` handler **and the next notification still arrives** — tested,
     * not assumed"*, and `§11 › Round limit` makes that sentence the ONLY thing that blocks
     * the tag once the review cap is spent. Re-registration was proven in three engines; the
     * arrival half was declared undrivable and left to `R-073`'s device run, which left the
     * fallback bar ambiguous — the one thing `§11` cannot tolerate.
     *
     * ⚠ WHAT MAKES IT DRIVABLE: the push TRANSPORT is Apple's, Google's or Mozilla's and no
     * runner can make one deliver — that part is genuinely `R-073`. But *"the next
     * notification still arrives"* is a claim about THIS WORKER: that after a rotation, a push
     * delivered to the NEW endpoint is handled and shown. The edge is already mocked for every
     * other case in this file, so the chain is driven end to end on the shipped bytes:
     *
     *   1. rotate — the handler DELETEs the old endpoint and registers the new one,
     *   2. take the endpoint the handler actually registered, from its own POST body,
     *   3. deliver a push to THAT endpoint and assert the notification appears, with the
     *      reference the dispatcher's real payload shape carries.
     *
     * Step 2 is what makes this more than two independent cases: the push is sent to the
     * endpoint the rotation produced, not to one the test chose. A handler that registered one
     * endpoint and listened on another would pass both halves separately and fail here.
     */
    const rotated = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        endpoint: 'https://push.example/old',
        expiresAt: LIVE(),
      },
    })

    const post = rotated.fetches.find((f) => f.method === 'POST')

    expect(
      post,
      'the rotation registered nothing, so there is no endpoint to deliver to',
    ).toBeTruthy()

    // Read out of the handler's own POST body rather than chosen by this test: that is what
    // makes the delivery below a test of the SAME endpoint the rotation produced.
    const registered = (JSON.parse(post?.body ?? '{}') as Record<string, unknown>).endpoint

    expect(registered).toBe('https://push.example/rotated')

    // The push, to the endpoint the handler itself registered, in Task 4's real payload shape.
    const delivered = await driveWorker(page, 'push', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        endpoint: typeof registered === 'string' ? registered : '',
        expiresAt: LIVE(),
      },
      payload: { v: 1, event: 'pager.ready', order_reference: 'A-42' },
    })

    expect(delivered.notifications.length, 'the push to the rotated endpoint showed nothing').toBe(
      1,
    )
    expect(delivered.notifications[0]?.title).toBe('Your order is ready')
    expect(delivered.notifications[0]?.options.body).toContain('A-42')
    // And it is the READY notification, not some other one: the tag carries the event and the
    // reference, which is the server's own `Topic` shape.
    expect(delivered.notifications[0]?.options.tag).toBe('qrpager:pager.ready:A-42')
  })

  test('sends the token to the CONFIGURED origin even when the cache names another -- SEC-R2 H-1', async ({
    page,
  }) => {
    /**
     * ⛔ THE ATTACK ROUND ONE DID NOT CLOSE. `§11` round two, SEC-R2 H-1.
     *
     * Round one's SEC M-3 fix removed the harness cache NAME from the worker and left the
     * MECHANISM: `apiBase` still came out of the credential entry, every token-bearing request
     * went to it, and the handler wrote it BACK — so a planted destination outlived the script
     * that planted it, which is the property that made the finding High. And the harness page
     * was repointed at that very entry, so the planting surface got wider.
     *
     * The worker now derives its API origin from its own script URL and never from stored data.
     * This case plants a hostile `apiBase` in the cache the worker DOES read, with a live
     * expiry so nothing else rejects it, and asserts that no request leaves the configured
     * origin and that the value is not written back.
     */
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: 'https://attacker.example',
        endpoint: 'https://push.example/old',
        expiresAt: LIVE(),
      },
    })

    // The handler ran: it did rotate. What it must not have done is send anywhere else.
    expect(captured.subscribes).toBe(1)
    expect(captured.fetches.length).toBeGreaterThan(0)
    expect(
      captured.fetches.filter((f) => f.url.includes('attacker.example')),
      'the worker sent the raw token to an origin a cache entry chose',
    ).toEqual([])
    for (const call of captured.fetches) {
      expect(call.url, 'a request left the configured origin').toMatch(/^\/v1\//)
    }

    // AND THE PERSISTENCE IS GONE: the write-back carries no `apiBase` at all, so a planted
    // destination cannot outlive the script that planted it.
    expect(JSON.stringify(captured.credentialAfter)).not.toContain('attacker.example')
    expect(Object.keys(captured.credentialAfter as Record<string, unknown>)).not.toContain(
      'apiBase',
    )
  })

  test('writes back a credential the NEXT rotation can still use -- QA H-2', async ({ page }) => {
    /**
     * ⛔ A REGRESSION ROUND ONE INTRODUCED. `§11` round two, QA H-2 and SEC-R2 M-1, and it is a
     * failure of this task's NAMED INVARIANT, so it blocks the tag whatever the round cap says.
     *
     * Round one made `hasExpired()` treat a MISSING expiry as expired (fail closed, correctly)
     * and left the write-back emitting `{token, applicationServerKey, apiBase, endpoint}` with
     * no `expiresAt`. So rotation 1 succeeded and wrote a credential that `readCredential()`
     * PURGED on the next read: rotation 2 found nothing, sent no `DELETE` for the dead endpoint
     * and registered nothing — on the engine with no `oldSubscription` (Firefox), which is the
     * only reason the write-back exists. On iOS the read never happens at all, so the token sat
     * in Cache Storage with no recorded expiry.
     *
     * Asserted on the entry the worker itself wrote, which nothing reported before.
     */
    const expiry = LIVE()
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        endpoint: 'https://push.example/old',
        expiresAt: expiry,
      },
    })

    const after = captured.credentialAfter as Record<string, unknown> | null

    expect(after, 'the rotation wrote no credential at all').not.toBeNull()
    expect(after?.endpoint).toBe('https://push.example/rotated')
    // The field whose absence made the entry self-purging, carried through unchanged.
    expect(after?.expiresAt, 'the write-back dropped expiresAt, so the entry is born expired').toBe(
      expiry,
    )
    expect(after?.token).toBe('AbCdEfGhIjKlMnOpQrStUv')
    expect(after?.applicationServerKey).toBe('AQAB')
  })

  test('tells the page and writes NOTHING when the re-register POST is refused -- QA M-3', async ({
    page,
  }) => {
    // Round one added this branch for QA M-2 and no case could reach it: the shim's `fetch`
    // always resolved `{ok:true,status:201}`. A 4xx is not a network failure and must not be
    // recorded as a success.
    const refused = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        endpoint: 'https://push.example/old',
        expiresAt: LIVE(),
      },
      fetchStatus: 409,
    })

    expect(refused.messages).toEqual([{ type: 'qrpager/resubscribe' }])
    expect(
      refused.credentialAfter,
      'a refused registration was recorded as the remembered endpoint',
    ).toBeNull()

    // And the same for a network failure, which is the other arm of that guard.
    const threw = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        endpoint: 'https://push.example/old',
        expiresAt: LIVE(),
      },
      fetchThrows: true,
    })

    expect(threw.messages).toEqual([{ type: 'qrpager/resubscribe' }])
    expect(threw.credentialAfter).toBeNull()
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
        expiresAt: LIVE(),
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
        expiresAt: LIVE(),
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
      credential: { applicationServerKey: 'AQAB', apiBase: '', expiresAt: LIVE() },
    })

    expect(captured.subscribes).toBe(1)
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
    // And it posted nothing, because it could not authenticate.
    expect(captured.fetches).toEqual([])
  })

  test('tells the page when it has no application server key either', async ({ page }) => {
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: { token: 'AbCdEfGhIjKlMnOpQrStUv', apiBase: '', expiresAt: LIVE() },
    })

    expect(captured.subscribes).toBe(0)
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
  })

  test('CANNOT read the harness cache, so no planted apiBase can take the token', async ({
    page,
  }) => {
    /**
     * ⛔ THE INVERSE OF WHAT THIS CASE USED TO ASSERT. `§11` round one, SEC M-3, raised Medium
     * and **ruled HIGH by Muhammad on 2026-11-01**.
     *
     * It used to read *"reads QRPAGER-24s own cache entry as well as Task 9s"* and proved that
     * the shipped worker accepted a credential from `qrpager-harness`. That made the worker
     * reachable by anything able to write a cache on this origin: plant
     * `{token, apiBase: 'https://attacker.example'}` and the next rotation POSTs the live QR
     * token off-origin — and the worker wrote `apiBase` BACK, so the exfiltration survived
     * removal of the script that planted it. `§4 › Data` says the raw token never leaves.
     *
     * The ruling is that the production worker must not be able to read it **at all — not
     * validate it, not reach it**. So this case now plants a HOSTILE harness entry, gives the
     * worker no credential of its own, and asserts the worker reaches NOTHING: no subscribe,
     * no fetch to any origin, and specifically no request to the planted one. QRPAGER-24's
     * harness writes `/pager-credential` instead, which the case above already covers.
     */
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: null,
      harnessCredential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: 'https://attacker.example',
      },
    })

    // Not "no POST": no request AT ALL, to anywhere. A worker with no credential has nothing
    // to authenticate with and must say so to the page instead.
    expect(captured.fetches).toEqual([])
    expect(captured.subscribes).toBe(0)
    expect(
      captured.fetches.filter((f) => f.url.includes('attacker.example')),
      'the worker sent the token to an origin a cache entry chose',
    ).toEqual([])

    // And the page is told, which is the branch a credential-less rotation is supposed to take.
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
  })

  test('drops an EXPIRED credential instead of using it -- SEC M-2', async ({ page }) => {
    /**
     * ⛔ THE TOKEN'S `Cache` HOME WAS NEVER PURGED. `§11` round one, SEC M-2.
     * `qrpager-pager-credential` is in the worker's `OWNED` list, so `activate` deleted only
     * caches NOT in it, and nothing anywhere checked an expiry — so a customer who collected
     * their order and closed the app (the common case, since `completed` is set by staff) left
     * a live 128-bit bearer token on the device for the whole 2-hour TTL. The `localStorage`
     * half has always self-deleted on read past expiry; this half could not, because the entry
     * did not carry one. The ticket stated in writing that it was purged and asserted.
     *
     * Driven on the shipped bytes: an entry whose `expiresAt` is in the past must be treated as
     * absent AND removed, so a rotation behaves exactly as it does with no credential.
     */
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: {
        token: 'AbCdEfGhIjKlMnOpQrStUv',
        applicationServerKey: 'AQAB',
        apiBase: '',
        expiresAt: new Date(Date.now() - 60_000).toISOString(),
      },
    })

    expect(captured.fetches, 'an expired credential was used to authenticate a request').toEqual([])
    expect(captured.subscribes).toBe(0)
    expect(captured.messages).toEqual([{ type: 'qrpager/resubscribe' }])
  })

  test('treats a credential with NO expiry as expired, which is the fail-closed half', async ({
    page,
  }) => {
    // An entry written by a build that recorded no expiry cannot be shown to be live. The cost
    // of being wrong this way is one reconcile from the page, which runs on every launch
    // anyway; the cost the other way is a live bearer token kept forever.
    const captured = await driveWorker(page, 'pushsubscriptionchange', {
      credential: { token: 'AbCdEfGhIjKlMnOpQrStUv', applicationServerKey: 'AQAB', apiBase: '' },
    })

    expect(captured.fetches).toEqual([])
    expect(captured.subscribes).toBe(0)
  })
})

test.describe('what the worker refuses to do, each asserted rather than read', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${origin()}/s`)
  })

  test('shows no sender-controlled text for a payload it cannot read -- SEC L-2', async ({
    page,
  }) => {
    // Round one stopped rendering the raw payload into the notification body; nothing asserted
    // it, so restoring `'... payload not recognised: ' + rawText` kept every case green and put
    // sender-controlled text back on the customer's lock screen.
    const captured = await driveWorker(page, 'push', { rawText: 'PLANTED-BY-SENDER' })

    expect(captured.notifications).toHaveLength(1)
    expect(
      JSON.stringify(captured.notifications[0]),
      'the raw payload reached the notification',
    ).not.toContain('PLANTED-BY-SENDER')
    // The delivery is still SHOWN: a push this build cannot read still arrived, and swallowing
    // it would turn a successful delivery into a silent failure.
    expect(captured.notifications[0]?.options.tag).toBe('qrpager-harness')
  })

  test('focuses the PAGER client and not a sibling surface -- SEC L-3', async ({ page }) => {
    /**
     * Round one replaced `client.url.includes('/s')` -- which matches `/settings`, `/staff`,
     * `/studio` -- with an exact pathname match, and `notificationclick` was driven by nothing
     * at all. From Task 10 the likely wrong surface is the staff dashboard, in front of the
     * customer whose order it is.
     */
    const focused = await page.evaluate(async () => {
      const scope = window as unknown as Record<string, unknown>
      const order: string[] = []
      const clients = [
        {
          url: `${location.origin}/settings`,
          focus: () => {
            order.push('/settings')
            return Promise.resolve()
          },
        },
        {
          url: `${location.origin}/staff`,
          focus: () => {
            order.push('/staff')
            return Promise.resolve()
          },
        },
        {
          url: `${location.origin}/s`,
          focus: () => {
            order.push('/s')
            return Promise.resolve()
          },
        },
      ]

      ;(scope.clients as Record<string, unknown>).matchAll = () => Promise.resolve(clients)

      const event = new CustomEvent('notificationclick') as CustomEvent & Record<string, unknown>
      const waits: Promise<unknown>[] = []

      event.waitUntil = (p: Promise<unknown>) => {
        waits.push(p)
      }
      event.notification = { close: () => undefined, data: {} }

      window.dispatchEvent(event)
      await Promise.all(waits)

      return order
    })

    expect(focused, 'notificationclick focused a surface that merely contains /s').toEqual(['/s'])
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
