/**
 * ================================================================================
 * THE STAFF API CLIENT. One credential, and it is a COOKIE rather than a token.
 * ================================================================================
 *
 * The customer client (`src/pager/api.ts`) carries a 128-bit token in a request body. This
 * one carries nothing: the credential is the first-party session cookie Task 5's login
 * establishes, which is why this file has no `token` parameter anywhere and no value worth
 * keeping in memory. `§4 › Tenancy`'s *two authentication surfaces, never confused* is why
 * they are two modules rather than one with a flag.
 *
 * ================================================================================
 * CSRF, AND WHY THE TOKEN IS SENT EVEN THOUGH THE SERVER WOULD NOT ASK
 * ================================================================================
 *
 * Measured in-session against `laravel/framework v13.38.0` rather than recalled
 * (`QRPAGER-25` pre-coding answer 4):
 *
 *   - `PreventRequestForgery::getTokenFromRequest()` reads `_token`, then `X-CSRF-TOKEN`,
 *     then DECRYPTS `X-XSRF-TOKEN`;
 *   - `newCookie()` writes `XSRF-TOKEN` with `httpOnly: false`, so a first-party script can
 *     read it, and `EncryptCookies::$except` is `[]`, so what it reads is the encrypted
 *     value the middleware expects back;
 *   - `handle()` ALSO passes on `Sec-Fetch-Site: same-origin` alone.
 *
 * So a same-origin fetch from this app satisfies CSRF with no token at all. **The token is
 * sent anyway.** The origin check is a property of the browser's own header and the token is
 * the mechanism; relying on the one the server would accept by accident is exactly the
 * assumption `§8` forbids, and the day a reverse proxy strips `Sec-Fetch-Site` is not the
 * day to discover which one was load-bearing.
 *
 * The cookie is primed by the first GET: a read verb passes CSRF unconditionally, so
 * `GET /v1/auth/me` both answers "who am I" and leaves the cookie behind.
 *
 * ================================================================================
 * EVERY FAILURE IS A DISTINCT ANSWER, BECAUSE THE COPY DIFFERS
 * ================================================================================
 *
 *   ok             a body this build can read
 *   unauthorised   401 -- no session, or it expired. NOT an error: it is the normal end of
 *                  a shift, and the shell shows sign-in rather than a problem
 *   forbidden      403 -- this person may not do this. No retry; retrying changes nothing
 *   notFound       404
 *   unreachable    the network did not answer. "No connection", and the data is unchanged
 *   server         a 5xx, or a 2xx this build cannot parse. "On us, not on you"
 *
 * A client that folded `unauthorised` into `server` would show a restaurant owner an error
 * page at the end of every shift, and one that folded `unreachable` into `server` would
 * tell them we are broken when their wifi dropped.
 */

export type StaffFailure = 'unauthorised' | 'forbidden' | 'notFound' | 'unreachable' | 'server'

export type StaffResult<T> =
  { readonly kind: 'ok'; readonly value: T } | { readonly kind: StaffFailure }

export interface StaffTenant {
  readonly name: string
  readonly slug: string
  readonly timezone: string
  readonly status: string
}

export interface StaffMembership {
  readonly tenantId: string
  readonly role: string
}

export interface StaffIdentity {
  readonly name: string
  readonly memberships: readonly StaffMembership[]
  readonly activeTenant: string | null
}

export interface StaffDevice {
  readonly id: string
  readonly name: string
  readonly expiresAt: string | null
  readonly lastUsedAt: string | null
}

export interface IssuedDevice {
  readonly id: string
  readonly token: string
  readonly expiresAt: string
}

/**
 * The `XSRF-TOKEN` cookie, url-decoded.
 *
 * ⚠ `decodeURIComponent` IS NOT OPTIONAL. Laravel's cookie value is base64 with `=`
 * padding and the browser percent-encodes it on the way into `document.cookie`; sent back
 * raw, the decrypt fails, `getTokenFromRequest()` returns `''` and the request is a 419 that
 * names nothing. That is the single most common way this integration is got wrong.
 */
