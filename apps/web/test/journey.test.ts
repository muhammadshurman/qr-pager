import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

import { PAGER_STATUS, PAGER_STATUS_TRANSITIONS, type PagerStatus } from '@qrpager/contracts'
import { describe, expect, it } from 'vitest'

import type { SessionSnapshot } from '../src/pager/api.ts'
import {
  INITIAL,
  JOURNEY_STATES,
  SCAN_OUTCOMES,
  alertsScreenFor,
  destinationFor,
  isTerminal,
  reduce,
  screenOf,
  type JourneyEvent,
  type JourneyState,
  type ScreenState,
} from '../src/pager/journey.ts'
import type { PresentedToken } from '../src/pager/token.ts'

/**
 * ================================================================================
 * EVERY BRANCH OF THE STATE MACHINE. `§11` role 2, check 2.
 * ================================================================================
 *
 * ⚠ THE SUBJECT LIST IS THE EXPORT, NOT A LIST TYPED HERE. `JOURNEY_STATES` is what the
 * reducer's own union is built from and what `browser/one-handed.spec.ts` iterates, so a
 * state that exists and is untested is impossible rather than merely unlikely. *Every table
 * is generated or gate-checked* -- and a subject list typed into a test is the same defect
 * one layer down.
 */

const TOKEN = 'AbCdEfGhIjKlMnOpQrStUv' as PresentedToken

function snapshot(status: PagerStatus, asOf = '2026-11-01T12:00:00Z'): SessionSnapshot {
  return {
    status,
    orderReference: 'A-42',
    expiresAt: '2026-11-01T14:00:00Z',
    asOf,
    pollAfterSeconds: 7,
  }
}

/** The state a customer is in after a valid scan of a waiting order. */
function waiting(): JourneyState {
  const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })

  return reduce(withToken, { type: 'resolve/ok', snapshot: snapshot('waiting') })
}

/** Every event, with a representative payload. The reducer's own union, enumerated. */
const ALL_EVENTS: readonly JourneyEvent[] = [
  { type: 'token/present', token: TOKEN },
  { type: 'token/malformed' },
  { type: 'resolve/ok', snapshot: snapshot('waiting') },
  { type: 'resolve/expired' },
  { type: 'resolve/unknown' },
  { type: 'resolve/server' },
  { type: 'resolve/unreadable' },
  { type: 'resolve/unreachable' },
  { type: 'poll/ok', snapshot: snapshot('ready') },
  { type: 'poll/stale' },
  { type: 'poll/unavailable' },
  { type: 'poll/unreachable' },
  { type: 'permission/probed', permission: 'granted' },
  { type: 'permission/granted' },
  { type: 'permission/denied' },
  { type: 'permission/unsupported' },
  { type: 'push/ready', reference: 'A-42' },
  { type: 'alerts/open' },
  { type: 'ack' },
  { type: 'retry' },
  { type: 'net/online' },
  { type: 'net/offline' },
  { type: 'skip' },
]

describe('the state inventory', () => {
  it('is the 17 screen states PLAN 4.1 inventories', () => {
    expect(JOURNEY_STATES.length).toBe(17)
    expect(new Set(JOURNEY_STATES).size).toBe(17)
  })

  it('covers every screen named in PLAN 4.1, with the states that section lists', () => {
    const byScreen = new Map<string, string[]>()

    for (const state of JOURNEY_STATES) {
      const screen = screenOf(state)
      byScreen.set(screen, [...(byScreen.get(screen) ?? []), state])
    }

    // Exact counts, per screen, so a state cannot be dropped and a sixth screen cannot be
    // invented without this failing.
    expect(byScreen.get('landing')?.length).toBe(2)
    expect(byScreen.get('alerts')?.length).toBe(4)
    expect(byScreen.get('waiting')?.length).toBe(3)
    expect(byScreen.get('ready')?.length).toBe(2)
    expect(byScreen.get('ended')?.length).toBe(3)
    expect(byScreen.get('error')?.length).toBe(3)
    expect([...byScreen.keys()].sort()).toEqual([
      'alerts',
      'ended',
      'error',
      'landing',
      'ready',
      'waiting',
    ])
  })

  it('maps all four of PLAN 4.1s scan-landing outcomes, each to a real state', () => {
    // `§4.1`'s own four: valid, expired, invalid, already-ready. Two of them are not
    // screens, which is why this is a mapping rather than four screen states.
    expect(Object.keys(SCAN_OUTCOMES).sort()).toEqual([
      'already-ready',
      'expired',
      'invalid',
      'valid',
    ])

    for (const destination of Object.values(SCAN_OUTCOMES)) {
      expect(JOURNEY_STATES).toContain(destination)
    }
  })
})

