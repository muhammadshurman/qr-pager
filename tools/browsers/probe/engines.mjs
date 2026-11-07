/* Measures, per engine, exactly the capabilities Task 9's states are keyed on, and the
 * Sec-Fetch-* question R-194 asks. Nothing here is assumed. */
import { chromium, firefox, webkit } from 'playwright'
import { createServer } from 'node:http'

const seen = []
const server = createServer((req, res) => {
  seen.push({ url: req.url, headers: req.headers })
  res.writeHead(200, { 'Content-Type': 'text/html' })
  res.end(
    '<!doctype html><html><head><title>probe</title></head><body><h1>probe</h1><a id=nav href="/second">go</a></body></html>',
  )
})
await new Promise((r) => server.listen(0, '127.0.0.1', r))
const port = server.address().port
const base = `http://127.0.0.1:${port}`

const CAPS = `(() => {
  const g = globalThis
  const has = (o, k) => { try { return k in o } catch { return 'throw' } }
  return {
    serviceWorker: has(navigator, 'serviceWorker'),
    PushManager: typeof g.PushManager,
    Notification: typeof g.Notification,
    notificationPermissionProp: (() => { try { return typeof Notification.permission } catch { return 'absent' } })(),
    ServiceWorkerRegistration_showNotification: (() => { try { return 'showNotification' in ServiceWorkerRegistration.prototype } catch { return 'absent' } })(),
    ServiceWorkerRegistration_pushManager: (() => { try { return 'pushManager' in ServiceWorkerRegistration.prototype } catch { return 'absent' } })(),
    onpushsubscriptionchange_on_SWGS: (() => { try { return 'onpushsubscriptionchange' in ServiceWorkerGlobalScope.prototype } catch { return 'no ServiceWorkerGlobalScope in window' } })(),
    PushSubscription: typeof g.PushSubscription,
    wakeLock: has(navigator, 'wakeLock'),
    vibrate: has(navigator, 'vibrate'),
    matchMediaDisplayMode: (() => { try { return typeof matchMedia('(display-mode: standalone)').matches } catch { return 'absent' } })(),
    navigatorStandalone: has(navigator, 'standalone'),
    onLine: typeof navigator.onLine,
    caches: typeof g.caches,
    AudioContext: typeof g.AudioContext,
    historyReplaceState: typeof history.replaceState,
  }
})()`

for (const [name, type] of [
  ['chromium', chromium],
  ['firefox', firefox],
  ['webkit', webkit],
]) {
  const b = await type.launch()
  const ctx = await b.newContext()
  const p = await ctx.newPage()
  const before = seen.length
  await p.goto(`${base}/first`)
  const nav = seen[before]
  await p.click('#nav').catch(() => {
    /* a link click is a best-effort second navigation; the first goto is the measurement */
  })
  await p.waitForTimeout(300)
  const navClick = seen[before + 1]
  const caps = await p.evaluate(CAPS)
  console.log(`\n===== ${name} ${b.version()} =====`)
  console.log('caps:', JSON.stringify(caps, null, 1))
  const pick = (h) =>
    h
      ? Object.fromEntries(
          Object.entries(h).filter(
            ([k]) => k.startsWith('sec-fetch') || k === 'accept' || k === 'referer',
          ),
        )
      : null
  console.log('top-level goto headers:', JSON.stringify(pick(nav?.headers)))
  console.log('link-click nav headers:', JSON.stringify(pick(navClick?.headers)))
  await b.close()
}
server.close()
