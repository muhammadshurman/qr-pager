import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffApp, type StaffView } from '../src/StaffApp.tsx'

/**
 * ================================================================================
 * THE ORCHESTRATOR — the lock's ORDER, and what survives it
 * ================================================================================
 *
 * `QRPAGER-25 §12` S3 states the lock as three steps and the ORDER is the security property:
 *
 *   1. `POST /v1/auth/logout` — the SERVER session is destroyed;
 *   2. the tenant data is dropped from memory;
 *   3. the overlay renders.
 *
 * A lock that did 3 and not 1 would leave a working cookie in a browser a passer-by can open
 * devtools on, and *"a locked shell reveals no tenant datum"* would be a claim about CSS. So
 * the case below asserts the REQUEST was made, not only that the overlay appeared — the two
 * are what distinguish a lock from a curtain.
 *
 * ⚠ AND IT ASSERTS THE DATA IS GONE FROM THE DOCUMENT, BY VALUE. The restaurant's name and
 * the person's name are given distinctive strings by the fixture, and after the lock neither
 * appears anywhere in the rendered tree or in any attribute of it. A check of the overlay's
 * presence would pass against a shell that kept the chrome underneath.
 */

const t = translatorFor('en')

const RESTAURANT = 'Al Baik Jeddah'
const PERSON = 'Hiba Mansour'
const TENANT_ID = '01a199c4-7f3e-7000-8000-0123456789ab'

interface Call {
  readonly url: string
  readonly method: string
}

/** A fake stack. Records every call, so the lock's first step is observable. */
function stack(options?: { readonly identity?: unknown; readonly tenantStatus?: number }) {
  const calls: Call[] = []

  const json = (status: number, body: unknown): Response =>
    ({ status, ok: status >= 200 && status < 300, json: async () => body }) as unknown as Response

  const fetchMock = vi.fn(async (input: unknown, init?: RequestInit) => {
    const url = String(input)

    calls.push({ url, method: init?.method ?? 'GET' })

    if (url.endsWith('/v1/auth/me')) {
      return json(
        200,
        options?.identity ?? {
          name: PERSON,
          memberships: [{ tenant_id: TENANT_ID, role: 'owner' }],
          active_tenant: TENANT_ID,
        },
      )
    }

    if (url.endsWith('/v1/tenant')) {
      const status = options?.tenantStatus ?? 200

      return status === 200
        ? json(200, {
            tenant: { name: RESTAURANT, slug: 'al-baik', timezone: 'UTC', status: 'active' },
          })
        : json(status, {})
    }

    if (url.endsWith('/v1/devices')) return json(200, { devices: [] })
    if (url.endsWith('/v1/auth/logout')) return json(204, null)

    return json(404, {})
  })

  vi.stubGlobal('fetch', fetchMock)

  return { calls, fetchMock }
}

/** Every text node and attribute value in the document, as one string. */
function everything(): string {
  const parts: string[] = [document.body.textContent ?? '']

  for (const element of document.body.querySelectorAll('*')) {
    for (const attribute of element.attributes) parts.push(attribute.value)
  }

  return parts.join(' | ')
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('an unauthenticated caller', () => {
  it('gets the sign-in view at EVERY staff view, with no chrome and no redirect', async () => {
    // The browser half of 10a's invariant, at the component level: there is no protected
    // document to reach, so an unauthenticated caller at `/staff/devices` sees sign-in AT
    // that view rather than being bounced from a page that briefly existed.
    for (const view of ['home', 'devices'] as StaffView[]) {
      vi.stubGlobal(
        'fetch',
        vi
          .fn()
          .mockResolvedValue({
            status: 401,
            ok: false,
            json: async () => ({}),
          } as unknown as Response),
      )

      const { unmount } = render(<StaffApp apiBase="" view={view} />)

      await waitFor(() => {
        expect(screen.getByRole('heading', { name: t('staff.signin.title') })).toBeDefined()
      })

      // No chrome, by the three things only the chrome renders.
      expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()
      expect(document.querySelector('[data-sign-out="true"]')).toBeNull()
      expect(everything()).not.toContain(t('staff.shell.signOut'))

      unmount()
    }
  })
})

describe('a signed-in shell', () => {
  it('names the restaurant and the person, and renders neither id', async () => {
    stack()

    render(<StaffApp apiBase="" view="home" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    expect(everything()).toContain(PERSON)
    // §12: no unexplained identifier. The tenant id is in the identity payload and must
    // reach no element -- text or attribute.
    expect(everything()).not.toContain(TENANT_ID)
    // And the role is the WORD, not the enum value.
    expect(everything()).toContain(t('staff.role.owner'))
    expect(document.querySelector('[data-role-word="true"]')?.textContent).toBe(
      t('staff.role.owner'),
    )
  })

  it('shows NO chrome while it is still loading, which was a real defect', async () => {
    // Found by reading the BUILT HTML rather than by a test: the first version rendered the
    // shell for every phase but locked and signed-out, so `/staff` prerendered
    // `qr-staff__restaurant`, a `Sign out` button and `Signed in as ` with an empty name --
    // for a caller who had presented nothing. Pinned here as well as in the build surface
    // test, because this is where the regression would be reintroduced.
    //
    // A never-resolving fetch holds the component in `loading`.
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>(() => undefined)),
    )

    render(<StaffApp apiBase="" view="home" />)

    expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()
    expect(everything()).not.toContain('Signed in as')
    expect(document.querySelector('[data-state="loading"]')).not.toBeNull()
  })
})

