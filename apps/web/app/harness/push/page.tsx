'use client'

import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * THE TOKEN'S SHAPE, FROM ITS ONE SOURCE. `isWellFormedPagerToken` is GENERATED from
 * `PagerToken::ALPHABET` and the length the PHP enum carries (`PAGER_TOKEN_LENGTH` is 22), and
 * `composer contracts:check` fails when the mirror is stale. The earlier drafts of this file
 * tested `/^[A-Za-z0-9_-]{8,64}$/` by hand in two places -- a second source, and a LOOSER one,
 * which would have accepted a truncated paste and then failed at the API with a 404 that named
 * nothing.
 */
import { isWellFormedPagerToken } from '@qrpager/contracts'

/**
 * QRPAGER-24 — the `R-073` buy-back harness. UNSTYLED, and that is a scope decision, not
 * an omission.
 *
 * Task 5's precedent, named in the ticket: it built "a minimal unstyled harness page purely
 * to exercise the WebAuthn browser API" and left the designed login screen to Task 10. This
 * is the same shape for Web Push. `QRPAGER-24 §2` puts the designed scan → wait → ready
 * journey and the styled install guidance in Task 9, so this file carries **no styling at
 * all** — no className, no style attribute, no token import. The §12 lint rule would be
 * satisfied by a token-sourced value too; the reason there is none is the ticket's §1
 * ("UNSTYLED. No designed screens, no copy work, no tokens, no theme"), not the rule.
 *
 * WHAT IT HAS TO ESTABLISH, and nothing else — `QRPAGER-24 §3`:
 *
 *   A real iPhone, with this harness installed to the HOME SCREEN, receives one real push
 *   produced by Task 4's dispatcher.
 *
 *   A push received in Safari WITHOUT a Home Screen install does not satisfy it.
 *
 * ⚠ SO THIS PAGE REFUSES TO LET THE OPERATOR MISREAD ITS OWN RESULT. The ticket says
 * outright that "testing in the browser tab is the easiest way to believe the risk is
 * closed while it is open". The display-mode is therefore measured and shown before
 * anything else, and every log line is stamped with it — a run performed in a tab produces
 * a transcript that says, on every line, that it does not satisfy §3. The tab run is still
 * ALLOWED, because it is the only available control for "did the server half work at all",
 * and a harness that refuses the control makes a failure ambiguous.
 *
 * THE DISPATCHER IS REAL. `QRPAGER-24 §2`: any change to Task 4's dispatcher is "Nobody's"
 * — this ticket CONSUMES it. The subscribe button posts to `POST /v1/pager/subscription`,
 * the route `routes/customer.php` already serves, with the body that endpoint already
 * validates. Nothing is mocked and no endpoint is added.
 *
 * THE TOKEN NEVER REACHES A URL THE SERVER SEES. `PLAN §3` row 3 as amended: the raw token
 * goes in the FRAGMENT, which no server receives, and both calls below put it in a request
 * BODY. The fragment is read once and stripped with `history.replaceState` immediately,
 * which is Task 9's own clause (a) — implemented here because the operator has to get the
 * token onto the phone somehow and a query string is the one place it must not go.
 *
 * ⚠ AND THE FRAGMENT IS WHY THE TOKEN IS PERSISTED. A Home-Screen install launches at the
 * manifest's `start_url`, which carries NO fragment — so a token held only in memory or
 * only in the URL is gone the moment the operator does the one thing this ticket is about.
 * It goes to `localStorage` for the page and to a Cache entry for the service worker (which
 * cannot read `localStorage`), and `QRPAGER-24 §4`'s one-session constraint is why that is
 * not optional: the tunnel hostname is random per run, a Home-Screen install pins its
 * origin, and the origin's storage dies with the hostname.
 */

/** The origin serving `/v1`. Empty means same-origin, through the harness rewrite. */
const API_BASE = process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''

/** Task 4's VAPID public key, base64url, as the browser's `applicationServerKey`. */
const VAPID_PUBLIC_KEY = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? ''

const TOKEN_KEY = 'qrpager.harness.token'

