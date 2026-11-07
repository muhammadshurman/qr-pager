import { render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

const push = vi.fn()

vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }))

const { StaffNavigator } = await import('../src/staff/StaffNavigator.tsx')

import type { StaffView } from '../src/StaffApp.tsx'

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

  it('pushes the right path for EVERY view, driven through the real nav buttons', async () => {
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

    /*
     * ⚠ **EVERY VIEW, AND THE LIST IS EXHAUSTIVE OVER `StaffView` BY TYPE** — `satisfies`
     * makes a view added to that union without an entry here a compile error.
     *
     * This mattered at 10e for a specific reason: `StaffNavigator`'s map was a TERNARY until
     * then (`next === 'devices' ? '/staff/devices' : '/staff'`), which was correct for two
     * views and silently wrong for five — every new view would have pushed `/staff` and
     * rendered the board, so a person pressing *Past orders* would have landed on the board
     * with no error anywhere. A case that drove only two of five could not have seen it.
     */
    const expected = {
      home: '/staff',
      history: '/staff/history',
      issue: '/staff/issue',
      team: '/staff/team',
      devices: '/staff/devices',
    } as const satisfies Record<StaffView, string>

    for (const [view, path] of Object.entries(expected)) {
      push.mockClear()

      const button = document.querySelector<HTMLButtonElement>(`[data-nav="${view}"]`)

      // Not vacuous: the nav really does carry a control for this view. A `?.click()` on a
      // missing button is a silent no-op, and the loop would then assert nothing five times.
      expect(button, `the shell renders no nav control for ${view}`).not.toBeNull()

      button?.click()

      expect(push, `${view} navigated somewhere else`).toHaveBeenCalledWith(path)
      expect(push).toHaveBeenCalledTimes(1)
    }
  })
})
