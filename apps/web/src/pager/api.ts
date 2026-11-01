import { PAGER_STATUS, type PagerStatus } from '@qrpager/contracts'

import type { PresentedToken } from './token.ts'

/**
 * ================================================================================
 * THE CUSTOMER API CLIENT. Four calls, one credential, and it is in the BODY.
 * ================================================================================
 *
 * The routes are Task 4's and already exist (`apps/api/routes/customer.php`); this task
 * consumes them and adds none. All four are POST/DELETE with the token in the request
 * BODY -- which is `PresentedTokenRequest`'s own reasoning, quoted because it is the reason
 * a read is not a GET:
 *
 *   *"A request body is the only place on an HTTP request that is in none of: the request
 *   line, an access log, a `Referer` header, a proxy's URL trace, or a CDN cache key."*
 *
 * ⚠ AND THE SERVER REFUSES A TOKEN IN THE QUERY STRING WITH A 422 (`BODY_ONLY`), so this
 * client putting one there would not be a leak that works -- it would be an outage. There
 * is no code path below that builds a URL from anything but a constant.
 *
 * ⚠ NO ERROR THIS MODULE PRODUCES CARRIES THE TOKEN. Not in a message, not in a thrown
 * object, not in a rejected promise's value. `§4 › Data` is *"never logged … never in an
 * error body"*, and a client exception string reaches an error reporter, a console and a
 * crash log. `apps/web/test/api.test.ts` drives every failure path and asserts the token
 * substring appears in nothing the module returns or throws.
 *
 * THE FOUR ANSWERS THE POLL GIVES ARE KEPT DISTINCT, because `§3` row 9's clause (c) says
 * a client that conflates them *"either abandons a live session or hammers a degraded
 * one"*:
 *
 *   200                              a live status
 *   404 `…/problems/stale`           this server holds no live status -> RESOLVE AGAIN
 *   503 `…/problems/unavailable`     come back shortly, nothing is wrong -> BACK OFF
 *   anything else / thrown           unreachable or unknown
 */

/** The problem `type` URIs this client distinguishes. Asserted against `docs/openapi.json`. */
export const PROBLEM_TYPES = {
  notFound: 'https://qrpager.dev/problems/not-found',
  expired: 'https://qrpager.dev/problems/expired',
  stale: 'https://qrpager.dev/problems/stale',
  unavailable: 'https://qrpager.dev/problems/unavailable',
} as const

export interface SessionSnapshot {
  readonly status: PagerStatus
  readonly orderReference: string
  readonly expiresAt: string
  /** The server's own timestamp for this answer. The elapsed clock is computed from it. */
  readonly asOf: string
  readonly pollAfterSeconds: number
}

export type ResolveResult =
  | { readonly kind: 'ok'; readonly snapshot: SessionSnapshot }
  | { readonly kind: 'expired' }
  | { readonly kind: 'unknown' }
  /** A 5xx, or a 4xx this client has no screen for. Something is wrong on our side. */
  | { readonly kind: 'server' }
  /**
   * ⚠ A 2xx WE CANNOT INTERPRET, AND IT IS A DIFFERENT ANSWER FROM A 5xx.
   *
   * The server said yes and sent something this build cannot read: a malformed body, or a
   * `status` outside the GENERATED union -- which is reachable only across a deploy. `§3.6`
   * of the ticket separates the two because the COPY differs: a 5xx says *"this is on us,
   * not on you"*, and this one says *"we're not sure what"*. Claiming to know would be the
   * dishonesty `§4.1`'s *"honest message"* is about.
   *
   * ⚠ AND WITHOUT IT `error.unknown` WAS AN UNREACHABLE STATE -- a screen in
   * `JOURNEY_STATES` that no event could produce. Found by `browser/one-handed.spec.ts`,
   * which iterates that export and timed out at 30 s trying to reach it.
   */
  | { readonly kind: 'unreadable' }
  | { readonly kind: 'unreachable' }

export type PollResult =
  | { readonly kind: 'ok'; readonly snapshot: SessionSnapshot }
  | { readonly kind: 'stale' }
  | { readonly kind: 'unavailable'; readonly retryAfterSeconds: number | null }
  | { readonly kind: 'unreachable' }

export interface SubscribeBody {
  readonly endpoint: string
  readonly p256dh: string
  readonly auth: string
}

/** What the client needs to talk to the API. Injected, so tests drive a real server. */
export interface ApiConfig {
  /** Empty means same-origin. */
  readonly base: string
  readonly fetch: typeof fetch
}

const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' } as const

function isPagerStatus(value: unknown): value is PagerStatus {
  return typeof value === 'string' && (PAGER_STATUS as readonly string[]).includes(value)
}

/**
 * Narrow a parsed body into a snapshot, or null.
 *
 * ⚠ A STATUS THIS BUILD DOES NOT HAVE IS A MISS, NOT A NEW STATUS. `PagerStatus` is the
 * generated union; a value outside it can only arrive across a deploy, and the honest answer
 * is the `unknown` error state rather than a screen rendering a status it cannot interpret.
 * This is the same decision `PagerStatusView::decode()` already makes server-side -- *"a
 * stored value this enum cannot map is treated as a corrupt projection and answered as a
 * MISS, never as a new status"* -- applied to the wire.
 */
