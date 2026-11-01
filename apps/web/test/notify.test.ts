import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it, vi } from 'vitest'

import type { ApiConfig } from '../src/pager/api.ts'
import {
  WORKER_CACHE,
  WORKER_CACHE_KEY,
  askPermission,
  base64UrlToBytes,
  clearWorkerCredential,
  currentPermission,
  pushCapability,
  reconcileSubscription,
  subscriptionFields,
  writeWorkerCredential,
  type PushEnvironment,
} from '../src/pager/notify.ts'
import type { PresentedToken } from '../src/pager/token.ts'

/**
 * ================================================================================
 * THE NAMED INVARIANT's ENGINE-INDEPENDENT HALF -- `SR-5`
 * ================================================================================
 *
 * The invariant: *"a rotated subscription is re-registered by the `pushsubscriptionchange`
 * handler and the next notification still arrives."*
 *
 * ⚠ `pushsubscriptionchange` IS `safari_ios: false` IN MDN's COMPAT DATA. It does not exist
 * on iOS at all, and iOS is the platform `§4 › Realtime` and `R-073` call this product's
 * largest risk. So the handler is one of two mechanisms and `reconcileSubscription()` is the
 * other -- the one that works everywhere. The handler itself is driven in a real engine in
 * `browser/sw.spec.ts`, INCLUDING with the handler removed, which is the proof that the iOS
 * path holds without it.
 *
 * This file is the reconcile's own branching: five outcomes, each reachable, each asserted.
 */

const TOKEN = 'AbCdEfGhIjKlMnOpQrStUv' as PresentedToken
/** An expiry comfortably in the future: these cases are not about the purge. */
const FUTURE = new Date(Date.now() + 3_600_000).toISOString()

const KEY =
  'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM'

/** A minimal `CacheStorage` over a Map, so the credential's two homes are observable. */
function fakeCaches() {
  const stores = new Map<string, Map<string, Response>>()

  return {
    storage: {
      open: (name: string) => {
        const store = stores.get(name) ?? new Map<string, Response>()
        stores.set(name, store)

        return Promise.resolve({
          // Keyed by the string form, and the parameter is typed `string` because every
          // caller in this app passes a path. A `RequestInfo` would stringify to
          // `[object Object]` and the lint rule is right to refuse it.
          put: (key: string, value: Response) => {
            store.set(key, value)

            return Promise.resolve()
          },
          match: (key: string) => Promise.resolve(store.get(key)),
          delete: (key: string) => Promise.resolve(store.delete(key)),
        } as unknown as Cache)
      },
    } as unknown as CacheStorage,
    stores,
  }
}

function env(overrides: Partial<PushEnvironment> = {}): PushEnvironment {
  const items = new Map<string, string>()

  return {
    navigator: { serviceWorker: {} as ServiceWorkerContainer },
    notification: {
      permission: 'granted',
      requestPermission: () => Promise.resolve('granted' as NotificationPermission),
    },
    caches: fakeCaches().storage,
    storage: {
      getItem: (key: string) => items.get(key) ?? null,
      setItem: (key: string, value: string) => {
        items.set(key, value)
      },
      removeItem: (key: string) => {
        items.delete(key)
      },
    },
    pushManagerAvailable: true,
    ...overrides,
  }
}

function subscription(endpoint: string): PushSubscription {
  return {
    endpoint,
    toJSON: () => ({ endpoint, keys: { p256dh: 'P', auth: 'A' } }),
  } as unknown as PushSubscription
}

function api(): ApiConfig {
  return { base: '', fetch: () => Promise.resolve(new Response('{}', { status: 201 })) }
}

describe('pushCapability -- three checks, because they fail independently', () => {
  it('needs a service worker, a PushManager AND a Notification constructor', () => {
    expect(pushCapability(env())).toBe('supported')
    // `webview_android` is `false` for `PushManager` in the compat data -- a service worker
    // with no push path is a real configuration, not a hypothetical.
    expect(pushCapability(env({ pushManagerAvailable: false }))).toBe('unsupported')
    expect(pushCapability(env({ notification: null }))).toBe('unsupported')
    expect(pushCapability(env({ navigator: {} as Navigator }))).toBe('unsupported')
  })
})

describe('currentPermission -- records, never asks', () => {
  it('maps the three browser values and reports unsupported before them', () => {
    expect(currentPermission(env())).toBe('granted')
    expect(
      currentPermission(
        env({
          notification: {
            permission: 'denied',
            requestPermission: () => Promise.resolve('denied'),
          },
        }),
      ),
    ).toBe('denied')
    expect(
      currentPermission(
        env({
          notification: {
            permission: 'default',
            requestPermission: () => Promise.resolve('default'),
          },
        }),
      ),
    ).toBe('default')
    expect(currentPermission(env({ pushManagerAvailable: false }))).toBe('unsupported')
  })
})

