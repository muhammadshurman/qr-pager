'use client'

import { Button, LockScreen, StaffShell, StaffState, ThemeProvider, type Locale } from '@qrpager/ui'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { translatorFor } from './copy/index.ts'
import { StaffBoard, type BoardPhase, type DetailPhase } from './screens/staff/StaffBoard.tsx'
import { StaffDevices } from './screens/staff/StaffDevices.tsx'
import { StaffSignIn, roleKey, type SignInStage } from './screens/staff/StaffSignIn.tsx'
import {
  PROBLEM_EXPIRED,
  cancelSession,
  completeSession,
  fetchBoard,
  fetchDevices,
  fetchIdentity,
  fetchTenant,
  issueDevice,
  markSessionReady,
  renotifySession,
  revokeDevice,
  selectTenant,
  signInWithPassword,
  signOut,
  completeTwoFactor,
  type BoardPager,
  type IssuedDevice,
  type StaffFailure,
  type StaffDevice,
  type StaffIdentity,
  type StaffResult,
  type StaffTenant,
  type TransitionOutcome,
} from './staff/api.ts'
import { nextBoardDelayMs, isStale, shouldTellThem } from './staff/board.ts'
import { browserLocale } from './pager/locale.ts'
import { elapsedSeconds } from './pager/poll.ts'
import { registerPasskey, signInWithPasskey, passkeysAvailable } from './staff/passkey.ts'
import { watchForIdle } from './staff/session.ts'

/**
 * ================================================================================
 * THE STAFF ORCHESTRATOR. One client component, four views, and a lock over all of them.
 * ================================================================================
 *
 * `apps/web/app/staff/*` are statically prerendered documents that each render this with a
 * `view`. **Nothing protected is server-rendered at any URL**, which is why sign-in is a
 * VIEW here rather than a route to redirect to: a redirect means a protected document
 * existed and was navigated away from, and the shape of that document is observable. An
 * unauthenticated request to `/staff/devices` gets the sign-in view at that URL, with no
 * flash of chrome and nothing to screenshot.
 *
 * ================================================================================
 * THE THREE PIECES OF TENANT DATA, AND WHERE THEY LIVE
 * ================================================================================
 *
 * `identity` (the person's name and their memberships), `tenant` (the restaurant's name) and
 * `devices`. All three are REACT STATE AND NOTHING ELSE: no `localStorage`, no
 * `sessionStorage`, no cookie this app writes, no URL. `§12`'s lock clause is about a shared
 * device, and the strongest form of "the lock reveals no tenant datum" is that there is
 * nowhere for a datum to survive the lock — `clearTenantData()` below is one function and it
 * is the whole of it.
 *
 * Task 9's own `R-210`/`SEC M-3` is the precedent read in the other direction: there, a value
 * written back into stored data was the defect, and moving a cache NAME while leaving the
 * mechanism was the fix that did not fix it. So this app stores nothing at all.
 *
 * ================================================================================
 * THE LOCK, IN ORDER, BECAUSE THE ORDER IS THE SECURITY PROPERTY
 * ================================================================================
 *
 *   1. `POST /v1/auth/logout` — the SERVER session is destroyed first;
 *   2. every byte of tenant data is dropped from memory;
 *   3. the overlay renders.
 *
 * A lock that did 3 and not 1 would leave a working cookie in a browser a passer-by can open
 * devtools on. Step 1 is awaited but its FAILURE DOES NOT STOP STEPS 2 AND 3: if the network
 * is down, the right answer is still to cover the screen and forget the data. The session
 * then expires on its own, which is the fallback rather than the mechanism.
 */

export type StaffView = 'home' | 'devices'

export interface StaffAppProps {
  readonly apiBase: string
  readonly view: StaffView
  /** Injected in tests. The real app reads the BROWSER's own language list. */
  readonly locale?: Locale | undefined
  /** Injected in tests, so the lock does not need fifteen real minutes to fire. */
  readonly idleSeconds?: number
  /** Injected in tests. The real app navigates. */
  readonly onNavigate?: (view: StaffView) => void
  /**
   * The board's randomness, injected so a test can assert an EXACT delay.
   *
   * `src/pager/poll.ts`'s own practice, and its reason: the jitter's bounds are checked at
   * the extremes (`0` and `1 - Number.EPSILON`) rather than by sampling and hoping, which is
   * the *assert exact values, never ranges* discipline applied to something random.
   */
  readonly random?: () => number
}

/**
 * The shell's phases.
 *
 * ⚠ `throttled` ARRIVED AT 10b's FIX ROUND, FROM `§11` QA H-3, AND IT IS `R-224`'s THIRD
 * SURFACE. That row's *review by* cell names the acceptance in so many words — *"with the
 * copy ruled and **the state added to `§12` S2**"* — and `§12` **S2 is the shell**. The first
 * version of this fix added `throttled` to the board (S6) and to sign-in (S1) and left the
 * shell funnelling every non-`ok` answer into `error`, so a throttled `/v1/auth/me` still
 * read *"Something went wrong on our side. Nothing you did caused it."* — `R-224`'s own
 * sentence, on the very endpoint `R-224` was measured on.
 *
 * ⚠ **AND THE SHELL RESUMES ITSELF RATHER THAN OFFERING A BUTTON, which is what makes its
 * copy true.** Muhammad's ruling splits the two surfaces by whether anything is required of
 * the person: on the board nothing is, because it polls; on sign-in something is, because
 * nothing retries for them. The shell had neither property — it loads once — so a *you need
 * do nothing* sentence would have been a lie and a *try again* button would have been a
 * second 429. It now schedules its own retry on the same jittered backoff the board uses,
 * which makes it the board's case rather than sign-in's, and the kit's `ThrottledState` —
 * which has no `action` member at all — is then exactly the right primitive.
 */
