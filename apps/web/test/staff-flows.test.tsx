import { act, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type * as PasskeyModule from '../src/staff/passkey.ts'
import { StaffApp } from '../src/StaffApp.tsx'
import {
  BACKOFF_CAP_SECONDS,
  BOARD_INTERVAL_SECONDS,
  MAX_CONSECUTIVE_FAILURES,
} from '../src/staff/board.ts'
import { translatorFor } from '../src/copy/index.ts'
import type { PasskeyOutcome } from '../src/staff/passkey.ts'

/**
 * ================================================================================
 * THE ORCHESTRATOR'S FLOWS — every way in, and every way each one can fail
 * ================================================================================
 *
 * `§11` QA H-1: `StaffApp.tsx` sat at **55.72% statements / 55.17% branches** while the suite
 * read as green, and the uncovered half was not an edge — it was *every credential path*
 * (passkey, password, second factor, restaurant chooser) and *every device handler* (issue,
 * remove, dismiss, add-a-passkey), lines 303–602. `staff-app.test.tsx` drives the LOCK, which
 * is the security property; nothing drove the ways a person actually gets in.
 *
 * ⚠ WHY THAT MATTERS MORE THAN THE PERCENTAGE. Two of 10a's defects were exactly here and both
 * were found by hand rather than by this suite: `/v1/auth/me` nests the person under `user`
 * (the shell rendered *"Signed in as "*), and a 2FA account answers **200 +
 * `two_factor_required`**, not 403 (so the code field was a state no event could reach). A
 * flow with no test is a flow whose first run is in a restaurant.
 *
 * The fake is scripted per route so each case states the SERVER's answer and asserts what the
 * person sees — and the shapes come from `apps/api`'s own controllers, pinned across the two
 * languages by `StaffContractTest.php`.
 */

/*
 * ⚠ ONLY THE CEREMONY IS MOCKED, and only because jsdom has no authenticator: the real
 * `signInWithPasskey` returns `unsupported` before it reaches the network, so the `ok` and
 * `refused` arms are unreachable in this environment. Everything else here goes through the
 * real `src/staff/api.ts` against a scripted `fetch` — the parsers are the half that got the
 * `user` nesting wrong, and mocking them would mock away the bug class.
 *
 * `apps/web/test/staff-passkey.test.ts` covers the ceremony itself, and
 * `browser/staff-passkey.spec.ts` drives a real virtual authenticator in Chromium.
 */
const passkey = vi.hoisted(() => ({
  signIn: vi.fn<() => Promise<PasskeyOutcome>>(),
  register: vi.fn<() => Promise<PasskeyOutcome>>(),
  available: vi.fn<() => boolean>(() => true),
}))

vi.mock('../src/staff/passkey.ts', async (importOriginal) => ({
  ...(await importOriginal<typeof PasskeyModule>()),
  signInWithPasskey: passkey.signIn,
  registerPasskey: passkey.register,
  passkeysAvailable: passkey.available,
}))

const t = translatorFor('en')

const RESTAURANT = 'Al Baik Jeddah'
const OTHER = 'Kudu Riyadh'
const PERSON = 'Hiba Mansour'
const TENANT_A = '01a199c4-7f3e-7000-8000-00000000000a'
const TENANT_B = '01a199c4-7f3e-7000-8000-00000000000b'
const DEVICE_ID = '01a199c4-7f3e-7000-8000-00000000d001'

/**
 * One scripted answer: a status with a body, or a connection that never completes.
 *
 * `headers` arrived at 10b with `R-224`: a 429 carries `Retry-After`, and the client reads it
 * off the response. A fake with no headers at all would make the throttle path throw on a
 * property access rather than exercise it.
 */
type Answer =
  | {
      readonly status: number
      readonly body?: unknown
      readonly headers?: Readonly<Record<string, string>>
    }
  | 'unreachable'

interface Seen {
  readonly key: string
  readonly body: unknown
}

function identity(options?: {
  readonly memberships?: readonly unknown[]
  readonly activeTenant?: string | null
}) {
  return {
    user: {
      id: '01a199c4-7f3e-7000-8000-ffffffffffff',
      name: PERSON,
      email: 'hiba@example.test',
      is_platform_admin: false,
      two_factor_enabled: false,
      passkeys_enabled: true,
    },
    memberships: options?.memberships ?? [
      { tenant_id: TENANT_A, role: 'owner', slug: 'al-baik-jeddah' },
    ],
    active_tenant: options?.activeTenant === undefined ? TENANT_A : options.activeTenant,
  }
}

const SIGNED_OUT: Record<string, Answer> = { 'GET /v1/auth/me': { status: 401 } }

const BOARD_PAGER = {
  id: '01a199c4-7f3e-7000-8000-00000000p001'.replace('p', 'a'),
  order_reference: 'A-128',
  status: 'waiting',
  created_at: '2026-11-02T10:00:00Z',
  ready_at: null,
}

const SIGNED_IN: Record<string, Answer> = {
  'GET /v1/auth/me': { status: 200, body: identity() },
  'GET /v1/tenant': {
    status: 200,
    body: { tenant: { name: RESTAURANT, timezone: 'Asia/Riyadh' } },
  },
  'GET /v1/devices': { status: 200, body: { devices: [] } },
  // ⚠ ADDED AT 10b, AND EVERY SIGNED-IN CASE NEEDS IT: the shell POLLS the board on every
  // load of `/staff`, and this fake THROWS on an unscripted route -- correctly, because an
  // unscripted call is a call the test did not mean to make. An empty board is the quiet
  // default so a case about something else is not also a case about the board.
  'GET /v1/pager-sessions': { status: 200, body: { sessions: [] } },
  'POST /v1/auth/logout': { status: 204 },
}

/**
 * A scripted stack.
 *
 * A route's value may be a LIST, which is consumed one answer per call and then sticks on the
 * last — that is how "the list was empty, then the issue landed, then the list has one row" is
 * expressed without a stateful fake pretending to be a database. A `DELETE /v1/devices/*` key
 * matches any single trailing segment.
 */
function stack(routes: Record<string, Answer | readonly Answer[]>) {
  const seen: Seen[] = []
  const queues = new Map<string, Answer[]>()

  for (const [key, value] of Object.entries(routes)) {
    queues.set(key, scripted(value))
  }

  const answerFor = (key: string): Answer | undefined => {
    const exact = queues.get(key)

    if (exact !== undefined) return exact.length === 1 ? exact[0] : exact.shift()

    for (const [pattern, queue] of queues) {
      if (!pattern.endsWith('/*')) continue
      if (!key.startsWith(pattern.slice(0, -1))) continue

      return queue.length === 1 ? queue[0] : queue.shift()
    }

    return undefined
  }

  const fetchMock = vi.fn((input: unknown, init?: RequestInit) => {
    const path = String(input).replace(/^https?:\/\/[^/]+/, '')
    const key = `${init?.method ?? 'GET'} ${path}`
    const raw = init?.body

    seen.push({ key, body: typeof raw === 'string' ? JSON.parse(raw) : null })

    const answer = answerFor(key)

    if (answer === undefined) {
      throw new Error(`the test did not script ${key}`)
    }

    if (answer === 'unreachable') {
      // What a dead network looks like to `fetch`: a rejection, not a status.
      return Promise.reject(new TypeError('Failed to fetch'))
    }

    return Promise.resolve({
      status: answer.status,
      ok: answer.status >= 200 && answer.status < 300,
      json: () => Promise.resolve(answer.body ?? {}),
      // A `Headers`-shaped stub rather than a real `Headers`, for the same reason the body
      // is a thunk: the client reads one method and nothing else, and a fake that answers
      // more than the client asks for is a fake that can drift from it silently.
      headers: { get: (name: string) => answer.headers?.[name] ?? null },
    } as unknown as Response)
  })

  vi.stubGlobal('fetch', fetchMock)

  return {
    seen,
    /** Every scripted call to one route, in order. */
    calls: (key: string) => seen.filter((call) => call.key === key),
  }
}

/**
 * One route's answers as a queue.
 *
 * Through a type predicate rather than a bare `Array.isArray`, which widens to `any[]` and
 * trips `no-unsafe-argument` -- a cast would silence the rule without telling the reader why
 * the value is a list.
 */
function scripted(value: Answer | readonly Answer[]): Answer[] {
  return isList(value) ? [...value] : [value]
}

function isList(value: Answer | readonly Answer[]): value is readonly Answer[] {
  return Array.isArray(value)
}

/** Every text node and attribute value in the document, as one string. */
function everything(): string {
  const parts: string[] = [document.body.textContent]

  for (const element of document.body.querySelectorAll('*')) {
    for (const attribute of element.attributes) parts.push(attribute.value)
  }

  return parts.join(' | ')
}

const signInHeading = (): Promise<void> =>
  waitFor(() => {
    expect(screen.getByRole('heading', { name: t('staff.signin.title') })).toBeDefined()
  })

const shellReady = (): Promise<void> =>
  waitFor(() => {
    expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
  })

function press(name: string): void {
  screen.getByRole('button', { name }).click()
}

/**
 * Press a button in the PAGE, not in the shell's nav.
 *
 * ⚠ `staff.home.action` and `staff.nav.devices` are both *"Kitchen screens"* — the page's own
 * next step and the nav entry for the same route, which is correct copy and an ambiguous
 * query. The nav entries are the ones carrying `data-nav`, so the page's button is the one
 * without it; asserted as exactly one match rather than "the first", because picking by index
 * would quietly follow the markup if a second ever appeared.
 */
function pressInPage(name: string): void {
  const matches = [...document.querySelectorAll('button:not([data-nav])')].filter(
    (button) => button.textContent === name,
  )

  expect(matches, `expected exactly one "${name}" outside the nav`).toHaveLength(1)
  ;(matches[0] as HTMLButtonElement).click()
}

beforeEach(() => {
  passkey.signIn.mockReset()
  passkey.register.mockReset()
  passkey.available.mockReset()
  passkey.available.mockReturnValue(true)
})

afterEach(() => {
  vi.restoreAllMocks()
  // ⚠ ADDED AT 10b, AND IT IS NOT HOUSEKEEPING. Three board cases take fake timers to let
  // the poll timer fire; without this, the NEXT file-order case inherits a frozen clock and
  // every `waitFor` in it hangs until its own timeout -- which reads as a flake in a case
  // that never touched a timer. `staff-app.test.tsx` has carried the same line since 10a.
  vi.useRealTimers()
})

describe('signing in with a passkey', () => {
  it('lands in the shell, and the shell names the restaurant it loaded', async () => {
    stack({ ...SIGNED_OUT, ...{} })
    passkey.signIn.mockResolvedValue({ kind: 'ok' })

    const { rerender } = render(<StaffApp apiBase="" view="home" locale="en" />)

    await signInHeading()

    // The session exists from the ceremony onwards, so the identity call that follows it
    // answers 200 -- scripted by re-stacking before the press.
    stack(SIGNED_IN)
    press(t('staff.signin.passkey'))

    await shellReady()
    rerender(<StaffApp apiBase="" view="home" locale="en" />)

    expect(everything()).toContain(PERSON)
    expect(passkey.signIn).toHaveBeenCalledTimes(1)
  })

  it('offers the password form instead when the browser cannot run a ceremony', async () => {
    stack(SIGNED_OUT)
    passkey.signIn.mockResolvedValue({ kind: 'unsupported' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    press(t('staff.signin.passkey'))

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.unsupported'))
    })

    // ⚠ AND THE WAY IN IS ALREADY OPEN, not behind a disclosure the person has to find. A
    // browser with no authenticator leaves exactly one route in, and §12's bar is a person
    // with zero technical background.
    expect(screen.getByLabelText(t('staff.signin.email'))).toBeDefined()
  })

  it('says one thing for every refusal, naming neither the account nor the reason', async () => {
    stack(SIGNED_OUT)
    passkey.signIn.mockResolvedValue({ kind: 'refused' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    press(t('staff.signin.passkey'))

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.failedHead'))
    })

    // The oracle clause: a refused credential must not tell a passer-by whether the account
    // exists, which is `§4 › Security`'s single-message rule read from the client side.
    const body = everything().toLowerCase()

    for (const word of ['no account', 'not found', 'unknown', 'no such', 'expired']) {
      expect(body.includes(word), `the refusal says "${word}"`).toBe(false)
    }
  })
})

describe('signing in with a password', () => {
  it('lands in the shell when the server answers a plain 200', async () => {
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/auth/me': [{ status: 401 }, { status: 200, body: identity() }],
      'POST /v1/auth/login': { status: 200, body: {} },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await shellReady()

    // The address and the password reached the server as the server names them.
    expect(calls('POST /v1/auth/login')).toHaveLength(1)
    expect(calls('POST /v1/auth/login')[0]?.body).toEqual({
      email: 'hiba@example.test',
      password: 'correct horse battery staple',
    })
  })

  it('asks for the code on a 200 that wants a second factor, then signs in', async () => {
    /*
     * ⚠ THE REGRESSION THIS CASE EXISTS FOR. A 2FA-armed account answers **200** with
     * `{"two_factor_required": true}` -- measured in `AuthController::login()` -- and the
     * first version of the client mapped 403 to that state, which made the code field
     * unreachable by any event. The same dead-state shape as `R-205` in Task 9.
     */
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/auth/me': [{ status: 401 }, { status: 200, body: identity() }],
      'POST /v1/auth/login': { status: 200, body: { two_factor_required: true } },
      'POST /v1/auth/two-factor/challenge': { status: 204 },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await waitFor(() => {
      expect(screen.getByLabelText(t('staff.signin.code'))).toBeDefined()
    })

    // NOT signed in yet, which is the whole point of distinguishing the two 200s.
    expect(everything()).not.toContain(RESTAURANT)

    fireChange(screen.getByLabelText(t('staff.signin.code')), '123456')
    submitForm()

    await shellReady()

    expect(calls('POST /v1/auth/two-factor/challenge')[0]?.body).toEqual({ code: '123456' })
  })

  it('shows the one failure message for a refused password', async () => {
    stack({ ...SIGNED_OUT, 'POST /v1/auth/login': { status: 422, body: {} } })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.failedHead'))
    })
  })

  it('says the network is down rather than blaming the credential', async () => {
    // ⚠ THE DISTINCTION §12 REQUIRES: an unreachable server is not a wrong password, and
    // telling a cook their password failed when the wifi dropped sends them to a manager.
    stack({ ...SIGNED_OUT, 'POST /v1/auth/login': 'unreachable' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.offline'))
    })

    expect(everything()).not.toContain(t('staff.signin.failedHead'))
  })

  it('says the network is down when the CODE cannot be delivered', async () => {
    stack({
      ...SIGNED_OUT,
      'POST /v1/auth/login': { status: 200, body: { two_factor_required: true } },
      'POST /v1/auth/two-factor/challenge': 'unreachable',
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await waitFor(() => {
      expect(screen.getByLabelText(t('staff.signin.code'))).toBeDefined()
    })

    fireChange(screen.getByLabelText(t('staff.signin.code')), '000000')
    submitForm()

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.offline'))
    })
  })

  it('shows the one failure message for a wrong code', async () => {
    stack({
      ...SIGNED_OUT,
      'POST /v1/auth/login': { status: 200, body: { two_factor_required: true } },
      'POST /v1/auth/two-factor/challenge': { status: 422, body: {} },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()

    await fillCredentials()
    submitForm()

    await waitFor(() => {
      expect(screen.getByLabelText(t('staff.signin.code'))).toBeDefined()
    })

    fireChange(screen.getByLabelText(t('staff.signin.code')), '000000')
    submitForm()

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.failedHead'))
    })
  })
})

