import { registerDevice, type ApiConfig } from './api.ts'
import type { PermissionState } from './journey.ts'
import type { PresentedToken } from './token.ts'

/**
 * ================================================================================
 * PERMISSION, SUBSCRIPTION, AND THE ROTATION THE INVARIANT IS ABOUT
 * ================================================================================
 *
 * The task's named invariant: *"a rotated subscription is re-registered by the
 * `pushsubscriptionchange` handler and the next notification still arrives."*
 *
 * ⚠ `pushsubscriptionchange` DOES NOT EXIST ON iOS SAFARI. Measured against MDN
 * `browser-compat-data` this session: `api/ServiceWorkerGlobalScope.json` →
 * `pushsubscriptionchange_event` → **`safari_ios: false`**, with `chrome: 138`,
 * `edge: 17 removed in 79`, and firefox `partial_implementation` (no `oldSubscription` /
 * `newSubscription`). Corroborated by WebKit Bugzilla 273063. And WebKit 249766, closed
 * WONTFIX, records that a push to an EXPIRED Safari endpoint still answers **200** -- so
 * Task 4's dispatcher's 404/410 pruning never fires for a Safari endpoint either.
 *
 * iOS is the platform `§4 › Realtime` and `R-073` call the product's largest risk. So a
 * handler-only implementation of that invariant is green in Chromium and dead on an iPhone,
 * which is `R-126`'s shape. `docs/tickets/QRPAGER-16.md SR-5` reports it.
 *
 * THEREFORE TWO MECHANISMS, and the second is the one that works everywhere:
 *
 *   1. the `pushsubscriptionchange` handler in `public/sw.js` -- what the clause names;
 *   2. `reconcileSubscription()` below, run on every launch and on every return to
 *      visibility, which compares the browser's CURRENT subscription against what was last
 *      registered and re-registers when they differ or when it is gone.
 *
 * (2) needs no new endpoint and no change to Task 4, because `POST /v1/pager/subscription`
 * is already idempotent by its own contract: *"Calling this again with the same endpoint
 * updates the stored keys rather than adding a second device, so it is safe to repeat on
 * every page load."*
 *
 * Both are asserted, and (2) is asserted WITH THE HANDLER REMOVED, so the iOS path is
 * proven to hold without it.
 */

/** Where the service worker reads the token from. A `Cache`, because a worker has no `localStorage`. */
export const WORKER_CACHE = 'qrpager-pager-credential'
export const WORKER_CACHE_KEY = '/pager-credential'

/** Where the page remembers the endpoint it last registered. Not a secret; an endpoint URL. */
const LAST_ENDPOINT_KEY = 'qrpager.pager.endpoint'

/** Everything this module touches, injected -- so a unit test needs no browser. */
export interface PushEnvironment {
  readonly navigator: Pick<Navigator, 'serviceWorker'>
  readonly notification: {
    readonly permission: NotificationPermission
    readonly requestPermission: () => Promise<NotificationPermission>
  } | null
  readonly caches: CacheStorage | null
  readonly storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null
  readonly pushManagerAvailable: boolean
}

/**
 * Is there a push path on this engine at all?
 *
 * THREE CHECKS, NOT ONE, because they fail independently and `§4.1`'s *unsupported browser*
 * state must be reachable from each: an engine with no service worker (every iOS before
 * 11.3, private browsing in several), one with a service worker and no `PushManager`
 * (`webview_android` is `false` for `PushManager` in the compat data above), and one with
 * both and no `Notification` constructor.
 */
export function pushCapability(env: PushEnvironment): 'supported' | 'unsupported' {
  // `in`, not a truthiness test: the property is non-optional on `Navigator` in the DOM
  // types but genuinely absent on engines without a service worker, which is the whole case
  // `§4.1`'s *unsupported browser* state exists for.
  const hasWorker = 'serviceWorker' in env.navigator

  return hasWorker && env.pushManagerAvailable && env.notification !== null
    ? 'supported'
    : 'unsupported'
}

/** The permission as it stands, without asking. */
export function currentPermission(env: PushEnvironment): PermissionState {
  if (pushCapability(env) === 'unsupported') return 'unsupported'
  if (env.notification === null) return 'unsupported'

  switch (env.notification.permission) {
    case 'granted':
      return 'granted'
    case 'denied':
      return 'denied'
    default:
      return 'default'
  }
}

/**
 * Ask. MUST be called from a user gesture.
 *
 * Apple's own words, from the WebKit announcement of Web Push for Home Screen web apps:
 * the request must be *"in response to direct user interaction"*. So this is reachable only
 * from a click handler and never from an effect -- QRPAGER-24's harness says the same, and
 * `apps/web/test/notify.test.ts` asserts no module in this app calls it outside one.
 */
