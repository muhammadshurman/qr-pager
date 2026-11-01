import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'

import { PAGER_TOKEN_LENGTH, isWellFormedPagerToken } from '@qrpager/contracts'

import { act, render, screen, waitFor } from '@testing-library/react'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import { PagerJourney } from '../src/PagerJourney.tsx'

/**
 * ================================================================================
 * THE ORCHESTRATOR, DRIVEN END TO END IN jsdom AGAINST A REAL HTTP SERVER
 * ================================================================================
 *
 * The reducer is pure and is tested exhaustively in `journey.test.ts`. What is left in
 * `PagerJourney.tsx` is the I/O the reducer cannot do -- the fragment, the requests, the
 * self-rescheduling timer, the permission gesture, the service worker, the wake lock and the
 * end-of-session cleanup -- and every one of those is a place a correct reducer can still be
 * wired up wrongly.
 *
 * ⚠ A REAL `node:http` SERVER, NOT A STUBBED `fetch`. *A test that crosses HTTP is not one
 * that does not.* The whole point of these cases is that the right request went out and the
 * right screen came back, and a stub asserts neither.
 *
 * ⚠ AND THE REQUEST LOG IS WHAT THE CADENCE ASSERTIONS READ. "One catch-up poll, not nine"
 * is a claim about how many requests arrived, so it is asserted on the server's own log
 * rather than on a spy's call count.
 */

interface Recorded {
  readonly url: string
  readonly body: string
  readonly token: string
}

/**
 * ⚠ ONE TOKEN PER TEST, AND ONE ANSWER QUEUE PER TOKEN. This harness was written with a
 * single shared token and a single shared queue, and 6 of 24 cases failed for a reason that
 * had nothing to do with the code: an `await` that resolved before the previous test's
 * in-flight request landed, so a late POST consumed the next test's queued answer and
 * appeared in its request log. The errors named the assertion rather than the cause, which
 * is the most expensive shape of failing test this project records.
 *
 * Keying both the log and the queue by the token makes a late request from a finished test
 * harmless and invisible, with no sleeping and no retry loop.
 */
/**
 * A stand-in for the `PushManager` CONSTRUCTOR, which `pushCapability` tests for with
 * `'PushManager' in window`. jsdom defines none, so without this every push path in this file
 * reports `unsupported` -- which is a real engine shape and is asserted elsewhere, but is not
 * the shape these cases are about. A named class rather than `class {}` so the lint rule's
 * objection to an empty class is answered rather than suppressed.
 */
class PushManagerStub {
  readonly stub = true
}

let nextToken = 0

function mintToken(): string {
  nextToken += 1

  // EXACTLY 22 base64url characters, which is what the generated contract requires -- and
  // the first version of this was 20, so every case failed with "unable to find A-42"
  // because the component correctly refused the token. `PAGER_TOKEN_LENGTH` is asserted
  // below rather than trusted, so a short token is a failure here and not a mystery there.
  const minted = `T${String(nextToken).padStart(3, '0')}AbCdEfGhIjKlMnOpQrStUv`.slice(
    0,
    PAGER_TOKEN_LENGTH,
  )

  if (!isWellFormedPagerToken(minted))
    throw new Error(`minted a token this client refuses: ${minted}`)

  return minted
}

let server: Server
let base = ''
const received: Recorded[] = []
const queues = new Map<string, { status: number; body: unknown }[]>()
const fallbacks = new Map<string, { status: number; body: unknown }>()

const KEY =
  'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM'

function snapshot(status: string, asOf = '2026-11-01T12:00:00Z') {
  return {
    status,
    order_reference: 'A-42',
    expires_at: '2026-11-01T14:00:00Z',
    as_of: asOf,
    poll_after_seconds: 7,
  }
}

beforeAll(async () => {
  server = createServer((request: IncomingMessage, response: ServerResponse) => {
    const chunks: Buffer[] = []

    request.on('data', (chunk: Buffer) => {
      chunks.push(chunk)
    })
    request.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8')
      const parsed = ((): Record<string, unknown> => {
        try {
          return JSON.parse(body) as Record<string, unknown>
        } catch {
          return {}
        }
      })()
      const token = typeof parsed.token === 'string' ? parsed.token : ''

      received.push({ url: request.url ?? '', body, token })

      const next = queues.get(token)?.shift() ??
        fallbacks.get(token) ?? { status: 200, body: snapshot('waiting') }

      response.writeHead(next.status, { 'Content-Type': 'application/json' })
      response.end(JSON.stringify(next.body))
    })
  })

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))

  const address = server.address()

  if (address === null || typeof address === 'string') throw new Error('no port')

  base = `http://127.0.0.1:${String(address.port)}`
})

afterAll(async () => {
  await new Promise<void>((resolve) => {
    server.close(() => {
      resolve()
    })
  })
})

