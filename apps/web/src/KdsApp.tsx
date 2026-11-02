'use client'

import { ThemeProvider, type Locale } from '@qrpager/ui'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { translatorFor } from './copy/index.ts'
import { fetchKdsBoard, type KdsOrder } from './kds/api.ts'
import { KDS_INTERVAL_SECONDS, isKdsStale, kdsElapsedSeconds, reconcile } from './kds/resync.ts'
import {
  deviceStorageFrom,
  forgetDeviceToken,
  recallDeviceToken,
  rememberDeviceToken,
  type DeviceTokenStore,
} from './kds/storage.ts'
import { browserLocale } from './pager/locale.ts'
import { delayFromAnswerMs } from './pager/poll.ts'
import { KdsBoard, type KdsPhase } from './screens/kds/KdsBoard.tsx'
import { KdsSetup } from './screens/kds/KdsSetup.tsx'

/**
 * ================================================================================
 * THE KITCHEN DISPLAY, ORCHESTRATED. 10c — `QRPAGER-27`.
 * ================================================================================
 *
 * Two screens and one loop. `KdsSetup` until a code is stored, `KdsBoard` after.
 *
 * ================================================================================
 * WHY THIS IS NOT A VIEW OF `StaffApp`
 * ================================================================================
 *
 * `StaffApp` is 1200 lines of session lifetime, idle lock, restaurant chooser, passkey
 * ceremony, two-factor and four transitions — all of it predicated on a human being signed in
 * with a cookie. This surface has **no session, no person and no cookie**: its credential is a
 * bearer token in `localStorage`, `§4 › Tenancy` calls the two *two authentication surfaces,
 * never confused*, and a `view` prop on `StaffApp` would put both in one component where a
 * single wrong branch serves one surface's data under the other's credential.
 *
 * The concrete things that would have to be defeated rather than reused:
 *
 *   - **the idle lock.** `StaffApp` signs the session out after 15 minutes of no input. A wall
 *     screen receives no input for a whole service, by design, and locking it is the precise
 *     opposite of its job. 10a's lock is a security control for a hand-held tablet with a
 *     human session on it; there is nothing here for it to protect.
 *   - **`credentials: 'same-origin'`.** Every `staff/api.ts` call sends the cookie. This
 *     surface sends `omit` (see `kds/api.ts`) so the token is the only credential in play.
 *   - **the chooser.** A device token resolves exactly one tenant and offers no choice.
 *
 * So: two apps, one copy catalogue, one kit, one theme. `apps/web/test/kds-app.test.tsx` and
 * `kds-build-surface.test.ts` hold the separation to behaviour rather than to this comment.
 *
 * ================================================================================
 * THE POLL LOOP IS `StaffApp`'s, DOWN TO THE MONOTONIC TOKEN
 * ================================================================================
 *
 * `pollRunRef` is bumped on arm and on teardown, and an answer in flight checks it before
 * arming the next timer — so a poll scheduled by a superseded run cannot resurrect itself.
 * That pattern and `delayFromAnswerMs`'s jittered, capped backoff are Task 9's arithmetic,
 * reused rather than re-implemented for `§6.2`'s reason.
 */

export interface KdsAppProps {
  readonly apiBase: string
  /** Injected in tests. The real app reads the BROWSER's own language list. */
  readonly locale?: Locale | undefined
  /** Injected in tests, so a case need not stand up `localStorage`. */
  readonly store?: DeviceTokenStore
  /** Injected in tests. The real app uses the global. */
  readonly fetcher?: typeof fetch
  /** Injected so a test can assert an EXACT delay rather than sampling a range. */
  readonly random?: () => number
}

