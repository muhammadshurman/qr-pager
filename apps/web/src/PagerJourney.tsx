'use client'

import { Spinner, ThemeProvider, directionFor, type Locale } from '@qrpager/ui'
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react'

import { translatorFor } from './copy/index.ts'
import { alertEnvironmentFrom, soundTheAlert } from './pager/alert.ts'
import { forgetDevice, pollStatus, resolveSession, type ApiConfig } from './pager/api.ts'
import { installGuidance } from './pager/install.ts'
import { browserLocale } from './pager/locale.ts'
import { INITIAL, reduce, screenOf, type JourneyEvent, type JourneyState } from './pager/journey.ts'
import {
  askPermission,
  clearWorkerCredential,
  currentPermission,
  pushCapability,
  reconcileSubscription,
  type PushEnvironment,
} from './pager/notify.ts'
import { delayFromAnswerMs, elapsedSeconds, nextDelayMs } from './pager/poll.ts'
import { forgetToken, recallToken, rememberToken, storageFrom } from './pager/storage.ts'
import { readPresentedToken, type PresentedToken } from './pager/token.ts'
import { holdScreenAwake, wakeEnvironmentFrom, type WakeOutcome } from './pager/wake.ts'
import { EnableAlerts } from './screens/EnableAlerts.tsx'
import { OfflineError } from './screens/OfflineError.tsx'
import { OrderReadyScreen } from './screens/OrderReadyScreen.tsx'
import { ScanLanding } from './screens/ScanLanding.tsx'
import { SessionEnded } from './screens/SessionEnded.tsx'
import { Waiting } from './screens/Waiting.tsx'

/**
 * ================================================================================
 * THE ORCHESTRATOR. The only stateful thing in the app, and it decides nothing.
 * ================================================================================
 *
 * Every decision is in `journey.ts`'s pure reducer. This component does the I/O the reducer
 * cannot: the fragment, the requests, the timer, the permission gesture, the service worker
 * and the wake lock. It reads `state.screen` and renders; it never computes a screen.
 *
 * That split is what makes `§11` role 2's *"every branch of any state machine has a test"*
 * answerable: the branches are in a function a unit test drives with no DOM, and what is
 * left here is wiring a browser test drives end to end.
 */

/** Injected so the browser suite can point the app at a real API on another port. */
export interface PagerJourneyProps {
  readonly apiBase: string
  readonly vapidPublicKey: string
  /** Forced by the browser suite, which drives both launch languages. Otherwise detected. */
  readonly locale?: Locale | undefined
}

interface WakeState {
  readonly outcome: WakeOutcome
  readonly on: boolean
}

/**
 * ================================================================================
 * THE STATIC SHELL, AND WHY IT CARRIES NO COPY
 * ================================================================================
 *
 * `§4.1`: the scan landing is a *"static shell from CDN"*. This file is statically
 * prerendered, so the HTML in the CDN is produced at build time -- with no `navigator` and
 * therefore no way to know whether the customer reads Arabic or English.
 *
 * ⚠ RENDERING THE ENGLISH SHELL AND SWITCHING ON HYDRATION WOULD BE TWO DEFECTS, not one.
 * It is a hydration mismatch for every Arabic customer, and it is a visible flash of the
 * wrong language and the wrong direction on the one screen `§12` names Arabic a launch
 * language for.
 *
 * So the prerendered shell is STRUCTURAL ONLY: a spinner with no text, which is identical in
 * both languages and in both directions, and it costs nothing -- the first paint has no
 * order data either way, because the reference needs the resolve round trip. The locale is
 * read at hydration, and by the moment there is a word to read it is already correct.
 */
