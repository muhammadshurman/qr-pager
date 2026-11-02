import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  NO_HISTORY_FILTERS,
  fetchBoard,
  fetchHistory,
  fetchTeam,
  fetchTenant,
  issueSheet,
  reissueSheet,
  revokeDevice,
  selectTenant,
} from '../src/staff/api.ts'

/**
 * ================================================================================
 * THE STAFF CLIENT's EDGES — the paths a happy-path test cannot reach
 * ================================================================================
 *
 * `staff-api.test.ts` and `staff-api-kinds.test.ts` drive the mappings every screen depends
 * on. What they cannot reach are the three defensive paths in `call()` and `xsrfToken()`, each
 * of which exists because of a real failure mode and each of which was an uncovered branch:
 *
 *   a MALFORMED `XSRF-TOKEN` cookie       `decodeURIComponent` throws on a stray `%`
 *   a 409 whose BODY cannot be parsed     the status still carries the fact
 *   a 204, and a 2xx with no reader       a write that answers nothing
 *
 * ⚠ **AN UNREACHED DEFENSIVE PATH IS INDISTINGUISHABLE FROM A BROKEN ONE**, which is this
 * project's own standard — *an unproven guard is not a guard*. Each branch below has a comment
 * in the source explaining why it is there; these are the cases that make those comments true.
 */

function answer(status: number, body?: unknown, parseFails = false) {
  return vi.fn(() =>
    Promise.resolve({
      status,
      ok: status >= 200 && status < 300,
      json: () =>
        parseFails
          ? Promise.reject(new SyntaxError('Unexpected token'))
          : Promise.resolve(body ?? {}),
      headers: { get: () => null },
    } as unknown as Response),
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
})

describe('the CSRF cookie', () => {
  it('url-decodes the value, which is the single most common way this integration is got wrong', () => {
    /*
     * Laravel's cookie value is base64 with `=` padding and the browser percent-encodes it on
     * the way into `document.cookie`. Sent back raw, the decrypt fails,
     * `getTokenFromRequest()` returns `''` and the request is a 419 that names nothing.
     */
    document.cookie = 'XSRF-TOKEN=a%2Bb%3D%3D; path=/'

    const fetcher = answer(200, { sessions: [] })

    vi.stubGlobal('fetch', fetcher)

    return fetchBoard('').then(() => {
      const headers = fetcher.mock.calls[0]?.[1]?.headers as Record<string, string>

      expect(headers['X-XSRF-TOKEN']).toBe('a+b==')
    })
  })

  it('sends NO token when the cookie is malformed, rather than throwing', async () => {
    /*
     * ⚠ THE `catch` IN `xsrfToken()`, DRIVEN. A stray `%` makes `decodeURIComponent` throw,
     * and the source's comment is the behaviour this asserts: *"a malformed cookie is not worth
     * throwing over: the request will be refused and the shell will show sign-in, which is the
     * right answer either way."* Unreached, that comment was a claim about untested code.
     */
    document.cookie = 'XSRF-TOKEN=%E0%A4%A; path=/'

    const fetcher = answer(200, { sessions: [] })

    vi.stubGlobal('fetch', fetcher)

    const result = await fetchBoard('')

    expect(result.kind).toBe('ok')

    const headers = fetcher.mock.calls[0]?.[1]?.headers as Record<string, string>

    expect(headers['X-XSRF-TOKEN']).toBeUndefined()
  })
})

describe('a 409 whose body cannot be read', () => {
  it('still answers conflict, with a null discriminator rather than a throw', async () => {
    /*
     * ⚠ THE `catch` AROUND THE PROBLEM-DOCUMENT PARSE. The source says why: *"the STATUS
     * already carries the fact that the state refused, and the panel's general conflict
     * sentence is correct without the discriminator."* A client that threw here would turn a
     * 409 from a proxy that mangled the body into an unhandled rejection.
     */
    vi.stubGlobal('fetch', answer(409, undefined, true))

    const result = await selectTenant('', '01a00000-0000-7000-8000-00000000000a')

    expect(result).toEqual({ kind: 'conflict', problem: null })
  })

  it('answers a null discriminator when the body parses but carries no `type`', async () => {
    // `type` is the CONTRACTUAL discriminator (`§4 › Edge & ops`); a body without one is
    // readable and says nothing, which is the same answer as an unreadable one.
    vi.stubGlobal('fetch', answer(409, { title: 'Conflict' }))

    const result = await selectTenant('', '01a00000-0000-7000-8000-00000000000a')

    expect(result).toEqual({ kind: 'conflict', problem: null })
  })
})