export function KdsApp({ apiBase, locale, store, fetcher, random }: KdsAppProps) {
  /*
   * ⚠ THE LANGUAGE COMES FROM THE BROWSER AND IS DETECTED IN AN EFFECT, which is
   * `StaffApp`'s own pattern and for its reason: this route is statically prerendered, so the
   * server render has no `navigator` and a direct read would be a hydration mismatch.
   */
  const [detected, setDetected] = useState<Locale | null>(locale ?? null)

  useEffect(() => {
    if (locale !== undefined) return
    if (typeof navigator === 'undefined') return

    setDetected(browserLocale(navigator))
  }, [locale])

  const resolved: Locale = detected ?? 'en'
  const t = useMemo(() => translatorFor(resolved), [resolved])

  /*
   * ⚠ THE STORE IS RESOLVED IN AN EFFECT TOO, AND NOT WITH A `useState` INITIALISER.
   * `deviceStorageFrom(window)` touches `window`, which does not exist during the prerender;
   * an initialiser runs on the server and would throw at build time.
   */
  const [resolvedStore, setResolvedStore] = useState<DeviceTokenStore | null>(store ?? null)

  useEffect(() => {
    if (store !== undefined) return

    setResolvedStore(deviceStorageFrom(typeof window === 'undefined' ? undefined : window))
  }, [store])

  const [token, setToken] = useState<string | null>(null)
  /** `null` until the store has been read once, so setup does not flash before a stored code. */
  const [recalled, setRecalled] = useState(false)

  useEffect(() => {
    if (resolvedStore === null) return

    setToken(recallDeviceToken(resolvedStore))
    setRecalled(true)
  }, [resolvedStore])

  /* --------------------------------------------------------------------------- setup */

  const [draft, setDraft] = useState('')
  const [refused, setRefused] = useState(false)
  const [verifying, setVerifying] = useState(false)

  /* ------------------------------------------------------------------------ the board */

  /**
   * ⚠ THE ORDERS AND THE PHASE ARE SEPARATE STATE, which is the whole of the resync design.
   *
   * A failed poll moves the PHASE and leaves the ORDERS alone, so the kitchen keeps looking at
   * what it had while the client retries. `reconcile` is the only writer of `orders`, and on a
   * successful answer it REPLACES — never merges. `kds/resync.ts`'s header has the three merge
   * shapes that produce a duplicate row and why the third one is the dangerous one.
   */
  const [orders, setOrders] = useState<readonly KdsOrder[]>([])
  const [phase, setPhase] = useState<KdsPhase>('loading')
  const [confirmedAt, setConfirmedAt] = useState<number | null>(null)
  /** Moves on every ATTEMPT, successful or not — `§11` QA H-1's lesson from the staff board:
   *  a staleness memo whose dependencies only change on SUCCESS can never fire. */
  const [attemptedAt, setAttemptedAt] = useState<number | null>(null)
  const [forgetting, setForgetting] = useState(false)

  const failuresRef = useRef(0)
  const retryAfterRef = useRef<number | null>(null)
  const pollRunRef = useRef(0)

  const randomRef = useRef<() => number>(random ?? Math.random)

  useEffect(() => {
    randomRef.current = random ?? Math.random
  }, [random])

  const fetcherRef = useRef<typeof fetch | undefined>(fetcher)

  useEffect(() => {
    fetcherRef.current = fetcher
  }, [fetcher])

  /** The one place the stored code is dropped, so the two callers cannot diverge. */
  const dropToken = useCallback(
    (asRefusal: boolean): void => {
      if (resolvedStore !== null) forgetDeviceToken(resolvedStore)

      setToken(null)
      setOrders([])
      setPhase('loading')
      setConfirmedAt(null)
      setAttemptedAt(null)
      setForgetting(false)
      setDraft('')
      setRefused(asRefusal)
      failuresRef.current = 0
      retryAfterRef.current = null
    },
    [resolvedStore],
  )

  const poll = useCallback(async (): Promise<void> => {
    if (token === null) return

    const answer = await fetchKdsBoard(apiBase, token, fetcherRef.current ?? fetch)

    setAttemptedAt(Date.now())

    /*
     * ⚠ A 401 OR A 403 DELETES THE STORED CODE AND RETURNS TO SETUP. `kds/storage.ts` has the
     * reason: a device token expires at thirty days or is revoked from `/staff/devices`, and
     * keeping a value that cannot work leaves a dead credential at rest on a shared device
     * indefinitely while the screen shows a failure nobody can act on. Setup is the honest
     * screen — this device needs a new code — and `kds/api.ts` explains why 403 collapses
     * onto the same answer here when it does not on the staff surface.
     */
    if (answer.kind === 'unauthorised') {
      dropToken(true)

      return
    }

    if (answer.kind === 'ok') {
      failuresRef.current = 0
      retryAfterRef.current = null
      setOrders((cached) => reconcile(cached, answer.orders))
      setConfirmedAt(Date.now())
      setPhase('ready')

      return
    }

    // Every remaining kind is a failure, so the counter moves and the rows do not. `reconcile`
    // is still the writer, with `null` for "no answer" — one path into `orders`, not two.
    failuresRef.current += 1
    retryAfterRef.current = answer.kind === 'throttled' ? answer.retryAfter : null
    setOrders((cached) => reconcile(cached, null))
    setPhase(
      answer.kind === 'throttled'
        ? 'throttled'
        : answer.kind === 'unreachable'
          ? 'offline'
          : 'error',
    )
  }, [apiBase, token, dropToken])

  useEffect(() => {
    if (token === null) return

    pollRunRef.current += 1

    const run = pollRunRef.current
    let timer: ReturnType<typeof setTimeout> | undefined

    const tick = async (): Promise<void> => {
      await poll()

      if (pollRunRef.current !== run) return

      timer = setTimeout(
        () => void tick(),
        delayFromAnswerMs(
          retryAfterRef.current,
          KDS_INTERVAL_SECONDS,
          failuresRef.current,
          randomRef.current,
        ),
      )
    }

    void tick()

    return () => {
      // Bumped as well as cleared: an answer already in flight checks the token before arming
      // the next timer, and `clearTimeout` cannot reach a timer that does not exist yet.
      pollRunRef.current += 1

      if (timer !== undefined) clearTimeout(timer)
    }
  }, [token, poll])

  /*
   * ⚠ THE BROWSER'S OWN `online` EVENT POLLS IMMEDIATELY RATHER THAN WAITING FOR THE TICK, AND
   * THIS IS THE LINE 10c's INVARIANT IS ACTUALLY ABOUT.
   *
   * Without it, a kitchen screen that lost the network for ten minutes shows stale orders for
   * up to another two minutes after it comes back, because the backoff has grown to its
   * 120-second cap. With it, the screen is right again within one round trip.
   *
   * **And it is the exact place a reconnect could re-send something**, which is why
   * `QRPAGER-27 §5` calls a naive refetch the hard half. What is scheduled here is `poll()` —
   * one `GET`, through `kds/api.ts`, which has no write in it at all. There is no queue being
   * drained, no last-action being retried and no pending transition being flushed, because
   * this client never had one. `tools/gate/bin/kds-resync-proof` drops a real browser's
   * network, brings it back, and compares the server's own dispatch count across the reconnect.
   *
   * `offline` is deliberately NOT handled: `navigator.onLine` going false does not need a
   * reaction, because the in-flight poll will fail on its own and the backoff is already the
   * right response. Acting on `offline` would mean trusting a flag every engine computes
   * differently; acting on `online` only ever costs one extra request.
   */
  useEffect(() => {
    if (typeof window === 'undefined') return
    if (token === null) return

    const onOnline = (): void => {
      // The failure counter is reset FIRST, so the tick this schedules is not itself delayed
      // by the backoff that the outage built up.
      failuresRef.current = 0
      retryAfterRef.current = null
      void poll()
    }

    window.addEventListener('online', onOnline)

    return () => {
      window.removeEventListener('online', onOnline)
    }
  }, [token, poll])

  /* ------------------------------------------------------------------------- derived */

  const now = attemptedAt ?? confirmedAt
  const staleSince = useMemo(() => {
    if (confirmedAt === null) return null
    if (now === null) return null
    if (!isKdsStale(confirmedAt, now)) return null

    return new Intl.DateTimeFormat(resolved, { hour: '2-digit', minute: '2-digit' }).format(
      confirmedAt,
    )
  }, [confirmedAt, now, resolved])

  const elapsed = useMemo(() => {
    const at = now ?? Date.now()
    const out: Record<string, number> = {}

    for (const order of orders) out[order.id] = kdsElapsedSeconds(order.createdAt, at)

    return out
  }, [orders, now])

  const onSubmitCode = useCallback((): void => {
    const trimmed = draft.trim()

    if (trimmed === '' || resolvedStore === null) return

    /*
     * ⚠ THE CODE IS STORED BEFORE IT IS VERIFIED, AND THE FIRST POLL IS THE VERIFICATION.
     *
     * The alternative — one probe request, then store on a 200 — is two round trips to learn
     * one thing, and it has a worse failure mode on the surface that matters: a probe that
     * fails because the kitchen wifi is down would tell the person their code is wrong and
     * discard it, which is the one answer that is certainly false. Stored first, a dead
     * network shows `offline` and the code survives; a genuinely bad code comes back 401 and
     * `dropToken(true)` removes it and says so.
     */
    setVerifying(true)
    rememberDeviceToken(resolvedStore, trimmed)
    setRefused(false)
    setToken(trimmed)
    setVerifying(false)
  }, [draft, resolvedStore])

  if (!recalled) {
    // One frame at most, and it must not be the setup screen: showing a code field to a wall
    // tablet that already has a code, even for a frame, is a flash a passer-by reads as
    // "somebody signed this out".
    return (
      <ThemeProvider locale={resolved} as="div">
        <main className="qr-kds" data-kds-view="loading" />
      </ThemeProvider>
    )
  }

  return (
    <ThemeProvider locale={resolved} as="div">
      {token === null ? (
        <KdsSetup
          t={t}
          value={draft}
          onChange={setDraft}
          onSubmit={onSubmitCode}
          refused={refused}
          busy={verifying}
        />
      ) : (
        <KdsBoard
          t={t}
          phase={phase}
          orders={orders}
          elapsed={elapsed}
          staleSince={staleSince}
          onRetry={() => {
            void poll()
          }}
          forgetting={forgetting}
          onForget={() => {
            setForgetting(true)
          }}
          onConfirmForget={() => {
            dropToken(false)
          }}
          onCancelForget={() => {
            setForgetting(false)
          }}
        />
      )}
    </ThemeProvider>
  )
}