describe('every one of the four scan outcomes, driven by the answer that produces it', () => {
  it('valid -- a waiting session lands on the scan landing with its reference', () => {
    const state = waiting()

    expect(state.screen).toBe(SCAN_OUTCOMES.valid)
    expect(state.screen).toBe('landing.valid')
    expect(state.snapshot?.orderReference).toBe('A-42')
    // Polling starts immediately, even though the landing screen shows no timer: a customer
    // who lingers on "Enable alerts" must still learn their order is ready.
    expect(state.polling).toBe(true)
  })

  it('already-ready -- a FIRST resolve answering ready goes straight to the ready screen', () => {
    // ⚠ THE COMMONEST CASE IN PRACTICE, not the rare one: the order is often marked ready
    // while the customer walks to the table. It must not show "we'll let you know".
    const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })
    const state = reduce(withToken, { type: 'resolve/ok', snapshot: snapshot('ready') })

    expect(state.screen).toBe(SCAN_OUTCOMES['already-ready'])
    expect(state.screen).toBe('ready.fresh')
  })

  it('expired -- a 409 lands on the session-ended screen and stops polling', () => {
    const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })
    const state = reduce(withToken, { type: 'resolve/expired' })

    expect(state.screen).toBe(SCAN_OUTCOMES.expired)
    expect(state.polling).toBe(false)
  })

  it('invalid -- a 404, and a malformed fragment, both land on the invalid landing', () => {
    const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })

    expect(reduce(withToken, { type: 'resolve/unknown' }).screen).toBe(SCAN_OUTCOMES.invalid)

    const malformed = reduce(INITIAL, { type: 'token/malformed' })

    expect(malformed.screen).toBe('landing.invalid')
    // No token means no request is ever made for it, which is what makes a damaged or
    // forged code cost the server nothing.
    expect(malformed.token).toBeNull()
    expect(malformed.polling).toBe(false)
  })
})

describe('the four answers the polling endpoint gives are four different things', () => {
  it('200 waiting leaves a customer on the landing or alerts screen', () => {
    // Stay-put rule 1. A poll confirming "still waiting" must not drag somebody off a
    // screen they are reading.
    const onLanding = waiting()
    const polled = reduce(onLanding, { type: 'poll/ok', snapshot: snapshot('waiting') })

    expect(polled.screen).toBe('landing.valid')

    const onAlerts = reduce(onLanding, { type: 'alerts/open' })

    expect(reduce(onAlerts, { type: 'poll/ok', snapshot: snapshot('waiting') }).screen).toBe(
      'alerts.notAsked',
    )
  })

  it('200 ready moves them, from any non-terminal screen', () => {
    for (const from of JOURNEY_STATES.filter((s) => !isTerminal(s))) {
      const state = reduce(
        { ...waiting(), screen: from },
        { type: 'poll/ok', snapshot: snapshot('ready') },
      )

      expect(['ready.fresh', 'ready.acknowledged'], from).toContain(state.screen)
    }
  })

  it('404 stale clears polling WITHOUT a backoff, so the client re-resolves', () => {
    // `§3` row 9 clause (c): *stale* means open the session again. Not a failure.
    const state = reduce({ ...waiting(), consecutiveFailures: 3 }, { type: 'poll/stale' })

    expect(state.polling).toBe(false)
    expect(state.consecutiveFailures).toBe(0)
    expect(state.token).toBe(TOKEN)
    // The screen does NOT change: the customer is still waiting while the client re-opens.
    expect(state.screen).toBe('landing.valid')
  })

  it('503 unavailable is the ONLY answer that advances the backoff', () => {
    const live = reduce(waiting(), { type: 'skip' })

    expect(live.screen).toBe('waiting.healthy')

    const once = reduce(live, { type: 'poll/unavailable' })

    expect(once.consecutiveFailures).toBe(1)
    expect(once.screen).toBe('waiting.degraded')

    const stale = reduce(live, { type: 'poll/stale' })

    expect(stale.consecutiveFailures).toBe(0)
  })

  it('degrades, then goes offline at the sixth consecutive failure, and not before', () => {
    let state = reduce(waiting(), { type: 'skip' })

    for (let n = 1; n <= 5; n += 1) {
      state = reduce(state, { type: 'poll/unavailable' })
      expect(state.consecutiveFailures, `failure ${String(n)}`).toBe(n)
      expect(state.screen, `failure ${String(n)}`).toBe('waiting.degraded')
    }

    state = reduce(state, { type: 'poll/unavailable' })

    expect(state.consecutiveFailures).toBe(6)
    expect(state.screen).toBe('waiting.offline')
  })

  it('clears the failure count on a successful answer even when the payload is ignored', () => {
    // A request that SUCCEEDED means the connection is healthy, even if the body is behind.
    // Leaving the count raised would show a degraded banner on a working connection.
    const ready = reduce(reduce(waiting(), { type: 'skip' }), {
      type: 'poll/ok',
      snapshot: snapshot('ready'),
    })
    const degraded = reduce(ready, { type: 'poll/unavailable' })

    expect(degraded.consecutiveFailures).toBe(1)

    const backwards = reduce(degraded, { type: 'poll/ok', snapshot: snapshot('waiting') })

    expect(backwards.consecutiveFailures).toBe(0)
    expect(backwards.screen).toBe('ready.fresh')
  })
})

