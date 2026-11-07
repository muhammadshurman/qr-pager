/**
 * ================================================================================
 * THE KITCHEN SCREEN'S API CLIENT. ONE FUNCTION, AND THE "ONE" IS THE SECURITY PROPERTY.
 * ================================================================================
 *
 * `src/staff/api.ts` carries a first-party COOKIE and twenty-odd calls. This one carries a
 * **bearer token** and makes exactly one request: `GET /v1/pager-sessions`. The two are
 * separate modules for `§4 › Tenancy`'s *two authentication surfaces, never confused* — and
 * here that separation does a second job, which `QRPAGER-27 §0` is built on.
 *
 * ================================================================================
 * ⚠ THERE IS NO WRITE IN THIS FILE, AND THAT IS WHAT MAKES 10c's INVARIANT STRUCTURAL
 * ================================================================================
 *
 * `QRPAGER-27 §0`: *"A KDS that loses the network mid-shift and reconnects re-sends NO
 * notification and shows NO duplicate row."* `§5` names the trap: *"a naive refetch after
 * reconnect can re-trigger sends."*
 *
 * A refetch can only re-trigger a send if there is a send to re-trigger. This client has
 * **no POST, no PATCH, no DELETE, no queue, no retry buffer and no outbox of its own** — so
 * there is no request for a reconnect to replay, and the invariant holds by construction
 * rather than by a guard somebody has to maintain.
 *
 * **That is asserted in three places rather than claimed here**, because a claim in a comment
 * is what `§11` calls an unproven guard:
 *
 *   1. `apps/web/test/kds-api.test.ts` drives this module and asserts every request it makes
 *      is a `GET` — reading the method off the recorded `fetch` calls, not off this file.
 *   2. `apps/api/tests/Feature/Staff/KdsEndpointsTest.php` drives a REAL device token at every
 *      transition route over HTTP and asserts the refusal. That is the half that holds even if
 *      this file is rewritten: the credential cannot notify, whatever a client asks.
 *   3. `tools/gate/bin/kds-resync-proof` drops a real browser's network and compares
 *      server-side dispatch counts across the reconnect, which is the clause verbatim.
 *
 * Layer 2 is the load-bearing one. A client is a client; the device token's ability list
 * (`Ability::issuableToDevice()` — a closed list of one, `pager:read`) is the thing an
 * attacker with the token cannot get past.
 *
 * ================================================================================
 * NO CSRF TOKEN, AND NO COOKIE
 * ================================================================================
 *
 * `credentials: 'omit'`, deliberately and not by default. A kitchen tablet may well have a
 * staff session cookie on it from the day somebody set it up on the same origin — and if this
 * request carried it, the board being rendered could be served against THAT session's tenant
 * rather than the token's. The two credentials would be resolved by whichever
 * `ResolvePrincipal` tries first (`web`, then `sanctum`), which means the screen's tenant
 * would depend on a cookie nobody on the wall knows exists. `omit` makes the bearer token the
 * only credential in the request, which is the only comprehensible answer.
 *
 * No CSRF token either: there is no cookie to forge against, and `PreventRequestForgery`
 * passes a GET unconditionally.
 */

import { PAGER_STATUS, type PagerStatus } from '@qrpager/contracts'

/**
 * One order on a kitchen screen.
 *
 * Deliberately the SAME SHAPE as `staff/api.ts`'s `BoardPager`, from the same endpoint, and
 * deliberately NOT an import of it: re-using that type would couple this module to the staff
 * client and a field added there for the staff board would silently become part of this
 * contract. Both read the one server response, and `KdsEndpointsTest` pins the keys.
 *
 * ⚠ NO ID IS RENDERED and no token can be — `token_hash` is not in the server's select list
 * and `qrpager_app` cannot read it at all. `id` is here because 10c's own resync is keyed by
 * it (`resync.ts`), which is the one thing on this screen that needs a stable identity.
 */
export interface KdsOrder {
  readonly id: string
  readonly orderReference: string
  readonly status: PagerStatus
  readonly createdAt: string
}

/**
 * What a poll can answer.
 *
 * `unauthorised` is a distinct kind and is NOT folded into `error`, for the reason
 * `storage.ts` gives: a device token expires at thirty days or is revoked from `/staff/devices`,
 * and in both cases the honest screen is *this device needs a new code* with the setup field,
 * not *something went wrong*. It is also the only kind that deletes the stored credential.
 */
