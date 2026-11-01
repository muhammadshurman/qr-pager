/*
 * ================================================================================
 * THE SERVICE WORKER. QRPAGER-24's, EXTENDED BY TASK 9 (QRPAGER-16).
 * ================================================================================
 *
 * ⚠ EXTENDED, NOT REPLACED, which is `QRPAGER-16 §2`'s own wording: *"The installable route,
 * the manifest and the push-handler service worker -> QRPAGER-24 -- this task EXTENDS that
 * harness, it does not rebuild it."* Everything QRPAGER-24 established is below, unchanged
 * in substance, and the operator script still drives it. What Task 9 adds is marked and is
 * each a named clause:
 *
 *   - THE OFFLINE SHELL (`§4.1`'s offline screen, and `§4 › Edge`'s static CDN page).
 *     QRPAGER-24 deliberately cached NOTHING and said why: *"QRPAGER-16 §3 question 4 asks
 *     'what does the service worker cache', and that is Task 9's answer to give."* This is
 *     that answer, and the harness's own routes are deliberately NOT in it.
 *   - THE NOTIFICATION `tag`, FROM THE PAYLOAD -- `R-075`, whose acceptance clause is *"the
 *     service worker shows a notification with a `tag` derived from the payload, so a
 *     duplicate replaces the visible notification rather than adding one"*.
 *   - THE MESSAGE TO THE PAGE, so an open tab moves to the order-ready screen on a push.
 *   - THE TWO HARDENINGS the harness named for Task 9 by name, at `pushsubscriptionchange`.
 *
 * QRPAGER-24's original header follows.
 *
 * QRPAGER-24's SERVICE WORKER. Unstyled harness, and nothing beyond the ticket's scope.
 *
 * WHY A SERVICE WORKER AT ALL, stated because it is the whole point of the ticket: on iOS
 * a push is delivered to a SERVICE WORKER of a web app launched from the HOME SCREEN, and
 * to nothing else. There is no notification path on iOS that does not go through this
 * file. QRPAGER-24 §3's named invariant is explicit that "a push received in Safari
 * WITHOUT a Home Screen install does not satisfy it".
 *
 * ⚠ THIS FILE CACHES NOTHING, DELIBERATELY. QRPAGER-16 §3 question 4 asks "what does the
 * service worker cache", and that is Task 9's answer to give. A harness that cached the
 * scan page would be making an offline-strategy decision on behalf of the task that owns
 * it, and it would make the operator's one session harder to read -- a stale cached page
 * is the commonest way a device test measures the wrong build.
 *
 * NOT THROWAWAY. QRPAGER-24 §1: Task 9's gate already requires a manifest, a service
 * worker and a `pushsubscriptionchange` handler, so Task 9 EXTENDS this rather than
 * replacing it.
 */

// Take over immediately rather than waiting for every tab to close. On a one-session
// device test the alternative is debugging a worker from a previous run.
self.addEventListener('install', () => {
  self.skipWaiting()
})

/*
 * ================================================================================
 * TASK 9: THE OFFLINE SHELL
 * ================================================================================
 *
 * `SHELL_CACHE` carries its own version. A deploy changes the string, `activate` deletes
 * every cache whose name is not the current one, and a customer's second visit cannot be
 * served a document from the previous build. Without the version a cache-first shell is
 * permanent: the commonest way a PWA ships a fix nobody receives.
 *
 * ⚠ IT IS RUNTIME-POPULATED, NOT PRECACHED FROM A LIST. Next hashes every asset filename, so
 * a precache list has to be GENERATED at build time or it goes stale silently -- and a stale
 * precache list is a worker that fetches four 404s on install and then reports success.
 * Cache-first-with-population needs no list and cannot go stale: the second visit is served
 * from whatever the first visit actually used.
 *
 * It costs the FIRST visit nothing, which is the visit `§12`'s cold-3G bar is about, and the
 * Lighthouse method requires *"no service worker installed"* for exactly that reason -- so
 * this caching is invisible to that figure by construction rather than by luck.
 */