describe('a write that answers nothing', () => {
  it('reads a 204 as ok with no value', async () => {
    // `DELETE /v1/devices/{id}` answers 204. A client that insisted on a body would treat a
    // correct response as a contract break.
    vi.stubGlobal('fetch', answer(204))

    expect(await revokeDevice('', '01a00000-0000-7000-8000-00000000000a')).toEqual({ kind: 'ok' })
  })

  it('reads a 200 with no reader as ok, without touching the body', async () => {
    const fetcher = answer(200, { anything: true })

    vi.stubGlobal('fetch', fetcher)

    expect(await selectTenant('', '01a00000-0000-7000-8000-00000000000a')).toEqual({ kind: 'ok' })
  })

  it('reads a NON-2xx with no reader as server, not as ok', async () => {
    /*
     * The branch that would be a real bug the other way round: `response.ok ? ok : server` on
     * the no-reader path. A 302 or a 418 with no body must not read as success — `R-042` is the
     * row about unmapped statuses, and this is its client-side sibling.
     */
    vi.stubGlobal('fetch', answer(418))

    expect(await revokeDevice('', '01a00000-0000-7000-8000-00000000000a')).toEqual({
      kind: 'server',
    })
  })
})

describe('a 2xx this build cannot use', () => {
  it('answers server when the body does not parse, because the server DID reply', async () => {
    // Reachable across a deploy. `server` and not `unreachable`: the distinction is whether
    // the network answered, and folding them would tell a kitchen we are broken when their
    // wifi dropped — or the reverse.
    vi.stubGlobal('fetch', answer(200, undefined, true))

    expect(await fetchTenant('')).toEqual({ kind: 'server' })
  })

  it('answers server when the reader rejects the shape', async () => {
    // `fetchTenant` returns null from its reader when the NAME is missing, deliberately: a
    // shell that quietly displayed a slug would fail `§12`'s zero-technical bar while looking
    // like it worked.
    vi.stubGlobal('fetch', answer(200, { tenant: { slug: 'al-baik', timezone: 'UTC' } }))

    expect(await fetchTenant('')).toEqual({ kind: 'server' })
  })

  it('defaults a missing timezone to UTC rather than rendering nothing', async () => {
    vi.stubGlobal('fetch', answer(200, { tenant: { name: 'Al Baik' } }))

    expect(await fetchTenant('')).toEqual({
      kind: 'ok',
      value: { name: 'Al Baik', timezone: 'UTC' },
    })
  })
})

describe('the api base', () => {
  it('strips a trailing slash rather than doubling it', async () => {
    const fetcher = answer(200, { sessions: [] })

    vi.stubGlobal('fetch', fetcher)
    await fetchBoard('https://api.example.test/')

    expect(fetcher.mock.calls[0]?.[0]).toBe('https://api.example.test/v1/pager-sessions')
  })

  it('sends a same-origin path when the base is empty, which is every real build', async () => {
    const fetcher = answer(200, { sessions: [] })

    vi.stubGlobal('fetch', fetcher)
    await fetchBoard('')

    expect(fetcher.mock.calls[0]?.[0]).toBe('/v1/pager-sessions')
    // The credential is a first-party cookie, so `same-origin` and never `include`: `include`
    // would additionally send it cross-origin, a capability this client has no use for.
    expect(fetcher.mock.calls[0]?.[1]?.credentials).toBe('same-origin')
  })
})