export type KdsAnswer =
  | { readonly kind: 'ok'; readonly orders: readonly KdsOrder[] }
  | { readonly kind: 'unauthorised' }
  | { readonly kind: 'throttled'; readonly retryAfter: number | null }
  | { readonly kind: 'unreachable' }
  | { readonly kind: 'error' }

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null
}

function asString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Whether a string is a status this build knows, checked against the GENERATED list.
 *
 * Same function and same reasoning as `staff/api.ts`'s: a status PHP adds and the generator
 * has not mirrored fails this check and the row is dropped, which is the safe direction. A
 * kitchen screen showing `awaiting_collection` to a cook is worse than one row short.
 */
function isPagerStatus(value: string): value is PagerStatus {
  return (PAGER_STATUS as readonly string[]).includes(value)
}

/**
 * Read the board.
 *
 * @param apiBase empty in every real build, so the URL is same-origin.
 * @param token   the device token, as pasted.
 * @param fetcher injected so a test drives this without a network and can read back the
 *                method and the headers of every call it made — which is how the
 *                "no write in this module" assertion is made against behaviour.
 */
export async function fetchKdsBoard(
  apiBase: string,
  token: string,
  fetcher: typeof fetch = fetch,
): Promise<KdsAnswer> {
  let response: Response

  try {
    response = await fetcher(`${apiBase.replace(/\/$/, '')}/v1/pager-sessions`, {
      method: 'GET',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      // See the header: the bearer token is the only credential in this request.
      credentials: 'omit',
      cache: 'no-store',
    })
  } catch {
    return { kind: 'unreachable' }
  }

  /*
   * 401 OR 403 — ONE ANSWER, AND THAT IS A DECISION RATHER THAN A SHORTCUT.
   *
   * On the staff surface the two differ and `staff/api.ts` keeps them apart for a good reason:
   * a 403 there means *this person may not do this*, which is a sentence about a role and has a
   * different remedy from *sign in again*.
   *
   * On this surface they collapse, because a device token holds exactly one ability and reaches
   * exactly one route. The only ways to get a 403 here are a token whose abilities no longer
   * include `pager:read` or whose tenant pin no longer matches its issuer's membership — and in
   * both cases the remedy is identical to a 401's and is the only one available on a wall
   * tablet: **this screen needs a new code from the staff surface.** Two sentences saying that
   * would be two ways of writing one instruction, and `§12` would rather have one.
   *
   * ⚠ 419 IS DELIBERATELY NOT LISTED, unlike in `staff/api.ts`: that is a CSRF answer and
   * cannot reach a bearer GET, because this surface has no session to mismatch.
   *
   * ⚠ AND THIS REASONING USED TO HANG ON AN EXPORTED CONST — `KDS_FORBIDDEN_IS_UNAUTHORISED` —
   * WITH NO CONSUMER ANYWHERE. A value invented to carry a comment is dead code that reads as
   * API, and the next person to see it exported would reasonably wonder who reads it. The
   * comment belongs on the branch it describes.
   */
  if (response.status === 401 || response.status === 403) return { kind: 'unauthorised' }

  if (response.status === 429) {
    const header = response.headers.get('Retry-After')
    const seconds = header === null ? Number.NaN : Number.parseInt(header, 10)

    return {
      kind: 'throttled',
      retryAfter: Number.isFinite(seconds) && seconds > 0 ? seconds : null,
    }
  }

  if (!response.ok) return { kind: 'error' }

  let body: unknown

  try {
    body = await response.json()
  } catch {
    return { kind: 'error' }
  }

  const raw = asRecord(body)?.sessions

  if (!Array.isArray(raw)) return { kind: 'error' }

  const orders: KdsOrder[] = []

  for (const entry of raw) {
    const row = asRecord(entry)
    const id = row === null ? null : asString(row.id)
    const reference = row === null ? null : asString(row.order_reference)
    const status = row === null ? null : asString(row.status)
    const createdAt = row === null ? null : asString(row.created_at)

    // A row missing any of the four is DROPPED rather than defaulted — `staff/api.ts`'s own
    // rule and its reason: a blank order reference on a screen read from two metres is an
    // unexplained value, which is the `§12` bar this surface is held to.
    if (id === null || reference === null || status === null || createdAt === null) continue
    if (!isPagerStatus(status)) continue

    orders.push({ id, orderReference: reference, status, createdAt })
  }

  return { kind: 'ok', orders }
}