type Phase = 'loading' | 'signed-out' | 'ready' | 'locked' | 'error' | 'offline' | 'throttled'

export function StaffApp({
  apiBase,
  view,
  locale,
  idleSeconds,
  onNavigate,
  random,
}: StaffAppProps) {
  /*
   * ⚠ THE LANGUAGE COMES FROM THE BROWSER, AND THE FIRST VERSION OF THIS HARD-CODED `'en'`.
   *
   * It defaulted the prop to `'en'` and nothing ever overrode it, so the staff surface was
   * English-only -- `§12` names Arabic a launch language and requires an RTL-safe layout, and
   * a surface that never renders RTL satisfies neither however carefully its stylesheet uses
   * logical properties. **Found by the browser gate**: the three `staff-*-rtl` projects set
   * `locale: 'ar-JO'` on the context and measured `dir` as `ltr`.
   *
   * `browserLocale()` is Task 9's resolver and is reused rather than re-implemented: it
   * matches on the PRIMARY SUBTAG, which is the half a `===` against `LOCALES` gets wrong --
   * real devices send `ar-JO` and `en-GB`, never a bare `ar`.
   *
   * ⚠ DETECTED IN AN EFFECT, NOT AT FIRST RENDER, which is `PagerJourney`'s own pattern and
   * for its reason: these routes are statically prerendered, so the server render has no
   * `navigator` and a direct read would be a hydration mismatch. The first paint is the
   * fallback language and the effect corrects it before anything but the loading state is on
   * screen.
   */
  const [detected, setDetected] = useState<Locale | null>(locale ?? null)

  useEffect(() => {
    if (locale !== undefined) return
    if (typeof navigator === 'undefined') return

    setDetected(browserLocale(navigator))
  }, [locale])

  const resolved: Locale = detected ?? 'en'
  const t = useMemo(() => translatorFor(resolved), [resolved])

  const [phase, setPhase] = useState<Phase>('loading')
  const [signInStage, setSignInStage] = useState<SignInStage>('idle')
  const [identity, setIdentity] = useState<StaffIdentity | null>(null)
  const [tenant, setTenant] = useState<StaffTenant | null>(null)
  const [devices, setDevices] = useState<readonly StaffDevice[] | null>(null)
  const [devicesPhase, setDevicesPhase] = useState<
    'loading' | 'ready' | 'error' | 'offline' | 'permission-denied'
  >('loading')
  const [issued, setIssued] = useState<IssuedDevice | null>(null)
  const [passkeyNotice, setPasskeyNotice] = useState<'added' | 'failed' | null>(null)
  /**
   * Why the overlay is up: the idle timer, or the person asking.
   *
   * ⚠ ONE MECHANISM, TWO MESSAGES — `§11` QA L-3. Sign-out and the idle lock share `lock()`
   * deliberately (`staff-app.test.tsx` pins that they are one path), and the first version
   * shared the COPY too — so a person who pressed *"Sign out"* read *"Screen locked. Nobody is
   * signed in on this screen."* That is the right sentence for a screen somebody walked away
   * from and the wrong one for an action somebody just took.
   */
  const [lockReason, setLockReason] = useState<'idle' | 'signed-out'>('idle')

  /* ------------------------------------------------------------------- the board, 10b */

  /**
   * ⚠ THE BOARD'S ROWS AND ITS PHASE ARE SEPARATE, AND THAT SEPARATION IS THE DESIGN.
   *
   * A failed poll moves the PHASE and leaves the ROWS alone, so the kitchen keeps looking at
   * the orders it had while the client retries — which is the honest reading of
   * `{kind:'unreachable'}`: *the data is unchanged*. Folding them into one nullable value
   * would make every dropped packet blank the board mid-service.
   */
  const [pagers, setPagers] = useState<readonly BoardPager[]>([])
  const [boardPhase, setBoardPhase] = useState<BoardPhase>('loading')
  const [detail, setDetail] = useState<DetailPhase>('idle')
  const [selected, setSelected] = useState<string | null>(null)
  /** When the newest SUCCESSFUL answer arrived. A server timestamp's local counterpart, in ms. */
  const [confirmedAt, setConfirmedAt] = useState<number | null>(null)
  /**
   * When the newest poll ATTEMPT finished, successful or not.
   *
   * ⚠ **THIS EXISTS BECAUSE `§11` QA H-1 PROVED THE STALENESS MARKER COULD NEVER RENDER, AND
   * THE CAUSE IS WORTH KEEPING: THE MEMO HAD NOTHING TO RE-EVALUATE ON.**
   *
   * `staleSince` was a `useMemo` over `[confirmedAt, resolved, pagers]`, and all three change
   * only on a SUCCESSFUL poll — at which instant `confirmedAt === now`, so `isStale(now, now)`
   * is false by construction. Through a run of failures nothing in the dependency list moved
   * (the failure counters are refs, and `boardPhase` saturates), so the memo was never
   * recomputed and the marker stayed `null` for ever. The reviewer measured it: one success
   * then ten rejected polls across 800 s of fake-timer time rendered the row with **no
   * staleness marker and no offline notice**, because the rows are deliberately kept.
   *
   * That made "keep the rows" into a lie rather than a kindness: the precoding document's own
   * justification for keeping them is that the marker says when they were last confirmed.
   *
   * So the memo now depends on a value that moves on every ATTEMPT. It is state rather than a
   * ref for exactly that reason — a ref would update without re-rendering, which is the
   * original defect wearing a different variable.
   */
  const [attemptedAt, setAttemptedAt] = useState<number | null>(null)
  /** Consecutive failed polls. 0 after any 200. Drives the backoff and `shouldTellThem`. */
  const failuresRef = useRef(0)
  /** The server's own `Retry-After` from the last 429, in seconds, or null. */
  const retryAfterRef = useRef<number | null>(null)
  /** A monotonic token so a poll scheduled by a superseded run cannot resurrect itself. */
  const pollRunRef = useRef(0)
  /**
   * The server's `Retry-After` from a throttled SHELL load, in seconds, or null.
   *
   * Separate from the board's, because the two surfaces back off independently and sharing
   * one value would make a throttled shell inherit the board's failure count.
   */
  const shellRetryRef = useRef<number | null>(null)
  /** How many times the shell load has been refused in a row. Drives its own backoff. */
  const shellFailuresRef = useRef(0)
  /** `Math.random` in the app, a sequence in the tests. */
  const randomRef = useRef<() => number>(random ?? Math.random)

  randomRef.current = random ?? Math.random

  /** One function, and it is the whole of "the shell holds no tenant datum". */
  const clearTenantData = useCallback((): void => {
    setIdentity(null)
    setTenant(null)
    setDevices(null)
    setIssued(null)
    setPasskeyNotice(null)
    setDevicesPhase('loading')
    /*
     * ⚠ THE BOARD IS TENANT DATA AND IS DROPPED HERE TOO. Every order reference on it
     * belongs to one restaurant, so a lock that left them rendered would be the exact thing
     * `§12`'s lock clause is about — and `staff-app.test.tsx` asserts the clearing rather
     * than this comment. Adding state to this component without adding it here is the
     * failure mode, which is why this function is one place and is named as such.
     */
    setPagers([])
    setBoardPhase('loading')
    setSelected(null)
    setDetail('idle')
    setConfirmedAt(null)
    setAttemptedAt(null)
    failuresRef.current = 0
    retryAfterRef.current = null
    // Supersede any poll already scheduled, so a timer armed before the lock cannot write
    // another tenant's rows back into state after it.
    pollRunRef.current += 1
  }, [])

  const load = useCallback(async (): Promise<void> => {
    const who = await fetchIdentity(apiBase)

    if (who.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    if (who.kind === 'unreachable') {
      setPhase('offline')

      return
    }

    if (who.kind === 'throttled') {
      shellRetryRef.current = who.retryAfter
      setPhase('throttled')

      return
    }

    if (who.kind !== 'ok') {
      setPhase('error')

      return
    }

    setIdentity(who.value)

    // More than one membership and none selected: the person has to choose, and until they
    // do, every staff route answers 401 because the resolver refuses to pick. That is
    // correct server behaviour and this is the screen for it.
    if (who.value.activeTenant === null && who.value.memberships.length !== 1) {
      setPhase('signed-out')
      setSignInStage('choose')

      return
    }

    const restaurant = await fetchTenant(apiBase)

    if (restaurant.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    if (restaurant.kind === 'unreachable') {
      setPhase('offline')

      return
    }

    if (restaurant.kind === 'throttled') {
      shellRetryRef.current = restaurant.retryAfter
      setPhase('throttled')

      return
    }

    if (restaurant.kind !== 'ok') {
      setPhase('error')

      return
    }

    setTenant(restaurant.value)
    shellFailuresRef.current = 0
    shellRetryRef.current = null
    setPhase('ready')
    setSignInStage('idle')
  }, [apiBase, clearTenantData])

  /**
   * A throttled shell retries itself, which is the half that makes its copy honest.
   *
   * `§11` QA H-3. The delay is the same arithmetic the board uses — the server's own
   * `Retry-After` when it sent one, otherwise the jittered doubling — so a shell and a board
   * refused by the same bucket do not hammer it on two different cadences.
   *
   * ⚠ IT IS ARMED ONLY IN THE `throttled` PHASE, so nothing retries in `error` or `offline`:
   * those two keep their explicit retry button, because a 5xx and a dead network are not
   * things a timer should paper over. And it increments the failure count BEFORE computing
   * the delay, so a run of refusals backs off rather than retrying at a fixed interval.
   */
  useEffect(() => {
    if (phase !== 'throttled') return
    if (typeof window === 'undefined') return

    shellFailuresRef.current += 1

    const timer = setTimeout(
      () => void load(),
      nextBoardDelayMs(shellFailuresRef.current, shellRetryRef.current, randomRef.current),
    )

    return () => {
      clearTimeout(timer)
    }
  }, [phase, load])

  useEffect(() => {
    void load()
  }, [load])

  /* ------------------------------------------------------------------------ the lock */

  // A ref, so the idle watch's callback always sees the current phase without the watch
  // being torn down and rebuilt on every phase change -- a rebuilt watch resets its own
  // deadline, which would mean a screen that never locks while anything re-renders.
  const phaseRef = useRef(phase)

  phaseRef.current = phase

  const lock = useCallback(
    (reason: 'idle' | 'signed-out' = 'idle'): void => {
      /*
       * ⚠ THE SCREEN IS COVERED AND THE DATA DROPPED **FIRST**, AND THE LOGOUT IS NOT AWAITED.
       * `§11` SECURITY M-1.
       *
       * This read `await signOut(apiBase)` and then cleared, with a comment claiming *"its
       * failure does not stop steps 2 and 3"*. That is true of a REJECTION and false of a
       * STALL: `call()` uses a bare `fetch` with no `AbortController`, so against a
       * reachable-but-dead route -- a captive portal, a hung proxy, an API outage -- the promise
       * is pending for as long as the browser's own connect timeout allows, tens of seconds to
       * minutes. For that whole window the restaurant's name, the person's name, the role word
       * and the device list stayed rendered on an unattended screen, which is precisely the
       * state the lock exists to end.
       *
       * The ordering argument that produced the bug was about the SERVER: destroy the session
       * before covering up, so a locked screen holds no working credential. That ordering does
       * not need the await -- the request is sent either way, and nothing the client does
       * afterwards can un-send it. What the await bought was the illusion of sequencing and the
       * risk of an unbounded delay.
       *
       * So: drop the data, raise the overlay, and let the logout fly. If it never lands, the
       * session expires on its own (`§5` `R-220`), which is the fallback rather than the
       * mechanism -- and the screen is already covered and already empty.
       */
      clearTenantData()
      setLockReason(reason)
      setPhase('locked')
      setSignInStage('idle')

      void signOut(apiBase)
    },
    [apiBase, clearTenantData],
  )

  /*
   * ⚠ ARMED WHENEVER A SESSION MAY EXIST, WHICH IS EVERY PHASE BUT `locked`. `§11` SECURITY
   * H-1, and it was a real hole rather than a missed edge.
   *
   * This read `if (phase !== 'ready') return`, so the watch was torn down and never re-armed
   * in three reachable states that each hold a LIVE server session with no countdown at all:
   *
   *   phase `offline`   one transient packet loss during a shell load (`load()` -> unreachable)
   *   phase `error`     one transient 5xx
   *   phase `signed-out` + stage `choose`   a person with two memberships and none selected --
   *                     `identity` is not cleared, the session is live, and `onChoose()` walks
   *                     straight into the shell
   *
   * Nothing retries automatically — the offline copy says *"We will keep trying"* and no timer
   * does — so the screen sat there indefinitely. The shell errors or drops a packet, the person
   * walks away, and a passer-by taps *"Try again"* or a restaurant button and is in, with no
   * credential. `§4`'s *"an idle shell locks … recovery needs the credential again"* was
   * satisfied only on the happy path, and the 120-minute `SESSION_LIFETIME` was the only
   * backstop — which `R-220` carries as a residual about an EXFILTRATED cookie, not about the
   * lock failing to fire.
   *
   * ⚠ `locked` IS THE ONE EXCLUSION AND IT IS NOT AN OPTIMISATION: `watchForIdle` disarms
   * itself after firing, but re-arming on the `locked` phase would start a fresh countdown
   * whose `onIdle` would `POST /v1/auth/logout` against a session that is already gone — a
   * request every fifteen minutes, all night, from a locked screen in an empty kitchen.
   */
  useEffect(() => {
    if (typeof window === 'undefined') return
    if (phase === 'locked') return

    const watch = watchForIdle(window, {
      ...(idleSeconds === undefined ? {} : { seconds: idleSeconds }),
      onIdle: () => {
        // Re-checked at FIRE time rather than trusted from the closure: the phase may have
        // moved since the watch was armed, and locking a screen that has already locked is
        // the one thing this must not do.
        if (phaseRef.current !== 'locked') lock('idle')
      },
    })

    return () => {
      watch.stop()
    }
  }, [phase, idleSeconds, lock])

  /* --------------------------------------------------------------------- signing in */

  const afterCredential = useCallback(async (): Promise<void> => {
    setSignInStage('idle')
    setPhase('loading')
    await load()
  }, [load])

  /**
   * The stage a failed credential attempt lands on.
   *
   * ⚠ **THIS EXISTS BECAUSE `R-224`'s SIGN-IN HALF SHIPPED UNREACHABLE — `§11` QA H-2 AND
   * SECURITY M-4, found independently by both reviewers.** The `throttled` stage was built,
   * its copy written in both launch languages, and the kit type made an actionless throttle
   * the only thing that compiles — and then no call site ever set it: every failure path
   * collapsed to `'failed'`, so a rate-limited person read *"That did not work. Try your
   * passkey again, or use your password."* and was told to do the one thing that would 429
   * again. `grep -rn "setSignInStage('throttled')"` returned **zero hits**.
   *
   * That is `R-205`'s dead-state shape — a screen state no event can produce — inside the fix
   * for a row about exactly that. It is one function now rather than four branches, so a
   * fifth credential path cannot forget it.
   *
   * `auth-2fa` is **5 requests a minute**, so five mistyped codes reach the throttle; it is
   * the most likely of the four to be met in ordinary use.
   *
   * Everything that is not a throttle or a dead network stays ONE message. Separating a wrong
   * password from an unknown address is the oracle `§4 › Security`'s single-message refusal
   * rule exists to close, and a 429 is not that kind of distinction: it says nothing about
   * whether the credential was right.
   */
  const stageForFailure = useCallback((kind: StaffFailure): SignInStage => {
    if (kind === 'unreachable') return 'offline'
    if (kind === 'throttled') return 'throttled'

    return 'failed'
  }, [])

  const onPasskey = useCallback(async (): Promise<void> => {
    setSignInStage('working')

    const outcome = await signInWithPasskey(apiBase)

    if (outcome.kind === 'ok') {
      await afterCredential()

      return
    }

    setSignInStage(outcome.kind === 'unsupported' ? 'unsupported' : 'failed')
  }, [apiBase, afterCredential])

  const onPassword = useCallback(
    async (email: string, password: string): Promise<void> => {
      setSignInStage('working')

      const result = await signInWithPassword(apiBase, email, password)

      if (result.kind === 'ok') {
        await afterCredential()

        return
      }

      // ⚠ `two-factor` IS A 200, NOT A 4xx, AND THE FIRST VERSION OF THIS MAPPED 403 TO IT
      // -- which made the code field a state no event could produce. `signInWithPassword`
      // reads `two_factor_required` out of the body for that reason; the mapping is in one
      // place and the screen just follows it.
      if (result.kind === 'two-factor') {
        setSignInStage('two-factor')

        return
      }

      setSignInStage(stageForFailure(result.kind))
    },
    [apiBase, afterCredential, stageForFailure],
  )

  const onTwoFactor = useCallback(
    async (code: string): Promise<void> => {
      const result = await completeTwoFactor(apiBase, code)

      if (result.kind === 'ok') {
        await afterCredential()

        return
      }

      // `auth-2fa` is 5/minute, so this is the throttle a person meets by mistyping.
      setSignInStage(stageForFailure(result.kind))
    },
    [apiBase, afterCredential, stageForFailure],
  )

  const onChoose = useCallback(
    async (tenantId: string): Promise<void> => {
      const result = await selectTenant(apiBase, tenantId)

      if (result.kind !== 'ok') {
        setSignInStage(stageForFailure(result.kind))

        return
      }

      await afterCredential()
    },
    [apiBase, afterCredential, stageForFailure],
  )

  /* ------------------------------------------------------------- the board's poll loop */

  /**
   * One board read, and the whole of `R-224`'s client half.
   *
   * ⚠ **THE PHASE MOVES AND THE ROWS DO NOT**, on every failure kind. That is the design
   * decision `pagers`/`boardPhase` are two pieces of state for: a kitchen mid-service keeps
   * looking at the orders it had while this retries.
   *
   * The five answers and why each is its own branch:
   *
   *   `ok`            rows replaced wholesale, failures reset, the confirmation stamped.
   *   `unauthorised`  the shift ended or the session expired. **Not an error** — the shell
   *                   drops to sign-in, which is what 10a's `call()` docblock is about.
   *   `throttled`     `R-224`. Its OWN phase, never `error`, and the server's `Retry-After`
   *                   is kept for the next delay because a server that is already refusing
   *                   knows better than our doubling.
   *   `unreachable`   `offline`. The wifi, not us.
   *   anything else   `error`.
   */
  const pollBoard = useCallback(async (): Promise<void> => {
    const run = pollRunRef.current
    const result = await fetchBoard(apiBase)

    /*
     * ⚠ **THE RUN TOKEN IS CHECKED HERE, AFTER THE AWAIT, AND `§11` SECURITY M-3 IS WHY.**
     *
     * `clearTenantData()` increments this token and its own comment claimed that superseded
     * "any poll already scheduled". It did — a SCHEDULED TIMER. It did nothing about a
     * request ALREADY IN FLIGHT: `lock()` does not await `signOut()` (deliberately, `§11`
     * SECURITY M-1), the component stays mounted, and this function's own resolution then
     * ran `setPagers(...)` and `setBoardPhase('ready')` AFTER the lock had emptied them.
     *
     * Nothing renders while `locked` or `signed-out`, so no tenant datum was on screen — but
     * `afterCredential()` does not clear, so **the next person to sign in got the previous
     * occupant's rows** until the first poll answered. On a shared kitchen tablet with two
     * restaurants that is a cross-tenant render, and `§12`'s *a locked shell holds no tenant
     * datum* was false in state meanwhile.
     *
     * And the window is **user-controllable rather than a timing coincidence**: the board's
     * failure states offer a retry that calls this function, so pressing *Try again* and then
     * *Sign out* opens it deliberately.
     *
     * One comparison, before any `setState`, covers every branch below.
     */
    if (pollRunRef.current !== run) return

    setAttemptedAt(Date.now())

    if (result.kind === 'ok') {
      failuresRef.current = 0
      retryAfterRef.current = null
      setPagers(result.value)
      setConfirmedAt(Date.now())
      setBoardPhase('ready')

      return
    }

    if (result.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    failuresRef.current += 1

    if (result.kind === 'throttled') {
      retryAfterRef.current = result.retryAfter

      /*
       * ⚠ **NO MESSAGE ON THE FIRST 429**, which is the ruling's third clause and the one a
       * UI gets wrong by default. Until the backoff window is exhausted the board stays in
       * whatever phase it was in — `ready` with its rows, or `loading` on a cold start — and
       * says nothing, because under a working backoff nothing is required of the person and
       * a message is a claim that something needs doing.
       */
      if (shouldTellThem(failuresRef.current)) setBoardPhase('throttled')

      return
    }

    retryAfterRef.current = null
    setBoardPhase(result.kind === 'unreachable' ? 'offline' : 'error')
  }, [apiBase, clearTenantData])

  /**
   * The timer. A self-rescheduling `setTimeout`, never `setInterval`.
   *
   * ⚠ **`setInterval` WOULD BE WRONG HERE FOR TWO SEPARATE REASONS**, and they compound.
   * A fixed interval cannot carry a backoff at all — the delay has to be recomputed from the
   * failure count after each answer. And an interval fires whether or not the previous
   * request finished, so a slow network produces overlapping reads that can land out of
   * order and write an older board over a newer one. A chain of timeouts armed *after* each
   * answer has neither property.
   *
   * ⚠ **AND THE RUN TOKEN IS WHAT MAKES A SUPERSEDED LOOP STOP.** React can tear this effect
   * down and rebuild it — a phase change, a navigation, the lock — while a request is in
   * flight. Without the token that in-flight answer would arm a fresh timer belonging to a
   * dead loop, so two chains would run at once and the board would poll at twice its
   * interval for the rest of the shift. `clearTenantData()` increments the same token, which
   * is how the lock stops the loop rather than merely clearing what it wrote.
   */
  useEffect(() => {
    if (phase !== 'ready' || view !== 'home') return

    pollRunRef.current += 1

    const run = pollRunRef.current
    let timer: ReturnType<typeof setTimeout> | undefined

    const tick = async (): Promise<void> => {
      await pollBoard()

      if (pollRunRef.current !== run) return

      timer = setTimeout(
        () => void tick(),
        nextBoardDelayMs(failuresRef.current, retryAfterRef.current, randomRef.current),
      )
    }

    void tick()

    return () => {
      // The token is bumped as well as the timer cleared: an answer already in flight checks
      // it before arming the next one, and a `clearTimeout` cannot reach a timer that does
      // not exist yet.
      pollRunRef.current += 1

      if (timer !== undefined) clearTimeout(timer)
    }
  }, [phase, view, pollBoard])

  /* ----------------------------------------------------------------- acting on a pager */

  /**
   * What the panel says after one transition, from the server's own answer.
   *
   * ⚠ **EVERY BRANCH IS A REAL SERVER ANSWER**, which is `R-205`'s lesson from Task 9: a
   * screen state no event can produce is a dead state, and `browser/one-handed.spec.ts`
   * found one there by iterating the list until a case timed out. Each phase below is
   * reachable, and `staff-flows.test.tsx` drives all of them.
   *
   * The board is refreshed immediately on success rather than at the next tick, because the
   * person just acted and a five-second wait to see their own action land reads as a screen
   * that did not hear them.
   */
  const afterTransition = useCallback(
    async (result: StaffResult<TransitionOutcome>, action: 'renotify' | 'other'): Promise<void> => {
      if (result.kind === 'ok') {
        setDetail('idle')
        setSelected(null)
        await pollBoard()

        return
      }

      if (result.kind === 'unauthorised') {
        clearTenantData()
        setPhase('signed-out')

        return
      }

      // A 404 means the row is gone from this restaurant entirely -- somebody else finished
      // it and the board had not caught up. Not an error: the board was out of date, which
      // is a normal thing for a polled board to be.
      if (result.kind === 'notFound') {
        setDetail('gone')
        await pollBoard()

        return
      }

      if (result.kind === 'conflict') {
        /*
         * TWO 409s, AND THE SERVER PUBLISHES THE DISCRIMINATOR. `expired` is its own problem
         * `type` precisely because the two need different sentences -- `ProblemRenderer`'s
         * own words: *"an illegal transition is a mistake to correct, an expired session is
         * one to re-issue."*
         *
         * The renotify CAP shares `type` with an illegal transition, so it is distinguished
         * by the action the person pressed rather than by the body. That is sound here and
         * only here: the panel offers `renotify` only on a row the server last reported as
         * `ready`, so *not ready* is unreachable from this button and the cap is the one
         * remaining reading.
         */
        if (result.problem?.endsWith(PROBLEM_EXPIRED) === true) {
          setDetail('expired')
        } else if (action === 'renotify') {
          setDetail('limit')
        } else {
          setDetail('stale')
        }

        await pollBoard()

        return
      }

      // `forbidden`, `throttled`, `unreachable`, `server`. One sentence, and its
      // load-bearing half is *nothing has changed* -- which is true of all four, because a
      // refused transition is not a partial one: Task 3 commits the status, the outbox row
      // and the audit row together or not at all.
      setDetail('failed')
    },
    [clearTenantData, pollBoard],
  )

  const act = useCallback(
    async (
      id: string,
      call: (apiBase: string, id: string) => Promise<StaffResult<TransitionOutcome>>,
      action: 'renotify' | 'other',
    ): Promise<void> => {
      setDetail('working')
      await afterTransition(await call(apiBase, id), action)
    },
    [apiBase, afterTransition],
  )

  /* ------------------------------------------------------------------ kitchen screens */

  const loadDevices = useCallback(async (): Promise<void> => {
    setDevicesPhase('loading')

    const result = await fetchDevices(apiBase)

    if (result.kind === 'ok') {
      setDevices(result.value)
      setDevicesPhase('ready')

      return
    }

    if (result.kind === 'forbidden') {
      setDevicesPhase('permission-denied')

      return
    }

    if (result.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    setDevicesPhase(result.kind === 'unreachable' ? 'offline' : 'error')
  }, [apiBase, clearTenantData])

  useEffect(() => {
    if (phase !== 'ready' || view !== 'devices') return
    void loadDevices()
  }, [phase, view, loadDevices])

  const onIssue = useCallback(
    async (name: string): Promise<void> => {
      const result = await issueDevice(apiBase, name)

      if (result.kind !== 'ok') {
        setDevicesPhase(result.kind === 'forbidden' ? 'permission-denied' : 'error')

        return
      }

      setIssued(result.value)
      await loadDevices()
    },
    [apiBase, loadDevices],
  )

  const onRemove = useCallback(
    async (id: string): Promise<void> => {
      const result = await revokeDevice(apiBase, id)

      if (result.kind !== 'ok' && result.kind !== 'notFound') {
        setDevicesPhase(result.kind === 'forbidden' ? 'permission-denied' : 'error')

        return
      }

      // A 404 is treated as success and that is deliberate: it means the screen is already
      // gone, which is what the person asked for. Reporting an error for a state they wanted
      // would be system vocabulary dressed as a failure.
      await loadDevices()
    },
    [apiBase, loadDevices],
  )

  const onAddPasskey = useCallback(async (): Promise<void> => {
    setPasskeyNotice(null)

    const outcome = await registerPasskey(apiBase, 'this device')

    // ⚠ THE OUTCOME IS SHOWN, AND THE FIRST VERSION THREW IT AWAY. `registerPasskey()`
    // returns a discriminated result precisely so a screen can say what happened; a call
    // that ignores it leaves the person tapping a button that does nothing observable.
    //
    // `cancelled` shows NOTHING, deliberately: the person dismissed the system prompt
    // themselves, and telling them their own action did not work is noise.
    if (outcome.kind === 'ok') {
      setPasskeyNotice('added')

      return
    }

    if (outcome.kind !== 'cancelled') setPasskeyNotice('failed')
  }, [apiBase])

  /* ----------------------------------------------------------------------- rendering */

  const formatDate = useCallback(
    (iso: string): string => {
      const when = new Date(iso)

      if (Number.isNaN(when.getTime())) return iso

      /*
       * ⚠ GUARDED, BECAUSE AN UNVALIDATED TIMEZONE THROWS DURING RENDER — `§11` SECURITY L-5.
       *
       * `timeZone` comes straight from `GET /v1/tenant`, and `fetchTenant` passes any
       * non-empty string through. Measured: `new Intl.DateTimeFormat('en', { timeZone:
       * 'Kitchen/Nowhere' })` throws `RangeError: Invalid time zone specified`. This is called
       * inside the device list's row map, so the throw is IN RENDER — and `apps/web/app/` has
       * no `error.tsx`, so the tree unmounts to a blank page. `tenants.timezone` is
       * `varchar(64)` with no CHECK and the app role may UPDATE it; only
       * `TenantProvisioning`'s hard-coded `'UTC'` makes it unreachable today.
       *
       * UTC is the fallback rather than the ISO string: a wrong-but-readable time beats a
       * blank screen, and the restaurant's own timezone being misconfigured is an operator
       * problem the staff screen should survive rather than report.
       */
      const format = (zone: string): string =>
        new Intl.DateTimeFormat(resolved, {
          dateStyle: 'medium',
          timeStyle: 'short',
          timeZone: zone,
        }).format(when)

      try {
        return format(tenant?.timezone ?? 'UTC')
      } catch {
        return format('UTC')
      }
    },
    [resolved, tenant],
  )

  /**
   * Whole seconds each pager has been waiting, from the SERVER's own `created_at`.
   *
   * ⚠ NOT A TICK COUNT, which is `elapsedSeconds`' own reason reused rather than
   * re-reasoned: a timer in a backgrounded or sleeping tab is throttled or stopped by every
   * engine, so a self-incrementing counter is wrong by however long the tablet slept — and
   * wrong in the direction that makes a long wait look short. Recomputed on every render
   * from two fresh readings instead.
   *
   * It re-renders on each poll rather than each second, which is a deliberate trade: a
   * kitchen needs to know *roughly how long*, and a per-second repaint on a wall tablet is
   * work for a digit nobody is watching. The customer's own screen has the live timer, where
   * it is the thing being looked at.
   */
  const elapsed = useMemo(() => {
    const now = Date.now()
    const out: Record<string, number> = {}

    for (const pager of pagers) out[pager.id] = elapsedSeconds(pager.createdAt, now)

    return out
  }, [pagers])

  /**
   * When the board was last confirmed, formatted, or null while it is fresh.
   *
   * `isStale` decides, from the timestamp rather than from the phase: a board can be in
   * `ready` and still be showing rows from before a run of failures, which is exactly the
   * case the marker exists for.
   */
  const staleSince = useMemo(() => {
    /*
     * ⚠ MEASURED AGAINST THE LAST ATTEMPT, NOT AGAINST `Date.now()` — `§11` QA H-1.
     *
     * `Date.now()` inside a memo is read only when a dependency changes, so the old version
     * evaluated it exactly when `confirmedAt === now` and could never be true. `attemptedAt`
     * moves on every poll including a failed one, which is both the trigger to recompute and
     * the honest clock to compare against: the question is *how old is what you are looking
     * at*, and the answer is the gap between the last successful answer and the last time we
     * asked.
     */
    if (confirmedAt === null || attemptedAt === null) return null
    if (!isStale(confirmedAt, attemptedAt)) return null

    return new Intl.DateTimeFormat(resolved, { timeStyle: 'short' }).format(confirmedAt)
  }, [confirmedAt, attemptedAt, resolved])

  /** The loading / error / offline view, used with and without the chrome. */
  const unresolved = (): React.ReactNode => {
    if (phase === 'loading') {
      return <StaffState state="loading" loadingLabel={t('staff.shell.loading')} />
    }

    if (phase === 'offline') {
      return (
        <StaffState
          state="offline"
          title={t('staff.shell.offline.title')}
          description={t('staff.shell.offline.body')}
          action={{ label: t('staff.shell.error.retry'), onAction: () => void load() }}
        />
      )
    }

    /*
     * `R-224`'s third surface — `§11` QA H-3. NO ACTION, and the kit's type is what enforces
     * that: this shell retries itself on the effect above, so there is nothing to press and a
     * button would be a second 429.
     */
    if (phase === 'throttled') {
      return (
        <StaffState
          state="throttled"
          title={t('staff.shell.throttled.title')}
          description={t('staff.shell.throttled.body')}
        />
      )
    }

    return (
      <StaffState
        state="error"
        title={t('staff.shell.error.title')}
        description={t('staff.shell.error.body')}
        action={{ label: t('staff.shell.error.retry'), onAction: () => void load() }}
      />
    )
  }

  const body = (): React.ReactNode => {
    if (phase !== 'ready') return unresolved()

    if (view === 'devices') {
      return (
        <StaffDevices
          t={t}
          state={devicesPhase}
          devices={devices ?? []}
          issued={issued}
          onRetry={() => void loadDevices()}
          onIssue={(name) => void onIssue(name)}
          onDismissIssued={() => {
            setIssued(null)
          }}
          onRemove={(id) => void onRemove(id)}
          formatDate={formatDate}
        />
      )
    }

    /*
     * ⚠ `StaffHome` IS GONE FROM THIS BRANCH AND THE FILE IS DELETED. Its own docblock said
     * so: *"When 10b lands, this is the file whose contents the board replaces."* It was an
     * honest interim screen -- *you are signed in, the board arrives next, here is what you
     * can do now* -- and keeping it beside the board would leave two answers to what `/staff`
     * is. The passkey affordance it carried has NOT been dropped; it moved below the board,
     * because it is a thing a person does once and the board is what they look at all day.
     */
    return (
      <>
        <StaffBoard
          t={t}
          phase={boardPhase}
          pagers={pagers}
          elapsed={elapsed}
          staleSince={staleSince}
          onRetry={() => void pollBoard()}
          selected={selected}
          onSelect={(id) => {
            setSelected(id)
            // The panel's sentence states belong to the attempt that produced them, so
            // opening or closing a row clears them. Keeping `limit` on screen after the
            // person moved to another order would be a message about an order they are no
            // longer looking at.
            setDetail('idle')
          }}
          detail={detail}
          onReady={(id) => void act(id, markSessionReady, 'other')}
          onRenotify={(id) => void act(id, renotifySession, 'renotify')}
          onComplete={(id) => void act(id, completeSession, 'other')}
          onCancel={() => {
            setDetail('confirm-cancel')
          }}
          onConfirmCancel={(id) => void act(id, cancelSession, 'other')}
        />
        {passkeysAvailable() && (
          <div className="qr-staff-actions">
            <Button variant="quiet" onClick={() => void onAddPasskey()} data-add-passkey="true">
              {t('staff.passkey.add')}
            </Button>
          </div>
        )}
        {passkeyNotice === 'added' && (
          <p className="qr-staff-state__description" role="status" data-passkey-notice="added">
            {t('staff.passkey.added')}
          </p>
        )}
        {passkeyNotice === 'failed' && (
          <p className="qr-staff-state__title" role="alert" data-passkey-notice="failed">
            {t('staff.passkey.failed')}
          </p>
        )}
      </>
    )
  }

  if (phase === 'locked') {
    return (
      <ThemeProvider locale={resolved} as="div">
        <LockScreen
          title={t(lockReason === 'idle' ? 'staff.lock.title' : 'staff.signedout.title')}
          description={t(lockReason === 'idle' ? 'staff.lock.body' : 'staff.signedout.body')}
          signInLabel={t('staff.lock.signIn')}
          onSignIn={() => {
            setPhase('signed-out')
            setSignInStage('idle')
          }}
        />
      </ThemeProvider>
    )
  }

  if (phase === 'signed-out') {
    return (
      <ThemeProvider locale={resolved} as="div">
        <StaffSignIn
          t={t}
          stage={signInStage}
          onPasskey={() => void onPasskey()}
          onPassword={(email, password) => void onPassword(email, password)}
          onTwoFactor={(code) => void onTwoFactor(code)}
          memberships={identity?.memberships ?? []}
          onChoose={(tenantId) => void onChoose(tenantId)}
        />
      </ThemeProvider>
    )
  }

  /*
   * ⚠ NO CHROME UNTIL THE RESTAURANT IS KNOWN, AND THIS WAS A DEFECT CAUGHT IN THE BUILD
   * OUTPUT RATHER THAN BY A TEST.
   *
   * The first version of this component rendered the shell for every phase but `locked` and
   * `signed-out`, with `unresolved()` inside it -- so the prerendered `/staff` document
   * carried `qr-staff__restaurant`, `Signed in as ` with an empty name, and a `Sign out`
   * button, for a caller who had presented no credential at all. Measured on the built HTML:
   * one occurrence of each in `.next/server/app/staff.html`.
   *
   * Two separate failures in one render. **`§12`**: *"Signed in as "* with the name missing is
   * a sentence with a hole in it, which is the defect `interpolate()` leaves a visible
   * `{name}` to prevent. And **the invariant's browser half**: a document that ships staff
   * chrome to an unauthenticated caller is a protected-looking surface at a public URL, and
   * the flash of it on every load is exactly what an authenticated shell should never do.
   *
   * So the chrome is rendered only when `phase === 'ready'`, which is the only phase in which
   * `tenant` and `identity` are both known. `apps/web/test/staff-build-surface.test.ts`
   * asserts the absence against the BUILT HTML of every staff route, derived from the build.
   */
  if (phase !== 'ready' || tenant === null || identity === null) {
    return (
      <ThemeProvider locale={resolved} as="div">
        <div className="qr-staff">
          <main className="qr-staff__main">{unresolved()}</main>
        </div>
      </ThemeProvider>
    )
  }

  const role = identity.memberships.find((m) => m.tenantId === identity.activeTenant)?.role ?? ''

  return (
    <ThemeProvider locale={resolved} as="div">
      <StaffShell
        restaurant={tenant.name}
        person={identity.name}
        roleWord={t(roleKey(role))}
        signedInLabel={t('staff.shell.signedIn', { name: identity.name })}
        signOutLabel={t('staff.shell.signOut')}
        onSignOut={() => {
          lock('signed-out')
        }}
        nav={
          /*
           * ⚠ NAVIGATION, AND ITS ABSENCE WAS A REAL HOLE RATHER THAN AN OMISSION OF POLISH.
           * `QRPAGER-25 §12` S2's content inventory names it, and the first version passed
           * `nav={null}`: a person who reached `/staff/devices` had no way back to `/staff`
           * except the browser's back button, which on a wall-mounted kitchen tablet in
           * kiosk mode is not reachable at all.
           *
           * Two entries, because there are two staff routes. `aria-current="page"` rather
           * than colour alone, so which one you are on is in the accessible tree and not
           * only in the paint -- `§12`'s *visible focus ring* clause's sibling.
           */
          <>
            <Button
              variant={view === 'home' ? 'secondary' : 'quiet'}
              onClick={() => onNavigate?.('home')}
              aria-current={view === 'home' ? 'page' : undefined}
              data-nav="home"
            >
              {t('staff.nav.today')}
            </Button>
            <Button
              variant={view === 'devices' ? 'secondary' : 'quiet'}
              onClick={() => onNavigate?.('devices')}
              aria-current={view === 'devices' ? 'page' : undefined}
              data-nav="devices"
            >
              {t('staff.nav.devices')}
            </Button>
          </>
        }
      >
        {body()}
      </StaffShell>
    </ThemeProvider>
  )
}
