import { PAGER_STATUS_TRANSITIONS, type PagerStatus } from '@qrpager/contracts'

import type { SessionSnapshot } from './api.ts'
import { MAX_CONSECUTIVE_FAILURES } from './poll.ts'
import type { PresentedToken } from './token.ts'

/**
 * ================================================================================
 * THE WHOLE JOURNEY, AS ONE PURE REDUCER
 * ================================================================================
 *
 * `§11` role 2, check 2: *"Every branch of any state machine has a test."* A journey spread
 * across six components has no state machine to enumerate, so there is nothing a reviewer
 * can count and nothing a test can iterate. It is one function instead, with no I/O, no
 * timers and no DOM -- so every branch is reachable from a unit test and `JOURNEY_STATES`
 * below is the list the test iterates.
 *
 * ⚠ NO `default` ARM ANYWHERE, AND THAT IS LOAD-BEARING. The status switch is exhaustive
 * over `PagerStatus`, which is GENERATED from the PHP enum -- so a status added in
 * `App\Domain\Pager\PagerStatus` makes this file a TYPE ERROR at `pnpm typecheck` rather
 * than a screen that silently renders nothing. The event switch is exhaustive the same way.
 * `assertNever` is how both are held to it.
 */

/** Every screen state in `PLAN §4.1`, as one union. 17 of them. */
export const JOURNEY_STATES = [
  'landing.valid',
  'landing.invalid',
  'alerts.notAsked',
  'alerts.granted',
  'alerts.denied',
  'alerts.unsupported',
  'waiting.healthy',
  'waiting.degraded',
  'waiting.offline',
  'ready.fresh',
  'ready.acknowledged',
  'ended.completed',
  'ended.cancelled',
  'ended.expired',
  'error.offline',
  'error.server',
  'error.unknown',
] as const

export type ScreenState = (typeof JOURNEY_STATES)[number]

/** The screen half of a state, for the `data-screen` attribute a locator reads. */
export type Screen = 'landing' | 'alerts' | 'waiting' | 'ready' | 'ended' | 'error'

export function screenOf(state: ScreenState): Screen {
  return state.split('.')[0] as Screen
}

/**
 * `§4.1`'s FOUR SCAN-LANDING STATES, and where each one lands.
 *
 * ⚠ WHY THIS EXISTS AS ITS OWN TABLE RATHER THAN AS FOUR SCREEN STATES. `§4.1` lists the
 * scan landing's states as *valid · expired · invalid · already-ready*, and two of those are
 * not screens: an expired scan IS the session-ended screen and a scan that is already ready
 * IS the order-ready screen. Giving them screen states of their own would mean two states
 * that render identically to two others -- invented states, which is worse than a mapping.
 *
 * So the four are enumerated here, each with its destination, and `journey.test.ts` asserts
 * the mapping by DRIVING the resolve answer that produces it. `§4.1`'s inventory is
 * therefore covered literally and countably, and nothing is duplicated.
 */
export const SCAN_OUTCOMES = {
  valid: 'landing.valid',
  expired: 'ended.expired',
  invalid: 'landing.invalid',
  'already-ready': 'ready.fresh',
} as const satisfies Readonly<Record<string, ScreenState>>

export type ScanOutcome = keyof typeof SCAN_OUTCOMES

export type PermissionState = 'unknown' | 'default' | 'granted' | 'denied' | 'unsupported'

export interface JourneyState {
  readonly screen: ScreenState
  /** Held in state so no component reads storage or the URL. Never rendered. */
  readonly token: PresentedToken | null
  readonly snapshot: SessionSnapshot | null
  /** The first `as_of` this journey saw. The elapsed clock's origin. */
  readonly scannedAt: string | null
  /** Reset to 0 by any successful answer. Drives the backoff and the degraded state. */
  readonly consecutiveFailures: number
  readonly permission: PermissionState
  /** Whether the poll timer should be running. The orchestrator reads this and nothing else. */
  readonly polling: boolean
}

export const INITIAL: JourneyState = {
  screen: 'landing.valid',
  token: null,
  snapshot: null,
  scannedAt: null,
  consecutiveFailures: 0,
  permission: 'unknown',
  polling: false,
}