describe('askPermission', () => {
  it('maps granted and denied straight through', async () => {
    expect(
      await askPermission(
        env({
          notification: {
            permission: 'default',
            requestPermission: () => Promise.resolve('granted'),
          },
        }),
      ),
    ).toBe('granted')
    expect(
      await askPermission(
        env({
          notification: {
            permission: 'default',
            requestPermission: () => Promise.resolve('denied'),
          },
        }),
      ),
    ).toBe('denied')
  })

  it('reports `default` for a REJECTION, never `denied`', async () => {
    /**
     * ⚠ THE DISTINCTION THAT STOPS A LIE ON SCREEN. Some engines reject rather than resolving
     * `'denied'` when `requestPermission()` is called outside a user gesture. A rejection is
     * not a denial: the browser WILL ask again, so reporting `denied` would show the one
     * screen that deliberately offers no retry at the exact moment a retry is what is needed.
     */
    expect(
      await askPermission(
        env({
          notification: {
            permission: 'default',
            requestPermission: () => Promise.reject(new Error('not a user gesture')),
          },
        }),
      ),
    ).toBe('default')
  })

  it('reports unsupported when there is no Notification at all', async () => {
    expect(await askPermission(env({ notification: null }))).toBe('unsupported')
  })

  it('is called from NO module outside a click handler', () => {
    /**
     * ⚠ ASSERTED AGAINST THE SOURCE, because it is Apple's requirement and a violation of it
     * is silent. WebKit's own announcement: the request must be *"in response to direct user
     * interaction"*. A request from an effect is refused on iOS and tells the customer
     * nothing -- it costs them the alert on the one platform where the alert is hardest to
     * get.
     *
     * The only caller is `onAsk`, and `onAsk` is only ever passed as `onAsk` to the alerts
     * screen's button.
     */
    const src = readFileSync(join(import.meta.dirname, '..', 'src', 'PagerJourney.tsx'), 'utf8')
    const callers = [...src.matchAll(/askPermission\(/g)]

    expect(callers.length).toBe(1)
    // And it is inside the `onAsk` callback rather than inside a `useEffect`.
    const before = src.slice(0, src.indexOf('askPermission('))

    expect(before.lastIndexOf('const onAsk')).toBeGreaterThan(before.lastIndexOf('useEffect('))
  })
})

describe('base64UrlToBytes', () => {
  it('decodes an unpadded base64url VAPID key to the right length', () => {
    // A P-256 uncompressed public point is 65 bytes, which is what a VAPID key is.
    expect(base64UrlToBytes(KEY).length).toBe(65)
    expect(base64UrlToBytes(KEY)[0]).toBe(4)
  })

  it('handles every padding remainder, which is where a hand-rolled decoder breaks', () => {
    expect(base64UrlToBytes('AQAB').length).toBe(3)
    expect(base64UrlToBytes('AQA').length).toBe(2)
    expect(base64UrlToBytes('AQ').length).toBe(1)
  })

  it('translates the base64URL alphabet, not base64', () => {
    // `-` and `_` rather than `+` and `/`. A decoder that forgot this produces a key the
    // push service rejects, which surfaces as "subscribe failed" with no explanation.
    expect([...base64UrlToBytes('-_')].length).toBe(1)
  })
})

describe('subscriptionFields', () => {
  it('reads the endpoint and both keys', () => {
    expect(subscriptionFields(subscription('https://push.example/a'))).toEqual({
      endpoint: 'https://push.example/a',
      p256dh: 'P',
      auth: 'A',
    })
  })

  it('answers null rather than sending a half subscription to the server', () => {
    const noKeys = { endpoint: 'https://x', toJSON: () => ({ endpoint: 'https://x' }) }
    const noEndpoint = { toJSON: () => ({ keys: { p256dh: 'P', auth: 'A' } }) }

    expect(subscriptionFields(noKeys as unknown as PushSubscription)).toBeNull()
    expect(subscriptionFields(noEndpoint as unknown as PushSubscription)).toBeNull()
  })
})

describe('the worker credential', () => {
  it('is written where the service worker reads it, with the endpoint for the rotation', async () => {
    const caches = fakeCaches()
    const environment = env({ caches: caches.storage })

    await writeWorkerCredential(environment, TOKEN, KEY, '', 'https://push.example/a', FUTURE)

    const stored = caches.stores.get(WORKER_CACHE)?.get(WORKER_CACHE_KEY)

    if (stored === undefined) throw new Error('nothing was written to the worker cache')

    const body = (await stored.json()) as Record<string, unknown>

    expect(body.token).toBe(TOKEN)
    expect(body.applicationServerKey).toBe(KEY)
    // ⚠ THE ENDPOINT IS IN HERE FOR THE ROTATION HANDLER. MDN marks Firefox's
    // `pushsubscriptionchange` `partial_implementation` -- *"The event does not have the
    // `oldSubscription` and `newSubscription` properties"* -- so a handler that wants to
    // DELETE the endpoint it is replacing has no way to learn it from the event there.
    expect(body.endpoint).toBe('https://push.example/a')
  })

  it('is deleted, and so is the remembered endpoint, when a session ends', async () => {
    /**
     * ⚠ A SECURITY STEP, NOT TIDYING. The credential is a 128-bit bearer token with a
     * two-hour life, and a phone is handed to a friend, left on a table and sold. BOTH homes
     * are cleared by one call rather than by two calls anyone could forget one of.
     */
    const caches = fakeCaches()
    const environment = env({ caches: caches.storage })

    await writeWorkerCredential(environment, TOKEN, KEY, '', 'https://push.example/a', FUTURE)
    environment.storage?.setItem('qrpager.pager.endpoint', 'https://push.example/a')

    await clearWorkerCredential(environment)

    expect(caches.stores.get(WORKER_CACHE)?.get(WORKER_CACHE_KEY)).toBeUndefined()
    expect(environment.storage?.getItem('qrpager.pager.endpoint')).toBeNull()
  })

  it('does nothing and does not throw when the engine has no CacheStorage', async () => {
    // Safari private browsing, and any context where storage is blocked.
    await expect(
      writeWorkerCredential(env({ caches: null }), TOKEN, KEY, '', null, FUTURE),
    ).resolves.toBeUndefined()
    await expect(clearWorkerCredential(env({ caches: null }))).resolves.toBeUndefined()
  })
})

describe('reconcileSubscription -- all five outcomes', () => {
  it('unsupported: no push path on this engine', async () => {
    expect(
      await reconcileSubscription({
        env: env({ pushManagerAvailable: false }),
        api: api(),
        token: TOKEN,
        applicationServerKey: KEY,
        registration: { pushManager: {} as PushManager },
        expiresAt: FUTURE,
      }),
    ).toEqual({ kind: 'unsupported' })
  })

  it('not-permitted: permission has not been granted, so there is nothing to subscribe', async () => {
    expect(
      await reconcileSubscription({
        env: env({
          notification: {
            permission: 'default',
            requestPermission: () => Promise.resolve('default'),
          },
        }),
        api: api(),
        token: TOKEN,
        applicationServerKey: KEY,
        registration: { pushManager: {} as PushManager },
        expiresAt: FUTURE,
      }),
    ).toEqual({ kind: 'not-permitted' })
  })

  it('unchanged: the live endpoint is the one already registered -- NO request is made', async () => {
    // ⚠ THE BOUND `§6` QUESTION 5 CLAIMS. "1 POST per launch at most" is this comparison:
    // the ordinary case costs one `getSubscription()` and no network call at all.
    const environment = env()
    environment.storage?.setItem('qrpager.pager.endpoint', 'https://push.example/a')
    const fetchSpy = vi.fn()

    const outcome = await reconcileSubscription({
      env: environment,
      api: { base: '', fetch: fetchSpy as typeof fetch },
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () => Promise.resolve(subscription('https://push.example/a')),
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(outcome).toEqual({ kind: 'unchanged', endpoint: 'https://push.example/a' })
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('reregistered: the browser ROTATED the endpoint behind our back', async () => {
    /**
     * ⚠ THIS IS THE CASE THE WHOLE INVARIANT IS ABOUT, AND ON iOS IT IS THE ONLY MECHANISM.
     * A rotation is an event the page does not see: the browser replaces the subscription,
     * the old endpoint starts answering 410, Task 4's dispatcher prunes it -- correctly --
     * and the customer silently stops receiving notifications. `QRPAGER-16`'s own comment
     * calls it *"the single quietest failure mode in the whole product"*.
     */
    const environment = env()
    environment.storage?.setItem('qrpager.pager.endpoint', 'https://push.example/OLD')
    const calls: string[] = []

    const outcome = await reconcileSubscription({
      env: environment,
      api: {
        base: '',
        fetch: (input, init) => {
          calls.push(`${init?.method ?? 'GET'} ${typeof input === 'string' ? input : 'non-string'}`)

          return Promise.resolve(new Response('{"registered":true}', { status: 201 }))
        },
      },
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () => Promise.resolve(subscription('https://push.example/NEW')),
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(outcome).toEqual({ kind: 'reregistered', endpoint: 'https://push.example/NEW' })
    expect(calls).toEqual(['POST /v1/pager/subscription'])
    // The new endpoint is remembered, so the NEXT reconcile is a no-op rather than a loop.
    expect(environment.storage?.getItem('qrpager.pager.endpoint')).toBe('https://push.example/NEW')
  })

  it('reregistered: the browser had NO subscription, so one is created', async () => {
    // The iOS case after a reinstall, and the case after a browser cleared site data.
    const environment = env()
    let subscribed = false

    const outcome = await reconcileSubscription({
      env: environment,
      api: { base: '', fetch: () => Promise.resolve(new Response('{}', { status: 201 })) },
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () => Promise.resolve(null),
          subscribe: (options: PushSubscriptionOptionsInit) => {
            subscribed = true
            expect(options.userVisibleOnly).toBe(true)

            return Promise.resolve(subscription('https://push.example/FRESH'))
          },
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(subscribed).toBe(true)
    expect(outcome).toEqual({ kind: 'reregistered', endpoint: 'https://push.example/FRESH' })
  })

  it('failed: subscribe throws, getSubscription throws, or the POST is refused', async () => {
    const base = {
      api: api(),
      token: TOKEN,
      applicationServerKey: KEY,
    }

    expect(
      await reconcileSubscription({
        ...base,
        env: env(),
        registration: {
          pushManager: {
            getSubscription: () => Promise.reject(new Error('no')),
          } as unknown as PushManager,
        },
        expiresAt: FUTURE,
      }),
    ).toEqual({ kind: 'failed' })

    expect(
      await reconcileSubscription({
        ...base,
        env: env(),
        registration: {
          pushManager: {
            getSubscription: () => Promise.resolve(null),
            subscribe: () => Promise.reject(new Error('permission revoked')),
          } as unknown as PushManager,
        },
        expiresAt: FUTURE,
      }),
    ).toEqual({ kind: 'failed' })

    expect(
      await reconcileSubscription({
        ...base,
        env: env(),
        api: { base: '', fetch: () => Promise.resolve(new Response('{}', { status: 409 })) },
        registration: {
          pushManager: {
            getSubscription: () => Promise.resolve(subscription('https://push.example/x')),
          } as unknown as PushManager,
        },
        expiresAt: FUTURE,
      }),
    ).toEqual({ kind: 'failed' })
  })

  it('does not remember an endpoint the server refused', async () => {
    // Otherwise the next reconcile would see them agree and never retry -- a refusal would
    // silently become permanent.
    const environment = env()

    await reconcileSubscription({
      env: environment,
      api: { base: '', fetch: () => Promise.resolve(new Response('{}', { status: 409 })) },
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () => Promise.resolve(subscription('https://push.example/x')),
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(environment.storage?.getItem('qrpager.pager.endpoint')).toBeNull()
  })

  it('writes the credential BEFORE it posts, so a rotation between the two finds it', async () => {
    // QRPAGER-24 named this ordering and this inherits it rather than rediscovering it.
    const caches = fakeCaches()
    const environment = env({ caches: caches.storage })
    const order: string[] = []

    await reconcileSubscription({
      env: environment,
      api: {
        base: '',
        fetch: () => {
          order.push('post')

          return Promise.resolve(new Response('{}', { status: 201 }))
        },
      },
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () => Promise.resolve(null),
          subscribe: () => {
            order.push('subscribe')

            return Promise.resolve(subscription('https://push.example/x'))
          },
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(caches.stores.get(WORKER_CACHE)?.get(WORKER_CACHE_KEY)).toBeDefined()
    expect(order).toEqual(['subscribe', 'post'])
  })
})

describe('the two early returns a browser can actually produce', () => {
  it('reports unsupported from currentPermission when Notification is absent but a worker is not', () => {
    // Reachable: `pushCapability` passes on `pushManagerAvailable` and the worker, and
    // `env.notification` is still null -- a context where the constructor was removed by a
    // policy rather than by the engine lacking it.
    const broken = env({ notification: null, pushManagerAvailable: true })

    expect(currentPermission(broken)).toBe('unsupported')
  })

  it('fails rather than posting when the browser hands back a subscription with no keys', async () => {
    // A subscription whose `toJSON()` omits the keys is what Safari returns in at least one
    // reported state. Posting it would store a row the dispatcher can never encrypt for.
    const outcome = await reconcileSubscription({
      env: env(),
      api: api(),
      token: TOKEN,
      applicationServerKey: KEY,
      registration: {
        pushManager: {
          getSubscription: () =>
            Promise.resolve({
              endpoint: 'https://push.example/x',
              toJSON: () => ({ endpoint: 'https://push.example/x' }),
            } as unknown as PushSubscription),
        } as unknown as PushManager,
      },
      expiresAt: FUTURE,
    })

    expect(outcome).toEqual({ kind: 'failed' })
  })
})