function xsrfToken(): string | null {
  if (typeof document === 'undefined') return null

  for (const part of document.cookie.split(';')) {
    const [name, ...rest] = part.trim().split('=')

    if (name === 'XSRF-TOKEN') {
      try {
        return decodeURIComponent(rest.join('='))
      } catch {
        // A malformed cookie is not worth throwing over: the request will be refused and
        // the shell will show sign-in, which is the right answer either way.
        return null
      }
    }
  }

  return null
}

/** The base. Empty string in every real build, so every URL below is same-origin. */
function base(apiBase: string): string {
  return apiBase.replace(/\/$/, '')
}

async function call<T>(
  apiBase: string,
  method: 'GET' | 'POST' | 'DELETE',
  path: string,
  body?: unknown,
  read?: (data: unknown) => T | null,
): Promise<StaffResult<T>> {
  const token = xsrfToken()
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (token !== null) headers['X-XSRF-TOKEN'] = token

  let response: Response

  try {
    response = await fetch(`${base(apiBase)}${path}`, {
      method,
      headers,
      // SAME-ORIGIN, NOT `include`. The credential is a first-party cookie and the app is
      // served from the API's own origin; `include` would additionally send it on a
      // cross-origin request, which is a capability this client has no use for and no
      // business having.
      credentials: 'same-origin',
      // SPREAD RATHER THAN `body: undefined`. `exactOptionalPropertyTypes` is on, and
      // `RequestInit.body` is `BodyInit | null` -- not optional-and-undefined -- so passing
      // `undefined` explicitly is a type error rather than an omission. A GET has no body
      // and says so by not having the key.
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      cache: 'no-store',
    })
  } catch {
    return { kind: 'unreachable' }
  }

  if (response.status === 401 || response.status === 419) return { kind: 'unauthorised' }
  if (response.status === 403) return { kind: 'forbidden' }
  if (response.status === 404) return { kind: 'notFound' }
  if (response.status >= 500) return { kind: 'server' }

  if (response.status === 204 || read === undefined) {
    return response.ok ? { kind: 'ok', value: undefined as T } : { kind: 'server' }
  }

  if (!response.ok) return { kind: 'server' }

  let data: unknown

  try {
    data = await response.json()
  } catch {
    // A 2xx whose body this build cannot parse. Reachable across a deploy, and it is a
    // `server` answer and not an `unreachable` one: the server did reply.
    return { kind: 'server' }
  }

  const value = read(data)

  return value === null ? { kind: 'server' } : { kind: 'ok', value }
}

function asRecord(data: unknown): Record<string, unknown> | null {
  return typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : null
}

function asString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

/** Who is asking, and which restaurants they may act in. Also primes the CSRF cookie. */
export function fetchIdentity(apiBase: string): Promise<StaffResult<StaffIdentity>> {
  return call(apiBase, 'GET', '/v1/auth/me', undefined, (data) => {
    const root = asRecord(data)

    if (root === null) return null

    const name = asString(root.name) ?? ''
    const raw = Array.isArray(root.memberships) ? root.memberships : []
    const memberships: StaffMembership[] = []

    for (const entry of raw) {
      const row = asRecord(entry)
      const tenantId = row === null ? null : asString(row.tenant_id)
      const role = row === null ? null : asString(row.role)

      if (tenantId !== null && role !== null) memberships.push({ tenantId, role })
    }

    return { name, memberships, activeTenant: asString(root.active_tenant) }
  })
}