describe('the status machine is monotone, read from the generated transition table', () => {
  it('ignores a poll that answers waiting after ready', () => {
    /**
     * ⚠ A REACHABLE CASE, NOT A DEFENSIVE ONE. The projection is a cache with a TTL written
     * by two different writers -- the request and the outbox worker -- and `PagerStatusView`
     * carries no `ready_at` precisely because *"the service learns a transition's own
     * timestamp only by re-reading the row"*. Without this guard the order-ready screen
     * flips back to waiting while the customer is walking to the counter, and then forward
     * again. That is the one failure a pager cannot have.
     */
    const ready = reduce(reduce(waiting(), { type: 'skip' }), {
      type: 'poll/ok',
      snapshot: snapshot('ready'),
    })

    expect(ready.screen).toBe('ready.fresh')

    const backwards = reduce(ready, { type: 'poll/ok', snapshot: snapshot('waiting') })

    expect(backwards.screen).toBe('ready.fresh')
    expect(backwards.snapshot?.status).toBe('ready')
  })

  it('accepts every transition the generated table permits, and refuses every other', () => {
    // Driven over the GENERATED table rather than a list here, so the client and the PHP
    // domain cannot disagree about what a legal move is.
    for (const from of PAGER_STATUS) {
      for (const to of PAGER_STATUS) {
        const permitted = from === to || PAGER_STATUS_TRANSITIONS[from].includes(to)
        const start: JourneyState = {
          ...waiting(),
          snapshot: snapshot(from),
          screen: destinationFor(from, 0),
        }

        if (isTerminal(start.screen)) continue

        const after = reduce(start, { type: 'poll/ok', snapshot: snapshot(to) })

        expect(after.snapshot?.status, `${from} -> ${to}`).toBe(permitted ? to : from)
      }
    }
  })
})

describe('terminality', () => {
  it('names exactly the three ended states', () => {
    for (const state of JOURNEY_STATES) {
      expect(isTerminal(state), state).toBe(screenOf(state) === 'ended')
    }
  })

  it('returns the IDENTICAL state object for every event, from every terminal state', () => {
    /**
     * ⚠ BY REFERENCE, AND OVER THE WHOLE EVENT TABLE. A poll in flight when `completed`
     * arrives answers afterwards, so a late `poll/ok` carrying `waiting` is reachable on
     * every completed session. Comparing by reference is what makes this one assertion
     * rather than seventeen about the fields.
     */
    for (const terminal of JOURNEY_STATES.filter(isTerminal)) {
      const state: JourneyState = { ...waiting(), screen: terminal, polling: false }

      for (const event of ALL_EVENTS) {
        expect(reduce(state, event), `${terminal} + ${event.type}`).toBe(state)
      }
    }
  })
})

