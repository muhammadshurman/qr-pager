import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { KdsApp } from '../src/KdsApp.tsx'
import { translatorFor } from '../src/copy/index.ts'
import { KDS_INTERVAL_SECONDS } from '../src/kds/resync.ts'
import type { DeviceTokenStore } from '../src/kds/storage.ts'

/**
 * ================================================================================
 * THE KITCHEN DISPLAY, ORCHESTRATED — AND 10c's INVARIANT THROUGH THE REAL COMPONENT
 * ================================================================================
 *
 * `kds-resync.test.ts` proves the RULE (`reconcile`) in isolation and `kds-api.test.ts` proves
 * the client makes no write. This file proves the two are actually WIRED to each other, which
 * is the part neither of them can see: a component that computed `reconcile(cached, fresh)` and
 * then rendered `cached` would pass both of those files.
 *
 * The three cases that carry 10c's clause here:
 *
 *   - an outage KEEPS the orders (so the kitchen can still cook) and says they are kept;
 *   - the reconnect REPLACES them, so a row the server dropped during the outage is gone and
 *     no row appears twice — asserted by counting rendered rows, which is *"the row identity
 *     is asserted, not eyeballed"* from `QRPAGER-27 §4`;
 *   - the `online` event polls IMMEDIATELY, and the request it makes is a GET.
 *
 * The server-side dispatch count across a real browser's network drop is
 * `tools/gate/bin/kds-resync-proof`. This is the jsdom half, and it is honest about which is
 * which: jsdom has no network to drop, so the outage here is a rejected `fetch` and the
 * reconnect is a dispatched `online` event. That is the mechanism, not a simulation of it —
 * `KdsApp` listens for exactly that event and `fetchKdsBoard` maps exactly that rejection.
 */

const t = translatorFor('en')

const ROW_A = {
  id: '01a00000-0000-7000-8000-00000000000a',
  order_reference: 'KDS-AAA',
  status: 'waiting',
  created_at: '2026-11-02T10:00:00Z',
  ready_at: null,
}

const ROW_B = {
  id: '01a00000-0000-7000-8000-00000000000b',
  order_reference: 'KDS-BBB',
  status: 'waiting',
  created_at: '2026-11-02T10:01:00Z',
  ready_at: null,
}

interface Call {
  readonly url: string
  readonly method: string
  readonly authorization: string | undefined
}

/** The url of a `fetch` input, whichever of the three shapes it is. See `kds-api.test.ts`. */
function requestUrl(input: string | URL | Request): string {
  if (typeof input === 'string') return input

  return input instanceof URL ? input.href : input.url
}

/**
 * A `fetch` driven by a queue of answers, with every call recorded.
 *
 * The LAST answer repeats once the queue is exhausted, so a polling component does not need a
 * queue as long as the test's fake-timer budget — and a case that depends on a specific later
 * answer pushes it explicitly rather than relying on the count of polls, which would make the
 * assertion depend on the backoff arithmetic.
 */
function harness(answers: (Response | Error)[]) {
  const calls: Call[] = []
  let index = 0

  /*
   * ⚠ TYPED AS `typeof fetch` RATHER THAN CAST TO IT, AND NOT `async` — `kds-api.test.ts`'s
   * own note has both reasons, and `requestUrl` is there for the third: `String()` on a
   * `Request` gives `[object Object]`.
   */
  const fetcher: typeof fetch = (input, init) => {
    const headers = init?.headers as Record<string, string> | undefined

    calls.push({
      url: requestUrl(input),
      method: init?.method ?? 'GET',
      authorization: headers?.Authorization,
    })

    const answer = answers[Math.min(index, answers.length - 1)]

    index += 1

    if (answer instanceof Error) return Promise.reject(answer)

    return Promise.resolve(answer ?? new Response(null, { status: 503 }))
  }

  return { calls, fetcher }
}