const SHELL_CACHE = 'qrpager-shell-v1'
const CREDENTIAL_CACHE = 'qrpager-pager-credential'
const HARNESS_CACHE = 'qrpager-harness'

/** The caches this worker owns. Anything else under this origin is deleted on activate. */
const OWNED = [SHELL_CACHE, CREDENTIAL_CACHE, HARNESS_CACHE]

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Delete superseded shells. The credential and harness caches are keyed by name
      // rather than by version, so they survive a deploy -- a customer mid-session must not
      // lose the credential their rotation handler needs because we shipped a CSS change.
      const names = await self.caches.keys()
      await Promise.all(
        names.filter((name) => !OWNED.includes(name)).map((name) => self.caches.delete(name)),
      )

      await self.clients.claim()
    })(),
  )
})

/**
 * ⚠ WHAT IS CACHED, AND WHAT IS NEVER CACHED. `QRPAGER-16 §3` question 4 asks this
 * explicitly and the answer is a whitelist rather than a blacklist, because a blacklist is
 * one forgotten path away from caching a per-session secret.
 *
 * CACHED: same-origin GET requests for the `/s` document and for Next's build output and
 * the self-hosted fonts. All immutable or build-versioned.
 *
 * NEVER CACHED, and each for its own reason:
 *   /v1/*            per-session data behind a per-session secret. A cached copy is the
 *                    answer left on a device the customer may hand to someone else -- and
 *                    every one of those responses already carries `Cache-Control: no-store`
 *                    from `CustomerPagerController::json()`, which this worker honours
 *                    rather than overriding.
 *   anything non-GET every write on this surface is a POST or a DELETE.
 *   cross-origin     there is nothing third-party in the built output (asserted against the
 *                    build, `build-surface.test.ts`), so a cross-origin request here is
 *                    something nobody wrote.
 */
function isShellRequest(request, url) {
  if (request.method !== 'GET') return false
  if (url.origin !== self.location.origin) return false
  if (url.pathname.startsWith('/v1/')) return false

  return (
    url.pathname === '/s' ||
    url.pathname === '/s/' ||
    url.pathname.startsWith('/_next/') ||
    url.pathname.startsWith('/fonts/') ||
    url.pathname === '/pager-icon.svg' ||
    url.pathname === '/pager.webmanifest'
  )
}

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)

  // A NAVIGATION, which is the one case that must survive being offline: `§4.1` requires an
  // offline screen with no dead end, and a customer who has installed this to the Home
  // Screen and is in a basement has to reach SOMETHING. The cached document boots the app,
  // which then renders its own offline state from `navigator.onLine` and a failed fetch.
  if (request.mode === 'navigate' && url.origin === self.location.origin) {
    event.respondWith(
      (async () => {
        try {
          const fresh = await fetch(request)
          const cache = await self.caches.open(SHELL_CACHE)
          // `/s` is the key for every navigation into this app: the fragment is not part of
          // a cache key and the path is constant by `SR-1`, so one entry serves every scan.
          await cache.put('/s', fresh.clone())

          return fresh
        } catch {
          const cache = await self.caches.open(SHELL_CACHE)
          const cached = await cache.match('/s')

          // No cached shell and no network: let the browser render its own offline page.
          // Inventing one here would mean shipping a second offline screen in a language we
          // have not resolved yet, which is worse than the browser's.
          return cached ?? Response.error()
        }
      })(),
    )

    return
  }

  if (!isShellRequest(request, url)) return

  event.respondWith(
    (async () => {
      const cache = await self.caches.open(SHELL_CACHE)
      const cached = await cache.match(request)

      if (cached) return cached

      const fresh = await fetch(request)

      // Only a real success is stored. A 404 or a 5xx cached here would be served for the
      // life of the cache version, which is how a transient deploy race becomes permanent.
      if (fresh.ok) await cache.put(request, fresh.clone())

      return fresh
    })(),
  )
})