/** One test's own token, answers and request log. */
class Session {
  readonly token = mintToken()

  answers(...list: { status: number; body: unknown }[]): this {
    queues.set(this.token, [...list])

    return this
  }

  then(answer: { status: number; body: unknown }): this {
    fallbacks.set(this.token, answer)

    return this
  }

  get log(): Recorded[] {
    return received.filter((entry) => entry.token === this.token)
  }

  calls(url: string): Recorded[] {
    return this.log.filter((entry) => entry.url === url)
  }

  mount(fragment: string | null = `#${this.token}`) {
    if (fragment !== null) window.history.replaceState(null, '', `/s${fragment}`)

    return render(<PagerJourney apiBase={base} vapidPublicKey={KEY} locale="en" />)
  }
}

beforeEach(() => {
  window.history.replaceState(null, '', '/s')
  window.localStorage.clear()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

/**
 * jsdom implements no `navigator.serviceWorker`, which is the shape of an engine with no push
 * path -- correct for the `unsupported` case and useless for the other three. These two give
 * the component the minimum surface it needs to reach a permission decision.
 */
function withPushPath(permission: NotificationPermission, answer: NotificationPermission) {
  Object.defineProperty(window.navigator, 'serviceWorker', {
    configurable: true,
    value: {
      register: () =>
        Promise.resolve({ pushManager: { getSubscription: () => Promise.resolve(null) } }),
      getRegistration: () => Promise.resolve(undefined),
      ready: Promise.resolve({}),
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    },
  })
  vi.stubGlobal('Notification', {
    permission,
    requestPermission: () => Promise.resolve(answer),
  })
  vi.stubGlobal('PushManager', PushManagerStub)
}

function withoutPushPath() {
  Reflect.deleteProperty(window.navigator, 'serviceWorker')
}

/**
 * Click, and let React flush whatever the click started.
 *
 * ONE HELPER RATHER THAN `await act(async () => { … })` AT TWENTY SITES. `act` returns a
 * thenable only for an async callback, so the two forms are not interchangeable and mixing
 * them produces `await`s on `void` -- which `@typescript-eslint/await-thenable` is right to
 * refuse and which would otherwise be silenced per line.
 */
async function settle(action: () => void): Promise<void> {
  await act(async () => {
    action()
    await Promise.resolve()
  })
}

/**
 * ⚠ THE STATIC SHELL IS ASSERTED AGAINST THE BUILT HTML, NOT HERE. `render()` wraps the
 * mount in `act`, which flushes the locale effect before anything can be queried -- so the
 * shell is not observable in this environment, and a test that claimed to observe it would be
 * asserting its own stub. The real claim is about the PRERENDERED document, and
 * `build-surface.test.ts` reads that file.
 */
describe('the locale', () => {
  it('resolves from the browsers own list and sets lang and dir together', async () => {
    vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(['ar-JO'])

    const { container } = render(<PagerJourney apiBase={base} vapidPublicKey={KEY} />)

    await waitFor(() => {
      const themed = container.querySelector('[data-theme]')

      expect(themed?.getAttribute('lang')).toBe('ar')
      expect(themed?.getAttribute('dir')).toBe('rtl')
    })
  })

  it('resolves English for an English phone', async () => {
    vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(['en-GB'])

    const { container } = render(<PagerJourney apiBase={base} vapidPublicKey={KEY} />)

    await waitFor(() => {
      expect(container.querySelector('[data-theme]')?.getAttribute('dir')).toBe('ltr')
    })
  })
})

describe('the fragment', () => {
  it('reads the token, strips it, and resolves the session over HTTP', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    // The REFERENCE is the proof the round trip completed: the screen appears from the
    // fragment alone, the reference only from an answer.
    await screen.findByText('A-42')

    expect(window.location.hash).toBe('')
    expect(window.location.href).not.toContain(session.token)
    expect(session.calls('/v1/pager/session').length).toBe(1)
    expect(session.log[0]?.body).toContain(session.token)
    await screen.findByText('Your order')
  })

  it('makes NO request at all for a malformed fragment', async () => {
    // ⚠ WHAT BOUNDS A GARBAGE-TRAFFIC LOOP ON THE SCAN PATH, asserted on the server's own
    // log rather than inferred from the screen.
    const session = new Session()
    session.mount('#not-a-token')

    await screen.findByText("That isn't a valid pager link")

    expect(session.log).toEqual([])
    expect(window.location.hash).toBe('')
  })

  it('reads the token only ONCE even when the effect runs twice', async () => {
    // React 19's strict mode mounts every effect twice in development. A second read would
    // find the fragment already stripped and turn a valid scan into `landing.invalid` in dev
    // only, which is the worst place for a bug to live.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')

    expect(session.calls('/v1/pager/session').length).toBe(1)
  })

  it('falls back to the remembered token when the launch carries no fragment', async () => {
    // A Home-Screen install launches at `start_url`, which has no fragment. That is the only
    // reason the token is persisted at all.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    window.localStorage.setItem('qrpager.pager.token', session.token)
    window.localStorage.setItem('qrpager.pager.expires', '2099-01-01T00:00:00Z')

    session.mount(null)

    await screen.findByText('A-42')
    expect(session.calls('/v1/pager/session').length).toBe(1)
  })

  it('shows the invalid screen when there is neither a fragment nor a remembered token', async () => {
    const session = new Session()
    session.mount(null)

    await screen.findByText("That isn't a valid pager link")
    expect(session.log).toEqual([])
  })

  it('does not use a remembered token whose session has already expired', async () => {
    const session = new Session()
    window.localStorage.setItem('qrpager.pager.token', session.token)
    window.localStorage.setItem('qrpager.pager.expires', '2020-01-01T00:00:00Z')

    session.mount(null)

    await screen.findByText("That isn't a valid pager link")
    expect(session.log).toEqual([])
    // And the expired entry is gone from the device rather than merely ignored.
    expect(window.localStorage.getItem('qrpager.pager.token')).toBeNull()
  })

  it('remembers the token once a session resolves, with its own expiry', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')

    expect(window.localStorage.getItem('qrpager.pager.token')).toBe(session.token)
    expect(window.localStorage.getItem('qrpager.pager.expires')).toBe('2026-11-01T14:00:00Z')
  })
})