/**
 * ⚠ THE PRODUCTION CREDENTIAL CACHE, NOT A HARNESS ONE OF ITS OWN, AND THAT IS A SECURITY
 * CHANGE RATHER THAN TIDYING. `§11` round one, SEC M-3, ruled HIGH by Muhammad on 2026-11-01.
 *
 * This page used to write `qrpager-harness` / `/harness-token`, and `sw.js` read it as a
 * SECOND credential source. That made the shipped worker reachable by anything that could
 * write a cache on this origin: plant `{token, apiBase: 'https://attacker.example'}` and the
 * next rotation POSTed the live QR token off-origin -- and the worker wrote `apiBase` back, so
 * the exfiltration survived removal of the script that planted it. `§4 › Data` says the raw
 * token never leaves.
 *
 * The ruling is that the production worker must not be able to read the harness credential
 * **at all -- not validate it, not reach it**. So the harness writes the one entry the worker
 * already owns, and `sw.js` has exactly one credential source again. `qrpager-harness` is no
 * longer in the worker's `OWNED` list either, which means `activate` now DELETES it from any
 * device that ever ran the older harness.
 */
const CACHE_NAME = 'qrpager-pager-credential'
const CACHE_KEY = '/pager-credential'

/**
 * base64url → bytes, for `applicationServerKey`.
 *
 * The return type is `Uint8Array<ArrayBuffer>` and the buffer is allocated explicitly,
 * which is not stylistic. `Uint8Array.from(...)` infers `Uint8Array<ArrayBufferLike>`, and
 * `PushSubscriptionOptionsInit.applicationServerKey` wants `ArrayBufferView<ArrayBuffer>`
 * — `ArrayBufferLike` admits `SharedArrayBuffer`, which is not transferable here, so the
 * two are genuinely different types and `tsc` was right to refuse the wider one.
 */
function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const padded = value.padEnd(value.length + ((4 - (value.length % 4)) % 4), '=')
  const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'))
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }

  return bytes
}

type DisplayMode = 'standalone' | 'browser tab'

function readDisplayMode(): DisplayMode {
  // Two probes, because they answer on different engines. `display-mode: standalone` is the
  // standard one; `navigator.standalone` is Safari's own and is what older iOS reports.
  const navigatorWithStandalone = navigator as Navigator & { standalone?: boolean }

  const standalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    navigatorWithStandalone.standalone === true

  return standalone ? 'standalone' : 'browser tab'
}