export type JourneyEvent =
  | { readonly type: 'token/present'; readonly token: PresentedToken }
  | { readonly type: 'token/malformed' }
  | { readonly type: 'resolve/ok'; readonly snapshot: SessionSnapshot }
  | { readonly type: 'resolve/expired' }
  | { readonly type: 'resolve/unknown' }
  | { readonly type: 'resolve/server' }
  | { readonly type: 'resolve/unreadable' }
  | { readonly type: 'resolve/unreachable' }
  | { readonly type: 'poll/ok'; readonly snapshot: SessionSnapshot }
  | { readonly type: 'poll/stale' }
  | { readonly type: 'poll/unavailable' }
  | { readonly type: 'poll/unreachable' }
  | { readonly type: 'permission/probed'; readonly permission: PermissionState }
  | { readonly type: 'permission/granted' }
  | { readonly type: 'permission/denied' }
  | { readonly type: 'permission/unsupported' }
  | { readonly type: 'push/ready'; readonly reference: string }
  | { readonly type: 'alerts/open' }
  | { readonly type: 'ack' }
  | { readonly type: 'retry' }
  | { readonly type: 'net/online' }
  | { readonly type: 'net/offline' }
  | { readonly type: 'skip' }

/** Exhaustiveness, enforced by the compiler rather than by a comment. */
function assertNever(value: never): never {
  throw new Error(`unhandled: ${JSON.stringify(value)}`)
}

/** The three terminal states. A session that reached one is over. */
const TERMINAL: readonly ScreenState[] = ['ended.completed', 'ended.cancelled', 'ended.expired']

export function isTerminal(state: ScreenState): boolean {
  return TERMINAL.includes(state)
}

/**
 * Whether a status is allowed to follow the one we already hold.
 *
 * ⚠ READ FROM THE GENERATED TRANSITION TABLE, not re-derived. `PAGER_STATUS_TRANSITIONS` is
 * emitted from the PHP table, so the client and the domain cannot disagree about what a
 * legal move is.
 *
 * ⚠ AND THE CASE THIS EXISTS FOR IS REAL, not defensive. A poll that answers `waiting` AFTER
 * one that answered `ready` is reachable: the projection is a cache with a TTL, written by
 * two different writers (the request and the outbox worker), and `PagerStatusView` has no
 * `ready_at` precisely because *"the service learns a transition's own timestamp only by
 * re-reading the row"*. Without this guard the order-ready screen flips back to waiting
 * while the customer is walking to the counter, and then forward again -- which is the one
 * failure a pager cannot have.
 */
function statusMayFollow(from: PagerStatus | null, to: PagerStatus): boolean {
  if (from === null || from === to) return true

  return PAGER_STATUS_TRANSITIONS[from].includes(to)
}

/**
 * The canonical screen for a status, ignoring where the customer currently is.
 *
 * The waiting screen's three states are a property of the CONNECTION, not of the order,
 * which is why they are decided from the failure count rather than from the status.
 * `§4 › Edge`: shedding drops the live timer first, so a degraded timer on a healthy order
 * is the expected state under load and not an error.
 */
export function destinationFor(status: PagerStatus, failures: number): ScreenState {
  switch (status) {
    case 'waiting':
      if (failures === 0) return 'waiting.healthy'

      return failures >= MAX_CONSECUTIVE_FAILURES ? 'waiting.offline' : 'waiting.degraded'
    case 'ready':
      return 'ready.fresh'
    case 'completed':
      return 'ended.completed'
    case 'cancelled':
      return 'ended.cancelled'
    case 'expired':
      return 'ended.expired'
    default:
      return assertNever(status)
  }
}

/**
 * Where an ANSWER puts the customer, which is not always the canonical destination.
 *
 * ⚠ TWO STAY-PUT RULES, AND BOTH ARE ABOUT NOT MOVING SOMEBODY WHO IS READING.
 *
 * 1. A poll that confirms *still waiting* must not drag a customer off the scan landing or
 *    the alerts screen. Polling starts the moment a session resolves -- it has to, or a
 *    customer who lingers on "Enable alerts" would not learn their order is ready until they
 *    tapped something -- so `waiting` answers arrive while they are still deciding. The
 *    answer updates the snapshot and leaves the screen alone.
 * 2. Acknowledgement survives a re-poll. A customer who tapped "Got it" must not have the
 *    button come back and the screen re-announce itself, and a `ready` answer arrives on
 *    every poll until the order is completed.
 */
