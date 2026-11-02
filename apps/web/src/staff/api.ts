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
 *   conflict       409 -- the request was fine and the session's STATE refuses it. Added at
 *                  10b: a double-tap, a renotify past its cap and an expired pager all
 *                  arrive this way, and each is a sentence rather than a failure
 *   throttled      429 -- added at 10b on Muhammad's `R-224` ruling. See below; it carries
 *                  the server's own `Retry-After` when there is one
 *   unreachable    the network did not answer. "No connection", and the data is unchanged
 *   server         a 5xx, or a 2xx this build cannot parse. "On us, not on you"
 *
 * A client that folded `unauthorised` into `server` would show a restaurant owner an error
 * page at the end of every shift, and one that folded `unreachable` into `server` would
 * tell them we are broken when their wifi dropped.
 *
 * ================================================================================
 * `throttled` IS ITS OWN KIND — `R-224`, RULED 2026-11-02, AND IT WAS A MEASURED DEFECT
 * ================================================================================
 *
 * Until 10b this function had no branch for **429**, so it fell through to
 * `if (!response.ok) return { kind: 'server' }` and a throttled staff screen rendered
 * *"Something went wrong on our side. Nothing you did caused it. Try again"*. **Both halves
 * of that sentence are false under a rate limit** — it is not our side, and the person's own
 * repeated action may well have caused it — and the one thing that would help, *wait a
 * moment*, is the one thing it did not say.
 *
 * Measured on the real stack at 10a: `/v1/auth/me -> 429 {"type":".../problems/rate-limited"}`
 * with the shell showing its error state, found by the API probe in `browser/support/e2e.ts`
 * after three red CI runs and two red local ones had all reported it as a sixty-second
 * timeout. Muhammad's ruling opens with the diagnosis rather than the fix: *"mapping it onto
 * `server` is the defect."*
 *
 * `retryAfter` rides on the kind because the SERVER knows better than a client's doubling: a
 * 429 from `RateLimit` carries `Retry-After`, and `StaffBoardEndpointsTest` drives the bound
 * to prove it is there rather than trusting it. It is seconds, or `null` when the header is
 * absent or unreadable — never a guess, because `board.ts`'s own backoff is the fallback and
 * it is better than an invented number.
 */

import { PAGER_STATUS, type PagerStatus } from '@qrpager/contracts'

export type StaffFailure =
  'unauthorised' | 'forbidden' | 'notFound' | 'conflict' | 'throttled' | 'unreachable' | 'server'

/**
 * The problem `type` members this client acts on, and it acts on exactly two.
 *
 * `§4 › Edge & ops`'s envelope makes `type` the CONTRACTUAL discriminator — the `title` is a
 * fixed phrase and the message never reaches the body — so a client that needs to tell two
 * 409s apart reads this and nothing else. The server produces `conflict` for an illegal
 * transition AND for a renotify at its cap, and `expired` for a session past its two-hour
 * TTL; `ProblemRenderer`'s own comment says why the second is a distinct type: *"an illegal
 * transition is a mistake to correct, an expired session is one to re-issue."*
 *
 * Compared by SUFFIX rather than by the whole URL. The host is `qrpager.dev` in the
 * published spec and this client is served same-origin from the API, so pinning the absolute
 * string would make a client that is otherwise correct break on a domain change — and the
 * path is the part `ProblemType` actually owns.
 */
export const PROBLEM_EXPIRED = '/problems/expired'

export type StaffResult<T> =
  | { readonly kind: 'ok'; readonly value: T }
  /**
   * A throttle, with the server's own hint when it sent one.
   *
   * Spelled as its own member of the union rather than as an optional field on every
   * failure, so a caller that reads `retryAfter` has had to narrow to `throttled` first and
   * cannot read it off a 404.
   */
  | { readonly kind: 'throttled'; readonly retryAfter: number | null }
  /**
   * A 409, carrying the problem `type` so the panel can say which of the two it is.
   *
   * `expired` needs its own sentence — *give the customer a new code* — and nothing else
   * does, which is why this is one boolean's worth of information carried as the type it came
   * from rather than as a flag somebody has to keep in step.
   */
  | { readonly kind: 'conflict'; readonly problem: string | null }
  | { readonly kind: Exclude<StaffFailure, 'throttled' | 'conflict'> }