export function PagerJourney({ apiBase, vapidPublicKey, locale }: PagerJourneyProps) {
  const [state, dispatch] = useReducer(reduce, INITIAL)
  const [wake, setWake] = useState<WakeState>({ outcome: 'unavailable', on: false })
  const [detected, setDetected] = useState<Locale | null>(locale ?? null)
  /** Bumped by the worker's `qrpager/resubscribe` message, to re-run the reconcile now. */
  const [reconcileNonce, setReconcileNonce] = useState(0)
  const resolved: Locale = detected ?? 'en'
  const t = useMemo(() => translatorFor(resolved), [resolved])

  useEffect(() => {
    if (locale !== undefined) return

    setDetected(browserLocale(navigator))
  }, [locale])

  const api = useMemo<ApiConfig>(
    () => ({ base: apiBase, fetch: (...args) => fetch(...args) }),
    [apiBase],
  )

  // ⚠ A REF, AND IT IS WHY THE FRAGMENT IS READ EXACTLY ONCE. React 19's strict mode mounts
  // every effect twice in development, and the second read would find the fragment already
  // stripped by the first -- turning a valid scan into `landing.invalid` in dev only, which
  // is the worst place for a bug to live.
  const readFragment = useRef(false)
  const releaseWake = useRef<null | (() => Promise<void>)>(null)
  const alerted = useRef(false)

  const push = useMemo<PushEnvironment | null>(() => {
    if (typeof window === 'undefined') return null

    return {
      navigator: window.navigator,
      notification:
        typeof Notification === 'undefined'
          ? null
          : {
              permission: Notification.permission,
              requestPermission: () => Notification.requestPermission(),
            },
      caches: 'caches' in window ? window.caches : null,
      storage: storageFrom(window),
      pushManagerAvailable: 'PushManager' in window,
    }
  }, [])

  const install = useMemo(() => {
    if (typeof window === 'undefined') return { kind: 'not-applicable' as const }

    const nav = window.navigator as Navigator & { standalone?: boolean }

    return installGuidance({
      matchMedia: (query) => window.matchMedia(query),
      navigatorStandalone: nav.standalone,
      userAgent: nav.userAgent,
      maxTouchPoints: nav.maxTouchPoints,
    })
  }, [])

  // ------------------------------------------------------------------ the fragment
  useEffect(() => {
    if (readFragment.current) return
    readFragment.current = true

    const store = { storage: storageFrom(window), now: () => Date.now() }
    const outcome = readPresentedToken(window)

    if (outcome.kind === 'present') {
      dispatch({ type: 'token/present', token: outcome.token })

      return
    }

    if (outcome.kind === 'malformed') {
      dispatch({ type: 'token/malformed' })

      return
    }

    // No fragment. A Home-Screen launch carries none, so the remembered token is the whole
    // reason this app is usable after an install -- see `storage.ts`.
    const remembered = recallToken(store)

    if (remembered === null) {
      dispatch({ type: 'token/malformed' })

      return
    }

    dispatch({ type: 'token/present', token: remembered })
  }, [])

  // ------------------------------------------------------- the permission probe
  useEffect(() => {
    if (push === null) return

    // A PROBE, never a request: `askPermission` is reachable only from the button. It records
    // what the browser already thinks so the alerts screen opens in the right state.
    dispatch({
      type: 'permission/probed',
      permission: pushCapability(push) === 'unsupported' ? 'unsupported' : currentPermission(push),
    })
  }, [push])

  // ---------------------------------------------------------------- the resolve
  //
  // Runs when a token arrives and whenever a `poll/stale` has cleared `polling` while a
  // token is still held -- which is `§3` row 9 clause (c)'s *"open the session again"*.
  const resolving = useRef(false)

  useEffect(() => {
    const token = state.token

    if (token === null) return
    if (state.snapshot !== null && state.polling) return
    if (resolving.current) return
    if (screenOf(state.screen) === 'ended') return

    resolving.current = true
    // ⚠ AN `AbortController` RATHER THAN A `let cancelled = false`. The flag is set from
    // the cleanup closure, which type narrowing cannot see, so an inferred `false` makes
    // every guard below look dead to `@typescript-eslint/no-unnecessary-condition` -- and
    // annotating it `boolean` then trips `no-inferrable-types`. `signal.aborted` is a real
    // `boolean` and is the platform's own answer to this, so neither rule has to be
    // silenced to keep the guard.
    const controller = new AbortController()
    const signal = controller.signal

    void (async () => {
      const result = await resolveSession(api, token)

      resolving.current = false

      if (signal.aborted) return

      switch (result.kind) {
        case 'ok':
          rememberToken(
            { storage: storageFrom(window), now: () => Date.now() },
            token,
            result.snapshot.expiresAt,
          )
          dispatch({ type: 'resolve/ok', snapshot: result.snapshot })
          break
        case 'expired':
          dispatch({ type: 'resolve/expired' })
          break
        case 'unknown':
          dispatch({ type: 'resolve/unknown' })
          break
        case 'server':
          dispatch({ type: 'resolve/server' })
          break
        case 'unreachable':
          dispatch({ type: 'resolve/unreachable' })
          break
      }
    })()

    return () => {
      controller.abort()
    }
  }, [api, state.token, state.snapshot, state.polling, state.screen])

  // ------------------------------------------------------------------- the poll
  //
  // A SELF-RESCHEDULING TIMEOUT, NOT A `setInterval`. An interval fires on a fixed grid and
  // cannot be jittered per request, and a backgrounded tab accumulates missed interval ticks
  // in some engines and then fires them in a burst -- which is the opposite of backoff. One
  // timeout per request, re-armed from the answer, is the only shape that honours both
  // `poll_after_seconds` and the backoff.
  useEffect(() => {
    if (!state.polling || state.token === null) return

    const token = state.token
    const advertised = state.snapshot?.pollAfterSeconds ?? 7
    let timer: ReturnType<typeof setTimeout> | null = null
    let stopped = false

    const tick = async () => {
      const result = await pollStatus(api, token)

      if (stopped) return

      switch (result.kind) {
        case 'ok':
          dispatch({ type: 'poll/ok', snapshot: result.snapshot })
          break
        case 'stale':
          dispatch({ type: 'poll/stale' })

          return
        case 'unavailable':
          dispatch({ type: 'poll/unavailable' })
          arm(
            delayFromAnswerMs(
              result.retryAfterSeconds,
              advertised,
              state.consecutiveFailures + 1,
              Math.random,
            ),
          )

          return
        case 'unreachable':
          dispatch({ type: 'poll/unreachable' })
          break
      }
    }

    const arm = (ms: number) => {
      if (stopped) return
      timer = setTimeout(() => {
        void tick()
      }, ms)
    }

    arm(nextDelayMs(advertised, state.consecutiveFailures, Math.random))

    return () => {
      stopped = true

      if (timer !== null) clearTimeout(timer)
    }
  }, [api, state.polling, state.token, state.snapshot, state.consecutiveFailures])

  // --------------------------------------------- the network, and the catch-up poll
  useEffect(() => {
    const online = () => {
      dispatch({ type: 'net/online' })
    }
    const offline = () => {
      dispatch({ type: 'net/offline' })
    }

    window.addEventListener('online', online)
    window.addEventListener('offline', offline)

    // ⚠ ONE CATCH-UP POLL ON RETURNING TO VISIBILITY, NOT A BURST. A phone that slept for
    // twenty minutes has a timer that never fired; `visibilitychange` is the only signal
    // that it is worth asking again, and asking ONCE is what keeps a restaurant full of
    // waking phones from arriving at the endpoint together. The ordinary cadence resumes
    // from the answer.
    const woke = () => {
      if (document.visibilityState !== 'visible') return
      dispatch({ type: 'net/online' })
    }

    document.addEventListener('visibilitychange', woke)

    return () => {
      window.removeEventListener('online', online)
      window.removeEventListener('offline', offline)
      document.removeEventListener('visibilitychange', woke)
    }
  }, [])

  // ----------------------------------------- the service worker and the rotation
  useEffect(() => {
    if (push === null || state.token === null) return
    if (pushCapability(push) === 'unsupported') return
    if (state.permission !== 'granted') return
    /**
     * ⚠ TWO GUARDS, AND NEITHER WAS HERE. Found by the test that asserts an expired session
     * makes no subscription call.
     *
     * The reducer keeps the token through a terminal status -- the ended screen still shows
     * the reference -- so without the first guard the app registers a push device for a
     * session it has just been told is over. And without the second it registers BEFORE the
     * resolve has answered at all, so an expired or unknown token still costs a subscribe
     * plus a POST that can only be refused.
     *
     * `CustomerPagerController::live()` refuses both with a 409/404 by design, so nothing
     * breaks either way; what happens instead is a request that can only fail, on every
     * launch, and a `reconcile` reporting `failed` for something that is not a failure.
     * Waiting for a live snapshot is one line and makes the sequence resolve -> reconcile.
     */
    if (screenOf(state.screen) === 'ended') return
    if (state.snapshot === null) return

    const token: PresentedToken = state.token
    // ⚠ AN `AbortController` RATHER THAN A `let cancelled = false`. The flag is set from
    // the cleanup closure, which type narrowing cannot see, so an inferred `false` makes
    // every guard below look dead to `@typescript-eslint/no-unnecessary-condition` -- and
    // annotating it `boolean` then trips `no-inferrable-types`. `signal.aborted` is a real
    // `boolean` and is the platform's own answer to this, so neither rule has to be
    // silenced to keep the guard.
    const controller = new AbortController()
    const signal = controller.signal

    void (async () => {
      const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' })
      await navigator.serviceWorker.ready

      if (signal.aborted) return

      // `SR-5`: the engine-independent half of the named invariant. `pushsubscriptionchange`
      // does not exist on iOS Safari, so this is the mechanism that catches a rotation there.
      await reconcileSubscription({
        env: push,
        api,
        token,
        applicationServerKey: vapidPublicKey,
        registration,
      })
    })()

    // Re-run on every return to visibility: for a Home-Screen app, the next launch is the
    // next thing a customer does, and it is the first moment a rotation can be noticed on an
    // engine that never fires the event.
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return

      void (async () => {
        const registration = await navigator.serviceWorker.getRegistration('/')

        if (registration === undefined || signal.aborted) return

        await reconcileSubscription({
          env: push,
          api,
          token,
          applicationServerKey: vapidPublicKey,
          registration,
        })
      })()
    }

    document.addEventListener('visibilitychange', onVisible)

    return () => {
      controller.abort()
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [
    api,
    push,
    state.token,
    state.permission,
    state.screen,
    state.snapshot,
    vapidPublicKey,
    reconcileNonce,
  ])

  // -------------------------------------------- the worker's message to the page
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return

    // ⚠ THE CONTAINER IS CAPTURED, AND THE CLEANUP USES THE CAPTURED REFERENCE. Re-reading
    // `navigator.serviceWorker` in the cleanup throws `Cannot read properties of undefined`
    // if the property is gone by then -- which a test can arrange and an engine can too,
    // since the container is absent in a context whose storage was revoked mid-session. A
    // cleanup that throws leaves the listener attached AND surfaces as an unrelated React
    // error, so the correct pattern is also the honest one: remove the listener from the
    // object it was added to.
    const container = navigator.serviceWorker

    const onMessage = (event: MessageEvent<unknown>) => {
      const data = event.data

      if (typeof data !== 'object' || data === null) return

      const kind = (data as Record<string, unknown>).type

      if (kind === 'qrpager/resubscribe') {
        // The rotation handler could not finish -- no token or no application server key in
        // its cache. `visibilitychange` already drives the reconcile, and this fires it now
        // rather than at the next launch, which on an open page is the difference between
        // seconds and hours.
        setReconcileNonce((previous) => previous + 1)

        return
      }

      if (kind !== 'qrpager/push') return

      // ⚠ THE REFERENCE IS NOT TAKEN FROM THE MESSAGE. The reducer ignores any payload here
      // and renders the reference from `snapshot`, so a message from any other script on this
      // origin cannot put attacker-chosen text on the one screen a customer reads from across
      // a room. The message is a NUDGE to show a screen, not a source of content.
      dispatch({ type: 'push/ready', reference: '' })
    }

    container.addEventListener('message', onMessage)

    return () => {
      container.removeEventListener('message', onMessage)
    }
  }, [])

  // ------------------------------------------------------------- the wake lock
  useEffect(() => {
    if (state.screen !== 'waiting.healthy' && state.screen !== 'waiting.degraded') return

    // ⚠ AN `AbortController` RATHER THAN A `let cancelled = false`. The flag is set from
    // the cleanup closure, which type narrowing cannot see, so an inferred `false` makes
    // every guard below look dead to `@typescript-eslint/no-unnecessary-condition` -- and
    // annotating it `boolean` then trips `no-inferrable-types`. `signal.aborted` is a real
    // `boolean` and is the platform's own answer to this, so neither rule has to be
    // silenced to keep the guard.
    const controller = new AbortController()
    const signal = controller.signal

    void (async () => {
      const handle = await holdScreenAwake(wakeEnvironmentFrom(navigator), () => {
        // The ENGINE released it, which it does whenever the document hides. Not a failure,
        // and the label must not flip to "unavailable" -- see `wake.ts`.
        setWake((previous) => ({ ...previous, on: false }))
      })

      if (signal.aborted) {
        await handle.release()

        return
      }

      releaseWake.current = handle.release
      setWake({ outcome: handle.outcome, on: handle.outcome === 'held' })
    })()

    return () => {
      controller.abort()
      const release = releaseWake.current
      releaseWake.current = null

      if (release !== null) void release()
    }
  }, [state.screen])

  // ------------------------------------------------- the alert, once, on arrival
  useEffect(() => {
    if (state.screen !== 'ready.fresh') {
      if (screenOf(state.screen) !== 'ready') alerted.current = false

      return
    }

    if (alerted.current) return
    alerted.current = true

    void soundTheAlert(alertEnvironmentFrom(window))
  }, [state.screen])

  // ------------------------------- the end of the session: clear both the homes
  useEffect(() => {
    if (screenOf(state.screen) !== 'ended') return
    if (push === null) return

    const token = state.token

    void (async () => {
      // On a session that ENDED rather than expired, tell the server to forget the device:
      // the alternative is a row the dispatcher keeps until the session's cascade removes it.
      // `expired` is skipped because the endpoint refuses an expired session by design
      // (`CustomerPagerController::live()`), so the call could only fail.
      if (token !== null && state.screen !== 'ended.expired') {
        const registration =
          'serviceWorker' in navigator
            ? await navigator.serviceWorker.getRegistration('/')
            : undefined
        const subscription = await registration?.pushManager.getSubscription()

        if (subscription) {
          await forgetDevice(api, token, subscription.endpoint)
          await subscription.unsubscribe()
        }
      }

      // BOTH homes, in one place, so neither can be forgotten: the `localStorage` entry and
      // the service worker's `Cache` entry. `storage.ts` says why this is a security step.
      forgetToken({ storage: storageFrom(window), now: () => Date.now() })
      await clearWorkerCredential(push)
    })()
  }, [api, push, state.screen, state.token])

  // ------------------------------------------------------------------- handlers
  const onAsk = useCallback(() => {
    if (push === null) {
      dispatch({ type: 'permission/unsupported' })

      return
    }

    void (async () => {
      const result = await askPermission(push)

      if (result === 'granted') dispatch({ type: 'permission/granted' })
      else if (result === 'denied') dispatch({ type: 'permission/denied' })
      else if (result === 'unsupported') dispatch({ type: 'permission/unsupported' })
      // 'default' -- the engine rejected the call or the customer dismissed the sheet. The
      // screen stays where it is, which is the only honest answer: the browser WILL ask
      // again, so showing the denied screen would be wrong.
    })()
  }, [push])

  /**
   * The keep-screen-awake toggle, and it really releases.
   *
   * ⚠ A NO-OP HANDLER HERE WOULD BE THE VACUOUS CONTROL `wake.ts` EXISTS TO PREVENT: a switch
   * that flips and changes nothing is worse than no switch, which is the whole argument for
   * not rendering one when `request()` rejects. Turning it off releases the real sentinel;
   * turning it on re-requests, because a released sentinel cannot be re-acquired.
   */
  const onToggleWake = useCallback(() => {
    void (async () => {
      const release = releaseWake.current

      if (release !== null) {
        releaseWake.current = null
        await release()
        setWake((previous) => ({ ...previous, on: false }))

        return
      }

      const handle = await holdScreenAwake(wakeEnvironmentFrom(navigator), () => {
        setWake((previous) => ({ ...previous, on: false }))
      })
      releaseWake.current = handle.release
      setWake({ outcome: handle.outcome, on: handle.outcome === 'held' })
    })()
  }, [])

  const send = useCallback((event: JourneyEvent) => {
    dispatch(event)
  }, [])

  return (
    // `ThemeProvider` sets `data-theme`, `lang` and `dir` on one element, together -- which
    // is what makes RTL a configuration rather than a rewrite, and is the property
    // `PlatformPanelAccessibilityTest` already pins for the admin panel.
    <ThemeProvider locale={resolved} as="div">
      {detected === null ? (
        // The structural shell. `aria-busy` and no text: see the header.
        <div className="pg-screen" data-screen="loading" data-state="loading" aria-busy="true">
          <Spinner />
        </div>
      ) : (
        <Render
          state={state}
          t={t}
          install={install.kind}
          wake={wake}
          onAsk={onAsk}
          onToggleWake={onToggleWake}
          send={send}
        />
      )}
    </ThemeProvider>
  )
}