/** The restaurant this session is acting in. Its NAME, which is what a screen shows. */
export function fetchTenant(apiBase: string): Promise<StaffResult<StaffTenant>> {
  return call(apiBase, 'GET', '/v1/tenant', undefined, (data) => {
    const tenant = asRecord(asRecord(data)?.tenant)

    if (tenant === null) return null

    const name = asString(tenant.name)

    // ⚠ NO FALLBACK TO THE SLUG. If the name is missing this returns null and the shell
    // shows its error state, which is the honest answer: a shell that quietly displayed
    // `e2e-0199…` would fail `§12`'s zero-technical bar while looking like it worked, and
    // the fallback is what would make that happen.
    if (name === null) return null

    return {
      name,
      slug: asString(tenant.slug) ?? '',
      timezone: asString(tenant.timezone) ?? 'UTC',
      status: asString(tenant.status) ?? 'active',
    }
  })
}

/** Choose which restaurant this session acts in. Only ever one the identity offered. */
export function selectTenant(apiBase: string, tenantId: string): Promise<StaffResult<void>> {
  return call(apiBase, 'POST', '/v1/auth/tenant', { tenant_id: tenantId })
}

export function signInWithPassword(
  apiBase: string,
  email: string,
  password: string,
): Promise<StaffResult<void>> {
  return call(apiBase, 'POST', '/v1/auth/login', { email, password })
}

export function completeTwoFactor(apiBase: string, code: string): Promise<StaffResult<void>> {
  return call(apiBase, 'POST', '/v1/auth/two-factor/challenge', { code })
}

/**
 * End the session, server-side.
 *
 * This is what the idle lock calls, and it is the lock's whole security property: after it,
 * the browser holds no credential that works. A client-side "lock" over a live session is a
 * claim about CSS.
 */
export function signOut(apiBase: string): Promise<StaffResult<void>> {
  return call(apiBase, 'POST', '/v1/auth/logout')
}

export function fetchDevices(apiBase: string): Promise<StaffResult<readonly StaffDevice[]>> {
  return call(apiBase, 'GET', '/v1/devices', undefined, (data) => {
    const raw = asRecord(data)?.devices

    if (!Array.isArray(raw)) return null

    const devices: StaffDevice[] = []

    for (const entry of raw) {
      const row = asRecord(entry)
      const id = row === null ? null : asString(row.id)

      if (row === null || id === null) continue

      devices.push({
        id,
        name: asString(row.name) ?? '',
        expiresAt: asString(row.expires_at),
        lastUsedAt: asString(row.last_used_at),
      })
    }

    return devices
  })
}

/**
 * Mint a kitchen screen's credential.
 *
 * `ability` is fixed at `pager:read` here and is not a parameter, because
 * `Ability::issuableToDevice()` is a closed list of one and a client that offered a choice
 * would be offering one the server refuses. The TTL is likewise fixed: `§6` Q5 wants a
 * bounded cost, and a field a restaurant owner fills in with "how many minutes" is an
 * unexplained identifier wearing a number.
 */
export function issueDevice(apiBase: string, name: string): Promise<StaffResult<IssuedDevice>> {
  return call(
    apiBase,
    'POST',
    '/v1/devices',
    { name, ability: 'pager:read', ttl_minutes: DEVICE_TTL_MINUTES },
    (data) => {
      const device = asRecord(asRecord(data)?.device)
      const id = device === null ? null : asString(device.id)
      const token = device === null ? null : asString(device.token)
      const expiresAt = device === null ? null : asString(device.expires_at)

      if (id === null || token === null || expiresAt === null) return null

      return { id, token, expiresAt }
    },
  )
}

export function revokeDevice(apiBase: string, id: string): Promise<StaffResult<void>> {
  return call(apiBase, 'DELETE', `/v1/devices/${encodeURIComponent(id)}`)
}

/**
 * How long a kitchen screen's credential lasts: thirty days.
 *
 * `DeviceTokens::MAX_TTL_MINUTES` is the server's ceiling and is the same figure, so this
 * asks for the longest the server will give. A kitchen screen is a fixture on a wall; a
 * shorter life means somebody re-sets it up during a service, which is when they have least
 * attention to spare. `staff/session.test.ts` pins the two together, so a tightening on the
 * server cannot leave this asking for more than it may have.
 */
export const DEVICE_TTL_MINUTES = 60 * 24 * 30
