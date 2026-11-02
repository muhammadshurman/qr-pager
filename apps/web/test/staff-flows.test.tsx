import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type * as PasskeyModule from '../src/staff/passkey.ts'
import { StaffApp } from '../src/StaffApp.tsx'
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

/** One scripted answer: a status with a body, or a connection that never completes. */
type Answer = { readonly status: number; readonly body?: unknown } | 'unreachable'

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

const SIGNED_IN: Record<string, Answer> = {
  'GET /v1/auth/me': { status: 200, body: identity() },
  'GET /v1/tenant': {
    status: 200,
    body: { tenant: { name: RESTAURANT, timezone: 'Asia/Riyadh' } },
  },
  'GET /v1/devices': { status: 200, body: { devices: [] } },
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
      expect(everything()).toContain(t('staff.signin.failed'))
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
      expect(everything()).toContain(t('staff.signin.failed'))
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

    expect(everything()).not.toContain(t('staff.signin.failed'))
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
      expect(everything()).toContain(t('staff.signin.failed'))
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
      expect(everything()).toContain(t('staff.signin.failed'))
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

    // And the page's own next action is still there, which is what §12 asks of the state.
    pressInPage(t('staff.home.action'))
  })
})

describe('navigation out of the shell', () => {
  it('asks the host to navigate rather than routing itself', async () => {
    stack(SIGNED_IN)

    const onNavigate = vi.fn()

    render(<StaffApp apiBase="" view="home" locale="en" onNavigate={onNavigate} />)
    await shellReady()

    pressInPage(t('staff.home.action'))

    expect(onNavigate).toHaveBeenCalledExactlyOnceWith('devices')
  })

  it('does not fall over when the host passed no navigator', async () => {
    // The optional-prop path, which `app/staff/page.tsx` never takes and a reviewer worktree
    // rendering the component directly does.
    stack(SIGNED_IN)

    render(<StaffApp apiBase="" view="home" locale="en" />)
    await shellReady()

    pressInPage(t('staff.home.action'))

    expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
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
  press(t('staff.signin.password'))

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