/*
 * THE PUSH HANDLER.
 *
 * showNotification() IS NOT OPTIONAL. Both Safari and Chrome require a visible
 * notification for every push delivered to a web app; a push handler that shows nothing
 * costs the origin its permission after a few silent deliveries. So this handler always
 * shows something, including when the payload cannot be parsed -- a push that arrived and
 * could not be read is still the fact QRPAGER-24 is trying to establish, and swallowing it
 * would turn a successful delivery into a silent failure the operator cannot tell from no
 * delivery at all.
 */
/*
 * ================================================================================
 * TASK 9: THE TAG IS DERIVED FROM THE PAYLOAD -- `R-075`
 * ================================================================================
 *
 * `R-075`'s acceptance clause, verbatim: *"the service worker shows a notification with a
 * `tag` derived from the payload, so a duplicate replaces the visible notification rather
 * than adding one -- and a test drives two identical pushes and asserts one notification."*
 *
 * WHY THE ROW EXISTS. One notification can be delivered twice: the window is one HTTP call
 * wide and no outbox design closes it, because Web Push has no idempotency key and a worker
 * that dies after the edge accepted a message and before committing has no way to record
 * that it did. `Topic` collapses a duplicate that has not yet been DELIVERED; what is left
 * is one that arrives after the first was shown, and only the device knows what it has
 * already shown. So this is the device's half.
 *
 * ⚠ THE TAG MUST NOT COLLAPSE TWO NOTIFICATIONS STAFF GENUINELY ASKED FOR. The payload is
 * `{ v, event, order_reference }` (`PushMessage::encode()`), and `event` is one of
 * `pager.ready`, `pager.renotify`, `pager.cancelled` -- so the tag is EVENT + REFERENCE. Two
 * identical `pager.ready` pushes for one order collapse; a `pager.renotify` that staff sent
 * on purpose does not, which is the same distinction the server's `Topic` makes
 * (`WebPushDispatcher`: *"a renotify and the ready it repeats get DIFFERENT topics, so two
 * notifications staff genuinely asked for cannot collapse into one"*).
 *
 * ⚠ AND THE HARNESS'S OWN TAG IS PRESERVED for a payload this worker cannot read: QRPAGER-24
 * drives real pushes through this file and its operator script reads the result, so a
 * payload-less or unparseable push must still produce exactly the notification that ticket
 * expects.
 *
 * `showNotification()` IS STILL NOT OPTIONAL -- QRPAGER-24's reason, unchanged: both Safari
 * and Chrome require a visible notification for every push delivered to a web app, and a
 * handler that shows nothing costs the origin its permission after a few silent deliveries.
 */
function notificationFor(payload, rawText) {
  // A payload this build cannot read is still a push that ARRIVED, and swallowing it would
  // turn a successful delivery into a silent failure -- QRPAGER-24's wording, kept.
  if (!payload || typeof payload !== 'object' || payload.v !== 1) {
    return {
      title: 'Your order is ready',
      body:
        rawText === null
          ? 'Push received with no payload'
          : 'Push received, payload not recognised: ' + rawText,
      tag: 'qrpager-harness',
    }
  }

  const reference = typeof payload.order_reference === 'string' ? payload.order_reference : ''
  const event = typeof payload.event === 'string' ? payload.event : 'pager.ready'

  if (event === 'pager.cancelled') {
    return {
      title: 'Your order was cancelled',
      body: reference === '' ? '' : 'Order ' + reference,
      tag: 'qrpager:' + event + ':' + reference,
    }
  }

  return {
    title: 'Your order is ready',
    body: reference === '' ? 'Show your order number at the counter.' : 'Order ' + reference,
    tag: 'qrpager:' + event + ':' + reference,
  }
}