function board(sessions: unknown[]): Response {
  return new Response(JSON.stringify({ sessions }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** A store backed by a plain Map, so a case needs no `localStorage`. */
function memoryStore(initial?: string): { store: DeviceTokenStore; read: () => string | null } {
  const map = new Map<string, string>()

  if (initial !== undefined) map.set('qrpager.kds.token', initial)

  return {
    store: {
      storage: {
        getItem: (key) => map.get(key) ?? null,
        setItem: (key, value) => {
          map.set(key, value)
        },
        removeItem: (key) => {
          map.delete(key)
        },
      },
    },
    read: () => map.get('qrpager.kds.token') ?? null,
  }
}

describe('setting a kitchen screen up', () => {
  it('shows the setup screen when nothing is stored, and makes no request at all', async () => {
    const { calls, fetcher } = harness([board([])])
    const { store } = memoryStore()

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.setup.title'))).toBeTruthy()
    // ⚠ NO REQUEST WITHOUT A CODE. A poll with `Authorization: Bearer ` would be a 401, and
    // the screen would render a refusal the person has not caused yet.
    expect(calls).toHaveLength(0)
  })

  it('stores the pasted code, polls with it as a bearer token, and shows the orders', async () => {
    const { calls, fetcher } = harness([board([ROW_A])])
    const { store, read } = memoryStore()

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    fireEvent.change(await screen.findByLabelText(t('kds.setup.code.label')), {
      target: { value: 'the-code' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('kds.setup.submit') }))

    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(read()).toBe('the-code')
    expect(calls[0]?.authorization).toBe('Bearer the-code')
  })

  it('trims a pasted code, because the value comes off a clipboard on a tablet', async () => {
    const { calls, fetcher } = harness([board([])])
    const { store } = memoryStore()

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    fireEvent.change(await screen.findByLabelText(t('kds.setup.code.label')), {
      target: { value: '  padded  ' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('kds.setup.submit') }))

    await waitFor(() => {
      expect(calls.length).toBeGreaterThan(0)
    })
    expect(calls[0]?.authorization).toBe('Bearer padded')
  })

  it('will not submit an empty code', async () => {
    const { store } = memoryStore()
    const { fetcher } = harness([board([])])

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    const submit = await screen.findByRole('button', { name: t('kds.setup.submit') })

    // Disabled rather than submitting-and-failing: a 401 the person did not cause would be
    // reported to them as a refused code.
    expect(submit).toHaveProperty('disabled', true)
  })

  it('recalls a stored code and goes straight to the orders, never flashing setup', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('stored-code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    // The setup title must never have been rendered -- on a wall tablet a flash of a code
    // field is read by a passer-by as "somebody signed this out".
    expect(screen.queryByText(t('kds.setup.title'))).toBeNull()
  })
})

describe('a code that stops working', () => {
  it('deletes the stored code on a 401 and returns to setup, saying what to do', async () => {
    const { fetcher } = harness([new Response(null, { status: 401 })])
    const { store, read } = memoryStore('revoked-code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.setup.refused'))).toBeTruthy()
    /*
     * ⚠ THE DELETION IS THE POINT, not the screen. A device token expires at thirty days or is
     * revoked from `/staff/devices`; keeping the dead value leaves a useless credential at
     * rest on a shared kitchen device for as long as the browser keeps `localStorage`, which
     * is indefinitely.
     */
    expect(read()).toBeNull()
  })

  it('does the same on a 403, which is this surface’s own collapse of the two', async () => {
    const { fetcher } = harness([new Response(null, { status: 403 })])
    const { store, read } = memoryStore('unpinned-code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.setup.refused'))).toBeTruthy()
    expect(read()).toBeNull()
  })

  it('keeps the code on a DEAD NETWORK, which is the answer that must not look like a refusal', async () => {
    const { fetcher } = harness([new TypeError('Failed to fetch')])
    const { store, read } = memoryStore('good-code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.offline.title'))).toBeTruthy()
    // ⚠ THE CODE SURVIVES. Discarding it here would tell a kitchen their code is wrong when
    // their wifi is down -- the one answer that is certainly false, and the reason
    // `onSubmitCode` stores before verifying rather than probing first.
    expect(read()).toBe('good-code')
    expect(screen.queryByText(t('kds.setup.title'))).toBeNull()
  })
})