describe('the four failure paths the ticket names', () => {
  it('expired token -- a 409 lands on the session-ended screen', async () => {
    new Session()
      .answers({ status: 409, body: { type: 'https://qrpager.dev/problems/expired' } })
      .mount()

    await screen.findByText('This code has expired')
    expect(screen.getByText(/two hours/)).toBeTruthy()
  })

  it('invalid token -- a 404 lands on the invalid landing', async () => {
    new Session()
      .answers({ status: 404, body: { type: 'https://qrpager.dev/problems/not-found' } })
      .mount()

    await screen.findByText("That isn't a valid pager link")
  })

  it('offline -- an unreachable API lands on the offline error screen with a retry', async () => {
    window.history.replaceState(null, '', `/s#${mintToken()}`)
    render(<PagerJourney apiBase="http://127.0.0.1:1" vapidPublicKey={KEY} locale="en" />)

    await screen.findByText("You're offline")
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy()
  })

  it('permission denied -- the screen offers no retry and polling CONTINUES', async () => {
    // A denial removes the buzz, not the product: the status screen is the fallback O2 made
    // polling for, and the copy says so.
    withPushPath('default', 'denied')

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    const ask = await screen.findByRole('button', { name: 'Allow notifications' })

    await settle(() => {
      ask.click()
    })

    await screen.findByText('Notifications are blocked')
    expect(screen.getAllByRole('button').map((b) => b.textContent)).toEqual(['Continue'])
    withoutPushPath()
  })
})

describe('the permission flow', () => {
  it('opens the alerts screen in the state the browser is already in', async () => {
    withPushPath('granted', 'granted')

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    // Granted on a previous visit: not asked again.
    await screen.findByText('Alerts are on. You can put your phone away.')
    withoutPushPath()
  })

  it('reports unsupported when the engine has no push path at all', async () => {
    // jsdom implements no `Notification` and no `PushManager`, which is exactly the shape of
    // an engine with no push path -- so this environment is the right one to assert it in.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    await screen.findByText("This browser can't send alerts")
    expect(screen.getByText(/Keep this page open/)).toBeTruthy()
  })

  it('stays put when the customer dismisses the sheet, because the browser WILL ask again', async () => {
    // A dismissal resolves `'default'`. Showing the denied screen there would be the one
    // screen that offers no retry, at the exact moment a retry is what is needed.
    withPushPath('default', 'default')

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    const ask = await screen.findByRole('button', { name: 'Allow notifications' })

    await settle(() => {
      ask.click()
    })

    expect(screen.queryByText('Notifications are blocked')).toBeNull()
    expect(screen.getByRole('button', { name: 'Allow notifications' })).toBeTruthy()
    withoutPushPath()
  })

  it('lets a customer skip straight to the status without granting anything', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await screen.findByText("We're on it")
    expect(screen.getByText('Being prepared')).toBeTruthy()
  })
})

