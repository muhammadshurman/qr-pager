import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'

import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import {
  PROBLEM_TYPES,
  forgetDevice,
  pollStatus,
  registerDevice,
  resolveSession,
  toSnapshot,
  type ApiConfig,
} from '../src/pager/api.ts'
import type { PresentedToken } from '../src/pager/token.ts'

/**
 * ================================================================================
 * THE CLIENT, DRIVEN OVER A REAL SOCKET. *A test that crosses HTTP is not one that does not.*
 * ================================================================================
 *
 * ⚠ `fetch` IS NOT STUBBED, DELIBERATELY. The four answers Task 4's polling endpoint gives
 * are distinguished by STATUS CODE **and** PROBLEM TYPE together, and the client's whole job
 * on that route is telling them apart -- `§3` row 9 clause (c): *"A client that treats them
 * alike either abandons a live session or hammers a degraded one."* A stubbed `fetch` would
 * assert the client's own assumptions about those answers rather than its behaviour against
 * a wire, which is the vacuous shape this project records four instances of.
 *
 * So there is a real `node:http` server below, a real socket, real headers and real JSON
 * parsing. What it is NOT is the Laravel application: the full journey against the real API
 * is `browser/pager.spec.ts`, which runs `php artisan serve` and `next start`. This file is
 * about the client's own branching, which needs a server that can be told to answer badly.
 *
 * THE SERVER RECORDS EVERY REQUEST IT RECEIVES, and three assertions read that log rather
 * than the client's return value -- because `§4 › Data`'s *"never in a URL query string"* is
 * a property of what went out, not of what came back.
 */

const TOKEN = 'AbCdEfGhIjKlMnOpQrStUv' as PresentedToken

interface Recorded {
  readonly method: string
  readonly url: string
  readonly body: string
  readonly headers: Record<string, string | string[] | undefined>
}

let server: Server
let base = ''
const received: Recorded[] = []
/** What the next request gets. Set per test. */
let answer: { status: number; body: unknown; raw?: string } = { status: 200, body: {} }