describe('every state accepts every event without throwing', () => {
  it('is exhaustive -- 17 states x 22 events, and the reducer has no default arm', () => {
    /**
     * ⚠ THIS IS NOT A SMOKE TEST. `reduce` ends in `assertNever(event)`, which THROWS, and
     * the status switch ends in `assertNever(status)`. So a union member added without a case
     * is a type error at `pnpm typecheck` AND a throw here. The pair is what makes the
     * absence of a `default` arm safe: a `default` returning a fallback is how a screen goes
     * missing in production and nothing fails.
     */
    let evaluated = 0

    for (const screen of JOURNEY_STATES) {
      for (const event of ALL_EVENTS) {
        const state: JourneyState = { ...waiting(), screen }
        const after = reduce(state, event)

        expect(JOURNEY_STATES, `${screen} + ${event.type}`).toContain(after.screen)
        evaluated += 1
      }
    }

    expect(evaluated).toBe(17 * 23)
    expect(ALL_EVENTS.length).toBe(23)
  })
})

describe('the permission path', () => {
  it('opens the alerts screen in the state the browser is ALREADY in', () => {
    expect(alertsScreenFor('unknown')).toBe('alerts.notAsked')
    expect(alertsScreenFor('default')).toBe('alerts.notAsked')
    expect(alertsScreenFor('granted')).toBe('alerts.granted')
    expect(alertsScreenFor('denied')).toBe('alerts.denied')
    expect(alertsScreenFor('unsupported')).toBe('alerts.unsupported')

    // A customer who granted on a previous visit is not asked again; one who denied is not
    // shown a button that cannot work.
    const probed = reduce(waiting(), { type: 'permission/probed', permission: 'denied' })

    expect(probed.screen).toBe('landing.valid')
    expect(reduce(probed, { type: 'alerts/open' }).screen).toBe('alerts.denied')
  })

  it('keeps POLLING after a denial, because a denial removes the buzz and not the product', () => {
    const onAlerts = reduce(waiting(), { type: 'alerts/open' })
    const denied = reduce(onAlerts, { type: 'permission/denied' })

    expect(denied.screen).toBe('alerts.denied')
    // O2 made polling the fallback. A customer who refuses notifications still has a status.
    expect(denied.polling).toBe(true)
  })

  it('lets a customer past the alerts screen without granting', () => {
    const onAlerts = reduce(waiting(), { type: 'alerts/open' })
    const skipped = reduce(onAlerts, { type: 'skip' })

    expect(skipped.screen).toBe('waiting.healthy')
    expect(skipped.polling).toBe(true)
  })

  it('does not move anybody on a probe -- a probe records, a request moves', () => {
    const state = reduce(waiting(), { type: 'permission/probed', permission: 'granted' })

    expect(state.permission).toBe('granted')
    expect(state.screen).toBe('landing.valid')
  })
})

describe('the ready screen', () => {
  it('acknowledges once and never re-announces', () => {
    const ready = reduce(waiting(), { type: 'poll/ok', snapshot: snapshot('ready') })

    expect(ready.screen).toBe('ready.fresh')

    const acked = reduce(ready, { type: 'ack' })

    expect(acked.screen).toBe('ready.acknowledged')
    // Stay-put rule 2: a `ready` answer arrives on every poll until the order is completed,
    // and the button must not come back.
    expect(reduce(acked, { type: 'poll/ok', snapshot: snapshot('ready') }).screen).toBe(
      'ready.acknowledged',
    )
    expect(reduce(acked, { type: 'push/ready', reference: 'A-42' }).screen).toBe(
      'ready.acknowledged',
    )
    // And acknowledging twice is not a transition.
    expect(reduce(acked, { type: 'ack' })).toBe(acked)
  })

  it('stops polling on a push, because the thing it was polling for has happened', () => {
    const state = reduce(reduce(waiting(), { type: 'skip' }), {
      type: 'push/ready',
      reference: 'A-42',
    })

    expect(state.screen).toBe('ready.fresh')
    expect(state.polling).toBe(false)
  })
})