export async function askPermission(env: PushEnvironment): Promise<PermissionState> {
  if (env.notification === null) return 'unsupported'

  try {
    const result = await env.notification.requestPermission()

    return result === 'granted' ? 'granted' : result === 'denied' ? 'denied' : 'default'
  } catch {
    // Some engines reject rather than resolving 'denied' when called outside a gesture. A
    // rejection is not 'denied': the browser WILL ask again, so reporting denied would show
    // the one screen that offers no retry when a retry is exactly what is needed.
    return 'default'
  }
}

/** base64url → bytes, for `applicationServerKey`. */
export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const padded = value.padEnd(value.length + ((4 - (value.length % 4)) % 4), '=')
  const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'))
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }

  return bytes
}

interface SubscriptionFields {
  readonly endpoint: string
  readonly p256dh: string
  readonly auth: string
}

/** `PushSubscription.toJSON()` with every field proven present. */
export function subscriptionFields(subscription: PushSubscription): SubscriptionFields | null {
  const json = subscription.toJSON()
  const keys = json.keys ?? {}

  if (json.endpoint === undefined || keys.p256dh === undefined || keys.auth === undefined) {
    return null
  }

  return { endpoint: json.endpoint, p256dh: keys.p256dh, auth: keys.auth }
}

/**
 * Put the credential where the service worker can reach it.
 *
 * ⚠ WRITTEN BEFORE THE POST, NOT AFTER. A rotation that fires between the two would find
 * nothing and give up silently -- which is the first of the two things QRPAGER-24's own
 * service worker names as *"TASK 9 MUST HARDEN"*. The harness wrote it before the POST for
 * the same reason and this inherits that order rather than rediscovering it.
 */