/**
 * The render, split out so it is a pure function of the journey state.
 *
 * ⚠ IT HAS NO `default` ARM. `ScreenState` is a closed union of all 17 states, so a state
 * added to `JOURNEY_STATES` without a screen is a TYPE ERROR. A `default` returning a
 * fallback is how a screen goes missing in production and nothing fails.
 */
function Render({
  state,
  t,
  install,
  wake,
  onAsk,
  onToggleWake,
  send,
}: {
  readonly state: JourneyState
  readonly t: ReturnType<typeof translatorFor>
  readonly install: 'required' | 'installed' | 'not-applicable'
  readonly wake: WakeState
  readonly onAsk: () => void
  readonly onToggleWake: () => void
  readonly send: (event: JourneyEvent) => void
}) {
  const reference = state.snapshot?.orderReference ?? null
  const now = Date.now()
  const elapsed = state.scannedAt === null ? 0 : elapsedSeconds(state.scannedAt, now)
  const asOfLabel = state.snapshot === null ? '' : formatTime(state.snapshot.asOf)

  switch (state.screen) {
    case 'landing.valid':
    case 'landing.invalid':
      return (
        <ScanLanding
          state={state.screen === 'landing.valid' ? 'valid' : 'invalid'}
          t={t}
          reference={reference}
          onEnableAlerts={() => {
            send({ type: 'alerts/open' })
          }}
          onSkip={() => {
            send({ type: 'skip' })
          }}
        />
      )

    case 'alerts.notAsked':
    case 'alerts.granted':
    case 'alerts.denied':
    case 'alerts.unsupported':
      return (
        <EnableAlerts
          state={state.screen.slice('alerts.'.length) as 'notAsked'}
          t={t}
          install={install}
          onAsk={onAsk}
          onContinue={() => {
            send({ type: 'skip' })
          }}
        />
      )

    case 'waiting.healthy':
    case 'waiting.degraded':
    case 'waiting.offline':
      return (
        <Waiting
          state={state.screen.slice('waiting.'.length) as 'healthy'}
          t={t}
          reference={reference ?? ''}
          elapsedSeconds={elapsed}
          asOfLabel={asOfLabel}
          wake={wake.outcome}
          wakeOn={wake.on}
          onToggleWake={onToggleWake}
          onRetry={() => {
            send({ type: 'retry' })
          }}
        />
      )

    case 'ready.fresh':
    case 'ready.acknowledged':
      return (
        <OrderReadyScreen
          state={state.screen === 'ready.fresh' ? 'fresh' : 'acknowledged'}
          t={t}
          reference={reference ?? ''}
          onAcknowledge={() => {
            send({ type: 'ack' })
          }}
        />
      )

    case 'ended.completed':
    case 'ended.cancelled':
    case 'ended.expired':
      return (
        <SessionEnded
          state={state.screen.slice('ended.'.length) as 'completed'}
          t={t}
          reference={reference}
        />
      )

    case 'error.offline':
    case 'error.server':
    case 'error.unknown':
      return (
        <OfflineError
          state={state.screen.slice('error.'.length) as 'offline'}
          t={t}
          onRetry={() => {
            send({ type: 'retry' })
          }}
        />
      )
  }
}

/**
 * A wall-clock time for the degraded banner's *"last confirmed {time}"*.
 *
 * `Intl.DateTimeFormat` with the DOCUMENT's language, read off the element `ThemeProvider`
 * set it on -- so an Arabic page gets Arabic-locale digits and an English one does not, from
 * one source rather than from a second locale variable this component would have to be
 * passed.
 */
function formatTime(iso: string): string {
  const at = Date.parse(iso)

  if (Number.isNaN(at)) return ''

  const lang = typeof document === 'undefined' ? 'en' : document.documentElement.lang || 'en'

  return new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit' }).format(new Date(at))
}

export { directionFor }