describe('a person who works at two restaurants', () => {
  const TWO = [
    { tenant_id: TENANT_A, role: 'owner', slug: 'al-baik-jeddah' },
    { tenant_id: TENANT_B, role: 'owner', slug: 'kudu-riyadh' },
  ]

  it('is asked which one, by name, and gets the shell for the one they pick', async () => {
    const { calls } = stack({
      'GET /v1/auth/me': [
        { status: 200, body: identity({ memberships: TWO, activeTenant: null }) },
        { status: 200, body: identity({ memberships: TWO, activeTenant: TENANT_B }) },
      ],
      'POST /v1/auth/tenant': { status: 204 },
      'GET /v1/tenant': { status: 200, body: { tenant: { name: OTHER, timezone: 'UTC' } } },
      'GET /v1/devices': { status: 200, body: { devices: [] } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: t('staff.signin.choose') })).toBeDefined()
    })

    /*
     * ⚠ THE TWO BUTTONS MUST BE TELLABLE APART — `§11` QA H-2, where both read "Owner"
     * because the name map the chooser indexed was never filled by the app. Same role on
     * purpose: that is the case the old test could not see.
     */
    press('kudu-riyadh · Owner')

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: OTHER })).toBeDefined()
    })

    expect(calls('POST /v1/auth/tenant')[0]?.body).toEqual({ tenant_id: TENANT_B })
  })

  it('stays on the chooser, with one message, when the server refuses the choice', async () => {
    stack({
      'GET /v1/auth/me': { status: 200, body: identity({ memberships: TWO, activeTenant: null }) },
      'POST /v1/auth/tenant': { status: 403, body: {} },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: t('staff.signin.choose') })).toBeDefined()
    })

    press('al-baik-jeddah · Owner')

    await waitFor(() => {
      expect(everything()).toContain(t('staff.signin.failedHead'))
    })

    // ⚠ AND NO RESTAURANT NAME ANYWHERE. A refused choice must not leak that the OTHER
    // membership exists or what it is called -- the chooser is pre-session surface.
    expect(everything()).not.toContain(RESTAURANT)
    expect(everything()).not.toContain(OTHER)
  })
})