describe('the network and the retry', () => {
  it('shows the offline WAITING state mid-wait, not the offline ERROR screen', () => {
    // Losing the connection mid-wait must not throw a customer out of their session.
    const live = reduce(waiting(), { type: 'skip' })

    expect(reduce(live, { type: 'net/offline' }).screen).toBe('waiting.offline')
    // With no snapshot yet there IS nothing to wait on, so the error screen is correct.
    expect(reduce(INITIAL, { type: 'net/offline' }).screen).toBe('error.offline')
  })

  it('does not clear the backoff on `online` alone', () => {
    // ⚠ `online` FIRES ON AN INTERFACE COMING BACK, which is not evidence the server is
    // reachable -- a captive portal fires it too. Clearing the backoff on it would make a
    // flapping connection poll at the base interval forever.
    const degraded = reduce(
      reduce(reduce(waiting(), { type: 'skip' }), { type: 'poll/unavailable' }),
      {
        type: 'poll/unavailable',
      },
    )

    expect(degraded.consecutiveFailures).toBe(2)
    expect(reduce(degraded, { type: 'net/online' }).consecutiveFailures).toBe(2)
  })

  it('clears the backoff on an explicit retry, which is what makes the button work', () => {
    const degraded = reduce(reduce(waiting(), { type: 'skip' }), { type: 'poll/unavailable' })
    const retried = reduce(degraded, { type: 'retry' })

    expect(retried.consecutiveFailures).toBe(0)
    expect(retried.screen).toBe('waiting.healthy')
    expect(retried.polling).toBe(true)
  })

  it('sends a retry from the error screen back to resolving', () => {
    const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })
    const unreachable = reduce(withToken, { type: 'resolve/unreachable' })

    expect(unreachable.screen).toBe('error.offline')

    const retried = reduce(unreachable, { type: 'retry' })

    expect(retried.screen).toBe('landing.valid')
    expect(retried.token).toBe(TOKEN)
  })

  it('leaves a retry nowhere to go when there is no token at all', () => {
    const malformed = reduce(INITIAL, { type: 'token/malformed' })

    expect(reduce(malformed, { type: 'retry' }).screen).toBe('landing.invalid')
  })
})

describe('the scan timestamp', () => {
  it('is the FIRST as_of the journey saw, and never moves', () => {
    // The elapsed clock's origin. A later answer must not reset it, or the timer jumps back
    // to zero on every poll.
    const first = waiting()

    expect(first.scannedAt).toBe('2026-11-01T12:00:00Z')

    const later = reduce(first, {
      type: 'poll/ok',
      snapshot: snapshot('waiting', '2026-11-01T12:04:12Z'),
    })

    expect(later.scannedAt).toBe('2026-11-01T12:00:00Z')
    expect(later.snapshot?.asOf).toBe('2026-11-01T12:04:12Z')
  })
})

describe('the initial state', () => {
  it('polls nothing and holds nothing', () => {
    expect(INITIAL.token).toBeNull()
    expect(INITIAL.snapshot).toBeNull()
    expect(INITIAL.polling).toBe(false)
    expect(INITIAL.consecutiveFailures).toBe(0)
    expect(INITIAL.permission).toBe('unknown')
  })
})

describe('destinationFor is exhaustive over the GENERATED status union', () => {
  it('maps every status in PAGER_STATUS to a real screen state', () => {
    for (const status of PAGER_STATUS) {
      const screen: ScreenState = destinationFor(status, 0)

      expect(JOURNEY_STATES, status).toContain(screen)
    }

    expect(PAGER_STATUS.length).toBe(5)
  })
})

describe('the branches reached only from an empty journey', () => {
  it('accepts the first snapshot from a poll when there is no previous status', () => {
    // Reachable: a `poll/ok` can arrive before the resolve's answer has been reduced, because
    // the two are separate requests. `statusMayFollow(null, …)` must admit it rather than
    // discarding the only status we have.
    const withToken = reduce(INITIAL, { type: 'token/present', token: TOKEN })
    const polled = reduce(withToken, { type: 'poll/ok', snapshot: snapshot('ready') })

    expect(polled.snapshot?.status).toBe('ready')
    expect(polled.screen).toBe('ready.fresh')
    // And it becomes the clock's origin, because it is the first `as_of` this journey saw.
    expect(polled.scannedAt).toBe('2026-11-01T12:00:00Z')
  })

  it('refuses a SECOND resolve that moves the status backwards', () => {
    // A re-resolve after a `poll/stale` can answer from a replica that is behind. The
    // monotone guard is on `resolve/ok` as well as on `poll/ok`, which is a separate branch.
    const ready = reduce(waiting(), { type: 'poll/ok', snapshot: snapshot('ready') })
    const backwards = reduce(ready, { type: 'resolve/ok', snapshot: snapshot('waiting') })

    expect(backwards).toBe(ready)
  })

  it('keeps the original scannedAt when a later resolve carries a newer as_of', () => {
    const first = waiting()
    const again = reduce(first, {
      type: 'resolve/ok',
      snapshot: snapshot('waiting', '2026-11-01T12:30:00Z'),
    })

    expect(again.scannedAt).toBe('2026-11-01T12:00:00Z')
  })
})