beforeAll(async () => {
  server = createServer((request: IncomingMessage, response: ServerResponse) => {
    const chunks: Buffer[] = []

    request.on('data', (chunk: Buffer) => {
      chunks.push(chunk)
    })
    request.on('end', () => {
      received.push({
        method: request.method ?? '',
        url: request.url ?? '',
        body: Buffer.concat(chunks).toString('utf8'),
        headers: request.headers,
      })

      response.writeHead(answer.status, {
        'Content-Type': 'application/problem+json',
        // The real surface answers `no-store` on every response, including its 200s.
        'Cache-Control': 'no-store',
      })
      response.end(answer.raw ?? JSON.stringify(answer.body))
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

function api(): ApiConfig {
  received.length = 0

  return { base, fetch: (...args) => fetch(...args) }
}

const OK_BODY = {
  status: 'waiting',
  order_reference: 'A-42',
  expires_at: '2026-11-01T14:00:00Z',
  as_of: '2026-11-01T12:00:00Z',
  poll_after_seconds: 7,
}

describe('the token goes in the BODY and nowhere else', () => {
  it('puts it in the body on all four calls, and in no URL', async () => {
    /**
     * ⚠ ASSERTED AGAINST WHAT THE SERVER RECEIVED, not against the client's return value.
     * `§4 › Data`: the raw token is *"never logged, never in a URL query string"*, and `§3`
     * row 3 as amended adds the path. The server's own `BODY_ONLY` list REFUSES a token in
     * the query string with a 422, so a client that put one there would not be a quiet leak
     * -- it would be an outage. Both halves are worth asserting.
     */
    answer = { status: 200, body: OK_BODY }
    const config = api()

    await resolveSession(config, TOKEN)
    await pollStatus(config, TOKEN)
    await registerDevice(config, TOKEN, {
      endpoint: 'https://push.example/x',
      p256dh: 'p',
      auth: 'a',
    })
    await forgetDevice(config, TOKEN, 'https://push.example/x')

    expect(received.length).toBe(4)

    for (const request of received) {
      expect(request.url, request.url).not.toContain(TOKEN)
      expect(request.url).not.toContain('?')
      expect(request.body).toContain(TOKEN)
      expect(['POST', 'DELETE']).toContain(request.method)
    }

    expect(received.map((r) => `${r.method} ${r.url}`)).toEqual([
      'POST /v1/pager/session',
      'POST /v1/pager/status',
      'POST /v1/pager/subscription',
      'DELETE /v1/pager/subscription',
    ])
  })

  it('sends no cookie and asks for no credentials', async () => {
    // There is no cookie on this surface. Asking for none is narrower than letting the
    // default decide, and a cookie sent here would be a second credential nobody designed.
    answer = { status: 200, body: OK_BODY }
    const config = api()

    await pollStatus(config, TOKEN)

    expect(received[0]?.headers.cookie).toBeUndefined()
  })
})

describe('resolveSession -- the five answers', () => {
  it('200 with a parseable body gives a snapshot', async () => {
    answer = { status: 200, body: OK_BODY }

    expect(await resolveSession(api(), TOKEN)).toEqual({
      kind: 'ok',
      snapshot: {
        status: 'waiting',
        orderReference: 'A-42',
        expiresAt: '2026-11-01T14:00:00Z',
        asOf: '2026-11-01T12:00:00Z',
        pollAfterSeconds: 7,
      },
    })
  })

  it('409 with the expired problem type gives expired', async () => {
    answer = {
      status: 409,
      body: { type: PROBLEM_TYPES.expired, title: 'That resource has expired.' },
    }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'expired' })
  })

  it('409 with a DIFFERENT problem type is not expired', async () => {
    // ⚠ THE TYPE DECIDES, NOT THE STATUS. `ProblemType::Conflict` is also a 409, and reading
    // it as "your code expired" would tell a customer their two hours ran out when something
    // else entirely happened.
    answer = { status: 409, body: { type: 'https://qrpager.dev/problems/conflict' } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })

  it('404 gives unknown', async () => {
    answer = { status: 404, body: { type: PROBLEM_TYPES.notFound } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'unknown' })
  })

  it('a 200 whose body does not parse is a server error, never a snapshot', async () => {
    answer = { status: 200, body: {}, raw: 'not json at all' }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })

  it('a 200 missing a field is a server error', async () => {
    answer = { status: 200, body: { status: 'waiting', order_reference: 'A-42' } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })

  it('a 200 carrying a status this build does not have is a server error', async () => {
    // ⚠ A MISS, NOT A NEW STATUS. `PagerStatus` is the generated union, so this is reachable
    // only across a deploy -- and the honest answer is the error state rather than a screen
    // rendering a status it cannot interpret. The same decision `PagerStatusView::decode()`
    // already makes server-side, applied to the wire.
    answer = { status: 200, body: { ...OK_BODY, status: 'refunded' } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })

  it('an unreachable server is unreachable, and the error is DISCARDED', async () => {
    // Pointed at a closed port on purpose.
    const result = await resolveSession(
      { base: 'http://127.0.0.1:1', fetch: (...a) => fetch(...a) },
      TOKEN,
    )

    expect(result).toEqual({ kind: 'unreachable' })
    // ⚠ NOTHING ABOUT THE FAILURE IS CARRIED OUT OF THE MODULE. `fetch` rejects with a
    // message that includes the request URL in several engines, and the request body is
    // reachable from a wrapped error in a debugger. The caller only needs "unreachable".
    expect(JSON.stringify(result)).not.toContain(TOKEN)
  })

  it('5xx is a server error', async () => {
    answer = { status: 503, body: { type: PROBLEM_TYPES.unavailable } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })
})

describe('pollStatus -- the four answers, and they are four', () => {
  it('200 gives the live status', async () => {
    answer = { status: 200, body: { ...OK_BODY, status: 'ready' } }
    const result = await pollStatus(api(), TOKEN)

    expect(result.kind).toBe('ok')
    expect(result.kind === 'ok' && result.snapshot.status).toBe('ready')
  })

  it('404 WITH the stale type means re-open the session', async () => {
    answer = { status: 404, body: { type: PROBLEM_TYPES.stale, poll_after_seconds: 7 } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'stale' })
  })

  it('404 WITHOUT the stale type is unreachable, not an instruction to re-resolve', async () => {
    /**
     * ⚠ THE DISTINCTION THAT STOPS A LOOP. A plain 404 -- a route that is not there, a
     * misconfigured proxy, a CDN serving its own error page -- is NOT the instruction
     * *"open the session again"*. Reading it as one sends the client into a resolve/poll
     * loop against a server that has no pager routes at all.
     */
    answer = { status: 404, body: { type: 'https://qrpager.dev/problems/not-found' } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unreachable' })

    answer = { status: 404, body: {}, raw: '<html>Not Found</html>' }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unreachable' })
  })

  it('503 means come back shortly, and carries the server hint through', async () => {
    answer = { status: 503, body: { type: PROBLEM_TYPES.unavailable, poll_after_seconds: 30 } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: 30 })
  })

  it('503 with no hint is still unavailable, with no hint', async () => {
    answer = { status: 503, body: { type: PROBLEM_TYPES.unavailable } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: null })
  })

  it('429 backs off like a 503, because the answer means the same thing to a poller', async () => {
    answer = {
      status: 429,
      body: { type: 'https://qrpager.dev/problems/rate-limited', poll_after_seconds: 12 },
    }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: 12 })
  })

  it('a malformed 200 falls to unavailable, NOT to stale', async () => {
    // ⚠ THE DIRECTION MATTERS. `stale` sends the client to re-resolve, and a malformed 200 is
    // not evidence the projection is gone -- it is evidence something between us is broken.
    answer = { status: 200, body: { nonsense: true } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: null })
  })

  it('an unreachable poll is unreachable', async () => {
    expect(
      await pollStatus({ base: 'http://127.0.0.1:1', fetch: (...a) => fetch(...a) }, TOKEN),
    ).toEqual({ kind: 'unreachable' })
  })
})

describe('the subscription calls', () => {
  it('treats 201 and 200 alike, which is what makes reconciling on every launch safe', async () => {
    // Task 4's contract: *"Calling this again with the same endpoint updates the stored keys
    // rather than adding a second device, so it is safe to repeat on every page load."*
    for (const status of [200, 201]) {
      answer = { status, body: { registered: true } }

      expect(
        await registerDevice(api(), TOKEN, {
          endpoint: 'https://push.example/x',
          p256dh: 'p',
          auth: 'a',
        }),
      ).toBe('registered')
    }
  })

  it('refuses on a 409 or a 422 rather than reporting success', async () => {
    for (const status of [409, 422, 404]) {
      answer = { status, body: { type: PROBLEM_TYPES.expired } }

      expect(
        await registerDevice(api(), TOKEN, {
          endpoint: 'https://push.example/x',
          p256dh: 'p',
          auth: 'a',
        }),
      ).toBe('refused')
    }
  })

  it('sends the endpoint and both keys, named as the server validates them', async () => {
    answer = { status: 201, body: { registered: true } }
    const config = api()

    await registerDevice(config, TOKEN, {
      endpoint: 'https://push.example/x',
      p256dh: 'KEY',
      auth: 'AUTH',
    })

    const body = JSON.parse(received[0]?.body ?? '{}') as Record<string, unknown>

    expect(Object.keys(body).sort()).toEqual(['auth', 'endpoint', 'p256dh', 'token'])
    expect(body.p256dh).toBe('KEY')
    expect(body.auth).toBe('AUTH')
  })

  it('deletes with the endpoint in the body, never in the query string', async () => {
    // `BODY_ONLY` lists `endpoint` as well as `token`: *"a URL in a URL is the same
    // disclosure in miniature"*.
    answer = { status: 200, body: { registered: false } }
    const config = api()

    await forgetDevice(config, TOKEN, 'https://push.example/x')

    expect(received[0]?.method).toBe('DELETE')
    expect(received[0]?.url).toBe('/v1/pager/subscription')
    expect(received[0]?.body).toContain('https://push.example/x')
  })
})

describe('toSnapshot -- the narrowing, without a server', () => {
  it('floors poll_after_seconds at one, as the server does', () => {
    expect(toSnapshot({ ...OK_BODY, poll_after_seconds: 0 })?.pollAfterSeconds).toBe(1)
    expect(toSnapshot({ ...OK_BODY, poll_after_seconds: -9 })?.pollAfterSeconds).toBe(1)
    expect(toSnapshot({ ...OK_BODY, poll_after_seconds: 7.9 })?.pollAfterSeconds).toBe(7)
  })

  it('refuses a non-finite interval rather than scheduling NaN milliseconds later', () => {
    expect(toSnapshot({ ...OK_BODY, poll_after_seconds: Number.NaN })).toBeNull()
    expect(toSnapshot({ ...OK_BODY, poll_after_seconds: Number.POSITIVE_INFINITY })).toBeNull()
  })

  it('refuses a non-object, a null and an array', () => {
    expect(toSnapshot(null)).toBeNull()
    expect(toSnapshot('string')).toBeNull()
    expect(toSnapshot(42)).toBeNull()
    expect(toSnapshot([])).toBeNull()
  })

  it('refuses every single missing field in turn', () => {
    for (const key of Object.keys(OK_BODY)) {
      // `Reflect.deleteProperty`, not `delete partial[key]`: the lint rule forbids deleting
      // a computed key, and it is right to -- the two are the same operation and only one
      // reads as deliberate.
      const partial: Record<string, unknown> = { ...OK_BODY }
      Reflect.deleteProperty(partial, key)

      expect(toSnapshot(partial), `without ${key}`).toBeNull()
    }
  })
})

describe('the branches that only an unreachable or malformed answer reaches', () => {
  it('reports unreachable from BOTH subscription calls, not only the register', async () => {
    const dead = {
      base: 'http://127.0.0.1:1',
      fetch: (...a: Parameters<typeof fetch>) => fetch(...a),
    }

    expect(
      await registerDevice(dead, TOKEN, {
        endpoint: 'https://push.example/x',
        p256dh: 'p',
        auth: 'a',
      }),
    ).toBe('unreachable')
    // The DELETE path has its own early return and its own caller -- the end-of-session
    // cleanup -- so it is asserted separately rather than assumed to share the register's.
    expect(await forgetDevice(dead, TOKEN, 'https://push.example/x')).toBe('unreachable')
  })

  it('refuses a DELETE that did not answer 200', async () => {
    // A 404 here means the session is gone, which is not success: the caller must not report
    // the device forgotten when the server never heard of it.
    answer = { status: 404, body: { type: PROBLEM_TYPES.notFound } }

    expect(await forgetDevice(api(), TOKEN, 'https://push.example/x')).toBe('refused')
  })

  it('treats a 429 exactly as it treats a 503, including the hint', async () => {
    // The rate limiter's answer means the same thing to a poller as the shedder's. Asserted
    // separately because the two statuses arrive from two different middlewares.
    answer = { status: 429, body: { type: 'https://qrpager.dev/problems/rate-limited' } }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: null })
  })

  it('ignores a non-object 503 body rather than reading a hint off it', async () => {
    answer = { status: 503, body: {}, raw: '"a bare string"' }

    expect(await pollStatus(api(), TOKEN)).toEqual({ kind: 'unavailable', retryAfterSeconds: null })
  })

  it('treats a problem document with a non-string `type` as having none', async () => {
    // A 409 whose `type` is a number is not the expired type, and reading it loosely would
    // tell a customer their code expired on the strength of a malformed body.
    answer = { status: 409, body: { type: 42 } }

    expect(await resolveSession(api(), TOKEN)).toEqual({ kind: 'server' })
  })
})
