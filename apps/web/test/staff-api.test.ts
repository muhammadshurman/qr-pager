import { afterEach, describe, expect, it, vi, type Mock } from 'vitest'

import {
  DEVICE_TTL_MINUTES,
  fetchDevices,
  fetchIdentity,
  fetchTenant,
  issueDevice,
  revokeDevice,
  signInWithPassword,
  signOut,
} from '../src/staff/api.ts'

/**
 * ================================================================================
 * THE STAFF CLIENT. The CSRF handling and the failure mapping, which are the two
 * things in it that can be subtly wrong.
 * ================================================================================
 *
 * Every assertion here is about a property the screens depend on and cannot check for
 * themselves: that a 401 is not an error, that an unreachable network is not a 5xx, and that
 * the `XSRF-TOKEN` cookie is URL-DECODED on the way back out.
 *
 * ⚠ THAT LAST ONE IS THE MOST LIKELY DEFECT IN THE WHOLE FILE AND IT IS SILENT. Laravel's
 * cookie value is base64 with `=` padding; the browser percent-encodes it into
 * `document.cookie`. Sent back raw, `PreventRequestForgery` fails to decrypt it,
 * `getTokenFromRequest()` returns `''` and every write is a 419 that names nothing. A test
 * that only checked the header is PRESENT would pass against exactly that bug.
 */

function cookie(value: string): void {
  Object.defineProperty(document, 'cookie', { value, writable: true, configurable: true })
}

/**
 * The `RequestInit` a recorded `fetch` call was given.
 *
 * ⚠ A HELPER AND NOT AN INLINE SUBSCRIPT, because `vi.fn()`'s `mock.calls` is `any[][]` --
 * so every `calls[0]?.[1].headers` is an unsafe member access on `any`, which the lint rule
 * is right about: an `any` here would let a typo in `headers` pass as a passing assertion.
 * Narrowed once, in one place, with the shape `fetch` actually has.
 */
function sentInit(mock: Mock, index = 0): RequestInit | undefined {
  const calls = mock.mock.calls as unknown as [unknown, RequestInit | undefined][]

  return calls[index]?.[1]
}

function respond(status: number, body?: unknown, ok?: boolean): Response {
  // `Promise.resolve` rather than an `async` thunk: an async function with no `await` in it
  // is what `@typescript-eslint/require-await` is for, and a fake's `json()` has nothing to
  // wait on.
  return {
    status,
    ok: ok ?? (status >= 200 && status < 300),
    json: () => Promise.resolve(body),
  } as unknown as Response
}

afterEach(() => {
  vi.restoreAllMocks()
  cookie('')
})

describe('the CSRF token', () => {
  it('is url-decoded before it is sent back, which is the silent failure if it is not', () => {
    const raw = 'eyJpdiI6IkFCQ0QiLCJ2YWx1ZSI6IldYWVo9PSJ9'
    // What a browser actually puts in `document.cookie`: `=` and `+` percent-encoded.
    cookie(`laravel_session=abc; XSRF-TOKEN=${encodeURIComponent(raw)}; other=1`)

    const fetchMock = vi.fn().mockResolvedValue(respond(204))

    vi.stubGlobal('fetch', fetchMock)

    return signOut('').then(() => {
      const headers = sentInit(fetchMock)?.headers as Record<string, string>

      // THE DECODED VALUE, asserted as an exact string. `toContain` would pass on the
      // percent-encoded form, which is the bug.
      expect(headers['X-XSRF-TOKEN']).toBe(raw)
      expect(headers['X-XSRF-TOKEN']).not.toContain('%3D')
    })
  })

  it('sends no CSRF header at all when there is no cookie, rather than an empty one', async () => {
    cookie('laravel_session=abc')

    const fetchMock = vi.fn().mockResolvedValue(respond(204))

    vi.stubGlobal('fetch', fetchMock)
    await signOut('')

    const headers = sentInit(fetchMock)?.headers as Record<string, string>

    // An empty header is a value the server will try to decrypt and fail on; its absence
    // lets `Sec-Fetch-Site: same-origin` carry the request, which is the documented second
    // path. Sending `''` would turn a working request into a 419.
    expect('X-XSRF-TOKEN' in headers).toBe(false)
  })

  it('sends the credential as same-origin and never as include', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(204))

    vi.stubGlobal('fetch', fetchMock)
    await signOut('')

    expect(sentInit(fetchMock)?.credentials).toBe('same-origin')
  })
})