describe('the shell cannot resolve the restaurant', () => {
  it('shows the offline state, and its retry really retries', async () => {
    const { calls } = stack({
      'GET /v1/auth/me': { status: 200, body: identity() },
      'GET /v1/tenant': ['unreachable', { status: 200, body: { tenant: { name: RESTAURANT } } }],
      'GET /v1/devices': { status: 200, body: { devices: [] } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.shell.offline.title'))
    })

    // ⚠ NO CHROME WHILE THE RESTAURANT IS UNKNOWN. The prerendered document carried the
    // shell once, with an empty name and a Sign out button, for a caller with no credential.
    expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()

    press(t('staff.shell.error.retry'))

    await shellReady()

    // Exactly two: the failed one and the retry. A retry that re-rendered without
    // re-requesting would leave this at one and still look like it worked.
    expect(calls('GET /v1/tenant')).toHaveLength(2)
  })

  it('shows the error state for a 500, and its retry really retries', async () => {
    const { calls } = stack({
      'GET /v1/auth/me': { status: 200, body: identity() },
      'GET /v1/tenant': [
        { status: 500, body: {} },
        { status: 200, body: { tenant: { name: RESTAURANT } } },
      ],
      'GET /v1/devices': { status: 200, body: { devices: [] } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.shell.error.title'))
    })

    press(t('staff.shell.error.retry'))

    await shellReady()
    expect(calls('GET /v1/tenant')).toHaveLength(2)
  })

  it('goes to sign-in when the identity call is refused mid-session', async () => {
    stack({
      'GET /v1/auth/me': { status: 200, body: identity() },
      'GET /v1/tenant': { status: 401 },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await signInHeading()

    // ⚠ AND THE PERSON'S NAME IS GONE WITH IT. A 401 on the tenant call means the session
    // died; an identity left in state would keep rendering "Signed in as" over a dead one.
    expect(everything()).not.toContain(PERSON)
  })

  it('shows the offline state when the identity call cannot be reached at all', async () => {
    stack({ 'GET /v1/auth/me': 'unreachable' })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.shell.offline.title'))
    })
  })

  it('shows the error state when the identity payload is missing the person', async () => {
    // A 200 whose body this build cannot read is an `error`, not a `signed-out`: the session
    // is fine and the shell is the thing that is broken. "Signed in as " is not an option.
    stack({ 'GET /v1/auth/me': { status: 200, body: { user: {}, memberships: [] } } })

    render(<StaffApp apiBase="" view="home" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.shell.error.title'))
    })
  })
})

describe('the kitchen screens page', () => {
  const ONE_DEVICE = {
    devices: [
      {
        id: DEVICE_ID,
        name: 'Pass counter',
        expires_at: '2026-12-01T10:00:00Z',
        last_used_at: null,
      },
    ],
  }

  it('issues a credential, shows it once, and reloads the list behind it', async () => {
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/devices': [
        { status: 200, body: { devices: [] } },
        { status: 200, body: ONE_DEVICE },
      ],
      'POST /v1/devices': {
        status: 201,
        body: {
          device: {
            id: DEVICE_ID,
            token: 'qrp_device_live_secret',
            expires_at: '2026-12-01T10:00:00Z',
          },
        },
      },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: t('staff.devices.add') })).toBeDefined()
    })

    await issueScreenNamed('Pass counter')

    await waitFor(() => {
      expect(everything()).toContain('qrp_device_live_secret')
    })

    /*
     * The ability and the TTL are the SERVER's closed choices, not a form's: `pager:read` is
     * the whole of `Ability::issuableToDevice()`, and 43200 minutes is `DEVICE_TTL_MINUTES`
     * (30 days).
     *
     * ⚠ WRITTEN AS LITERALS RATHER THAN IMPORTED. Importing `DEVICE_TTL_MINUTES` here would
     * make the assertion compare the constant with itself and pass for any value it ever
     * takes; the point of the case is that THESE numbers go to the server, so a change to
     * either has to come past a failing test and a human.
     */
    expect(calls('POST /v1/devices')[0]?.body).toEqual({
      name: 'Pass counter',
      ability: 'pager:read',
      ttl_minutes: 43_200,
    })

    // And the list was re-read, so the new screen appears without a manual refresh.
    expect(calls('GET /v1/devices')).toHaveLength(2)

    // ⚠ DISMISSING IT REMOVES THE TOKEN FROM THE DOCUMENT. It is shown once and it is a
    // live credential; a dismissal that only hid it with CSS would leave it in the DOM of
    // an unattended screen.
    press(t('staff.devices.issued.done'))

    await waitFor(() => {
      expect(everything()).not.toContain('qrp_device_live_secret')
    })
  })

  it('posts ONE issue request for two submits, over the real client', async () => {
    /*
     * ⚠ `§11` SECURITY L-2's OWN ACCEPTANCE CRITERION, VERBATIM: *"disable the submit while a
     * request is outstanding, and a test that fires two submits and asserts exactly one POST
     * /v1/devices"*. The component-level half is in `staff-interactions.test.tsx`; this is the
     * half that counts REQUESTS, through `src/staff/api.ts` and a scripted `fetch`, because
     * what the finding is about is a second credential existing on the server.
     */
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/devices': { status: 200, body: { devices: [] } },
      'POST /v1/devices': {
        status: 201,
        body: {
          device: {
            id: DEVICE_ID,
            token: 'qrp_device_live_secret',
            expires_at: '2026-12-01T10:00:00Z',
          },
        },
      },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: t('staff.devices.add') })).toBeDefined()
    })

    press(t('staff.devices.add'))

    await waitFor(() => {
      expect(screen.getByLabelText(t('staff.devices.name.label'))).toBeDefined()
    })

    fireChange(screen.getByLabelText(t('staff.devices.name.label')), 'Pass counter')

    // Three submits with no await between them: the greasy-tablet double-tap, and the Enter
    // key, and a `requestSubmit()` -- all three reach the handler, which is where the guard is.
    submitForm()
    submitForm()
    submitForm()

    await waitFor(() => {
      expect(everything()).toContain('qrp_device_live_secret')
    })

    // ⚠ EXACTLY ONE. Two would mean a second live 30-day credential nobody was shown.
    expect(calls('POST /v1/devices')).toHaveLength(1)
  })

  it('removes a screen after a confirmation, and treats a 404 as the thing already being gone', async () => {
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/devices': [
        { status: 200, body: ONE_DEVICE },
        { status: 200, body: { devices: [] } },
      ],
      'DELETE /v1/devices/*': { status: 404 },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain('Pass counter')
    })

    await removeTheScreen()

    await waitFor(() => {
      expect(calls(`DELETE /v1/devices/${DEVICE_ID}`)).toHaveLength(1)
    })

    /*
     * ⚠ A 404 IS SUCCESS HERE, DELIBERATELY. It means the screen is already gone, which is
     * what the person asked for; an error state for a state they wanted is system vocabulary
     * dressed as a failure. So: no error on screen, and the list re-read.
     */
    await waitFor(() => {
      expect(calls('GET /v1/devices')).toHaveLength(2)
    })

    expect(everything()).not.toContain(t('staff.shell.error.title'))
  })

  it('says it is not allowed, in plain words, when the role cannot manage screens', async () => {
    stack({ ...SIGNED_IN, 'GET /v1/devices': { status: 403, body: {} } })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.devices.denied.title'))
    })

    // The words a developer would reach for and a restaurant owner would not understand.
    const body = everything().toLowerCase()

    for (const word of ['403', 'forbidden', 'ability', 'scope', 'token']) {
      expect(body.includes(word), `the denial says "${word}"`).toBe(false)
    }
  })

  it('reports a refused ISSUE as a permission problem, not as a broken screen', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/devices': { status: 200, body: { devices: [] } },
      'POST /v1/devices': { status: 403, body: {} },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: t('staff.devices.add') })).toBeDefined()
    })

    await issueScreenNamed('Grill')

    await waitFor(() => {
      expect(everything()).toContain(t('staff.devices.denied.title'))
    })
  })

  it('reports a 500 on issue as the error state, with a retry', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/devices': { status: 200, body: { devices: [] } },
      'POST /v1/devices': { status: 500, body: {} },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: t('staff.devices.add') })).toBeDefined()
    })

    await issueScreenNamed('Grill')

    await waitFor(() => {
      expect(everything()).toContain(t('staff.devices.error.title'))
    })
  })

  it('reports a refused REMOVAL as a permission problem', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/devices': { status: 200, body: ONE_DEVICE },
      'DELETE /v1/devices/*': { status: 403, body: {} },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain('Pass counter')
    })

    await removeTheScreen()

    await waitFor(() => {
      expect(everything()).toContain(t('staff.devices.denied.title'))
    })
  })

  it('shows the offline state when the list cannot be reached, and retries from it', async () => {
    const { calls } = stack({
      ...SIGNED_IN,
      'GET /v1/devices': ['unreachable', { status: 200, body: ONE_DEVICE }],
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain(t('staff.devices.offline.title'))
    })

    press(t('staff.shell.error.retry'))

    await waitFor(() => {
      expect(everything()).toContain('Pass counter')
    })

    expect(calls('GET /v1/devices')).toHaveLength(2)
  })

  it('drops the whole session when the list answers 401', async () => {
    // ⚠ NOT A DEVICE-PAGE ERROR. A 401 means the session died under the screen, so the
    // right answer is sign-in with nothing of the restaurant left rendered -- the same
    // clearing the lock does.
    stack({ ...SIGNED_IN, 'GET /v1/devices': { status: 401 } })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await signInHeading()

    expect(everything()).not.toContain(RESTAURANT)
    expect(everything()).not.toContain(PERSON)
  })

  it('renders a device expiry in the restaurant own timezone, and survives a broken one', async () => {
    /*
     * ⚠ THE SECOND HALF IS `§11` SECURITY L-5. `timeZone` comes straight from
     * `GET /v1/tenant` and `new Intl.DateTimeFormat('en', { timeZone: 'Kitchen/Nowhere' })`
     * THROWS -- inside the row map, in render, in a tree with no `error.tsx` above it. The
     * blank page that produces is worse than a time in the wrong zone.
     */
    stack({
      ...SIGNED_IN,
      'GET /v1/tenant': {
        status: 200,
        body: { tenant: { name: RESTAURANT, timezone: 'Kitchen/Nowhere' } },
      },
      'GET /v1/devices': { status: 200, body: ONE_DEVICE },
    })

    render(<StaffApp apiBase="" view="devices" locale="en" />)

    await waitFor(() => {
      expect(everything()).toContain('Pass counter')
    })

    // 2026-12-01T10:00:00Z, formatted in the UTC fallback rather than throwing. Exact
    // string, so a silent change of fallback zone fails here.
    expect(everything()).toContain('Dec 1, 2026')
  })
})