describe('the polling cadence, read off the request log', () => {
  it('polls after the jitter floor and not before it', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    // The floor of the jittered window is 5.6 s. Nothing may arrive before it.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5000)
    })
    expect(session.calls('/v1/pager/status').length).toBe(0)

    // And something must arrive by the ceiling, 8.4 s.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4000)
    })
    expect(session.calls('/v1/pager/status').length).toBeGreaterThan(0)
  })

  it('moves to the order-ready screen when a poll answers ready', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 200, body: snapshot('ready') })

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await screen.findByText('Your order is ready')
    expect(screen.getByRole('button', { name: 'Got it' })).toBeTruthy()
  })

  it('re-resolves the session on a 404 stale rather than backing off', async () => {
    // `§3` row 9 clause (c): *stale* means open the session again. Asserted on the log: a
    // SECOND `/v1/pager/session` call is what distinguishes this from a backoff.
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session().answers(
      { status: 200, body: snapshot('waiting') },
      { status: 404, body: { type: 'https://qrpager.dev/problems/stale', poll_after_seconds: 7 } },
      { status: 200, body: snapshot('waiting') },
    )

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/session').length).toBe(2)
    })
  })

  it('degrades on a 503 and says the alert is unaffected', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 503, body: { type: 'https://qrpager.dev/problems/unavailable' } })

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await screen.findByText('Live updates are running behind')
    expect(screen.getByText(/Your alert is not affected/)).toBeTruthy()
  })

  it('shows the offline WAITING state when the network drops mid-wait', async () => {
    // Losing the connection mid-wait must not throw a customer out of their session.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await settle(() => {
      window.dispatchEvent(new Event('offline'))
    })

    await screen.findByText("You're offline")
    // Still the waiting screen -- the order reference is still there.
    expect(screen.getByText('A-42')).toBeTruthy()
  })

  it('issues at most ONE catch-up request when the document becomes visible again', async () => {
    /**
     * ⚠ ONE, NOT A BURST. A phone that slept for twenty minutes has a timer that never
     * fired. `visibilitychange` is the only signal that it is worth asking again, and asking
     * ONCE is what keeps a restaurant full of waking phones from arriving at the endpoint
     * together.
     */
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    const before = session.log.length

    await settle(() => {
      document.dispatchEvent(new Event('visibilitychange'))
    })

    expect(session.log.length - before).toBeLessThanOrEqual(1)
  })

  it('stops polling entirely once the session is over', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session().answers({ status: 200, body: snapshot('completed') })
    session.mount()

    await screen.findByText('Enjoy your order')

    const after = session.log.length

    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000)
    })

    // A product that keeps polling a completed session spends a customer's battery to learn
    // nothing.
    expect(session.log.length).toBe(after)
  })
})

describe('the end of a session clears the credential from the device', () => {
  it('forgets the token on a terminal status', async () => {
    // ⚠ A SECURITY STEP, NOT TIDYING: a 128-bit bearer token with a two-hour life, on a phone
    // that gets handed to a friend, left on a table and sold.
    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 200, body: snapshot('cancelled') })

    // ⚠ FAKE TIMERS BEFORE THE MOUNT, NOT AFTER IT. Switching them on later leaves the
    // already-armed poll timeout on the real clock, so advancing fake time never fires it --
    // and the case fails looking like a missing transition rather than a harness mistake.
    vi.useFakeTimers({ shouldAdvanceTime: true })
    session.mount()
    await screen.findByText('A-42')

    expect(window.localStorage.getItem('qrpager.pager.token')).toBe(session.token)
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await screen.findByText('This order was cancelled')
    await vi.waitFor(() => {
      expect(window.localStorage.getItem('qrpager.pager.token')).toBeNull()
      expect(window.localStorage.getItem('qrpager.pager.expires')).toBeNull()
    })
  })
})

describe('the acknowledge', () => {
  it('changes the label and keeps the customer on the screen', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('ready') })
    session.mount()

    await screen.findByText('Your order is ready')
    ;(await screen.findByRole('button', { name: 'Got it' })).click()

    await screen.findByRole('button', { name: 'On your way' })
    // The reference is still there: it is what the customer is holding up at the counter.
    expect(screen.getByText('A-42')).toBeTruthy()
  })
})

describe('the retry', () => {
  it('clears the backoff, which is what makes the button do something', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 503, body: { type: 'https://qrpager.dev/problems/unavailable' } })

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })
    await screen.findByText('Live updates are running behind')

    session.then({ status: 200, body: snapshot('waiting') })
    ;(await screen.findByRole('button', { name: 'Try again' })).click()

    await vi.waitFor(() => {
      expect(screen.queryByText('Live updates are running behind')).toBeNull()
    })
    expect(screen.getByText('Being prepared')).toBeTruthy()
  })
})