export function toSnapshot(body: unknown): SessionSnapshot | null {
  if (typeof body !== 'object' || body === null) return null

  const record = body as Record<string, unknown>
  const status = record.status
  const reference = record.order_reference
  const expires = record.expires_at
  const asOf = record.as_of
  const pollAfter = record.poll_after_seconds

  if (
    !isPagerStatus(status) ||
    typeof reference !== 'string' ||
    typeof expires !== 'string' ||
    typeof asOf !== 'string' ||
    typeof pollAfter !== 'number' ||
    !Number.isFinite(pollAfter)
  ) {
    return null
  }

  return {
    status,
    orderReference: reference,
    expiresAt: expires,
    asOf,
    // Floored at 1, the same floor `CustomerPagerController::pollInterval()` applies, so a
    // server that ever answered 0 cannot turn this client into a busy loop.
    pollAfterSeconds: Math.max(1, Math.floor(pollAfter)),
  }
}

/** The `type` of a problem document, or null when the body is not one. */
function problemType(body: unknown): string | null {
  if (typeof body !== 'object' || body === null) return null
  const type = (body as Record<string, unknown>).type

  return typeof type === 'string' ? type : null
}

/**
 * One request. `no-store` and `same-origin` credentials: there is no cookie on this surface
 * and asking for none is narrower than letting the default decide.
 */
async function post(
  config: ApiConfig,
  path: string,
  body: Readonly<Record<string, unknown>>,
  method: 'POST' | 'DELETE' = 'POST',
): Promise<{ status: number; body: unknown } | null> {
  try {
    const response = await config.fetch(`${config.base}${path}`, {
      method,
      headers: JSON_HEADERS,
      body: JSON.stringify(body),
      cache: 'no-store',
      credentials: 'omit',
    })

    let parsed: unknown = null

    try {
      parsed = (await response.json()) as unknown
    } catch {
      // A 503 from an edge that is not this application can be HTML. An unparseable body is
      // not an error here: the STATUS is what the caller switches on, and a null body falls
      // through to the same branch an unrecognised one does.
      parsed = null
    }

    return { status: response.status, body: parsed }
  } catch {
    // ⚠ THE CAUGHT ERROR IS DISCARDED RATHER THAN WRAPPED. `fetch` rejects with a message
    // that includes the request URL in several engines, and the request body is reachable
    // from a wrapped error in a debugger. Nothing about the failure is worth the risk of
    // carrying the token out of this function -- the caller only needs "unreachable".
    return null
  }
}

export async function resolveSession(
  config: ApiConfig,
  token: PresentedToken,
): Promise<ResolveResult> {
  const answer = await post(config, '/v1/pager/session', { token })

  if (answer === null) return { kind: 'unreachable' }

  if (answer.status >= 200 && answer.status < 300) {
    const snapshot = toSnapshot(answer.body)

    return snapshot === null ? { kind: 'unreadable' } : { kind: 'ok', snapshot }
  }

  if (answer.status === 409 && problemType(answer.body) === PROBLEM_TYPES.expired) {
    return { kind: 'expired' }
  }

  if (answer.status === 404) return { kind: 'unknown' }

  return { kind: 'server' }
}

export async function pollStatus(config: ApiConfig, token: PresentedToken): Promise<PollResult> {
  const answer = await post(config, '/v1/pager/status', { token })

  if (answer === null) return { kind: 'unreachable' }

  if (answer.status === 200) {
    const snapshot = toSnapshot(answer.body)

    // A 200 whose body does not parse is NOT treated as a live status. It falls to
    // `unavailable` rather than `stale`, because `stale` sends the client to re-resolve and
    // a malformed 200 is not evidence the projection is gone.
    return snapshot === null
      ? { kind: 'unavailable', retryAfterSeconds: null }
      : { kind: 'ok', snapshot }
  }

  if (answer.status === 404) {
    // `…/problems/stale` means re-open the session. A plain 404 -- a route that is not
    // there at all, a misconfigured proxy -- is NOT that instruction, and treating it as one
    // would send the client into a resolve/poll loop against a server that has no pager
    // routes. So the type is required, not assumed from the status.
    return problemType(answer.body) === PROBLEM_TYPES.stale
      ? { kind: 'stale' }
      : { kind: 'unreachable' }
  }

  if (answer.status === 503 || answer.status === 429) {
    const body = answer.body
    const hinted =
      typeof body === 'object' && body !== null
        ? (body as Record<string, unknown>).poll_after_seconds
        : null

    return {
      kind: 'unavailable',
      retryAfterSeconds: typeof hinted === 'number' && Number.isFinite(hinted) ? hinted : null,
    }
  }

  return { kind: 'unreachable' }
}

export type SubscribeResult = 'registered' | 'refused' | 'unreachable'

export async function registerDevice(
  config: ApiConfig,
  token: PresentedToken,
  subscription: SubscribeBody,
): Promise<SubscribeResult> {
  const answer = await post(config, '/v1/pager/subscription', {
    token,
    endpoint: subscription.endpoint,
    p256dh: subscription.p256dh,
    auth: subscription.auth,
  })

  if (answer === null) return 'unreachable'

  // 201 created, 200 keys updated. Task 4's contract: *"Calling this again with the same
  // endpoint updates the stored keys rather than adding a second device, so it is safe to
  // repeat on every page load"* -- which is what makes `reconcileSubscription()` possible
  // without a new endpoint.
  return answer.status === 201 || answer.status === 200 ? 'registered' : 'refused'
}

export async function forgetDevice(
  config: ApiConfig,
  token: PresentedToken,
  endpoint: string,
): Promise<SubscribeResult> {
  const answer = await post(config, '/v1/pager/subscription', { token, endpoint }, 'DELETE')

  if (answer === null) return 'unreachable'

  return answer.status === 200 ? 'registered' : 'refused'
}