describe('adding a passkey to this device', () => {
  it('confirms it in plain words when the ceremony succeeds', async () => {
    stack(SIGNED_IN)
    passkey.register.mockResolvedValue({ kind: 'ok' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    press(t('staff.passkey.add'))

    await waitFor(() => {
      expect(everything()).toContain(t('staff.passkey.added'))
    })
  })

  it('says it did not work when the server refuses the credential', async () => {
    stack(SIGNED_IN)
    passkey.register.mockResolvedValue({ kind: 'refused' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    press(t('staff.passkey.add'))

    await waitFor(() => {
      expect(document.querySelector('[role="alert"]')?.textContent).toBe(t('staff.passkey.failed'))
    })
  })

  it('says NOTHING when the person dismissed the system prompt themselves', async () => {
    stack(SIGNED_IN)
    passkey.register.mockResolvedValue({ kind: 'cancelled' })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    press(t('staff.passkey.add'))

    // Not vacuous: the handler ran to completion, and the assertion is that it chose to say
    // nothing. Telling a person their own deliberate cancellation "failed" is noise.
    await waitFor(() => {
      expect(passkey.register).toHaveBeenCalledTimes(1)
    })

    expect(document.querySelector('[data-passkey-notice]')).toBeNull()
  })

  it('offers no button at all where the browser cannot run a ceremony', async () => {
    stack(SIGNED_IN)
    passkey.available.mockReturnValue(false)

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    expect(screen.queryByRole('button', { name: t('staff.passkey.add') })).toBeNull()

    // ⚠ AND THE PAGE STILL CARRIES A NEXT ACTION, which is what `§12` asks of the state —
    // but it is the BOARD's now, not the deleted home screen's. 10b replaced `/staff`'s body,
    // so what an empty board offers is *"Check for new orders"*, which polls immediately
    // instead of waiting for the tick. The case is kept rather than dropped because its
    // point was never which button: it was that the ceremony's absence does not take the
    // page's own affordance with it.
    // ⚠ AWAITED, because `shellReady()` waits for the CHROME and the board's first poll is a
    // second round trip behind it. Pressing straight after it looked for a button on a board
    // still in its `loading` state — the same "await the something a press reveals" rule
    // `fillCredentials` below records, one surface over.
    await waitFor(() => {
      expect(screen.getByText(t('staff.board.empty.title'))).toBeDefined()
    })

    pressInPage(t('staff.board.empty.action'))
  })
})

describe('navigation out of the shell', () => {
  it('asks the host to navigate rather than routing itself', async () => {
    stack(SIGNED_IN)

    const onNavigate = vi.fn()

    render(<StaffApp apiBase="" view="home" locale="en" onNavigate={onNavigate} />)
    await shellReady()

    /*
     * ⚠ THE **NAV** BUTTON, since 10b. This pressed the home screen's own *"Kitchen
     * screens"* action, and that screen is deleted — the board replaced it, and a board's
     * empty state points at the board rather than at another route. The nav entry is now
     * the only way out of `/staff`, which makes it the thing worth asserting: a wall-mounted
     * tablet in kiosk mode has no back button, so if the nav does not navigate there is no
     * second route to this surface at all.
     */
    press(t('staff.nav.devices'))

    expect(onNavigate).toHaveBeenCalledExactlyOnceWith('devices')
  })

  it('does not fall over when the host passed no navigator', async () => {
    // The optional-prop path, which `app/staff/page.tsx` never takes and a reviewer worktree
    // rendering the component directly does.
    stack(SIGNED_IN)

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    press(t('staff.nav.devices'))

    // Nothing is thrown and the shell is still there, which is the whole of the optional
    // call's contract: `onNavigate?.()` on an absent prop is a no-op, not a crash.
    expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
  })
})

describe('the board, through the real client — 10b', () => {
  const PAGER_PATH = `/v1/pager-sessions/${BOARD_PAGER.id}`

  const boardReady = (): Promise<void> =>
    waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

  /** Open the one row's detail panel. */
  const openDetail = async (): Promise<void> => {
    await boardReady()
    press(t('staff.board.open', { reference: 'A-128' }))

    await waitFor(() => {
      expect(document.querySelector('[data-detail="true"]')).not.toBeNull()
    })
  }

  it('polls the board and renders what the server sent', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': { status: 200, body: { sessions: [BOARD_PAGER] } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await boardReady()

    expect(screen.getByText(t('staff.board.status.waiting'))).toBeDefined()
    // The session id is nowhere, asserted over text AND attributes -- the component-level
    // case proves it in isolation and this proves it through the whole shell.
    expect(everything()).not.toContain(BOARD_PAGER.id)
  })

  /**
   * Let the poll TIMER fire once, which is the only honest way to provoke a second read.
   *
   * ⚠ THE FIRST VERSION OF THESE THREE CASES PRESSED *"Try again"* AND THERE IS NO SUCH
   * BUTTON when the board has rows: the retry affordance belongs to the failure states, and
   * those replace the board only when it has nothing to show. So pressing it tested the
   * state the case was trying to leave.
   *
   * With `random` pinned at the midpoint the jitter contributes nothing, so the first delay
   * is exactly `BOARD_INTERVAL_SECONDS` — an EXACT advance rather than a generous one, which
   * is the *assert exact values, never ranges* discipline applied to a clock.
   */
  const letTheTimerFire = async (): Promise<void> => {
    await vi.advanceTimersByTimeAsync(BOARD_INTERVAL_SECONDS * 1000 + 1)
  }

  it('SAYS NOTHING on the first 429 and keeps the rows — R-224, through the real mapping', async () => {
    /*
     * ⚠ THIS IS THE CASE `R-224` EXISTS FOR, and it is driven through the real
     * `src/staff/api.ts` rather than against a mocked result: the defect was a MAPPING --
     * 429 falling through to `{kind:'server'}` -- so a test that mocked the client's answer
     * would have mocked away the bug.
     */
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [
        { status: 200, body: { sessions: [BOARD_PAGER] } },
        { status: 429, headers: { 'Retry-After': '30' } },
      ],
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)
    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

    await letTheTimerFire()

    expect(screen.queryByText(t('staff.board.throttled.title'))).toBeNull()
    expect(screen.queryByText(t('staff.board.throttled.body'))).toBeNull()

    // THE ROWS ARE STILL THERE. The data is not wrong, it is not fresh.
    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()

    // AND THE ERROR STATE'S SENTENCE IS ABSENT, which is the defect in one assertion: the
    // old mapping rendered *"Something went wrong on our side. Nothing you did caused it."*
    expect(everything()).not.toContain(t('staff.shell.error.title'))
    expect(everything()).not.toContain(t('staff.board.error.title'))
  })

  it('shows the offline state only with nothing to show, and keeps the rows otherwise', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [{ status: 200, body: { sessions: [BOARD_PAGER] } }, 'unreachable'],
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)
    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

    await letTheTimerFire()

    // Still the row, not the offline screen -- `offline` replaces the board only when the
    // board has nothing in it.
    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    expect(everything()).not.toContain(t('staff.board.offline.title'))
  })

  it('marks ready through the real route and refreshes the board at once', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [
        { status: 200, body: { sessions: [BOARD_PAGER] } },
        { status: 200, body: { sessions: [{ ...BOARD_PAGER, status: 'ready' }] } },
      ],
      [`POST ${PAGER_PATH}/ready`]: {
        status: 200,
        body: { session: { id: BOARD_PAGER.id, status: 'ready', changed: true } },
      },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await openDetail()

    press(t('staff.detail.ready'))

    // ⚠ THE BOARD IS RE-READ IMMEDIATELY rather than at the next tick, because the person
    // just acted: a five-second wait to see your own action land reads as a screen that did
    // not hear you. Asserted on the CALL rather than on the paint, so it cannot pass by the
    // timer happening to fire.
    await waitFor(() => {
      expect(scripted.calls(`POST ${PAGER_PATH}/ready`)).toHaveLength(1)
      expect(screen.getByText(t('staff.board.status.ready'))).toBeDefined()
    })

    // And the panel closed, which is what `done` means.
    expect(document.querySelector('[data-detail="true"]')).toBeNull()
  })

  it('has a DISTINCT sentence for each refusal, every one from a real server answer', async () => {
    /*
     * ⚠ FOUR SERVER ANSWERS, FOUR SENTENCES, DRIVEN. `R-205`'s lesson from Task 9 is that a
     * screen state no event can produce is a dead state; the component test asserts the
     * sentences exist, and this asserts each is REACHABLE from the answer it belongs to.
     *
     * The renotify cap and an illegal move share the problem `type`, so the panel tells them
     * apart by the action pressed -- sound only because `renotify` is offered only on a row
     * the server last reported `ready`, which is why this case drives it on one.
     */
    const READY_PAGER = { ...BOARD_PAGER, status: 'ready', ready_at: '2026-11-02T10:02:00Z' }
    const problem = (type: string) => ({ type: `https://qrpager.dev${type}` })

    const cases = [
      {
        name: 'expired',
        verb: 'renotify',
        answer: { status: 409, body: problem('/problems/expired') },
        sentence: t('staff.detail.expired'),
      },
      {
        name: 'limit',
        verb: 'renotify',
        answer: { status: 409, body: problem('/problems/conflict') },
        sentence: t('staff.detail.limit'),
      },
      {
        name: 'gone',
        verb: 'complete',
        answer: { status: 404, body: problem('/problems/not-found') },
        sentence: t('staff.detail.gone'),
      },
      {
        name: 'stale',
        verb: 'complete',
        answer: { status: 409, body: problem('/problems/conflict') },
        sentence: t('staff.detail.stale'),
      },
      {
        name: 'failed',
        verb: 'complete',
        answer: { status: 500 },
        sentence: t('staff.detail.failed'),
      },
    ] as const

    for (const scenario of cases) {
      stack({
        ...SIGNED_IN,
        'GET /v1/pager-sessions': { status: 200, body: { sessions: [READY_PAGER] } },
        [`POST ${PAGER_PATH}/${scenario.verb}`]: scenario.answer,
      })

      const { unmount } = render(<StaffApp apiBase="" view="home" locale="en" />)

      await openDetail()
      press(t(scenario.verb === 'renotify' ? 'staff.detail.renotify' : 'staff.detail.complete'))

      await waitFor(() => {
        expect(
          document.querySelector(`[data-detail-message="${scenario.name}"]`)?.textContent,
          scenario.name,
        ).toBe(scenario.sentence)
      })

      unmount()
    }
  })

  it('confirms a cancellation before sending anything', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': { status: 200, body: { sessions: [BOARD_PAGER] } },
      [`POST ${PAGER_PATH}/cancel`]: {
        status: 200,
        body: { session: { id: BOARD_PAGER.id, status: 'cancelled', changed: true } },
      },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await openDetail()

    press(t('staff.detail.cancel'))

    await waitFor(() => {
      expect(screen.getByText(t('staff.detail.cancel.title'))).toBeDefined()
    })

    // ⚠ NOTHING HAS BEEN SENT YET, which is the whole of "confirmed": a destructive action
    // whose first tap already fired it has a confirmation dialog and no confirmation.
    expect(scripted.calls(`POST ${PAGER_PATH}/cancel`)).toHaveLength(0)

    press(t('staff.detail.cancel.confirm'))

    await waitFor(() => {
      expect(scripted.calls(`POST ${PAGER_PATH}/cancel`)).toHaveLength(1)
    })
  })

  it('drops the board when the shell locks, so a locked screen holds no order', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': { status: 200, body: { sessions: [BOARD_PAGER] } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" idleSeconds={0} />)
    await boardReady()

    press(t('staff.shell.signOut'))

    await waitFor(() => {
      expect(screen.getByText(t('staff.signedout.title'))).toBeDefined()
    })

    // ⚠ BY VALUE, over text AND attributes. Every order reference on the board belongs to one
    // restaurant, so `§12`'s lock clause covers them as much as it covers the restaurant's
    // own name — and `clearTenantData()` is one function precisely so that adding board state
    // without clearing it is caught here.
    expect(everything()).not.toContain('A-128')
    expect(everything()).not.toContain(BOARD_PAGER.id)
  })

  it('drops to sign-in when the board answers 401, which is the end of a shift', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [
        { status: 200, body: { sessions: [BOARD_PAGER] } },
        { status: 401 },
      ],
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)
    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

    await letTheTimerFire()

    // NOT an error state: a session ending is the normal end of a shift, which is 10a's
    // `call()` docblock applied to the one route that is read every few seconds.
    await vi.waitFor(() => {
      expect(screen.getByRole('heading', { name: t('staff.signin.title') })).toBeDefined()
    })

    expect(everything()).not.toContain('A-128')
  })
})