self.addEventListener('push', (event) => {
  let payload = null
  let rawText = null

  try {
    payload = event.data ? event.data.json() : null
  } catch {
    payload = null

    try {
      rawText = event.data ? event.data.text() : null
    } catch {
      rawText = null
    }
  }

  const notification = notificationFor(payload, rawText)

  event.waitUntil(
    (async () => {
      await self.registration.showNotification(notification.title, {
        body: notification.body,
        tag: notification.tag,
        // `renotify` with a tag: the notification is REPLACED rather than stacked, and the
        // device still alerts. Without it a replacement is silent, which on a pager means
        // a renotify staff sent deliberately would arrive with no buzz.
        renotify: true,
        data: { receivedAt: new Date().toISOString(), payload },
      })

      /*
       * TASK 9: TELL AN OPEN PAGE.
       *
       * A customer may be LOOKING at the waiting screen when the push lands -- it is the
       * commonest case, not the rare one, because a pager's whole premise is a short wait.
       * Without this the screen would keep showing "being prepared" until the next poll,
       * up to `poll_after_seconds` later, while a notification on the same device says the
       * order is ready. Two surfaces disagreeing is worse than either being slow.
       *
       * ⚠ THE MESSAGE CARRIES NO CONTENT THE PAGE RENDERS. The page's reducer ignores the
       * payload and renders the reference from its own snapshot, so a message from any
       * other script on this origin cannot put attacker-chosen text on the one screen a
       * customer reads from across a room. It is a nudge, not a source.
       */
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

      for (const client of windows) {
        client.postMessage({ type: 'qrpager/push' })
      }
    })(),
  )
})

/*
 * Clicking the notification focuses the installed app rather than opening a second copy.
 * On iOS a standalone web app that gets opened twice loses its service-worker state, which
 * on a single-session test reads as "the push did not arrive".
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      // TASK 9: `/s` as well as the harness route, and `/s` FIRST. A customer tapping the
      // notification must land on the order-ready screen of the app they installed, not on
      // QRPAGER-24's operator page -- and on iOS a standalone web app opened twice loses its
      // service-worker state, which reads as "the push did not arrive".
      for (const client of windows) {
        if (
          (client.url.includes('/s') || client.url.includes('/harness/push')) &&
          'focus' in client
        ) {
          return client.focus()
        }
      }

      return self.clients.openWindow('/s')
    }),
  )
})

/*
 * ================================================================================
 * pushsubscriptionchange -- THE ROTATION HANDLER, HARDENED BY TASK 9
 * ================================================================================
 *
 * The clause it serves is the task's NAMED INVARIANT: *"a rotated subscription is
 * re-registered by the `pushsubscriptionchange` handler and the next notification still
 * arrives."*
 *
 * QRPAGER-24 built the shape and named the two things Task 9 owed it. Both are closed here,
 * and a third thing is recorded that neither ticket knew:
 *
 * ⚠ (1) THE TOKEN. A service worker cannot read `localStorage`, so the credential comes from
 * a `Cache` entry the page writes BEFORE it posts. The harness's handler *"gives up silently"*
 * when that entry is absent -- which the harness itself called *"exactly the failure Task 9's
 * gate clause is about"*. It no longer gives up: it reads EITHER cache (Task 9's, then
 * QRPAGER-24's, so the operator script keeps working), and when neither has one it still
 * SUBSCRIBES and still tells every open client, so `reconcileSubscription()` completes the
 * registration at the next launch. A rotation can no longer end in nothing happening.
 *
 * ⚠ (2) THE OLD ENDPOINT IS NOW FORGOTTEN SERVER-SIDE. The harness left *"a dead row for the
 * dispatcher's 404/410 handling to reap"* and said Task 9 *"should assert rather than inherit
 * [it] by accident"*. It is deleted here, before the new one is registered, so the row goes
 * away because we said so rather than because an edge eventually answered 410.
 *
 * ⚠ AND (3), WHICH IS WHY (2) MATTERS MORE THAN IT LOOKS: WebKit bug 249766, closed WONTFIX,
 * records that a push to an EXPIRED SAFARI ENDPOINT STILL ANSWERS 200. So on Safari the
 * dispatcher's 404/410 pruning never fires at all, and the dead row would never be reaped --
 * an explicit DELETE is the only mechanism that removes it. Measured claim, primary source,
 * recorded in `docs/tickets/QRPAGER-16.md SR-5`.
 *
 * ⚠ `event.oldSubscription` IS NOT RELIED ON. MDN `browser-compat-data` marks Firefox's
 * implementation `partial_implementation` with the note *"The event does not have the
 * `oldSubscription` and `newSubscription` properties"* -- so a handler built on them works in
 * Chromium and does nothing in Firefox. The remembered endpoint from the credential entry is
 * the fallback, and the event's own property is used only when it is there.
 *
 * ⚠ AND THIS HANDLER IS NOT THE WHOLE INVARIANT, which is the finding `SR-5` reports:
 * `pushsubscriptionchange` is `safari_ios: false` in the compat data -- it does not exist on
 * iOS at all, and iOS is the platform `§4 › Realtime` and `R-073` call this product's largest
 * risk. The engine-independent half is `reconcileSubscription()` in
 * `apps/web/src/pager/notify.ts`, run on every launch and every return to visibility, and it
 * is asserted WITH THIS HANDLER REMOVED.
 */

