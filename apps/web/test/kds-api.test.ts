import { describe, expect, it } from 'vitest'

import { fetchKdsBoard } from '../src/kds/api.ts'

/**
 * ================================================================================
 * 10c's INVARIANT, FIRST HALF — *re-sends NO notification*, at the client
 * ================================================================================
 *
 * `QRPAGER-27 §5` names the trap: *"A naive refetch after reconnect can re-trigger sends."*
 * `kds/api.ts` answers it structurally — there is no write in the module — and this file holds
 * that to BEHAVIOUR rather than to the comment: every request the module makes is recorded and
 * its method is read back.
 *
 * ⚠ **THIS IS THE WEAKER OF THE TWO PROOFS AND IT SAYS SO.** A client is a client: it can be
 * rewritten, and a test over this module proves nothing about what the CREDENTIAL can do. The
 * load-bearing half is `apps/api/tests/Feature/Staff/KdsEndpointsTest.php`, which drives a
 * real device token at every transition route over HTTP and asserts the refusal — that one
 * holds even if every line of this client changes.
 *
 * What this file adds over it: the server-side proof says *the token cannot notify*, and this
 * says *the client does not even try*. A client that posted and was refused would be a kitchen
 * screen whose buttons silently fail, which is a `§12` defect rather than a security one, and
 * no server-side assertion can see it.
 */

/**
 * The url of a `fetch` input, whichever of the three shapes it is.
 *
 * `String(input)` is wrong for two of them: a `Request` stringifies to `[object Object]`, which
 * is what `@typescript-eslint/no-base-to-string` catches and what would have made every url
 * assertion in this file compare against one constant.
 */
function requestUrl(input: string | URL | Request): string {
  if (typeof input === 'string') return input

  return input instanceof URL ? input.href : input.url
}

/** A `fetch` that records every call and answers whatever it is told to. */
function recorder(answer: Response | Error) {
  const calls: { url: string; init: RequestInit }[] = []

  /*
   * ⚠ TYPED AS `typeof fetch` RATHER THAN CAST TO IT, AND NOT `async`. Both were lint errors
   * and both were telling the truth: the cast was unnecessary once the parameters matched the
   * real signature, and an `async` function with no `await` in it is a promise wrapper written
   * the long way. `requestUrl` below is why the cast looked necessary — `String(input)` on a
   * `Request` gives `[object Object]`, which would have made every url assertion compare
   * against the same meaningless string.
   */
  const fetcher: typeof fetch = (input, init) => {
    calls.push({ url: requestUrl(input), init: init ?? {} })

    return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer)
  }

  return { calls, fetcher }
}