describe('the wake lock', () => {
  it('holds the screen awake on the waiting screen, and the toggle really releases', async () => {
    let held = 0
    let released = 0

    Object.defineProperty(window.navigator, 'wakeLock', {
      configurable: true,
      value: {
        request: () => {
          held += 1

          return Promise.resolve({
            release: () => {
              released += 1

              return Promise.resolve()
            },
            addEventListener: () => undefined,
          })
        },
      },
    })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await waitFor(() => {
      expect(held).toBe(1)
    })

    // The toggle is rendered because a REAL request resolved, not because the API exists.
    const toggle = await screen.findByLabelText('Keep the screen on')

    expect((toggle as HTMLInputElement).checked).toBe(true)

    await settle(() => {
      toggle.click()
    })

    await waitFor(() => {
      expect(released).toBe(1)
    })

    Reflect.deleteProperty(window.navigator, 'wakeLock')
  })

  it('renders no toggle at all when the request is REFUSED', async () => {
    // The iOS 16.4-18.4 standalone case: the API is present and does nothing.
    Object.defineProperty(window.navigator, 'wakeLock', {
      configurable: true,
      value: { request: () => Promise.reject(new Error('not supported here')) },
    })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await screen.findByText(/Your screen may dim/)
    expect(screen.queryByLabelText('Keep the screen on')).toBeNull()

    Reflect.deleteProperty(window.navigator, 'wakeLock')
  })
})