describe('the failure mapping', () => {
  it('keeps the five answers distinct, because the copy differs for each', async () => {
    const cases: readonly [number, string][] = [
      [401, 'unauthorised'],
      [419, 'unauthorised'],
      [403, 'forbidden'],
      [404, 'notFound'],
      [500, 'server'],
      [503, 'server'],
    ]

    for (const [status, expected] of cases) {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(status, {})))

      const result = await fetchTenant('')

      expect(result.kind, `${String(status)} should map to ${expected}`).toBe(expected)
    }
  })

  it('answers unreachable when the network throws, and NOT server', async () => {
    // The distinction a client that folded them would lose: "no connection, we will keep
    // trying" against "something went wrong on our side". One of those blames us for the
    // restaurant's wifi.
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    expect((await fetchTenant('')).kind).toBe('unreachable')
    expect((await fetchIdentity('')).kind).toBe('unreachable')
    expect((await fetchDevices('')).kind).toBe('unreachable')
  })

  it('answers server for a 200 whose body it cannot read, because the server DID reply', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        status: 200,
        ok: true,
        json: () => Promise.reject(new SyntaxError('not json')),
      }),
    )

    expect((await fetchTenant('')).kind).toBe('server')
  })
})

describe('the restaurant', () => {
  it('reads the name, the slug and the timezone', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        respond(200, {
          tenant: { name: 'Al Baik', slug: 'al-baik', timezone: 'Asia/Riyadh', status: 'active' },
        }),
      ),
    )

    const result = await fetchTenant('')

    expect(result.kind).toBe('ok')
    expect(result.kind === 'ok' ? result.value.name : null).toBe('Al Baik')
    expect(result.kind === 'ok' ? result.value.timezone : null).toBe('Asia/Riyadh')
  })

  it('does NOT fall back to the slug when the name is missing — §12 would rather fail loudly', async () => {
    // ⚠ THE FALLBACK IS THE DEFECT, NOT THE PROTECTION. A client that showed `al-baik` when
    // `name` was absent would put a DNS label on a staff screen -- an unexplained identifier,
    // which is the one thing §12's zero-technical bar names outright -- and it would LOOK
    // like it worked, so nobody would find it. Answering `server` shows the error state,
    // which is honest.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(respond(200, { tenant: { slug: 'al-baik', timezone: 'UTC' } })),
    )

    expect((await fetchTenant('')).kind).toBe('server')
  })
})