/** The credential, from Task 9's cache or QRPAGER-24's. Null when neither holds one. */
async function readCredential() {
  for (const [name, key] of [
    [CREDENTIAL_CACHE, '/pager-credential'],
    [HARNESS_CACHE, '/harness-token'],
  ]) {
    try {
      const cache = await self.caches.open(name)
      const stored = await cache.match(key)

      if (stored) return await stored.json()
    } catch {
      // A cache that cannot be opened or a body that is not JSON. Try the next one; a
      // throw here would abandon the rotation for a reason the next source may not share.
    }
  }

  return null
}

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    (async () => {
      const credential = await readCredential()
      const token = credential && typeof credential.token === 'string' ? credential.token : null
      const key =
        credential && typeof credential.applicationServerKey === 'string'
          ? credential.applicationServerKey
          : null
      const apiBase = credential && typeof credential.apiBase === 'string' ? credential.apiBase : ''

      // (2) and (3): forget the old endpoint server-side, FIRST, and only when we can
      // authenticate. The endpoint comes from the event when the engine supplies it and
      // from what the page last registered when it does not (Firefox).
      const oldEndpoint =
        (event.oldSubscription && event.oldSubscription.endpoint) ||
        (credential && typeof credential.endpoint === 'string' ? credential.endpoint : null)

      if (token !== null && oldEndpoint !== null) {
        try {
          await fetch(apiBase + '/v1/pager/subscription', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ token, endpoint: oldEndpoint }),
          })
        } catch {
          // The dead row survives until the dispatcher reaps it, which on Safari it never
          // will (3). Not fatal, and not a reason to skip the re-subscribe below -- the
          // customer getting their notification matters more than the stale row.
        }
      }

      if (key === null) {
        // No application server key: we cannot subscribe at all. Tell every open client so
        // the page's reconcile runs with what it has, which is more than this worker has.
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

        for (const client of clients) client.postMessage({ type: 'qrpager/resubscribe' })

        return
      }

      let subscription

      try {
        subscription =
          (event.newSubscription && event.newSubscription) ||
          (await self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: Uint8Array.from(atob(key), (c) => c.charCodeAt(0)),
          }))
      } catch {
        return
      }

      const json = subscription.toJSON()

      if (token === null) {
        // (1): no token, so this worker cannot authenticate -- but it no longer gives up
        // silently. The new subscription exists; an open client is told, and the next launch
        // reconciles. `reconcileSubscription()` compares the live endpoint against the
        // remembered one, so it will see the difference and register it.
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

        for (const client of clients) client.postMessage({ type: 'qrpager/resubscribe' })

        return
      }

      await fetch(apiBase + '/v1/pager/subscription', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          token,
          endpoint: json.endpoint,
          p256dh: json.keys.p256dh,
          auth: json.keys.auth,
        }),
      })

      // Remember the endpoint we just registered, so the next rotation can DELETE it even on
      // an engine that supplies no `oldSubscription`.
      try {
        const cache = await self.caches.open(CREDENTIAL_CACHE)
        await cache.put(
          '/pager-credential',
          new Response(
            JSON.stringify({
              token,
              applicationServerKey: key,
              apiBase,
              endpoint: json.endpoint,
            }),
            { headers: { 'Content-Type': 'application/json' } },
          ),
        )
      } catch {
        // The next rotation falls back to `event.oldSubscription`, and the launch reconcile
        // repairs the record either way.
      }
    })(),
  )
})