export async function writeWorkerCredential(
  env: PushEnvironment,
  token: PresentedToken,
  applicationServerKey: string,
  apiBase: string,
  endpoint: string | null,
  expiresAt: string,
): Promise<void> {
  if (env.caches === null) return

  const cache = await env.caches.open(WORKER_CACHE)
  await cache.put(
    WORKER_CACHE_KEY,
    // ⚠ `endpoint` IS IN HERE FOR THE ROTATION HANDLER, not for the page. MDN's compat data
    // marks Firefox's `pushsubscriptionchange` `partial_implementation` -- *"The event does
    // not have the `oldSubscription` and `newSubscription` properties"* -- so a handler that
    // wants to DELETE the endpoint it is replacing has no way to learn it from the event
    // there. This is that way. Null before the first subscribe, which the handler tolerates.
    // ⚠ `expiresAt` IS WHAT MAKES THE ENTRY PURGEABLE, and it was missing -- `§11` round one,
    // SEC M-2. `CREDENTIAL_CACHE` is in the worker's `OWNED` list, so `activate` never deleted
    // it and nothing anywhere checked an expiry: a customer who collected their order and
    // closed the app left a live 128-bit bearer token on the device for the whole 2-hour TTL.
    // The `localStorage` half has always self-deleted on read past expiry; this half could
    // not, because it did not know when that was. `sw.js`'s `hasExpired()` reads this, treats
    // an ABSENT value as expired, and deletes the entry on read as well as on activate.
    new Response(JSON.stringify({ token, applicationServerKey, apiBase, endpoint, expiresAt }), {
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

/**
 * Remove it. Called on every terminal status and on unsubscribe.
 *
 * ⚠ THIS IS A SECURITY STEP AND NOT TIDYING. The credential is a 128-bit bearer token with a
 * two-hour life, and a phone is handed to a friend, left on a table and sold. A session that
 * is over must not leave a live credential in a cache on the device, and `§4 › Data` treats
 * the raw token as a secret wherever it is.
 *
 * ⚠ THE PREVIOUS SENTENCE HERE CLAIMED *"Asserted: after a terminal status both homes are
 * empty"* AND THAT WAS HALF TRUE -- `§11` round one, SEC M-5. The call site is an effect in
 * `PagerJourney.tsx`, not an `endSession()` function, and the only test
 * (`orchestrator.test.tsx`'s *forgets the token on a terminal status*) asserted the two
 * `localStorage` keys and NOT this cache -- it cannot, because jsdom has no `window.caches`,
 * so this function short-circuits there. The `Cache` half is now asserted where `caches`
 * really exists: `browser/sw.spec.ts`'s *clears BOTH homes when the session is over*.
 *
 * ⚠ AND IT IS NO LONGER THE ONLY DEFENCE. Even if this call is never reached -- a tab closed
 * mid-session, a crash -- `sw.js` drops the entry once `expiresAt` has passed, on read and on
 * activate. One of the two had to survive the other being skipped.
 */
export async function clearWorkerCredential(env: PushEnvironment): Promise<void> {
  if (env.caches !== null) {
    const cache = await env.caches.open(WORKER_CACHE)
    await cache.delete(WORKER_CACHE_KEY)
  }

  env.storage?.removeItem(LAST_ENDPOINT_KEY)
}

export type ReconcileOutcome =
  /** Nothing to do: the browser's subscription is the one already registered. */
  | { readonly kind: 'unchanged'; readonly endpoint: string }
  /** The browser had none, or a different one. Re-registered through the real endpoint. */
  | { readonly kind: 'reregistered'; readonly endpoint: string }
  /** Permission is not granted, so there is nothing to subscribe. */
  | { readonly kind: 'not-permitted' }
  /** The engine has no push path. */
  | { readonly kind: 'unsupported' }
  /** A subscribe or a POST failed. The caller keeps polling; it does not error. */
  | { readonly kind: 'failed' }

export interface ReconcileDeps {
  readonly env: PushEnvironment
  readonly api: ApiConfig
  readonly token: PresentedToken
  readonly applicationServerKey: string
  readonly registration: Pick<ServiceWorkerRegistration, 'pushManager'>
  /**
   * The session's own expiry, from the snapshot the resolve returned. Carried into the
   * worker's credential so the worker can drop it without asking anyone -- `§11` round one,
   * SEC M-2. Required rather than optional: an entry whose expiry is unknown is treated as
   * expired, so making this optional would quietly disable the purge.
   */
  readonly expiresAt: string
}

/**
 * ================================================================================
 * `reconcileSubscription` -- `SR-5`'s engine-independent half of the invariant
 * ================================================================================
 *
 * Run on every launch and on every `visibilitychange` to visible. It compares the endpoint
 * the browser holds RIGHT NOW against the one this device last registered, and re-registers
 * when they differ or when there is none.
 *
 * WHY IT CATCHES WHAT THE HANDLER CANNOT. A rotation is an event the page does not see: the
 * browser replaces the subscription, the old endpoint starts answering 410, Task 4's
 * dispatcher prunes it -- correctly -- and the customer silently stops receiving
 * notifications. `QRPAGER-16`'s own comment of 2026-10-25 calls that *"the single quietest
 * failure mode in the whole product"*. On an engine that fires `pushsubscriptionchange` the
 * handler catches it immediately; on iOS, which never fires it, THIS is the only thing that
 * does, and it catches it at the next launch -- which for a Home-Screen app a customer opens
 * to check their order is the next thing that happens.
 *
 * ⚠ IT COMPARES BEFORE IT POSTS, so the ordinary case costs one `getSubscription()` and no
 * request. `§6` question 5's bound ("1 per launch at most") is this comparison.
 */
export async function reconcileSubscription(deps: ReconcileDeps): Promise<ReconcileOutcome> {
  const { env, api, token, applicationServerKey, registration } = deps

  if (pushCapability(env) === 'unsupported') return { kind: 'unsupported' }
  if (currentPermission(env) !== 'granted') return { kind: 'not-permitted' }

  let existing: PushSubscription | null

  try {
    existing = await registration.pushManager.getSubscription()
  } catch {
    return { kind: 'failed' }
  }

  const remembered = env.storage?.getItem(LAST_ENDPOINT_KEY) ?? null

  if (existing !== null && remembered !== null && existing.endpoint === remembered) {
    return { kind: 'unchanged', endpoint: existing.endpoint }
  }

  let subscription = existing

  if (subscription === null) {
    try {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(applicationServerKey),
      })
    } catch {
      return { kind: 'failed' }
    }
  }

  const fields = subscriptionFields(subscription)

  if (fields === null) return { kind: 'failed' }

  // The credential goes to the worker's cache BEFORE the POST, for the reason
  // `writeWorkerCredential` states.
  await writeWorkerCredential(
    env,
    token,
    applicationServerKey,
    api.base,
    fields.endpoint,
    deps.expiresAt,
  )

  const result = await registerDevice(api, token, fields)

  if (result !== 'registered') return { kind: 'failed' }

  env.storage?.setItem(LAST_ENDPOINT_KEY, fields.endpoint)

  return { kind: 'reregistered', endpoint: fields.endpoint }
}
