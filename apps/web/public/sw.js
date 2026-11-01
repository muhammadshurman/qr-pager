/*
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

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim())
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
self.addEventListener('push', (event) => {
  let payload

  try {
    payload = event.data ? event.data.json() : null
  } catch {
    payload = null
  }

  const title = (payload && payload.title) || 'Your order is ready'
  const body =
    (payload && payload.body) ||
    (event.data
      ? 'Push received, payload not JSON: ' + event.data.text()
      : 'Push received with no payload')

  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      // A tag, so a second push replaces the first rather than stacking. On a device test
      // a stack of identical notifications hides whether the second one arrived.
      tag: 'qrpager-harness',
      renotify: true,
      data: { receivedAt: new Date().toISOString(), payload },
    }),
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
      for (const client of windows) {
        if (client.url.includes('/harness/push') && 'focus' in client) {
          return client.focus()
        }
      }

      return self.clients.openWindow('/harness/push')
    }),
  )
})

/*
 * pushsubscriptionchange -- THE ROTATION HANDLER, and it is here because Task 9's gate
 * names it: "a rotated subscription is re-registered by the pushsubscriptionchange handler
 * and the next notification still arrives". That is TASK 9's invariant to prove, not this
 * ticket's; what this harness owes is the handler Task 9 extends, so the shape exists and
 * the endpoint it posts to is the real one.
 *
 * ⚠ TWO THINGS TASK 9 MUST HARDEN, named here rather than left to be discovered:
 *  1. THE TOKEN. A service worker cannot read localStorage, so the token is passed in at
 *     subscribe time and kept in a Cache entry by the page. If that entry is absent the
 *     re-registration cannot authenticate and this handler gives up silently -- which is
 *     exactly the failure Task 9's gate clause is about and must assert against.
 *  2. The old endpoint is not unsubscribed server-side here, so a rotation leaves a dead
 *     row for the dispatcher's 404/410 handling to reap. That is correct behaviour for
 *     Task 4's dispatcher (it treats exactly 404 and 410 as dead) but it is a behaviour
 *     Task 9 should assert rather than inherit by accident.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await self.caches.open('qrpager-harness')
      const stored = await cache.match('/harness-token')

      if (!stored) {
        return
      }

      const { token, applicationServerKey, apiBase } = await stored.json()

      const subscription = await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: Uint8Array.from(atob(applicationServerKey), (c) => c.charCodeAt(0)),
      })

      const json = subscription.toJSON()

      await fetch((apiBase || '') + '/v1/pager/subscription', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          token,
          endpoint: json.endpoint,
          p256dh: json.keys.p256dh,
          auth: json.keys.auth,
        }),
      })
    })(),
  )
})