describe('the lock', () => {
  it('destroys the SERVER session first, then drops the data, then covers the screen', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const { calls } = stack()

    render(<StaffApp apiBase="" view="home" idleSeconds={10} />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    // Not vacuous: the data IS on the screen before the lock, so its absence afterwards
    // means something.
    expect(everything()).toContain(RESTAURANT)
    expect(everything()).toContain(PERSON)
    expect(calls.some((call) => call.url.endsWith('/v1/auth/logout'))).toBe(false)

    await vi.advanceTimersByTimeAsync(20_000)

    await waitFor(() => {
      expect(document.querySelector('[data-locked="true"]')).not.toBeNull()
    })

    // 1. THE REQUEST WAS MADE. This is the assertion that distinguishes a lock from a
    //    curtain, and it is a POST to the real logout route.
    const logout = calls.filter((call) => call.url.endsWith('/v1/auth/logout'))

    expect(logout.length).toBe(1)
    expect(logout[0]?.method).toBe('POST')

    // 2. THE DATA IS GONE FROM THE DOCUMENT, by value and including attributes.
    const rendered = everything()

    expect(rendered.includes(RESTAURANT), 'the restaurant survived the lock').toBe(false)
    expect(rendered.includes(PERSON), 'the person survived the lock').toBe(false)
    expect(rendered.includes(TENANT_ID), 'the tenant id survived the lock').toBe(false)

    // 3. AND THE OVERLAY SAYS WHAT TO DO, naming neither the person nor the restaurant.
    expect(screen.getByRole('heading', { name: t('staff.lock.title') })).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.lock.signIn') })).toBeDefined()
    expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()
  })

  it('writes nothing to storage, so there is nowhere for a datum to survive', async () => {
    stack()

    render(<StaffApp apiBase="" view="home" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    // ⚠ THE STRONGEST FORM OF "the lock reveals no tenant datum" IS THAT THERE IS NOWHERE
    // FOR ONE TO SURVIVE. Task 9's `R-210`/`SEC M-3` is the precedent read the other way: a
    // value written back into stored data was the defect, and moving a cache NAME while
    // leaving the mechanism was the fix that did not fix it. This app stores nothing.
    expect(window.localStorage.length).toBe(0)
    expect(window.sessionStorage.length).toBe(0)
    expect(document.cookie).toBe('')
    // And nothing of the tenant reached the URL either.
    expect(window.location.href).not.toContain(TENANT_ID)
    expect(window.location.hash).toBe('')
  })

  it('signs out on demand by the same path as the idle lock, so there is one mechanism', async () => {
    const { calls } = stack()

    render(<StaffApp apiBase="" view="home" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: RESTAURANT })).toBeDefined()
    })

    document.querySelector<HTMLButtonElement>('[data-sign-out="true"]')?.click()

    await waitFor(() => {
      expect(document.querySelector('[data-locked="true"]')).not.toBeNull()
    })

    expect(calls.filter((call) => call.url.endsWith('/v1/auth/logout')).length).toBe(1)
    expect(everything().includes(RESTAURANT)).toBe(false)
  })
})

describe('a user with two memberships and none selected', () => {
  it('is asked which restaurant, because the server refuses to pick', async () => {
    // The actor 10a makes real. `Memberships::sole()` refuses to choose, so every staff
    // route answers 401 until `POST /v1/auth/tenant` -- and this is the screen for it.
    stack({
      identity: {
        name: PERSON,
        memberships: [
          { tenant_id: TENANT_ID, role: 'owner' },
          { tenant_id: '01a199c4-7f3e-7000-8000-ba9876543210', role: 'manager' },
        ],
        active_tenant: null,
      },
    })

    render(<StaffApp apiBase="" view="home" />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: t('staff.signin.choose') })).toBeDefined()
    })

    // And no chrome yet: no tenant is resolved, so there is no restaurant to name.
    expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()
  })
})

describe('the shell when the restaurant cannot be read', () => {
  it('shows the error state WITHOUT chrome rather than a shell with a blank name', async () => {
    stack({ tenantStatus: 500 })

    render(<StaffApp apiBase="" view="home" />)

    await waitFor(() => {
      expect(screen.getByText(t('staff.shell.error.title'))).toBeDefined()
    })

    // A shell whose `<h1>` is empty is the §12 defect this phase exists to avoid: the
    // heading a person glances up to confirm where they are would say nothing at all.
    expect(document.querySelector('[data-staff-shell="true"]')).toBeNull()
    expect(screen.getByRole('button', { name: t('staff.shell.error.retry') })).toBeDefined()
  })
})