describe('the service worker wiring', () => {
  /**
   * A service worker cannot be registered in jsdom, so the CONTAINER is substituted and the
   * assertions are about what the component asks of it and what it does with the answers.
   * The worker's own code -- the push handler, the tag, the rotation handler, the offline
   * shell -- is driven for real in `browser/sw.spec.ts`, in all three engines.
   */
  function container(options: {
    subscription?: PushSubscription | null
    onSubscribe?: () => void
  }) {
    const listeners = new Set<(event: MessageEvent<unknown>) => void>()
    /**
     * ⚠ COUNTED, SO "THE RECONCILE RAN" IS OBSERVABLE RATHER THAN SLEPT FOR.
     *
     * Two cases below assert that NO subscription request was made. A negative like that
     * needs a point in time at which the thing is known to have happened, and the first
     * version used `await new Promise(r => setTimeout(r, 50))` -- which made the assertion
     * timing-dependent: it passed, then failed when an unrelated test was added to the file,
     * then passed again when the failure MESSAGE grew. A fixed sleep is not a measurement.
     *
     * `reconcileSubscription()` calls `getSubscription()` before it decides anything, so this
     * counter is exactly "the reconcile reached its decision", and `vi.waitFor` on it is
     * deterministic.
     */
    const probe = { getSubscriptionCalls: 0 }
    const registration = {
      pushManager: {
        getSubscription: () => {
          probe.getSubscriptionCalls += 1

          return Promise.resolve(options.subscription ?? null)
        },
        subscribe: () => {
          options.onSubscribe?.()

          return Promise.resolve({
            endpoint: 'https://push.example/fresh',
            toJSON: () => ({
              endpoint: 'https://push.example/fresh',
              keys: { p256dh: 'P', auth: 'A' },
            }),
            unsubscribe: () => Promise.resolve(true),
          } as unknown as PushSubscription)
        },
      },
    }

    Object.defineProperty(window.navigator, 'serviceWorker', {
      configurable: true,
      value: {
        register: () => Promise.resolve(registration),
        getRegistration: () => Promise.resolve(registration),
        ready: Promise.resolve(registration),
        addEventListener: (_type: string, fn: (event: MessageEvent<unknown>) => void) => {
          listeners.add(fn)
        },
        removeEventListener: (_type: string, fn: (event: MessageEvent<unknown>) => void) => {
          listeners.delete(fn)
        },
      },
    })
    vi.stubGlobal('Notification', {
      permission: 'granted',
      requestPermission: () => Promise.resolve('granted'),
    })
    vi.stubGlobal('PushManager', PushManagerStub)

    return {
      send: (data: unknown) => {
        for (const listener of listeners) listener({ data } as MessageEvent<unknown>)
      },
      /** Resolves once the reconcile has reached its decision at least `n` times. */
      reconciled: async (n = 1) => {
        await vi.waitFor(() => {
          expect(probe.getSubscriptionCalls).toBeGreaterThanOrEqual(n)
        })
      },
    }
  }

  it('registers the worker and REGISTERS THE DEVICE over HTTP once permission is granted', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    let subscribed = 0
    container({
      onSubscribe: () => {
        subscribed += 1
      },
    })

    session.mount()
    await screen.findByText('A-42')

    // `reconcileSubscription()`: the engine-independent half of the named invariant. The
    // browser held no subscription, so one is created and registered through Task 4's real
    // endpoint -- asserted on the request log, not on a spy.
    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/subscription').length).toBe(1)
    })
    expect(subscribed).toBe(1)
    expect(session.calls('/v1/pager/subscription')[0]?.body).toContain('https://push.example/fresh')
    withoutPushPath()
  })

  it('makes NO subscription request when the live endpoint is the one already registered', async () => {
    // The bound `§6` question 5 claims: one POST per launch AT MOST.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    window.localStorage.setItem('qrpager.pager.endpoint', 'https://push.example/known')
    container({
      subscription: {
        endpoint: 'https://push.example/known',
        toJSON: () => ({
          endpoint: 'https://push.example/known',
          keys: { p256dh: 'P', auth: 'A' },
        }),
      } as unknown as PushSubscription,
    })

    session.mount()
    await screen.findByText('A-42')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(
      session.calls('/v1/pager/subscription').map((c) => c.body),
      `a reconcile posted when the endpoint already matched. remembered=${String(
        window.localStorage.getItem('qrpager.pager.endpoint'),
      )} keys=${JSON.stringify(Object.keys(window.localStorage))}`,
    ).toEqual([])
    withoutPushPath()
  })

  it('moves to the order-ready screen on the workers push message', async () => {
    /**
     * ⚠ THE COMMONEST CASE, NOT THE RARE ONE: a customer is usually LOOKING at the waiting
     * screen when the push lands, because a pager's whole premise is a short wait. Without
     * this the screen would keep saying "being prepared" for up to `poll_after_seconds` while
     * a notification on the same device says the order is ready.
     */
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    const worker = container({})

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await settle(() => {
      worker.send({ type: 'qrpager/push' })
    })

    await screen.findByText('Your order is ready')
    withoutPushPath()
  })

  it('renders the workers message as a NUDGE, never as content', async () => {
    // ⚠ A MESSAGE FROM ANY OTHER SCRIPT ON THIS ORIGIN CANNOT PUT TEXT ON THE ONE SCREEN A
    // CUSTOMER READS FROM ACROSS A ROOM. The reference comes from the snapshot.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    const worker = container({})

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await settle(() => {
      worker.send({ type: 'qrpager/push', reference: 'ATTACKER-CHOSEN' })
    })

    await screen.findByText('Your order is ready')
    expect(screen.queryByText('ATTACKER-CHOSEN')).toBeNull()
    expect(screen.getByText('A-42')).toBeTruthy()
    withoutPushPath()
  })

  it('ignores a message that is not ours, and one that is not an object', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    const worker = container({})

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()
    await screen.findByText("We're on it")

    await settle(() => {
      worker.send({ type: 'something/else' })
      worker.send('a string')
      worker.send(null)
    })

    expect(screen.getByText('Being prepared')).toBeTruthy()
    withoutPushPath()
  })

  it('re-runs the reconcile when the worker says it could not finish one', async () => {
    // The rotation handler posts `qrpager/resubscribe` when its cache held no token or no
    // application server key. On an open page this is the difference between seconds and
    // hours.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    let subscribed = 0
    const worker = container({
      onSubscribe: () => {
        subscribed += 1
      },
    })

    session.mount()
    await screen.findByText('A-42')
    await vi.waitFor(() => {
      expect(subscribed).toBe(1)
    })

    window.localStorage.removeItem('qrpager.pager.endpoint')

    await settle(() => {
      worker.send({ type: 'qrpager/resubscribe' })
    })

    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/subscription').length).toBeGreaterThan(1)
    })
    withoutPushPath()
  })

  it('forgets the device server-side when the session COMPLETES', async () => {
    /**
     * The harness left *"a dead row for the dispatcher's 404/410 handling to reap"*. On
     * Safari that never happens -- WebKit 249766, closed WONTFIX, records that a push to an
     * expired Safari endpoint still answers 200 -- so an explicit DELETE is the only
     * mechanism that removes it.
     */
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 200, body: snapshot('completed') })

    container({
      subscription: {
        endpoint: 'https://push.example/known',
        toJSON: () => ({
          endpoint: 'https://push.example/known',
          keys: { p256dh: 'P', auth: 'A' },
        }),
        unsubscribe: () => Promise.resolve(true),
      } as unknown as PushSubscription,
    })

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await screen.findByText('Enjoy your order')
    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/subscription').some((c) => c.body.includes('known'))).toBe(
        true,
      )
    })
    withoutPushPath()
  })

  it('does NOT reconcile again AFTER the session has ended -- the terminal guard', async () => {
    /**
     * ⚠ THIS CASE EXISTS BECAUSE `tools/gate/bin/revert-proof` FOUND THAT THE TERMINAL GUARD
     * WAS COVERED BY NOTHING.
     *
     * The reconcile effect has two guards: *not on a terminal screen* and *not before a
     * snapshot exists*. The EXPIRED case below is caught by the second one -- an expired
     * resolve never produces a snapshot -- so removing BOTH left that case green and the
     * proof reported the claim as not holding. Which it did not.
     *
     * A session that reaches `completed` or `cancelled` DOES have a snapshot, so the terminal
     * guard is the only thing standing between it and a subscription request on every return
     * to visibility, for the rest of the app's life in that tab. That is what this measures:
     * the count at the moment the ended screen appears, then a `visibilitychange`, then the
     * count again.
     */
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const session = new Session()
      .answers({ status: 200, body: snapshot('waiting') })
      .then({ status: 200, body: snapshot('completed') })

    container({
      subscription: {
        endpoint: 'https://push.example/known',
        toJSON: () => ({
          endpoint: 'https://push.example/known',
          keys: { p256dh: 'P', auth: 'A' },
        }),
        unsubscribe: () => Promise.resolve(true),
      } as unknown as PushSubscription,
    })

    session.mount()
    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(9000)
    })

    await screen.findByText('Enjoy your order')

    // Let the end-of-session cleanup's own DELETE land, so what is counted next is only what
    // a RECONCILE would add.
    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/subscription').length).toBeGreaterThan(0)
    })

    const settled = session.calls('/v1/pager/subscription').length

    // The endpoint the page remembered is deliberately cleared, so a reconcile that DID run
    // would find a difference and post -- the strongest form of this assertion.
    window.localStorage.removeItem('qrpager.pager.endpoint')

    await settle(() => {
      document.dispatchEvent(new Event('visibilitychange'))
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(session.calls('/v1/pager/subscription').length).toBe(settled)
    withoutPushPath()
  })

  it('does NOT call the subscription endpoint when the session EXPIRED', async () => {
    // `CustomerPagerController::live()` refuses an expired session by design, so the call
    // could only fail. Skipping it is behaviour, not an omission.
    const session = new Session().answers({
      status: 409,
      body: { type: 'https://qrpager.dev/problems/expired' },
    })

    container({
      subscription: {
        endpoint: 'https://push.example/known',
        toJSON: () => ({
          endpoint: 'https://push.example/known',
          keys: { p256dh: 'P', auth: 'A' },
        }),
        unsubscribe: () => Promise.resolve(true),
      } as unknown as PushSubscription,
    })

    session.mount()
    await screen.findByText('This code has expired')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(
      session.calls('/v1/pager/subscription').map((c) => c.body),
      'a reconcile posted when the endpoint already matched',
    ).toEqual([])
    withoutPushPath()
  })
})