export default function PushHarnessPage() {
  const [log, setLog] = useState<readonly string[]>([])
  const [token, setToken] = useState('')
  const [pasted, setPasted] = useState('')
  const [displayMode, setDisplayMode] = useState<DisplayMode | 'measuring'>('measuring')
  const [permission, setPermission] = useState<string>('unknown')
  const [endpoint, setEndpoint] = useState('')
  const modeRef = useRef<DisplayMode | 'measuring'>('measuring')

  const append = useCallback((line: string) => {
    const stamp = new Date().toISOString()
    // THE DISPLAY MODE IS ON EVERY LINE. A transcript pasted into PLAN §4a has to say, by
    // itself, whether it satisfies §3 -- a line that does not name the mode is a line
    // somebody will read as a Home-Screen result.
    setLog((previous) => [...previous, `${stamp} [${modeRef.current}] ${line}`])
  }, [])

  useEffect(() => {
    const mode = readDisplayMode()
    modeRef.current = mode
    setDisplayMode(mode)
    setPermission(typeof Notification === 'undefined' ? 'unsupported' : Notification.permission)

    /*
     * ⚠ THREE SOURCES, IN THIS ORDER, AND THE QUERY STRING IS THE ONE THAT MAKES A HOME-SCREEN
     * LAUNCH WORK AT ALL. The device run of 2026-11-02 reached `standalone` with permission
     * `granted` and then printed `refusing to subscribe: no token`, because:
     *   - an installed web app launches at the MANIFEST'S `start_url`, never at the bookmarked
     *     URL, so a fragment cannot survive the install; and
     *   - a Home-Screen app has its OWN storage, so the `localStorage` written while in Safari
     *     is invisible to it — which is the assumption the comment below used to make.
     * `app/harness/manifest/route.ts` now serves a `start_url` carrying `?t=`, so the launch
     * arrives with the token in the query. Harness-only; production's QR payload is unchanged
     * and still uses the fragment (`PagerToken::qrPayload()`). `R-214`.
     */
    const hash = window.location.hash
    const fromHash = /^#t=([A-Za-z0-9_-]+)$/.exec(hash)
    const fromQuery = new URLSearchParams(window.location.search).get('t') ?? ''
    const queryValid = isWellFormedPagerToken(fromQuery)

    // `fromHash[1]` is `string | undefined` under noUncheckedIndexedAccess, and the `??`
    // is not defensive noise: a group that matched can still be typed optional, and
    // asserting it away with `!` is what the strict config exists to prevent.
    // Declared without an initialiser on purpose: all three branches below assign it, so a
    // starting value would be dead and `no-useless-assignment` says so.
    let resolved: string

    if (fromHash) {
      const value = fromHash[1] ?? ''
      resolved = value
      window.localStorage.setItem(TOKEN_KEY, value)
      setToken(value)
      window.history.replaceState(null, '', window.location.pathname)
      append('token read from the URL fragment, stored, and the fragment stripped')
    } else if (queryValid) {
      /*
       * ⚠ NOT STRIPPED, UNLIKE THE FRAGMENT, AND DELIBERATELY. The installed app's `start_url`
       * IS this query, so every launch arrives with it; rewriting the address bar would buy no
       * secrecy (the token is already in the manifest this origin served) while adding a moving
       * part to the one path a human performs by hand. The residual — a session token in a
       * development tunnel's access log for one run — is stated in the manifest route.
       */
      resolved = fromQuery
      window.localStorage.setItem(TOKEN_KEY, fromQuery)
      setToken(fromQuery)
      append('token read from the start_url query -- this is a Home Screen launch')
    } else {
      const stored = window.localStorage.getItem(TOKEN_KEY) ?? ''
      resolved = stored
      setToken(stored)
      append(
        stored === ''
          ? 'no token yet -- open this page as /harness/push?t=<token>'
          : 'token restored from storage (same browsing context as the read)',
      )
    }

    /*
     * ⚠ THE MANIFEST LINK IS REPOINTED AT THE PER-SESSION MANIFEST, AND THIS IS THE LINE THE
     * DEVICE RUN TURNS ON. iOS reads the `<link rel="manifest">` in the live DOM when the
     * operator taps *Add to Home Screen*, so rewriting the href before that moment is what puts
     * the token into the installed app's `start_url`. The static `/manifest.webmanifest` stays
     * as the no-token fallback, which is what makes an install without a token still produce a
     * diagnosable page rather than a greyed-out menu item.
     *
     * Done here rather than in the layout's `metadata` because the layout is a server component
     * on a statically prerendered route: it cannot see a token that only exists in this
     * browser's URL.
     */
    if (resolved !== '') {
      const link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')

      if (link === null) {
        append('WARNING: no <link rel="manifest"> to repoint -- the install will carry no token')
      } else {
        link.href = `/harness/manifest?t=${encodeURIComponent(resolved)}`
        append(
          `manifest repointed to ${link.getAttribute('href') ?? ''} -- start_url carries the token`,
        )
      }
    }

    if (mode !== 'standalone') {
      append(
        'NOT STANDALONE. A push received here does NOT satisfy QRPAGER-24 §3. ' +
          'Add to Home Screen and reopen from the icon.',
      )
    }

    if (VAPID_PUBLIC_KEY === '') {
      append('NEXT_PUBLIC_VAPID_PUBLIC_KEY is empty -- subscribing cannot work. See §4.')
    }
  }, [append])

  const register = useCallback(async (): Promise<ServiceWorkerRegistration | null> => {
    if (!('serviceWorker' in navigator)) {
      append('this engine has no service worker, so it has no push path at all')

      return null
    }

    // The same `?api=` channel the customer page uses (SEC-R2 H-1).
    const registration = await navigator.serviceWorker.register(
      `/sw.js?api=${encodeURIComponent(API_BASE)}`,
      { scope: '/' },
    )
    await navigator.serviceWorker.ready
    append(`service worker ready, scope ${registration.scope}`)

    return registration
  }, [append])

  const onPermission = useCallback(() => {
    void (async () => {
      try {
        // MUST be called from a user gesture on iOS, which is why this is a button and not
        // something the effect above does on mount.
        const result = await Notification.requestPermission()
        setPermission(result)
        append(`Notification.requestPermission() -> ${result}`)
      } catch (error) {
        append(`permission request threw: ${String(error)}`)
      }
    })()
  }, [append])

  const onSubscribe = useCallback(() => {
    void (async () => {
      try {
        if (token === '') {
          append('refusing to subscribe: no token. Open /harness/push#t=<token>')

          return
        }

        const registration = await register()

        if (!registration) {
          return
        }

        const subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64UrlToBytes(VAPID_PUBLIC_KEY),
        })

        const json = subscription.toJSON()
        const keys = json.keys ?? {}

        if (json.endpoint === undefined || keys.p256dh === undefined || keys.auth === undefined) {
          append('the browser returned a subscription with no endpoint or no keys')

          return
        }

        setEndpoint(json.endpoint)
        append(`subscribed at ${new URL(json.endpoint).origin} (Apple's push service, if iOS)`)

        // The service worker cannot read localStorage, so `pushsubscriptionchange` gets
        // what it needs from a Cache entry instead. Written BEFORE the POST: a rotation
        // that fires between the two would otherwise find nothing.
        const cache = await caches.open(CACHE_NAME)
        await cache.put(
          CACHE_KEY,
          new Response(
            JSON.stringify({
              token,
              applicationServerKey: VAPID_PUBLIC_KEY,
              apiBase: API_BASE,
              // `sw.js` treats an ABSENT expiry as expired (SEC M-2), so the harness supplies
              // one. Two hours is the TTL of the pager session this token belongs to.
              expiresAt: new Date(Date.now() + 2 * 60 * 60 * 1000).toISOString(),
            }),
            { headers: { 'Content-Type': 'application/json' } },
          ),
        )

        const response = await fetch(`${API_BASE}/v1/pager/subscription`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            token,
            endpoint: json.endpoint,
            p256dh: keys.p256dh,
            auth: keys.auth,
          }),
        })

        append(`POST /v1/pager/subscription -> ${String(response.status)} ${await response.text()}`)

        if (response.ok) {
          append('registered with Task 4. Trigger the dispatcher now; the push lands here.')
        }
      } catch (error) {
        append(`subscribe failed: ${String(error)}`)
      }
    })()
  }, [append, register, token])

  const onUnsubscribe = useCallback(() => {
    void (async () => {
      try {
        const registration = await navigator.serviceWorker.getRegistration('/')
        const subscription = await registration?.pushManager.getSubscription()

        if (!subscription) {
          append('no subscription on this device')

          return
        }

        const response = await fetch(`${API_BASE}/v1/pager/subscription`, {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ token, endpoint: subscription.endpoint }),
        })

        await subscription.unsubscribe()
        setEndpoint('')
        append(`DELETE /v1/pager/subscription -> ${String(response.status)}, then unsubscribed`)
      } catch (error) {
        append(`unsubscribe failed: ${String(error)}`)
      }
    })()
  }, [append, token])

  /**
   * ================================================================================
   * THE TOKEN, PASTED INTO THE INSTALLED APP. RULED BY MUHAMMAD, 2026-11-02.
   * ================================================================================
   *
   * ⚠ TWO DEVICE ATTEMPTS FAILED BEFORE THIS EXISTED, BOTH THE SAME WAY, and the second one
   * failed AFTER the manifest was made per-session. Verbatim from the phone both times:
   *
   *     [standalone] no token yet
   *     [standalone] Notification.requestPermission() -> granted
   *     [standalone] refusing to subscribe: no token.
   *
   * iOS did not honour the repointed `<link rel="manifest">`, for either of two sufficient
   * reasons: Safari reads that link from the HTML **as served** and a client-side rewrite is
   * unreliable there, and Safari caches a manifest per ORIGIN, so attempt 1's token-less
   * manifest was very likely still in use.
   *
   * ⚠ THE RULING, AND IT IS THE POINT OF THIS WHOLE FILE: **THE INSTALL PATH'S TOKEN TRANSPORT
   * IS NOT THE THING UNDER TEST.** This harness exists to prove ONE fact — that a push from
   * Task 4's real dispatcher reaches an iPhone installed to the Home Screen. The token merely
   * has to BE in the standalone context; how it arrives there proves nothing about the push.
   * Two attempts were spent guessing at Safari's manifest behaviour, which is exactly the kind
   * of incidental mechanism `§8` says to replace with the strongest one available.
   *
   * So: a text field. Deterministic, no iOS behaviour to guess at. It is shown ONLY when this
   * context has no token, so a launch that does carry one is unchanged.
   *
   * ⚠ **PRODUCTION'S QR PAYLOAD IS UNTOUCHED.** `PagerToken::qrPayload()` still emits
   * `{landing_url}#{token}` and `PLAN §3` row 3's fragment rule is unchanged. Nothing a customer
   * ever sees has an input box for a token; this is a development harness and `R-214` says so.
   *
   * The storage write is this context's OWN storage, which is the half that made the previous
   * approach impossible: a Home-Screen app's storage is partitioned from Safari's, so a token
   * written here is visible to the installed app and to nothing else.
   */
  const onPasteToken = useCallback(() => {
    const value = pasted.trim()

    if (!isWellFormedPagerToken(value)) {
      append(
        `refusing that token: it is not well-formed (${String(value.length)} chars). ` +
          'The shape is checked with the GENERATED isWellFormedPagerToken, so a truncated ' +
          'paste is refused here rather than failing later at the API.',
      )

      return
    }

    window.localStorage.setItem(TOKEN_KEY, value)
    setToken(value)
    setPasted('')
    append(
      'token accepted from the paste field and stored in THIS context -- subscribe is now available',
    )
  }, [append, pasted])

  return (
    <main>
      <h1>QR Pager push harness</h1>
      <p>
        QRPAGER-24. Unstyled on purpose — Task 9 (QRPAGER-16) owns every customer screen. This page
        exists to establish one fact: that a push produced by Task 4&apos;s real dispatcher reaches
        an iPhone running this app <strong>installed to the Home Screen</strong>.
      </p>

      <h2>State</h2>
      <dl>
        <dt>Display mode</dt>
        <dd>
          <strong>{displayMode}</strong>
          {displayMode === 'browser tab'
            ? ' — a push received in a tab does NOT satisfy QRPAGER-24 §3.'
            : ''}
        </dd>
        <dt>Notification permission</dt>
        <dd>{permission}</dd>
        <dt>Token</dt>
        <dd>{token === '' ? 'none — paste it below' : 'present'}</dd>
        <dt>Push endpoint</dt>
        <dd>{endpoint === '' ? 'not subscribed' : new URL(endpoint).origin}</dd>
        <dt>API base</dt>
        <dd>{API_BASE === '' ? 'same origin' : API_BASE}</dd>
      </dl>

      {token === '' ? (
        <section>
          <h2>Token</h2>
          <p>
            This context has no token. Paste it here — the install path&apos;s token transport is
            not what this harness tests, and a Home Screen app&apos;s storage is partitioned from
            Safari&apos;s, so it cannot be carried across. Production&apos;s QR payload is unchanged
            and still uses the URL fragment.
          </p>
          <label htmlFor="token">Pager token</label>{' '}
          <input
            id="token"
            name="token"
            type="text"
            autoComplete="off"
            autoCapitalize="off"
            autoCorrect="off"
            spellCheck={false}
            inputMode="text"
            value={pasted}
            onChange={(event) => {
              setPasted(event.target.value)
            }}
          />{' '}
          <button type="button" onClick={onPasteToken}>
            Use this token
          </button>
        </section>
      ) : null}

      <h2>Steps</h2>
      <ol>
        <li>
          Add to Home Screen, then reopen from the icon. On iOS there is no push path without this.
        </li>
        <li>
          <button type="button" onClick={onPermission}>
            Grant notification permission
          </button>
        </li>
        <li>
          <button type="button" onClick={onSubscribe}>
            Subscribe
          </button>
        </li>
        <li>Trigger the dispatcher from the operator script, and wait.</li>
        <li>
          <button type="button" onClick={onUnsubscribe}>
            Unsubscribe
          </button>
        </li>
      </ol>

      <h2>Transcript</h2>
      <p>Paste this into PLAN §4a. Every line names the display mode it was produced in.</p>
      <pre>{log.join('\n')}</pre>
    </main>
  )
}