describe('the outage and the resync — 10c’s invariant through the component', () => {
  /*
   * ⚠ FAKE TIMERS, AND AN EXACT ADVANCE RATHER THAN A GENEROUS ONE — `staff-flows.test.tsx`'s
   * own pattern and its reason. These four cases need the poll TIMER to fire, which is the only
   * honest way to provoke a second read: there is no *Try again* button when the screen has
   * rows, because the retry affordance belongs to the failure states and those replace the
   * board only when it has nothing to show. Pressing it would test the state the case is
   * trying to leave.
   *
   * With `random` pinned at the midpoint the jitter contributes nothing, so the first delay is
   * exactly `KDS_INTERVAL_SECONDS` — the *assert exact values, never ranges* discipline
   * applied to a clock. `shouldAdvanceTime` is on so the testing-library `waitFor` polling
   * that these cases also rely on still makes progress.
   */
  afterEach(() => {
    vi.useRealTimers()
  })

  const letTheTimerFire = async (): Promise<void> => {
    await vi.advanceTimersByTimeAsync(KDS_INTERVAL_SECONDS * 1000 + 1)
  }

  it('KEEPS the orders through a failed poll and says they are the last ones reached', async () => {
    const { fetcher } = harness([board([ROW_A, ROW_B]), new TypeError('Failed to fetch')])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()

    // The outage arrives on the next tick. The rows must still be there afterwards.
    await letTheTimerFire()
    await screen.findByText(t('kds.offline.body'))

    expect(screen.getByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(screen.getByText(t('kds.order', { reference: 'KDS-BBB' }))).toBeTruthy()
  })

  it('REPLACES them on reconnect: a row the server dropped during the outage is gone, and nothing appears twice', async () => {
    /*
     * ⚠ THIS IS 10c's *shows NO duplicate row*, DRIVEN THROUGH THE REAL COMPONENT.
     *
     * Two orders, then an outage, then a reconnect whose answer carries only ONE of them —
     * which is what happens when an order is collected while the screen is offline. The
     * assertion is on the rendered ROW COUNT and on the identity of what survived, so every
     * merge shape `resync.ts` names fails it: an append renders three, and the `Map`-seeded
     * shape renders two with no duplicate id.
     */
    const { fetcher } = harness([
      board([ROW_A, ROW_B]),
      new TypeError('Failed to fetch'),
      board([ROW_A]),
    ])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-BBB' }))
    await letTheTimerFire()
    await screen.findByText(t('kds.offline.body'))

    // The browser comes back. `KdsApp` listens for this exact event.
    window.dispatchEvent(new Event('online'))

    await waitFor(() => {
      expect(screen.queryByText(t('kds.order', { reference: 'KDS-BBB' }))).toBeNull()
    })

    const rows = document.querySelectorAll('[data-kds-order]')

    // ONE row, and it is A. Counted off the DOM rather than inferred from the absence of B:
    // an append would leave B absent from a `queryByText` only if it ALSO rendered twice, and
    // counting is what tells the two apart.
    expect(rows).toHaveLength(1)
    expect(rows[0]?.getAttribute('data-kds-order')).toBe('KDS-AAA')
  })

  it('polls IMMEDIATELY on reconnect, and the request it makes is a GET', async () => {
    /*
     * ⚠ THE `online` HANDLER IS THE EXACT PLACE A RECONNECT COULD RE-SEND SOMETHING, which is
     * why `QRPAGER-27 §5` calls a naive refetch the hard half. What is asserted here is that
     * the reconnect (a) causes a request at all — without it a kitchen screen shows stale
     * orders for up to the 120-second backoff cap after the network returns — and (b) that the
     * request is a GET, because there is no queue in this client for a reconnect to drain.
     */
    const { calls, fetcher } = harness([
      board([ROW_A]),
      new TypeError('Failed to fetch'),
      board([ROW_A]),
    ])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))
    await letTheTimerFire()
    await screen.findByText(t('kds.offline.body'))

    const before = calls.length

    window.dispatchEvent(new Event('online'))

    await waitFor(() => {
      expect(calls.length).toBeGreaterThan(before)
    })

    // EVERY call this component has made, not only the reconnect's: the clause is about the
    // whole client, and a write anywhere in the sequence would break it.
    expect(calls.every((call) => call.method === 'GET')).toBe(true)
    expect(calls.every((call) => call.url === '/v1/pager-sessions')).toBe(true)
  })

  it('shows the empty state rather than stale orders when the server says the board is empty', async () => {
    // The boundary of the replace rule, and the one a "do not flicker empty" guard breaks:
    // the last order of a service is collected and the screen must go quiet.
    const { fetcher } = harness([board([ROW_A]), board([])])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))
    await letTheTimerFire()

    expect(await screen.findByText(t('kds.empty.title'))).toBeTruthy()
    expect(document.querySelectorAll('[data-kds-order]')).toHaveLength(0)
  })
})