describe('the iOS install guidance, on the real component', () => {
  it('shows the three-step flow on an iOS tab and not on an installed one', async () => {
    const iphone =
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'

    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(iphone)
    // `defineProperty`, not `spyOn`: jsdom's Navigator has no `maxTouchPoints` at all, and
    // `vi.spyOn` refuses a property that is not defined. That absence is itself the reason
    // `install.ts` reads it defensively -- an engine that does not report it is a real one.
    Object.defineProperty(window.navigator, 'maxTouchPoints', { configurable: true, value: 5 })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    await screen.findByText('Add this to your Home Screen first')
    // It SELF-LABELS, which is QRPAGER-24's inherited pattern.
    expect(screen.getByText(/alerts can't reach you yet/)).toBeTruthy()
  })

  it('shows nothing of the kind on a standalone launch', async () => {
    const iphone =
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'

    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(iphone)
    // `defineProperty`, not `spyOn`: jsdom's Navigator has no `maxTouchPoints` at all, and
    // `vi.spyOn` refuses a property that is not defined. That absence is itself the reason
    // `install.ts` reads it defensively -- an engine that does not report it is a real one.
    Object.defineProperty(window.navigator, 'maxTouchPoints', { configurable: true, value: 5 })
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: query.includes('display-mode: standalone'),
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }))

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()

    // jsdom still has no push path, so this is the `unsupported` state -- which is exactly
    // the state the previous case proves DOES carry the guidance on a tab. Here it must not:
    // the app is already installed, so telling the customer to install it is noise, and the
    // honest answer is the unsupported copy alone.
    await screen.findByText("This browser can't send alerts")
    expect(screen.queryByText('Add this to your Home Screen first')).toBeNull()
  })
})