describe('the exhaustiveness guard itself', () => {
  it('THROWS on an event the union does not contain', () => {
    /**
     * ⚠ THIS IS WHAT MAKES THE ABSENCE OF A `default` ARM SAFE, and it is asserted rather
     * than argued. A member added to `JourneyEvent` without a case is a type error at
     * `pnpm typecheck`; a member that reaches the reducer anyway -- across a deploy, or from
     * a cast -- throws here rather than silently returning the current state.
     *
     * A `default` returning a fallback is how a screen goes missing in production and nothing
     * fails. The cast below is the only way to reach the guard, which is the point: without a
     * cast it does not compile.
     */
    expect(() => reduce(waiting(), { type: 'nonsense/event' } as unknown as JourneyEvent)).toThrow(
      /unhandled/,
    )
  })

  it('THROWS on a status the generated union does not contain', () => {
    expect(() => destinationFor('refunded' as PagerStatus, 0)).toThrow(/unhandled/)
  })
})

describe('the state inventory as an artefact the browser suite can read', () => {
  it('matches the committed docs/contracts/journey-states.json', () => {
    /**
     * ================================================================================
     * WHY THE BROWSER SUITE READS A FILE INSTEAD OF IMPORTING THIS MODULE
     * ================================================================================
     *
     * `browser/one-handed.spec.ts` must iterate exactly these states -- *every table is
     * generated or gate-checked*, and a subject list typed into that spec is the same defect
     * one layer down. It imported `JOURNEY_STATES` from here first, and Playwright's loader
     * cannot resolve it: `@qrpager/contracts` re-exports `./generated/status.ts` with an
     * explicit `.ts` extension, and the run died with
     * `request for './generated/status.ts' is from a module not been linked`.
     *
     * So the union is emitted to a JSON artefact and byte-compared HERE, where the types are
     * real -- and the spec reads the artefact. One source, two readers, and a staleness check
     * that runs BEFORE the browser suite does in `pnpm gate`.
     */
    const path = join(
      import.meta.dirname,
      '..',
      '..',
      '..',
      'docs',
      'contracts',
      'journey-states.json',
    )
    const encoded = `${JSON.stringify(
      {
        _README: [
          'GENERATED from apps/web/src/pager/journey.ts by apps/web/test/journey.test.ts.',
          'Do not edit: that test byte-compares this file and fails when it differs.',
          '',
          'browser/one-handed.spec.ts reads it so that its subject list cannot drift from the',
          "reducer's own union -- it cannot import the module directly, because Playwright's",
          "loader cannot resolve @qrpager/contracts' explicit .ts re-export.",
        ],
        states: JOURNEY_STATES,
      },
      null,
      2,
    )}\n`

    if (!existsSync(path)) {
      writeFileSync(path, encoded)
    }

    expect(readFileSync(path, 'utf8'), `${path} is stale -- regenerate and commit it`).toBe(encoded)
  })
})

describe('every state is REACHABLE, which one of them was not', () => {
  it('has an event that produces each of the 17 screen states', () => {
    /**
     * ⚠ THIS ASSERTION EXISTS BECAUSE `error.unknown` WAS UNREACHABLE. It was in
     * `JOURNEY_STATES`, it had a screen, it had copy in both languages -- and no event
     * produced it, because `api.ts` returned `server` for a 2xx it could not read. The
     * state, the screen and six strings were dead, and `browser/one-handed.spec.ts` found it
     * by timing out at 30 s trying to reach it.
     *
     * Exhaustiveness over the event union does not catch that: it proves every EVENT is
     * handled, not that every STATE is produced. This is the other direction, and it is the
     * one a reviewer would otherwise have to check by eye.
     */
    const produced = new Set<ScreenState>()

    for (const screen of JOURNEY_STATES) {
      for (const event of ALL_EVENTS) {
        produced.add(reduce({ ...waiting(), screen }, event).screen)
      }
    }

    // Driven from every starting state, so a state reachable only from one place still counts.
    const unreachable = JOURNEY_STATES.filter((state) => !produced.has(state))

    expect(
      unreachable,
      'these states have a screen and copy and no event that produces them',
    ).toEqual([])
  })
})