describe('taking the screen off the wall', () => {
  it('confirms before removing the code, and marks it not reversible', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store, read } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    fireEvent.click(await screen.findByRole('button', { name: t('kds.forget') }))

    // The confirmation says what happens to the SCREEN, which is the consequence being
    // decided about -- and the code is still there until it is confirmed.
    expect(screen.getByText(t('kds.forget.body'))).toBeTruthy()
    expect(read()).toBe('code')

    fireEvent.click(screen.getByRole('button', { name: t('kds.forget.confirm') }))

    expect(await screen.findByText(t('kds.setup.title'))).toBeTruthy()
    expect(read()).toBeNull()
  })

  it('keeps showing orders when the confirmation is declined', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store, read } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    fireEvent.click(await screen.findByRole('button', { name: t('kds.forget') }))
    fireEvent.click(screen.getByRole('button', { name: t('kds.forget.keep') }))

    expect(screen.getByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(read()).toBe('code')
  })

  it('returns to setup with NO refusal message, because nothing was refused', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    fireEvent.click(await screen.findByRole('button', { name: t('kds.forget') }))
    fireEvent.click(screen.getByRole('button', { name: t('kds.forget.confirm') }))

    await screen.findByText(t('kds.setup.title'))

    // ⚠ THE TWO PATHS BACK TO SETUP MUST NOT SHARE A MESSAGE. `dropToken(true)` is a refused
    // code and says so; `dropToken(false)` is a person choosing to remove it, and telling
    // them their code "does not work any more" would be false.
    expect(screen.queryByText(t('kds.setup.refused'))).toBeNull()
  })
})

describe('what this surface is not', () => {
  it('offers no action on an order, because a device token cannot notify', async () => {
    /*
     * `Ability::issuableToDevice()` is a closed list of one (`pager:read`) and its docblock
     * says *"PagerNotify is absent because marking ready is Task 10's staff action, not the
     * screen's."* A *Tell them it is ready* button here would be a control the server refuses
     * -- a screen that lies to the person using it.
     *
     * Asserted by the ABSENCE of the staff board's own action copy in the rendered tree, so
     * this fails the moment somebody wires one of those handlers up.
     */
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))

    for (const key of [
      'staff.detail.ready',
      'staff.detail.renotify',
      'staff.detail.complete',
      'staff.detail.cancel',
    ] as const) {
      expect(screen.queryByText(t(key)), `${key} reached the kitchen screen`).toBeNull()
    }
  })

  it('renders no uuid anywhere, which is §12’s no-unexplained-identifier clause', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    const { container } = render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))

    // The whole subtree, attributes included -- `data-kds-order` carries the REFERENCE for
    // exactly this reason, so the browser proof counts something a person can also see.
    expect(container.innerHTML).not.toContain(ROW_A.id)
  })

  it('renders the Arabic catalogue and sets dir=rtl, because §12 names it a launch language', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')
    const ar = translatorFor('ar')

    const { container } = render(<KdsApp apiBase="" locale="ar" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(ar('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(container.querySelector('[dir="rtl"]')).toBeTruthy()
    expect(container.querySelector('[lang="ar"]')).toBeTruthy()
  })
})