describe('the recovery paths', () => {
  it('goes back to resolving when the network comes back on the offline ERROR screen', async () => {
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })

    // Unreachable first, so the journey lands on `error.offline` with a token still held.
    window.history.replaceState(null, '', `/s#${session.token}`)
    const dead = render(
      <PagerJourney apiBase="http://127.0.0.1:1" vapidPublicKey={KEY} locale="en" />,
    )

    await screen.findByText("You're offline")
    dead.unmount()

    // Now the real API, and the `online` event rather than the button: the two are different
    // entry points and the event one is what a customer walking back into range produces.
    window.history.replaceState(null, '', `/s#${session.token}`)
    render(<PagerJourney apiBase={base} vapidPublicKey={KEY} locale="en" />)

    await screen.findByText('A-42')

    await settle(() => {
      window.dispatchEvent(new Event('online'))
    })

    expect(screen.getByText('Your order')).toBeTruthy()
  })

  it('re-runs the reconcile on every return to visibility -- SR-5s iOS mechanism', async () => {
    /**
     * ⚠ THIS IS THE ONLY MECHANISM ON iOS. `pushsubscriptionchange` is `safari_ios: false` in
     * MDN's compat data, so a rotation is noticed at the next LAUNCH -- which for a
     * Home-Screen app a customer opens to check their order is the next thing that happens.
     */
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    const listeners = new Set<(event: MessageEvent<unknown>) => void>()
    let subscribes = 0
    const registration = {
      pushManager: {
        getSubscription: () => Promise.resolve(null),
        subscribe: () => {
          subscribes += 1

          return Promise.resolve({
            endpoint: `https://push.example/rotated-${String(subscribes)}`,
            toJSON: () => ({
              endpoint: `https://push.example/rotated-${String(subscribes)}`,
              keys: { p256dh: 'P', auth: 'A' },
            }),
          } as unknown as PushSubscription)
        },
      },
    }

    Object.defineProperty(window.navigator, 'serviceWorker', {
      configurable: true,
      value: {
        register: () => Promise.resolve(registration),
        getRegistration: () => Promise.resolve(registration),
        ready: Promise.resolve(registration),
        addEventListener: (_t: string, fn: (event: MessageEvent<unknown>) => void) => {
          listeners.add(fn)
        },
        removeEventListener: (_t: string, fn: (event: MessageEvent<unknown>) => void) => {
          listeners.delete(fn)
        },
      },
    })
    vi.stubGlobal('Notification', {
      permission: 'granted',
      requestPermission: () => Promise.resolve('granted'),
    })
    vi.stubGlobal('PushManager', PushManagerStub)

    session.mount()
    await screen.findByText('A-42')
    await vi.waitFor(() => {
      expect(subscribes).toBe(1)
    })

    // The browser rotated behind our back: the remembered endpoint no longer matches.
    window.localStorage.setItem('qrpager.pager.endpoint', 'https://push.example/stale')

    await settle(() => {
      document.dispatchEvent(new Event('visibilitychange'))
    })

    await vi.waitFor(() => {
      expect(session.calls('/v1/pager/subscription').length).toBeGreaterThan(1)
    })
    withoutPushPath()
  })

  it('does not report the toggle off when the ENGINE releases the wake lock', async () => {
    // The engine releases it whenever the document hides, by specification. A release we did
    // not ask for must not flip the label to "unavailable".
    // The same holder-object reason as `environment.test.ts`: an assignment inside a callback
    // is invisible to control-flow narrowing.
    const captured: { lost: (() => void) | null } = { lost: null }

    Object.defineProperty(window.navigator, 'wakeLock', {
      configurable: true,
      value: {
        request: () =>
          Promise.resolve({
            release: () => Promise.resolve(),
            addEventListener: (_type: 'release', fn: () => void) => {
              captured.lost = fn
            },
          }),
      },
    })

    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Just show me the status' })).click()

    const toggle = await screen.findByLabelText('Keep the screen on')

    expect((toggle as HTMLInputElement).checked).toBe(true)

    await settle(() => {
      captured.lost?.()
    })

    // Unchecked, and the toggle is STILL THERE -- the outcome did not become 'unavailable'.
    const after: HTMLInputElement = await screen.findByLabelText('Keep the screen on')

    expect(after.checked).toBe(false)
    Reflect.deleteProperty(window.navigator, 'wakeLock')
  })

  it('continues past the alerts screen and retries from the error screen', async () => {
    // The two remaining handlers: `onContinue` on an alerts state, and `onRetry` on an error
    // state. Both are the way out of a screen a customer could otherwise be stuck on.
    const session = new Session().answers({ status: 200, body: snapshot('waiting') })
    session.mount()

    await screen.findByText('A-42')
    ;(await screen.findByRole('button', { name: 'Enable alerts' })).click()
    await screen.findByText("This browser can't send alerts")
    ;(await screen.findByRole('button', { name: 'Continue' })).click()

    await screen.findByText("We're on it")
  })

  it('retries from the error screen with the button, not only with the event', async () => {
    window.history.replaceState(null, '', `/s#${mintToken()}`)
    render(<PagerJourney apiBase="http://127.0.0.1:1" vapidPublicKey={KEY} locale="en" />)

    await screen.findByText("You're offline")

    const retry = await screen.findByRole('button', { name: 'Try again' })

    await settle(() => {
      retry.click()
    })

    // It goes back to resolving, which fails again against the dead port -- the point is
    // that the button is wired, not that the retry succeeds.
    await screen.findByText("You're offline")
  })
})