describe('R-224 and the board’s staleness, through the real client — 10b’s FIX ROUND', () => {
  /*
   * ================================================================================
   * EVERY CASE HERE IS A `§11` ROUND-ONE FINDING, DRIVEN
   * ================================================================================
   *
   * `§11`'s merge rule is *every Critical and High fixed with a regression test*, and the
   * reviewers established by probe that four of these behaviours did not exist and one of
   * them had no test that could tell it from its own absence. So each case names its finding
   * and asserts the POSITIVE — the sentence that must appear, the state that must be
   * reachable — rather than an absence that would hold either way. That is H-4's lesson
   * applied to the rest of the round.
   */

  /**
   * Advance the fake clock by exactly `seconds`, with React's work flushed.
   *
   * ⚠ **`act()` IS NOT OPTIONAL HERE, AND LEAVING IT OUT IS H-4's LESSON AGAIN.**
   * `vi.advanceTimersByTimeAsync` fires the timer and resolves the fetch, but the resulting
   * `setState` calls are not flushed into the DOM outside `act`, so the document a following
   * assertion reads is the one from BEFORE the poll. Every assertion that is an ABSENCE
   * passes anyway — which is why the existing 429 cases in this file work without it, and
   * exactly why `§11` QA H-4 could revert the fix under them and see nothing move. A case
   * that asserts a POSITIVE change needs the change to have happened.
   *
   * Measured while writing these cases: without `act`, the staleness memo was observed
   * recomputing twice (both with `gap: 0`) and the marker never appeared, against a
   * component that is correct.
   */
  const advance = async (seconds: number): Promise<void> => {
    await act(async () => {
      await vi.advanceTimersByTimeAsync(seconds * 1000 + 1)
    })
  }

  const letTheTimerFire = async (): Promise<void> => {
    await advance(BOARD_INTERVAL_SECONDS)
  }

  it('QA H-1: shows the staleness marker once the board stops being confirmed', async () => {
    /*
     * The marker could NEVER render: `staleSince` was a memo over values that change only on
     * a SUCCESSFUL poll, and at that instant `confirmedAt === now`. Through a run of failures
     * nothing in its dependency list moved, so it was never recomputed — the reviewer measured
     * ten rejected polls across 800 s of fake-timer time and found no marker and no offline
     * notice, because the rows are deliberately kept. "Keep the rows" was a lie without it.
     */
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [
        { status: 200, body: { sessions: [BOARD_PAGER] } },
        'unreachable',
        'unreachable',
        'unreachable',
      ],
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)

    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

    // Fresh: no marker, because the rows ARE current.
    expect(document.querySelector('[data-board-stale="true"]')).toBeNull()

    await letTheTimerFire()

    /*
     * ⚠ STILL NOT STALE AFTER EXACTLY ONE INTERVAL, AND THIS IS THE BOUNDARY RATHER THAN A
     * MISS. `isStale` is a strict `>`, and with the jitter pinned at its midpoint the first
     * failed poll lands exactly one interval after the last confirmation — so the data is one
     * interval old, which is the freshest a polled board is ever guaranteed to be. Saying
     * *last updated* then would mark every healthy board stale once, between a poll and its
     * answer.
     *
     * The first version of this case stopped here and reported the component broken. It was
     * the test that was wrong, and the boundary is worth asserting rather than stepping over.
     */
    expect(document.querySelector('[data-board-stale="true"]')).toBeNull()

    /*
     * ⚠ TEN SECONDS, NOT FIVE: the client has already backed off once, so the next poll is a
     * DOUBLING away and a second five-second advance fires nothing. Advancing by the interval
     * again is the other mistake this case made — the clock moved and the poll did not, which
     * reads exactly like a component that stopped updating.
     */
    await advance(BOARD_INTERVAL_SECONDS * 2)

    /*
     * ⚠ THE POSITIVE ASSERTION, SYNCHRONOUS AFTER AN `act`-WRAPPED ADVANCE. Two failed polls,
     * so what is on screen is now older than one interval, and the marker names when it was
     * last confirmed. A `waitFor` here would hide whether the render had happened at all.
     */
    expect(document.querySelector('[data-board-stale="true"]')).not.toBeNull()
    expect(document.querySelector('[data-board-stale="true"]')?.textContent).toContain(
      t('staff.board.stale', { time: '' }).replace('{time}', '').trim(),
    )

    // And the rows are still there, which is the property the marker makes honest.
    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
  })

  it('QA H-2 / SEC M-4: a 429 on sign-in says WAIT, and never “that did not work”', async () => {
    // The stage, its copy in both languages and the type forbidding an action were all built
    // — and no call site ever set it. A rate-limited person was told their credential was
    // wrong and to try the one thing that would 429 again.
    stack({
      ...SIGNED_OUT,
      'POST /v1/auth/login': { status: 429, headers: { 'Retry-After': '60' } },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()
    await fillCredentials()
    press(t('staff.signin.submit'))

    await waitFor(() => {
      expect(screen.getByText(t('staff.signin.throttled'))).toBeDefined()
    })

    // ⚠ AND THE FALSE SENTENCE IS GONE. Asserting only the new copy would pass against a
    // screen that showed both.
    expect(everything()).not.toContain(t('staff.signin.failedHead'))

    // The form stays usable, which is the whole difference from the board's throttle: on
    // sign-in the person must act, later.
    expect(document.querySelector('[data-passkey-signin="true"]')).not.toBeNull()
  })

  it('QA H-2: the second factor throttles too, which is the 5-per-minute bucket', async () => {
    // `auth-2fa` is 5/minute, so this is the throttle a person meets by mistyping — the most
    // likely of the four to be reached in ordinary use.
    stack({
      ...SIGNED_OUT,
      'POST /v1/auth/login': { status: 200, body: { two_factor_required: true } },
      'POST /v1/auth/two-factor/challenge': { status: 429 },
    })

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await signInHeading()
    await fillCredentials()
    press(t('staff.signin.submit'))

    await waitFor(() => {
      expect(screen.getByLabelText(t('staff.signin.code'))).toBeDefined()
    })

    fireChange(screen.getByLabelText(t('staff.signin.code')), '123456')
    press(t('staff.signin.submit'))

    await waitFor(() => {
      expect(screen.getByText(t('staff.signin.throttled'))).toBeDefined()
    })

    expect(everything()).not.toContain(t('staff.signin.failedHead'))
  })

  it('QA H-3: a throttled SHELL says what R-224 ruled, not “something went wrong on our side”', async () => {
    /*
     * `R-224`'s *review by* cell names the acceptance in so many words — *"the state added to
     * §12 S2"* — and `§12` S2 IS the shell. The first fix added the state to the board and to
     * sign-in and left `load()` funnelling every non-ok answer into `error`, so a throttled
     * `/v1/auth/me` still read R-224's own sentence, on the very endpoint R-224 was measured
     * on.
     */
    stack({ 'GET /v1/auth/me': { status: 429, headers: { 'Retry-After': '45' } } })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)

    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.shell.throttled.title'))).toBeDefined()
    })

    expect(screen.getByText(t('staff.shell.throttled.body'))).toBeDefined()

    // ⚠ THE SENTENCE R-224 IS ABOUT IS ABSENT.
    expect(everything()).not.toContain(t('staff.shell.error.title'))
    expect(everything()).not.toContain(t('staff.shell.error.body'))

    // ⚠ AND NOTHING TO PRESS, which is what makes "you do not need to do anything" true: the
    // shell schedules its own retry. A button here would be a second 429.
    expect(document.querySelector('[data-state="throttled"] button')).toBeNull()
  })

  it('QA H-3: the throttled shell RESUMES ITSELF, which is what makes its copy honest', async () => {
    const scripted = stack({
      'GET /v1/auth/me': [{ status: 429 }, { status: 200, body: identity() }],
      'GET /v1/tenant': {
        status: 200,
        body: { tenant: { name: RESTAURANT, timezone: 'Asia/Riyadh' } },
      },
      'GET /v1/pager-sessions': { status: 200, body: { sessions: [] } },
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)

    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.shell.throttled.title'))).toBeDefined()
    })

    expect(scripted.calls('GET /v1/auth/me')).toHaveLength(1)

    // The shell's own backoff: one failure, so one doubling of the board interval.
    await vi.advanceTimersByTimeAsync(BOARD_INTERVAL_SECONDS * 2 * 1000 + 1)

    // ⚠ ASSERTED ON THE SECOND CALL, not only on the paint: a shell that merely re-rendered
    // would satisfy a copy assertion while still having stopped.
    await vi.waitFor(() => {
      expect(scripted.calls('GET /v1/auth/me')).toHaveLength(2)
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })
  })

  it('QA M-14: the board’s throttled notice appears only once the backoff window is exhausted', async () => {
    /*
     * The TRUE branch of `shouldTellThem` was never driven: the arithmetic had unit tests and
     * the screen had a prop-level render, and the one orchestration case was the NEGATIVE.
     * This drives the composition — a run of 429s until the window is exhausted — and asserts
     * both halves of the ruling: silent before, speaking after.
     */
    stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions': [
        { status: 200, body: { sessions: [BOARD_PAGER] } },
        ...Array.from({ length: MAX_CONSECUTIVE_FAILURES }, () => ({ status: 429 }) as const),
      ],
    })

    vi.useFakeTimers()

    render(<StaffApp apiBase="" view="home" locale="en" random={() => 0.5} />)

    await vi.waitFor(() => {
      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    })

    /*
     * ⚠ ONE ATTEMPT PER ADVANCE, BY THE EXACT NEXT DELAY — and advancing by the CAP instead
     * is what the first version did and why it reported *"the board spoke after 3
     * throttle(s)"*. The delays double (5, 10, 20, 40, 80, then the 120 s cap), so a single
     * 120-second advance fires the 5 s timer, then the 10 s one, then the 20 s one inside the
     * remaining window: three attempts consumed by one advance, and the loop's own counter
     * then described something that had not happened. Stepping by the delay the client will
     * actually have chosen is the only way the count means what the assertion says.
     */
    const delayFor = (failures: number): number =>
      Math.min(BOARD_INTERVAL_SECONDS * 2 ** failures, BACKOFF_CAP_SECONDS)

    for (let attempt = 1; attempt < MAX_CONSECUTIVE_FAILURES; attempt += 1) {
      await advance(delayFor(attempt - 1))

      expect(
        document.querySelector('[data-board-throttled="true"]'),
        `the board spoke after ${String(attempt)} throttle(s), before the window was exhausted`,
      ).toBeNull()
    }

    await advance(delayFor(MAX_CONSECUTIVE_FAILURES - 1))

    expect(document.querySelector('[data-board-throttled="true"]')).not.toBeNull()

    // The rows are STILL there: the ruling says the copy appears, not that the board goes.
    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
  })

  it('SEC M-3: a poll already in flight when the shell locks cannot write its rows back', async () => {
    /*
     * The run token covered a SCHEDULED TIMER and not a REQUEST IN FLIGHT. `lock()` does not
     * await, the component stays mounted, and the pending answer then repopulated `pagers` —
     * so the NEXT person to sign in got the previous occupant's orders until the first poll
     * answered. On a shared kitchen tablet with two restaurants that is a cross-tenant render.
     *
     * The board's answer is held open deliberately and released AFTER the lock, which is the
     * window the fix closes and which no settled-board test can reach.
     */
    let releaseBoard: ((value: Response) => void) | undefined

    const held = new Promise<Response>((resolve) => {
      releaseBoard = resolve
    })

    const json = (status: number, body: unknown): Response =>
      ({
        status,
        ok: status >= 200 && status < 300,
        json: () => Promise.resolve(body),
        headers: { get: () => null },
      }) as unknown as Response

    let boardCalls = 0

    vi.stubGlobal(
      'fetch',
      vi.fn((input: unknown) => {
        const path = String(input).replace(/^https?:\/\/[^/]+/, '')

        if (path === '/v1/auth/me') return Promise.resolve(json(200, identity()))
        if (path === '/v1/tenant') {
          return Promise.resolve(json(200, { tenant: { name: RESTAURANT, timezone: 'UTC' } }))
        }
        if (path === '/v1/auth/logout') return Promise.resolve(json(204, null))
        if (path === '/v1/auth/login') return Promise.resolve(json(200, {}))

        if (path === '/v1/pager-sessions') {
          boardCalls += 1

          /*
           * ⚠ BOTH READS ARE HELD, AND THE SECOND ONE IS WHAT MAKES THIS DETERMINISTIC.
           *
           * The first is released after the lock — that is the in-flight write-back under
           * test. The second, which the board issues on the NEXT sign-in, is held for ever,
           * so the board has no fresh data of its own and what it renders is whatever
           * survived in state. Letting that second read answer would race the assertion and
           * clear the rows for the wrong reason.
           */
          return boardCalls === 1 ? held : new Promise<Response>(() => undefined)
        }

        return Promise.resolve(json(404, {}))
      }),
    )

    render(<StaffApp apiBase="" view="home" locale="en" />)

    // The chrome is up, so the shell is `ready` and the board's first read is in flight.
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    press(t('staff.shell.signOut'))

    await waitFor(() => {
      expect(screen.getByText(t('staff.signedout.title'))).toBeDefined()
    })

    // NOW the held board answer lands, after the lock. This is the write-back.
    await act(async () => {
      releaseBoard?.(json(200, { sessions: [BOARD_PAGER] }))
      await Promise.resolve()
    })

    // Nothing of that restaurant's is on the locked screen.
    expect(everything()).not.toContain('A-128')

    /*
     * ⚠ AND NOW THE SECOND PERSON SIGNS IN, WHICH IS THE HALF THAT ACTUALLY BITES AND THE
     * HALF THE FIRST VERSION OF THIS CASE MISSED.
     *
     * The locked phase renders only the overlay, so a write-back behind it is invisible and
     * an assertion that stops at the lock PASSES AGAINST THE DEFECT — measured: with the
     * run-token check reverted, the version of this case that ended above stayed green.
     * `afterCredential()` does not clear tenant data, so the next successful sign-in sets
     * `phase = 'ready'` with whatever is in state — and on a shared kitchen tablet with two
     * restaurants that is one occupant's orders rendered to the next.
     */
    press(t('staff.lock.signIn'))
    await signInHeading()
    await fillCredentials()
    press(t('staff.signin.submit'))

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    // ⚠ BY VALUE, over text AND attributes. The order reference belongs to one restaurant, so
    // §12's lock clause covers it as much as the restaurant's own name.
    expect(everything()).not.toContain('A-128')
    expect(everything()).not.toContain(BOARD_PAGER.id)
  })
})