describe('the person', () => {
  it('reads the name from `user.name`, where the server actually puts it', async () => {
    // ⚠ THIS CASE EXISTS BECAUSE THE FIRST VERSION READ `data.name` AND EIGHT CASES PASSED
    // OVER IT. The server nests the person under `user`
    // (`AuthController::identity()`), the shell rendered "Signed in as " with a hole in it,
    // and the fakes in `staff-app.test.tsx` returned the shape the CLIENT expected -- which
    // is a fake that cannot find a disagreement with the server by construction.
    //
    // The cross-language half is `apps/api/tests/Feature/Staff/StaffContractTest.php`, which
    // drives the real endpoint. This is the client half: the payload below is the server's
    // real shape, and the assertion is that the name is found in it.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        respond(200, {
          user: { id: 'u1', name: 'Hiba Mansour', email: 'h@example.test' },
          memberships: [{ tenant_id: 't1', role: 'owner' }],
          active_tenant: 't1',
        }),
      ),
    )

    const result = await fetchIdentity('')

    expect(result.kind).toBe('ok')
    expect(result.kind === 'ok' ? result.value.name : null).toBe('Hiba Mansour')
    expect(result.kind === 'ok' ? result.value.activeTenant : null).toBe('t1')
    expect(result.kind === 'ok' ? result.value.memberships.length : 0).toBe(1)
  })

  it('answers server when the name is missing, rather than signing somebody in as nobody', async () => {
    // No fallback to the email and none to a placeholder: a shell that cannot say who is
    // signed in shows its error state, which is honest. "Signed in as " is not, and it is
    // the exact §12 defect this task asserts against on four other screens.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(respond(200, { user: { id: 'u1' }, memberships: [] })),
    )

    expect((await fetchIdentity('')).kind).toBe('server')
  })

  it('reads a second factor out of a 200 BODY, because that is how the server asks for one', async () => {
    // ⚠ 200 AND A FLAG, NOT A 4xx. The client's first version mapped 403 to this, which made
    // the code field a screen state no event could produce. `AuthController::login()` returns
    // `response()->json(['two_factor_required' => true], 200)`.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, { two_factor_required: true })))

    expect((await signInWithPassword('', 'a@b.test', 'pw')).kind).toBe('two-factor')

    // And a plain 200 with no flag is a completed sign-in. Without this, a client that
    // answered `two-factor` for every 200 would pass the case above.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, { ok: true })))

    expect((await signInWithPassword('', 'a@b.test', 'pw')).kind).toBe('ok')

    // A refused password is one message, whatever the status.
    for (const status of [401, 403, 422]) {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(status, {})))

      const outcome = await signInWithPassword('', 'a@b.test', 'pw')

      expect(outcome.kind, `${String(status)} should not be two-factor`).not.toBe('two-factor')
    }
  })
})

describe('kitchen screens', () => {
  it('reads the list and drops a row with no id rather than rendering a blank one', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        respond(200, {
          devices: [
            { id: 'a', name: 'Pass', expires_at: '2026-12-01T00:00:00Z', last_used_at: null },
            { name: 'no id at all' },
          ],
        }),
      ),
    )

    const result = await fetchDevices('')

    expect(result.kind).toBe('ok')
    expect(result.kind === 'ok' ? result.value.length : 0).toBe(1)
    expect(result.kind === 'ok' ? result.value[0]?.name : null).toBe('Pass')
  })

  it('asks for pager:read and the server ceiling, and offers the caller no choice of either', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        respond(201, { device: { id: 'x', token: 'secret', expires_at: '2026-12-01T00:00:00Z' } }),
      )

    vi.stubGlobal('fetch', fetchMock)

    await issueDevice('', 'Grill')

    const sent = sentInit(fetchMock)?.body

    expect(typeof sent).toBe('string')

    const body: unknown = JSON.parse(typeof sent === 'string' ? sent : '{}')

    // `Ability::issuableToDevice()` is a closed list of ONE, so a client that offered a
    // choice would be offering one the server refuses. Pinned as exact values.
    expect(body).toEqual({ name: 'Grill', ability: 'pager:read', ttl_minutes: DEVICE_TTL_MINUTES })
    // And the TTL is the server's own ceiling, `DeviceTokens::MAX_TTL_MINUTES`. The two are
    // pinned together here so a tightening on the server cannot leave this asking for more
    // than it may have.
    expect(DEVICE_TTL_MINUTES).toBe(60 * 24 * 30)
  })

  it('url-encodes the id in the revoke path, so no id can shape the URL', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(204))

    vi.stubGlobal('fetch', fetchMock)
    await revokeDevice('', '../../v1/members')

    // The route is `whereUuid`, so a non-uuid is a routing miss either way -- but a client
    // that interpolated raw is a client that builds a URL from data, and that is the habit
    // rather than this one instance.
    const calls = fetchMock.mock.calls as unknown as [string, RequestInit | undefined][]

    expect(calls[0]?.[0]).toBe('/v1/devices/..%2F..%2Fv1%2Fmembers')
  })
})