export interface StaffTenant {
  readonly name: string
  /**
   * The restaurant's own timezone, which the device list's dates are rendered in.
   *
   * ⚠ `slug` AND `status` WERE HERE AND ARE GONE — `§11` QA L-5. Nothing rendered either, and
   * a field a client holds is a field a later change renders: the whole reason `GET /v1/tenant`
   * exists is that a slug is not a name, and `browser/staff.spec.ts` asserts the slug never
   * reaches the page. Holding it in client state was one commit away from contradicting that.
   * The ROUTE still returns both — a published response shape is not changed in place (`§6`) —
   * and this is the client declining to keep them.
   */
  readonly timezone: string
}

export interface StaffMembership {
  readonly tenantId: string
  readonly role: string
  /**
   * The restaurant's own NAME — the chooser's label since 10b (`R-222`, ruled by Muhammad on
   * 2026-11-02).
   *
   * `/v1/auth/me` publishes it because the chooser runs BEFORE a tenant is selected, so
   * there is no scope in which `GET /v1/tenant` could answer. `public.user_memberships(uuid)`
   * returns it as a fifth column; `PLAN.md §2.8` carries why that widens no cross-tenant
   * path and why it is not a reversal of 10a's decision to build `/v1/tenant` instead.
   */
  readonly name: string
  /**
   * The restaurant's own slug.
   *
   * ⚠ CARRIED FOR THE CHOOSER AND FOR NOTHING ELSE — `§11` QA H-2. `/v1/auth/me` has always
   * returned it; the client ignored it, and the chooser fell back to the ROLE WORD for every
   * membership. Two memberships of the same role then rendered two buttons both reading
   * *"Owner"* under *"Which restaurant?"* — and that actor is not hypothetical: it is
   * `R-119`'s own residual and the very actor `StaffSurfaceIsolationTest` case 3 constructs.
   *
   * A slug is an identifier, which `§12` would rather not put on a screen — but it is the
   * restaurant's OWN label, the caller already holds a membership in it, and it is the only
   * datum available before a tenant is selected. Two distinguishable buttons beat two
   * identical ones. `R-222` carries the proper fix (the name) with the `§4` tension that
   * makes it a decision rather than a patch.
   */
  readonly slug: string
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

  /*
   * 409 — the request was well-formed and the SESSION'S STATE refuses it. Added at 10b
   * because the detail panel has three different sentences for it: a renotify past its cap,
   * a pager past its two-hour TTL, and an illegal move such as readying something already
   * cancelled. The server distinguishes them by the problem document's `type` member; the
   * panel distinguishes a 409 from a 404, which is the difference between *"somebody else
   * finished it"* and *"that cannot be done to this order"*.
   */
  if (response.status === 409) {
    // The body is read for its `type` and for nothing else. An unreadable body is `null`
    // rather than a throw: the STATUS already carries the fact that the state refused, and
    // the panel's general conflict sentence is correct without the discriminator.
    let problem: string | null = null

    try {
      const document: unknown = await response.json()

      if (typeof document === 'object' && document !== null) {
        const type = (document as Record<string, unknown>).type

        if (typeof type === 'string') problem = type
      }
    } catch {
      problem = null
    }

    return { kind: 'conflict', problem }
  }

