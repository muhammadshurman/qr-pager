import { afterEach, describe, expect, it, vi } from 'vitest'

import { fetchBoard, fetchIdentity, markSessionReady } from '../src/staff/api.ts'

/**
 * ================================================================================
 * `call()`'s STATUS → KIND MAPPING, ASSERTED DIRECTLY. `R-224`'s MISSING CONTROL.
 * ================================================================================
 *
 * ⚠ **THIS FILE EXISTS BECAUSE `§11` QA H-4 PROVED `R-224`'s FIX HAD NO TEST THAT FAILS
 * WITHOUT IT, BY REVERTING IT.** The reviewer deleted the `response.status === 429` branch so
 * a 429 fell through to `{kind:'server'}` — the exact pre-fix behaviour the row was filed
 * about — and **every one of the 82 targeted staff tests stayed green**, with zero new
 * failures in the full suite.
 *
 * The reason is worth more than the fix. The headline orchestration case — *"SAYS NOTHING on
 * the first 429 and keeps the rows"* — asserts only **absences**, and every one of them holds
 * under BOTH mappings: with rows already in hand, the `server` kind puts the board in `error`,
 * which also keeps the rows and also renders no error primitive, so *throttled copy absent*,
 * *row present*, *error sentence absent* all passed either way. A test built out of absences
 * cannot distinguish two behaviours that differ only in which absence you get.
 *
 * So the mapping is asserted **positively and at the boundary**, on the one function that owns
 * it. `§11`'s merge rule is *every Critical and High fixed with a regression test*, and this is
 * the test the revert must redden.
 */

afterEach(() => {
  vi.restoreAllMocks()
})

/** A `Response`-shaped stub: the client reads a status, a body and one header. */
function answer(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return {
    status,
    ok: status >= 200 && status < 300,
    json: () => Promise.resolve(body),
    headers: { get: (name: string) => headers[name] ?? null },
  } as unknown as Response
}

function stub(response: Response): void {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))
}

describe('a 429 is its own kind, and it carries the server’s hint', () => {
  it('maps 429 to `throttled` and NOT to `server` — the revert must redden this', async () => {
    stub(
      answer(429, { type: 'https://qrpager.dev/problems/rate-limited' }, { 'Retry-After': '30' }),
    )

    const result = await fetchBoard('')

    // ⚠ POSITIVE, AND ON THE KIND ITSELF. This is the assertion the old test could not make:
    // an absence-based case cannot tell `throttled` from `server`, because both of them are
    // "not ok" and neither renders throttled copy on a board that already has rows.
    expect(result.kind).toBe('throttled')

    // Narrowed before `retryAfter` is read, which is what the discriminated union is for: a
    // caller cannot read the hint off a 404.
    if (result.kind !== 'throttled') throw new Error('unreachable: kind was asserted above')

    expect(result.retryAfter).toBe(30)
  })

  it('answers a null hint rather than a guess when Retry-After is absent or unusable', async () => {
    // `board.ts`'s own jittered doubling is the fallback, and it is better than an invented
    // number — so these four must all be `null` rather than 0, NaN or a default.
    for (const header of [undefined, '', 'soon', '0', '-5'] as const) {
      stub(answer(429, {}, header === undefined ? {} : { 'Retry-After': header }))

      const result = await fetchBoard('')

      expect(result.kind, String(header)).toBe('throttled')

      if (result.kind !== 'throttled') throw new Error('unreachable')

      expect(result.retryAfter, String(header)).toBeNull()
    }
  })

  it('maps the kinds that are NOT throttled, so the branch cannot be made to fire on everything', async () => {
    /*
     * ⚠ THE OTHER HALF OF THE SAME CONTROL. A `call()` that returned `throttled` for every
     * failure would pass the two cases above and be worse than the defect they fix. Each
     * status is driven to its own kind, which is also the first direct assertion this project
     * has of the five mappings `api.ts`'s docblock has always described.
     */
    for (const [status, kind] of [
      [401, 'unauthorised'],
      [419, 'unauthorised'],
      [403, 'forbidden'],
      [404, 'notFound'],
      [409, 'conflict'],
      [500, 'server'],
      [503, 'server'],
    ] as const) {
      stub(answer(status, {}))

      expect((await fetchBoard('')).kind, String(status)).toBe(kind)
    }
  })

  it('reads the problem type off a 409, which is how `expired` gets its own sentence', async () => {
    stub(answer(409, { type: 'https://qrpager.dev/problems/expired' }))

    const result = await markSessionReady('', '01a199c4-7f3e-7000-8000-0123456789ab')

    expect(result.kind).toBe('conflict')

    if (result.kind !== 'conflict') throw new Error('unreachable')

    // The SUFFIX is what the client compares, so a domain change cannot break a correct
    // client -- `PROBLEM_EXPIRED`'s own reasoning, asserted here rather than only stated.
    expect(result.problem).toContain('/problems/expired')
  })

  it('answers `server` for a 2xx whose body it cannot read, which is reachable across a deploy', async () => {
    stub({
      status: 200,
      ok: true,
      json: () => Promise.reject(new SyntaxError('not json')),
      headers: { get: () => null },
    } as unknown as Response)

    expect((await fetchIdentity('')).kind).toBe('server')
  })

  it('answers `unreachable` when fetch rejects, which is the wifi and not us', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    expect((await fetchBoard('')).kind).toBe('unreachable')
  })
})