describe('what it resolves for itself when nothing is injected', () => {
  /*
   * ⚠ EVERY OTHER CASE IN THIS FILE INJECTS `locale` AND `store`, WHICH IS RIGHT FOR THEM AND
   * LEAVES TWO EFFECTS UNDRIVEN — both of them the ones the real app actually takes. They are
   * driven here, against the real `navigator` and the real `localStorage`.
   *
   * ⚠ AND BOTH RESOLVE IN AN EFFECT RATHER THAN AT FIRST RENDER, which is the property these
   * cases protect: these routes are statically prerendered, so a direct read of `navigator` or
   * `window` during the render would be a hydration mismatch on the customer's and the
   * kitchen's first paint alike. `PagerJourney` and `StaffApp` take the same shape.
   */
  it('reads the language from the BROWSER when no locale is injected', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    // `ar-JO` rather than a bare `ar`: real devices send a region subtag, and `browserLocale`
    // matches on the PRIMARY one for exactly that reason.
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['ar-JO', 'en'])

    const { container } = render(<KdsApp apiBase="" store={store} fetcher={fetcher} />)

    const ar = translatorFor('ar')

    expect(await screen.findByText(ar('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(container.querySelector('[dir="rtl"]')).toBeTruthy()

    vi.restoreAllMocks()
  })

  it('falls back to English when the browser offers nothing it knows', async () => {
    const { fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['fr-FR'])

    const { container } = render(<KdsApp apiBase="" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(container.querySelector('[dir="ltr"]')).toBeTruthy()

    vi.restoreAllMocks()
  })

  it('reads the real localStorage when no store is injected', async () => {
    const { fetcher } = harness([board([ROW_A])])

    window.localStorage.setItem('qrpager.kds.token', 'real-code')

    try {
      render(<KdsApp apiBase="" locale="en" fetcher={fetcher} />)

      expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    } finally {
      // Removed in a `finally`, so a failing assertion cannot leak a credential-shaped value
      // into the next case's `localStorage` -- which would make that case pass for the wrong
      // reason and this one hard to re-run.
      window.localStorage.removeItem('qrpager.kds.token')
    }
  })

  it('shows setup when the real localStorage holds nothing', async () => {
    const { calls, fetcher } = harness([board([])])

    window.localStorage.removeItem('qrpager.kds.token')

    render(<KdsApp apiBase="" locale="en" fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.setup.title'))).toBeTruthy()
    expect(calls).toHaveLength(0)
  })
})

describe('a throttled kitchen screen — R-224 on a third surface', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the throttle copy and keeps the code, because a 429 is not a refusal', async () => {
    /*
     * ⚠ `R-224`'s WHOLE POINT, ON THE SURFACE IT MATTERS MOST. That row exists because a 429
     * fell through to `{kind:'server'}` and a throttled staff screen read *"Something went
     * wrong on our side. Nothing you did caused it."* — both halves false under a rate limit.
     * `kds/api.ts` has its own `throttled` kind for the same reason, and the copy says the
     * screen will refresh itself because it will.
     *
     * The code is NOT dropped: a throttle says nothing about whether the credential is valid,
     * and discarding it would send somebody to find a manager over a rate limit.
     */
    const { fetcher } = harness([
      new Response(null, { status: 429, headers: { 'Retry-After': '30' } }),
    ])
    const { store, read } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    expect(await screen.findByText(t('kds.throttled.title'))).toBeTruthy()
    expect(screen.getByText(t('kds.throttled.body'))).toBeTruthy()
    expect(read()).toBe('code')
    // And no action is offered: the screen resumes itself, so a button would be a second 429.
    expect(screen.queryByRole('button', { name: t('kds.retry') })).toBeNull()
  })

  it('honours the server’s own Retry-After rather than its own doubling', async () => {
    /*
     * A server that is already refusing knows better than a client's arithmetic.
     * `delayFromAnswerMs` takes the hint, jitters it and caps it — so with `random` pinned at
     * the midpoint the next poll is exactly 30 s away, and nothing happens before then.
     */
    const { calls, fetcher } = harness([
      new Response(null, { status: 429, headers: { 'Retry-After': '30' } }),
      board([ROW_A]),
    ])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    await screen.findByText(t('kds.throttled.title'))

    const afterFirst = calls.length

    // One second short of the hint: nothing more is asked.
    await vi.advanceTimersByTimeAsync(29_000)
    expect(calls.length).toBe(afterFirst)

    // And past it: the screen resumes itself, which is what the copy promised.
    await vi.advanceTimersByTimeAsync(2_000)
    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
  })

  it('maps a 5xx to the error state, which is a fault rather than a throttle', async () => {
    const { fetcher } = harness([new Response(null, { status: 503 })])
    const { store } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    expect(await screen.findByText(t('kds.error.title'))).toBeTruthy()
    expect(screen.getByRole('button', { name: t('kds.retry') })).toBeTruthy()
  })
})