  /*
   * 429 — `R-224`. Its own kind, never `server`, and it carries the server's own hint.
   *
   * `Retry-After` is seconds here (`RateLimit` sets it from `availableIn()`), and an
   * unreadable or absent header answers `null` rather than a guess: `board.ts`'s jittered
   * doubling is the fallback and an invented number would be worse than it.
   */
  if (response.status === 429) {
    const header = response.headers.get('Retry-After')
    const seconds = header === null ? Number.NaN : Number.parseInt(header, 10)

    return {
      kind: 'throttled',
      retryAfter: Number.isFinite(seconds) && seconds > 0 ? seconds : null,
    }
  }

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

/**
 * Who is asking, and which restaurants they may act in. Also primes the CSRF cookie.
 *
 * ⚠ THE NAME IS UNDER `user`, NOT AT THE TOP LEVEL, AND THE FIRST VERSION OF THIS READ IT
 * FROM THE WRONG PLACE. `AuthController::identity()` returns
 * `{ user: { id, name, email, … }, memberships, active_tenant }`; this read `data.name`,
 * which is always absent -- so `name` was `''` and the shell rendered *"Signed in as "* with
 * a hole in it. That is the exact `§12` defect this task asserts against elsewhere, and the
 * unit test did not catch it because the FAKE returned the shape the client expected rather
 * than the shape the server sends.
 *
 * The mechanism against a repeat is not a sharper eye: it is
 * `apps/api/tests/Feature/Staff/StaffContractTest.php`, which drives the real endpoint and
 * pins every key this function reads. A client reader and a server shape are a contract
 * across two languages, and the only honest place to assert one is against the real
 * response.
 */
export function fetchIdentity(apiBase: string): Promise<StaffResult<StaffIdentity>> {
  return call(apiBase, 'GET', '/v1/auth/me', undefined, (data) => {
    const root = asRecord(data)

    if (root === null) return null

    const user = asRecord(root.user)
    const name = user === null ? null : asString(user.name)

    // No fallback to the email and none to a placeholder. A shell that cannot say who is
    // signed in shows its error state, which is honest; "Signed in as " is not.
    if (name === null) return null

    const raw = Array.isArray(root.memberships) ? root.memberships : []
    const memberships: StaffMembership[] = []

    for (const entry of raw) {
      const row = asRecord(entry)
      const tenantId = row === null ? null : asString(row.tenant_id)
      const role = row === null ? null : asString(row.role)

      if (tenantId !== null && role !== null) {
        memberships.push({
          tenantId,
          role,
          name: (row === null ? null : asString(row.name)) ?? '',
          slug: (row === null ? null : asString(row.slug)) ?? '',
        })
      }
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

    return { name, timezone: asString(tenant.timezone) ?? 'UTC' }
  })
}

/** Choose which restaurant this session acts in. Only ever one the identity offered. */
export function selectTenant(apiBase: string, tenantId: string): Promise<StaffResult<void>> {
  return call(apiBase, 'POST', '/v1/auth/tenant', { tenant_id: tenantId })
}

/**
 * Exchange an address and a password for a session, or for a second-factor challenge.
 *
 * ⚠ A 2FA-ARMED ACCOUNT ANSWERS **200** WITH `{"two_factor_required": true}`, NOT A 4xx, AND
 * THE FIRST VERSION OF THIS MAPPED 403 TO THAT STATE. Measured in
 * `AuthController::login()`: `response()->json(['two_factor_required' => true], 200)`. So the
 * code field was unreachable -- a screen state in `SignInStage` that no event could produce,
 * which is the same dead-state shape `R-205`'s `error.unknown` had in Task 9 and which
 * `browser/one-handed.spec.ts` found there by iterating the state list and timing out.
 *
 * It is a distinct result rather than a boolean on `ok`, because the SCREEN differs: a 200
 * that signed you in and a 200 that wants six digits are not the same answer, and a client
 * that treated them alike would show a shell with no session behind it.
 */
export type PasswordOutcome =
  { readonly kind: 'ok' } | { readonly kind: 'two-factor' } | { readonly kind: StaffFailure }

export async function signInWithPassword(
  apiBase: string,
  email: string,
  password: string,
): Promise<PasswordOutcome> {
  const result = await call<'ok' | 'two-factor'>(
    apiBase,
    'POST',
    '/v1/auth/login',
    { email, password },
    (data) => (asRecord(data)?.two_factor_required === true ? 'two-factor' : 'ok'),
  )

  if (result.kind !== 'ok') return { kind: result.kind }

  return result.value === 'two-factor' ? { kind: 'two-factor' } : { kind: 'ok' }
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

/* ================================================================================ */
/* THE BOARD AND THE FOUR TRANSITIONS — added at 10b                                 */
/* ================================================================================ */

/**
 * One pager on the board.
 *
 * `status` is typed from `@qrpager/contracts`, which is GENERATED from the PHP enum — so a
 * status added in PHP fails this build until its copy exists, rather than rendering as a raw
 * enum value on a kitchen screen. That is `§6.2`'s one source, already mechanised at Task 8a
 * and reused here rather than re-stated.
 *
 * ⚠ NO SESSION ID IS RENDERED and no token can be: `token_hash` is not in the server's
 * select list and `qrpager_app` cannot read it at all. `id` is here because every action
 * needs it in a path; `browser/staff.spec.ts` asserts it never reaches the page.
 */
export interface BoardPager {
  readonly id: string
  readonly orderReference: string
  readonly status: PagerStatus
  readonly createdAt: string
  readonly readyAt: string | null
}

/** The board, newest first, as the server ordered it. */
export function fetchBoard(apiBase: string): Promise<StaffResult<readonly BoardPager[]>> {
  return call(apiBase, 'GET', '/v1/pager-sessions', undefined, (data) => {
    const raw = asRecord(data)?.sessions

    if (!Array.isArray(raw)) return null

    const board: BoardPager[] = []

    for (const entry of raw) {
      const row = asRecord(entry)
      const id = row === null ? null : asString(row.id)
      const reference = row === null ? null : asString(row.order_reference)
      const status = row === null ? null : asString(row.status)
      const createdAt = row === null ? null : asString(row.created_at)

      /*
       * A ROW MISSING ANY OF THE FOUR IS DROPPED, not defaulted. The alternative — a blank
       * order reference, or a status this build does not know rendered as-is — puts an
       * unexplained value on a kitchen screen, which is the `§12` bar this whole surface is
       * held to. `StaffBoardEndpointsTest` pins every one of these keys against the real
       * response, so a drop here means the contract moved and the suite says so.
       */
      if (id === null || reference === null || status === null || createdAt === null) continue
      if (!isPagerStatus(status)) continue

      board.push({
        id,
        orderReference: reference,
        status,
        createdAt,
        readyAt: row === null ? null : asString(row.ready_at),
      })
    }

    return board
  })
}

/**
 * Whether a string is a status this build knows.
 *
 * Checked against the GENERATED list rather than against a hand-written union, so the
 * narrowing and the type come from the same place. A status PHP adds and the generator has
 * not yet mirrored fails this check and the row is dropped — which is the safe direction:
 * better a missing row than a kitchen screen showing `awaiting_collection`.
 */
function isPagerStatus(value: string): value is PagerStatus {
  return (PAGER_STATUS as readonly string[]).includes(value)
}

/** The server's word on a transition: the state after it, and whether this call moved it. */
export interface TransitionOutcome {
  readonly status: PagerStatus
  /**
   * True when THIS call performed the transition.
   *
   * It is read rather than inferred from the status, because a repeat answers **200** with
   * the same status — that is what idempotent means — so the status alone cannot say which
   * of two concurrent callers applied it. The board uses it to decide whether to refresh
   * immediately or wait for the next tick.
   */
  readonly changed: boolean
}

function transition(
  apiBase: string,
  id: string,
  verb: 'ready' | 'renotify' | 'complete' | 'cancel',
): Promise<StaffResult<TransitionOutcome>> {
  return call(
    apiBase,
    'POST',
    `/v1/pager-sessions/${encodeURIComponent(id)}/${verb}`,
    undefined,
    (data) => {
      const session = asRecord(asRecord(data)?.session)
      const status = session === null ? null : asString(session.status)

      if (status === null || !isPagerStatus(status)) return null

      return { status, changed: session?.changed === true }
    },
  )
}

/**
 * The buzz. Safe to repeat, and the repeat is not an error.
 *
 * ⚠ THE CLIENT DOES NOT RELY ON THAT, which is `QRPAGER-26 §7`'s trap written as code: *"a
 * double-tap, a slow network retry and two devices are all the same event — the board must
 * converge, not double-count."* So nothing here remembers that it pressed the button; the
 * board's next read is the source of truth, and `StaffBoardConcurrencyTest` proves the
 * server end of it over HTTP with two real client sessions.
 */
export function markSessionReady(
  apiBase: string,
  id: string,
): Promise<StaffResult<TransitionOutcome>> {
  return transition(apiBase, id, 'ready')
}

/** Buzz again. Needs a session already ready, and is capped per session. */
export function renotifySession(
  apiBase: string,
  id: string,
): Promise<StaffResult<TransitionOutcome>> {
  return transition(apiBase, id, 'renotify')
}

/** They collected it. Notifies nobody — the customer is at the counter. */
export function completeSession(
  apiBase: string,
  id: string,
): Promise<StaffResult<TransitionOutcome>> {
  return transition(apiBase, id, 'complete')
}

/** Cancelled, and the customer IS told — a pager that will never buzz is worse than news. */
export function cancelSession(
  apiBase: string,
  id: string,
): Promise<StaffResult<TransitionOutcome>> {
  return transition(apiBase, id, 'cancel')
}