function screenAfterAnswer(
  status: PagerStatus,
  failures: number,
  previous: ScreenState,
): ScreenState {
  if (status === 'waiting') {
    const where = screenOf(previous)

    if (where === 'landing' || where === 'alerts') return previous
  }

  if (status === 'ready' && previous === 'ready.acknowledged') return 'ready.acknowledged'

  return destinationFor(status, failures)
}

/** The alerts screen that matches what the browser already thinks about permission. */
export function alertsScreenFor(permission: PermissionState): ScreenState {
  switch (permission) {
    case 'granted':
      return 'alerts.granted'
    case 'denied':
      return 'alerts.denied'
    case 'unsupported':
      return 'alerts.unsupported'
    case 'default':
    case 'unknown':
      return 'alerts.notAsked'
    default:
      return assertNever(permission)
  }
}

export function reduce(state: JourneyState, event: JourneyEvent): JourneyState {
  // ⚠ TERMINALITY FIRST, BEFORE THE SWITCH. A poll in flight when `completed` arrives
  // answers afterwards, so a late `poll/ok` carrying `waiting` is reachable on every
  // completed session. Returning the IDENTICAL object (not a copy) is what lets
  // `journey.test.ts` assert terminality by reference over the whole event table.
  if (isTerminal(state.screen)) return state

  switch (event.type) {
    case 'token/present':
      return { ...state, token: event.token, screen: 'landing.valid' }

    case 'token/malformed':
      // No request is made for this, which is the point: a damaged or forged code costs the
      // server nothing. `polling` stays false.
      return { ...state, token: null, screen: 'landing.invalid' }

    case 'resolve/ok': {
      if (!statusMayFollow(state.snapshot?.status ?? null, event.snapshot.status)) return state

      // A first resolve answering `waiting` is `§4.1`'s `valid` scan outcome, and
      // `SCAN_OUTCOMES` is where that mapping is stated and tested. `ready` on a FIRST
      // resolve is its `already-ready` outcome -- the commonest case in practice, because the
      // order is often marked ready while the customer walks to the table.
      const screen =
        event.snapshot.status === 'waiting'
          ? screenAfterAnswer('waiting', 0, state.screen)
          : destinationFor(event.snapshot.status, 0)

      return {
        ...state,
        snapshot: event.snapshot,
        scannedAt: state.scannedAt ?? event.snapshot.asOf,
        consecutiveFailures: 0,
        screen,
        polling: !isTerminal(screen),
      }
    }

    case 'resolve/expired':
      return { ...state, screen: SCAN_OUTCOMES.expired, polling: false }

    case 'resolve/unknown':
      return { ...state, screen: SCAN_OUTCOMES.invalid, polling: false }

    case 'resolve/server':
      return { ...state, screen: 'error.server', polling: false }

    case 'resolve/unreadable':
      // ⚠ THE ONLY TRANSITION INTO `error.unknown`, AND IT DID NOT EXIST. That state was in
      // `JOURNEY_STATES` and no event produced it, so the screen was unreachable and its copy
      // was dead. Found by `browser/one-handed.spec.ts`, which iterates that export.
      return { ...state, screen: 'error.unknown', polling: false }

    case 'resolve/unreachable':
      return { ...state, screen: 'error.offline', polling: false }

    case 'poll/ok': {
      if (!statusMayFollow(state.snapshot?.status ?? null, event.snapshot.status)) {
        // Ignored, and the failure count is still cleared: the request SUCCEEDED, so the
        // connection is healthy even though the payload is behind. Leaving the count raised
        // would show a degraded banner on a working connection.
        return { ...state, consecutiveFailures: 0 }
      }

      const screen = screenAfterAnswer(event.snapshot.status, 0, state.screen)

      return {
        ...state,
        snapshot: event.snapshot,
        scannedAt: state.scannedAt ?? event.snapshot.asOf,
        consecutiveFailures: 0,
        screen,
        polling: !isTerminal(screen),
      }
    }

    case 'poll/stale':
      // 404 `…/problems/stale`: this server no longer holds a live status, so OPEN THE
      // SESSION AGAIN. Not a failure and not a backoff -- `§3` row 9 clause (c). The
      // orchestrator sees `polling: false` with a token still held and re-resolves.
      return { ...state, polling: false, consecutiveFailures: 0 }

    case 'poll/unavailable': {
      // 503: come back shortly, nothing is wrong. This is the ONLY answer that advances the
      // backoff, and the screen degrades rather than erroring.
      const failures = state.consecutiveFailures + 1

      return {
        ...state,
        consecutiveFailures: failures,
        screen:
          state.snapshot === null
            ? 'error.server'
            : screenAfterAnswer(state.snapshot.status, failures, state.screen),
      }
    }

    case 'poll/unreachable': {
      const failures = state.consecutiveFailures + 1

      return {
        ...state,
        consecutiveFailures: failures,
        screen:
          state.snapshot === null
            ? 'error.offline'
            : screenAfterAnswer(state.snapshot.status, failures, state.screen),
      }
    }

    case 'permission/probed':
      // The probe only records; it never moves the customer. A granted permission from a
      // previous visit must not skip the screen that explains what is about to happen.
      return { ...state, permission: event.permission }

    case 'permission/granted':
      // Stays on the alerts screen to confirm, rather than jumping: `§3.2`'s `granted` state
      // is a deliverable with its own copy, and a screen that flashes past is not a state.
      return { ...state, permission: 'granted', screen: 'alerts.granted' }

    case 'permission/denied':
      // ⚠ POLLING CONTINUES. A denied permission removes the buzz, not the product: the
      // status screen is the fallback `§2`'s O2 made polling for, and the copy says so.
      return { ...state, permission: 'denied', screen: 'alerts.denied' }

    case 'permission/unsupported':
      return { ...state, permission: 'unsupported', screen: 'alerts.unsupported' }

    case 'push/ready':
      // The service worker told the page. Trusted only to the extent of showing the screen
      // the customer is about to see anyway -- the reference comes from `snapshot` when we
      // have one, so a forged message cannot put an attacker's text on the screen.
      return {
        ...state,
        screen: state.screen === 'ready.acknowledged' ? 'ready.acknowledged' : 'ready.fresh',
        polling: false,
      }

    case 'alerts/open':
      // `§4.1`'s single primary action on the scan landing. It opens the alerts screen in
      // the state the browser is ALREADY in -- a customer who granted permission on a
      // previous visit must not be asked again, and one who denied it must not be shown a
      // button that cannot work.
      return { ...state, screen: alertsScreenFor(state.permission) }

    case 'ack':
      return state.screen === 'ready.fresh' ? { ...state, screen: 'ready.acknowledged' } : state

    case 'retry':
      // The manual retry, from the two waiting banners and from all three error states.
      // ⚠ IT CLEARS THE FAILURE COUNT, which is what makes it a retry rather than a nudge:
      // leaving the count raised would re-arm the timer at the backed-off delay, so the
      // button would appear to do nothing for up to two minutes.
      return state.snapshot === null
        ? {
            ...state,
            consecutiveFailures: 0,
            screen: state.token === null ? state.screen : 'landing.valid',
          }
        : {
            ...state,
            consecutiveFailures: 0,
            screen: destinationFor(state.snapshot.status, 0),
            polling: true,
          }

    case 'net/online':
      // ⚠ IT DOES NOT RESET THE FAILURE COUNT BY ITSELF. `online` fires on a network
      // interface coming back, which is not evidence the server is reachable -- a captive
      // portal fires it too. What it does is make one more attempt worth making: an
      // `error.offline` goes back to resolving, and a degraded wait keeps its backoff until
      // an answer actually arrives.
      return state.screen === 'error.offline' && state.token !== null
        ? { ...state, screen: 'landing.valid', consecutiveFailures: 0 }
        : state

    case 'net/offline':
      return {
        ...state,
        screen: state.snapshot === null ? 'error.offline' : 'waiting.offline',
      }

    case 'skip':
      // Past the alerts screen without granting. `§3.1`: a customer who declines must still
      // reach the waiting screen -- `§4.1` asks for one PRIMARY action, not for a gate.
      return state.snapshot === null
        ? state
        : {
            ...state,
            screen: destinationFor(state.snapshot.status, state.consecutiveFailures),
            polling: true,
          }

    default:
      return assertNever(event)
  }
}