describe('the retry affordance and the default fetcher', () => {
  it('polls immediately when the empty state’s action is pressed', async () => {
    // `§12` requires an empty state to offer a next action that WORKS. Checking now is a real
    // one -- it polls rather than waiting for the tick -- so it is driven rather than assumed.
    const { calls, fetcher } = harness([board([]), board([ROW_A])])
    const { store } = memoryStore('code')

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    await screen.findByText(t('kds.empty.title'))

    const before = calls.length

    fireEvent.click(screen.getByRole('button', { name: t('kds.empty.action') }))

    expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    expect(calls.length).toBeGreaterThan(before)
  })

  it('uses the GLOBAL fetch when none is injected, which is what the real app does', async () => {
    /*
     * ⚠ EVERY OTHER CASE INJECTS A FETCHER, SO THE `?? fetch` FALLBACK WENT UNDRIVEN — and it
     * is the only path production takes. A default that no test exercises is a default whose
     * first run is on a wall in a kitchen.
     */
    const { store } = memoryStore('code')

    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve({
          status: 200,
          ok: true,
          json: () => Promise.resolve({ sessions: [ROW_A] }),
          headers: { get: () => null },
        } as unknown as Response),
      ),
    )

    try {
      render(<KdsApp apiBase="" locale="en" store={store} />)

      expect(await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
    } finally {
      vi.unstubAllGlobals()
    }
  })
})

describe('the staleness marker', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('says when the rows were last confirmed once they are older than one interval', async () => {
    /*
     * ⚠ COMPUTED FROM A TIMESTAMP AND RECOMPUTED ON EVERY ATTEMPT, which is `§11` QA H-1's
     * lesson from the staff board: a memo whose dependencies only change on SUCCESS can never
     * fire, because at the instant of success `confirmedAt === now`. `attemptedAt` moves on
     * every attempt including a failed one, which is both the trigger and the honest clock.
     */
    const { fetcher } = harness([board([ROW_A]), new TypeError('Failed to fetch')])
    const { store } = memoryStore('code')

    vi.useFakeTimers({ shouldAdvanceTime: true })
    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />)

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))

    /*
     * ⚠ **TWO FAILED POLLS, NOT ONE, AND THE OFF-BY-ONE IS THE REASON — it cost a red.**
     *
     * The first attempt advanced by `interval + 1` and expected the marker. It does not
     * appear: with `random` pinned at the midpoint the jitter contributes nothing, so the next
     * poll fires at exactly `T0 + interval` and sets `attemptedAt` there. `isKdsStale` is a
     * STRICT comparison — `now - confirmed > interval` — so a gap of exactly one interval is
     * not stale, which is correct: the rows are precisely as old as the cadence promises.
     *
     * The SECOND failed poll is the first one that makes them older than that, and it comes
     * after the backoff has doubled to 10 s. Advancing past both is what reaches the state.
     */
    await vi.advanceTimersByTimeAsync(KDS_INTERVAL_SECONDS * 1000 + 1)
    await vi.advanceTimersByTimeAsync(KDS_INTERVAL_SECONDS * 2 * 1000 + 1)

    const marker = await screen.findByText(/Last updated/)

    expect(marker.getAttribute('data-kds-stale')).toBe('true')
    // And the rows are still there, which is what makes the marker a kindness rather than an
    // excuse for having blanked them.
    expect(screen.getByText(t('kds.order', { reference: 'KDS-AAA' }))).toBeTruthy()
  })
})

describe('the poll loop', () => {
  it('stops when the component unmounts, so a superseded run cannot resurrect itself', async () => {
    const { calls, fetcher } = harness([board([ROW_A])])
    const { store } = memoryStore('code')

    const view = render(
      <KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} random={() => 0.5} />,
    )

    await screen.findByText(t('kds.order', { reference: 'KDS-AAA' }))

    view.unmount()

    const after = calls.length

    // `pollRunRef` is bumped on teardown as well as the timer cleared, so an answer already in
    // flight checks it before arming the next one. Given a moment, nothing further is asked.
    await new Promise((resolve) => {
      setTimeout(resolve, 50)
    })

    expect(calls.length).toBe(after)
  })

  it('does not listen for `online` before a code is stored', async () => {
    const add = vi.spyOn(window, 'addEventListener')
    const { fetcher } = harness([board([])])
    const { store } = memoryStore()

    render(<KdsApp apiBase="" locale="en" store={store} fetcher={fetcher} />)

    await screen.findByText(t('kds.setup.title'))

    // A reconnect with no credential has nothing to poll, so the listener is not armed --
    // which is what makes the "no request without a code" case above hold under a flaky
    // network rather than only on a quiet one.
    expect(add.mock.calls.filter(([event]) => event === 'online')).toHaveLength(0)

    add.mockRestore()
  })
})