/* ------------------------------------------------------------------ small test helpers */

function fireChange(element: HTMLElement, value: string): void {
  const input = element as HTMLInputElement
  const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')

  descriptor?.set?.call(input, value)
  input.dispatchEvent(new Event('input', { bubbles: true }))
}

/**
 * Open the password disclosure and fill it.
 *
 * ⚠ IT AWAITS THE FORM. A `.click()` dispatches the event synchronously but React's state
 * update and re-render are not synchronous with it, so a `getByLabelText` on the next line
 * looks for a field that does not exist yet — which is how the first run of this file failed
 * six cases with *"Unable to find a label with the text of: Email"* against a screen that
 * works. Every press that reveals something is followed by a wait for the something.
 */
async function fillCredentials(): Promise<void> {
  // QRPAGER-38: the password form needs no reveal; see the note in staff-interactions.

  await waitFor(() => {
    expect(screen.getByLabelText(t('staff.signin.email'))).toBeDefined()
  })

  fireChange(screen.getByLabelText(t('staff.signin.email')), 'hiba@example.test')
  fireChange(screen.getByLabelText(t('staff.signin.passwordLabel')), 'correct horse battery staple')
}

/** Open the add-a-screen form, name the screen, and submit it. */
async function issueScreenNamed(name: string): Promise<void> {
  press(t('staff.devices.add'))

  await waitFor(() => {
    expect(screen.getByLabelText(t('staff.devices.name.label'))).toBeDefined()
  })

  fireChange(screen.getByLabelText(t('staff.devices.name.label')), name)
  submitForm()
}

/**
 * Remove the one screen on the page, through its confirmation.
 *
 * `staff.devices.remove` and `staff.devices.remove.confirm` are both *"Remove"*, which is
 * deliberate — the confirmation REPLACES the button rather than stacking a second one — so
 * this presses the same name twice and the wait between them is what makes that honest.
 */
async function removeTheScreen(): Promise<void> {
  press(t('staff.devices.remove'))

  await waitFor(() => {
    expect(screen.getByText(t('staff.devices.remove.title'))).toBeDefined()
  })

  press(t('staff.devices.remove.confirm'))
}

function submitForm(): void {
  const form = document.querySelector('form')

  if (form === null) throw new Error('there is no form on screen to submit')

  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}
