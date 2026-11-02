import { render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

const push = vi.fn()

vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }))

const { StaffNavigator } = await import('../src/staff/StaffNavigator.tsx')

/**
 * ================================================================================
 * THE ROUTER ADAPTER. Three lines, and the reason it is tested is coverage honesty.
 * ================================================================================
 *
 * `StaffApp` takes an `onNavigate` callback and knows nothing about routing, which is what
 * lets every other test of it — unit, jsdom and browser — drive real navigation or none
 * without a router mock. This component is the adapter.
 *
 * ⚠ ITS OWN DOCBLOCK SAID IT "needs no test of its own beyond the browser case that clicks
 * through", and that was a reasonable thing to write and the wrong thing to leave: it came out
 * of the coverage report at **0%**, and a file at zero is indistinguishable from a file that
 * is dead. `§6`'s clause is a NUMBER on the code this task added, so "it is only three lines"
 * is an argument the threshold does not take.
 *
 * What is asserted is the mapping, which is the only decision in the file — and getting it
 * backwards is a real bug a browser case would catch slowly and this catches instantly.
 */

afterEach(() => {
  push.mockClear()
  vi.unstubAllGlobals()
})

describe('the router adapter', () => {
  it('maps each view to its own path, and nothing navigates on render', () => {
    const { container } = render(<StaffNavigator apiBase="" view="home" />)

    // Rendering navigates nowhere. A redirect on mount is how a shell loops.
    expect(push).not.toHaveBeenCalled()
    expect(container.firstChild).not.toBeNull()
  })

  it('pushes /staff/devices for the devices view and /staff for home', async () => {
    // Driven through the real `StaffApp`, whose nav buttons call `onNavigate` -- so this
    // asserts the WIRING and not just a function in isolation.
    vi.stubGlobal(
      'fetch',
      vi.fn((input: unknown) => {
        const url = String(input)

        const body = url.endsWith('/v1/auth/me')
          ? {
              user: { id: 'u', name: 'Hiba', email: 'h@example.test' },
              memberships: [{ tenant_id: 't', role: 'owner' }],
              active_tenant: 't',
            }
          : { tenant: { name: 'Al Baik', slug: 'al-baik', timezone: 'UTC', status: 'active' } }

        return Promise.resolve({
          status: 200,
          ok: true,
          json: () => Promise.resolve(body),
        } as unknown as Response)
      }),
    )

    render(<StaffNavigator apiBase="" view="home" />)

    const devices = await vi.waitFor(() => {
      const button = document.querySelector<HTMLButtonElement>('[data-nav="devices"]')

      expect(button).not.toBeNull()

      // `!` rather than `as`: the expect above has already proven it is not null, and the
      // lint rule is right that an assertion says that more briefly than a cast does.
      return button!
    })

    devices.click()

    expect(push).toHaveBeenCalledWith('/staff/devices')

    document.querySelector<HTMLButtonElement>('[data-nav="home"]')?.click()

    expect(push).toHaveBeenLastCalledWith('/staff')
  })
})