function board(sessions: unknown): Response {
  return new Response(JSON.stringify({ sessions }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

const ROW = {
  id: '01a00000-0000-7000-8000-000000000001',
  order_reference: 'A-1',
  status: 'waiting',
  created_at: '2026-11-02T10:00:00Z',
  ready_at: null,
}

describe('the kitchen screen client', () => {
  it('reads the board and maps the server’s own keys', async () => {
    const { fetcher } = recorder(board([ROW]))
    const answer = await fetchKdsBoard('', 'tok', fetcher)

    expect(answer).toEqual({
      kind: 'ok',
      orders: [
        {
          id: ROW.id,
          orderReference: 'A-1',
          status: 'waiting',
          createdAt: ROW.created_at,
        },
      ],
    })
  })

  it('makes exactly one request, and it is a GET', async () => {
    const { calls, fetcher } = recorder(board([ROW]))

    await fetchKdsBoard('', 'tok', fetcher)

    expect(calls).toHaveLength(1)
    // Read off the recorded call rather than off the source: this is the behavioural form of
    // "there is no write in this module".
    expect(calls[0]?.init.method).toBe('GET')
    expect(calls[0]?.url).toBe('/v1/pager-sessions')
  })

  it('sends the device token as a bearer credential and NO cookie', async () => {
    const { calls, fetcher } = recorder(board([]))

    await fetchKdsBoard('', 'the-code', fetcher)

    const init = calls[0]?.init
    const headers = init?.headers as Record<string, string> | undefined

    expect(headers?.Authorization).toBe('Bearer the-code')

    /*
     * ⚠ `omit` IS THE ASSERTION THAT MATTERS HERE. A kitchen tablet may well carry a staff
     * session cookie from the day somebody set it up on the same origin — and `ResolvePrincipal`
     * tries the `web` guard BEFORE `sanctum`, so a request carrying both credentials resolves
     * as the COOKIE's user and the board would be served against that session's tenant rather
     * than the token's. The screen's tenant would then depend on a cookie nobody on the wall
     * knows exists. `omit` makes the bearer token the only credential in the request.
     */
    expect(init?.credentials).toBe('omit')
    // And no CSRF header: there is no cookie to forge against, and a GET passes CSRF
    // unconditionally. A token sent here would be a value read from a cookie this request
    // deliberately does not send.
    expect(headers?.['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('never caches the board', async () => {
    const { calls, fetcher } = recorder(board([]))

    await fetchKdsBoard('', 'tok', fetcher)

    // The server sets `private, no-store` on this route (`§11` SECURITY M-2, 10b). The client
    // asking for the same thing is belt and braces on a SHARED kitchen device, where the
    // residual that header closes is the device's own HTTP cache keeping one restaurant's
    // order board on disk.
    expect(calls[0]?.init.cache).toBe('no-store')
  })

  it('strips a trailing slash from the api base rather than doubling it', async () => {
    const { calls, fetcher } = recorder(board([]))

    await fetchKdsBoard('https://api.example.test/', 'tok', fetcher)

    expect(calls[0]?.url).toBe('https://api.example.test/v1/pager-sessions')
  })

  describe('every answer is a distinct kind, because the screen differs', () => {
    it('maps 401 to unauthorised, which is what deletes the stored code', async () => {
      const { fetcher } = recorder(new Response(null, { status: 401 }))

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'unauthorised' })
    })

    it('maps 403 to unauthorised TOO, which is this surface’s own decision', async () => {
      /*
       * On the staff surface these differ and `staff/api.ts` keeps them apart. Here they
       * collapse: a device token holds one ability and reaches one route, so the only ways to
       * get a 403 are a token whose abilities no longer include `pager:read` or whose tenant
       * pin no longer matches its issuer's membership — and both have the same remedy as a
       * 401's, which is the only one available on a wall tablet: a new code.
       */
      const { fetcher } = recorder(new Response(null, { status: 403 }))

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'unauthorised' })
    })

    it('maps 429 to throttled and carries the server’s own Retry-After', async () => {
      const { fetcher } = recorder(
        new Response(null, { status: 429, headers: { 'Retry-After': '12' } }),
      )

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'throttled', retryAfter: 12 })
    })

    it('answers a null Retry-After rather than a guess when the header is absent or unreadable', async () => {
      // `null` and not a number, because `resync.ts`'s jittered doubling is the fallback and an
      // invented number would be worse than it. `R-224`'s own reasoning.
      const absent = recorder(new Response(null, { status: 429 }))
      const garbage = recorder(
        new Response(null, { status: 429, headers: { 'Retry-After': 'soon' } }),
      )
      const zero = recorder(new Response(null, { status: 429, headers: { 'Retry-After': '0' } }))

      expect(await fetchKdsBoard('', 'tok', absent.fetcher)).toEqual({
        kind: 'throttled',
        retryAfter: null,
      })
      expect(await fetchKdsBoard('', 'tok', garbage.fetcher)).toEqual({
        kind: 'throttled',
        retryAfter: null,
      })
      // A hint of 0 would be a busy loop, so it is refused as a hint rather than honoured.
      expect(await fetchKdsBoard('', 'tok', zero.fetcher)).toEqual({
        kind: 'throttled',
        retryAfter: null,
      })
    })

    it('maps a thrown fetch to unreachable, NOT to error', async () => {
      // The distinction is the whole reason both kinds exist: `offline` keeps the orders and
      // says they are the last ones we could reach; `error` is a fault. Folding them would
      // tell a kitchen we are broken when their wifi dropped.
      const { fetcher } = recorder(new TypeError('Failed to fetch'))

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'unreachable' })
    })

    it('maps a 5xx to error', async () => {
      const { fetcher } = recorder(new Response(null, { status: 503 }))

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'error' })
    })

    it('maps a 200 whose body it cannot read to error, because the server DID reply', async () => {
      const { fetcher } = recorder(
        new Response('not json', { status: 200, headers: { 'Content-Type': 'application/json' } }),
      )

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'error' })
    })

    it('maps a 200 with no sessions array to error rather than an empty board', async () => {
      // ⚠ NOT AN EMPTY BOARD. *No orders* and *we could not read the answer* are different
      // facts, and a kitchen screen showing "Nothing is waiting" during a contract break is
      // the one failure that looks like success.
      const { fetcher } = recorder(board(undefined))

      expect(await fetchKdsBoard('', 'tok', fetcher)).toEqual({ kind: 'error' })
    })
  })

  describe('a row this build cannot render is dropped, never defaulted', () => {
    it('drops a row missing any of the four required keys', async () => {
      const { fetcher } = recorder(
        board([
          { ...ROW, order_reference: '' },
          { ...ROW, id: null },
          { ...ROW, created_at: undefined },
          ROW,
        ]),
      )

      const answer = await fetchKdsBoard('', 'tok', fetcher)

      // Exactly one survivor, and it is the complete row. A blank order reference on a screen
      // read from two metres is the unexplained value `§12` forbids.
      expect(answer.kind === 'ok' && answer.orders).toHaveLength(1)
    })

    it('drops a status this build does not know, rather than rendering the raw enum value', async () => {
      const { fetcher } = recorder(board([{ ...ROW, status: 'awaiting_collection' }]))
      const answer = await fetchKdsBoard('', 'tok', fetcher)

      // Checked against the GENERATED contract, so a status added in PHP and not yet mirrored
      // costs one missing row rather than putting `awaiting_collection` in front of a cook.
      expect(answer).toEqual({ kind: 'ok', orders: [] })
    })

    it('ignores a non-object entry without throwing', async () => {
      const { fetcher } = recorder(board([null, 'A-1', 42, ROW]))
      const answer = await fetchKdsBoard('', 'tok', fetcher)

      expect(answer.kind === 'ok' && answer.orders).toHaveLength(1)
    })
  })
})