describe('the readers drop a row they cannot render, never default it', () => {
  /*
   * ⚠ THE RULE IS STATED IN THE SOURCE AND IS WHAT THESE CASES MAKE TRUE: *"a row missing any
   * of the four is DROPPED, not defaulted. The alternative — a blank order reference, or a
   * status this build does not know rendered as-is — puts an unexplained value on a kitchen
   * screen, which is the `§12` bar this whole surface is held to."*
   *
   * Every drop path and every `?? ''` fallback below was an uncovered branch, which is the
   * same thing as an unproven guard: the comments asserted a behaviour nothing exercised.
   */
  it('drops a history row missing any required key, and keeps the complete one', async () => {
    const complete = {
      id: '01a00000-0000-7000-8000-00000000000a',
      order_reference: 'A-1',
      status: 'completed',
      created_at: '2026-11-02T10:00:00Z',
      ready_at: '2026-11-02T10:04:00Z',
      completed_at: '2026-11-02T10:06:00Z',
    }

    vi.stubGlobal(
      'fetch',
      answer(200, {
        sessions: [
          null,
          'not an object',
          { ...complete, id: null },
          { ...complete, order_reference: '' },
          { ...complete, status: undefined },
          { ...complete, created_at: null },
          // A status PHP added and the generator has not mirrored: dropped rather than
          // rendered, which is the safe direction on a screen read from two metres.
          { ...complete, status: 'awaiting_collection' },
          complete,
        ],
      }),
    )

    const result = await fetchHistory('', NO_HISTORY_FILTERS)

    expect(result.kind).toBe('ok')
    expect(result.kind === 'ok' && result.value).toHaveLength(1)
    expect(result.kind === 'ok' && result.value[0]).toEqual({
      id: complete.id,
      orderReference: 'A-1',
      status: 'completed',
      createdAt: complete.created_at,
      readyAt: complete.ready_at,
      completedAt: complete.completed_at,
    })
  })

  it('reads a history row with no ready or completed time as nulls, not as empty strings', async () => {
    // The screen renders a timestamp only when the server sent one -- an order that was
    // cancelled was never collected, and *"Collected —"* is an unexplained thing.
    vi.stubGlobal(
      'fetch',
      answer(200, {
        sessions: [
          {
            id: '01a00000-0000-7000-8000-00000000000a',
            order_reference: 'A-1',
            status: 'cancelled',
            created_at: '2026-11-02T10:00:00Z',
          },
        ],
      }),
    )

    const result = await fetchHistory('', NO_HISTORY_FILTERS)

    expect(result.kind === 'ok' && result.value[0]?.readyAt).toBeNull()
    expect(result.kind === 'ok' && result.value[0]?.completedAt).toBeNull()
  })

  it('answers server when the history body carries no sessions array', async () => {
    // ⚠ NOT AN EMPTY LIST. *No orders* and *we could not read the answer* are different facts,
    // and a screen showing the empty state during a contract break is the one failure that
    // looks like success.
    vi.stubGlobal('fetch', answer(200, { sessions: 'nope' }))

    expect((await fetchHistory('', NO_HISTORY_FILTERS)).kind).toBe('server')
  })

  it('refuses a sheet that is missing any of its four parts', async () => {
    const whole = {
      session: { id: '01a00000-0000-7000-8000-00000000000a', order_reference: 'A-1' },
      qr_payload: 'https://qrpager.dev/s#abc',
      qr_svg: '<svg></svg>',
    }

    for (const [label, body] of [
      ['no session', { ...whole, session: null }],
      ['no id', { ...whole, session: { order_reference: 'A-1' } }],
      ['no reference', { ...whole, session: { id: whole.session.id } }],
      ['no payload', { ...whole, qr_payload: '' }],
      ['no svg', { ...whole, qr_svg: undefined }],
    ] as const) {
      vi.stubGlobal('fetch', answer(201, body))

      // A sheet without its code is a sheet nobody can scan, and rendering one with a hole
      // where the QR goes is worse than the failure state: a staff member would print it.
      expect((await issueSheet('', 'A-1')).kind, label).toBe('server')
      vi.unstubAllGlobals()
    }
  })

  it('reads a whole sheet from both the create and the re-issue routes', async () => {
    const whole = {
      session: { id: '01a00000-0000-7000-8000-00000000000a', order_reference: 'A-1' },
      qr_payload: 'https://qrpager.dev/s#abc',
      qr_svg: '<svg></svg>',
    }

    for (const call of [
      () => issueSheet('', 'A-1'),
      () => reissueSheet('', '01a00000-0000-7000-8000-00000000000a'),
    ]) {
      vi.stubGlobal('fetch', answer(200, whole))

      const result = await call()

      expect(result).toEqual({
        kind: 'ok',
        value: {
          id: whole.session.id,
          orderReference: 'A-1',
          qrPayload: whole.qr_payload,
          qrSvg: whole.qr_svg,
        },
      })

      vi.unstubAllGlobals()
    }
  })

  it('drops a member with no id or no role, and renders the rest by address when unnamed', async () => {
    vi.stubGlobal(
      'fetch',
      answer(200, {
        members: [
          null,
          { user_id: 'u', role: 'staff' },
          { id: 'm-1', user_id: 'u' },
          // No name and no user id: both fall back to an empty string, which the screen
          // handles by rendering the ADDRESS -- a thing a manager recognises, unlike a blank.
          { id: 'm-2', email: 'nameless@example.test', role: 'staff' },
        ],
      }),
    )

    const result = await fetchTeam('')

    expect(result.kind === 'ok' && result.value).toHaveLength(1)
    expect(result.kind === 'ok' && result.value[0]).toEqual({
      id: 'm-2',
      userId: '',
      name: '',
      email: 'nameless@example.test',
      role: 'staff',
    })
  })

  it('answers server when the members body carries no array', async () => {
    vi.stubGlobal('fetch', answer(200, {}))

    expect((await fetchTeam('')).kind).toBe('server')
  })

  it('drops a board row it cannot render, including an unknown status', async () => {
    const complete = {
      id: '01a00000-0000-7000-8000-00000000000a',
      order_reference: 'A-1',
      status: 'waiting',
      created_at: '2026-11-02T10:00:00Z',
      ready_at: null,
    }

    vi.stubGlobal(
      'fetch',
      answer(200, {
        sessions: [null, { ...complete, status: 'nonsense' }, { ...complete, id: '' }, complete],
      }),
    )

    const result = await fetchBoard('')

    expect(result.kind === 'ok' && result.value).toHaveLength(1)
    expect(result.kind === 'ok' && result.value[0]?.readyAt).toBeNull()
  })
})
