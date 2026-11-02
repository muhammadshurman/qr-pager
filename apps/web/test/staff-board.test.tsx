import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffBoard, legalActions, type DetailPhase } from '../src/screens/staff/StaffBoard.tsx'
import type { BoardPager } from '../src/staff/api.ts'

/**
 * ================================================================================
 * S6 + S7 — THE BOARD AND ITS DETAIL, AT THE COMPONENT LEVEL
 * ================================================================================
 *
 * `QRPAGER-26-precoding.md §12` S6/S7 is the specification; this file is the assertion of
 * it. The ORCHESTRATION — the poll loop, the backoff, what each server answer does to the
 * phase — is `staff-flows.test.tsx`'s, driven through `StaffApp` against a fake stack,
 * because a cadence asserted against a component in isolation is a cadence nobody exercises.
 *
 * ⚠ TWO PROPERTIES HERE ARE INHERITED FROM THE DELETED `StaffHome` AND ARE ASSERTED OVER THE
 * BOARD'S COPY INSTEAD: `§12`'s *no empty state without a next action*, and the sweep for
 * system vocabulary about our own delivery plan. `staff-screens.test.tsx` records where they
 * went, so the move reads as a move rather than as coverage lost.
 */

const t = translatorFor('en')

function pager(overrides?: Partial<BoardPager>): BoardPager {
  return {
    id: '01a199c4-7f3e-7000-8000-0123456789ab',
    orderReference: 'A-128',
    status: 'waiting',
    createdAt: '2026-11-02T10:00:00Z',
    readyAt: null,
    ...overrides,
  }
}

/** Every prop, so a case can override exactly the one it is about. */
function props(overrides?: Partial<Parameters<typeof StaffBoard>[0]>) {
  return {
    t,
    phase: 'ready' as const,
    pagers: [pager()],
    elapsed: { '01a199c4-7f3e-7000-8000-0123456789ab': 185 },
    staleSince: null,
    onRetry: vi.fn(),
    selected: null,
    onSelect: vi.fn(),
    detail: 'idle' as DetailPhase,
    onReady: vi.fn(),
    onRenotify: vi.fn(),
    onComplete: vi.fn(),
    onCancel: vi.fn(),
    onConfirmCancel: vi.fn(),
    ...overrides,
  }
}

/** Every text node and attribute value in the document, as one string. */
function everything(): string {
  const parts: string[] = [document.body.textContent]

  for (const element of document.body.querySelectorAll('*')) {
    for (const attribute of element.attributes) parts.push(attribute.value)
  }

  return parts.join(' | ')
}

describe('which actions a status offers', () => {
  it('offers exactly what the server would allow, per status', () => {
    // The SERVER is the authority -- `PagerStatus::allows()` answers 409 -- so this asserts
    // the screen agrees with it. Offering an action the server refuses is how a person
    // learns the screen lies to them.
    expect(legalActions('waiting')).toEqual(['ready', 'cancel'])
    expect(legalActions('ready')).toEqual(['complete', 'renotify', 'cancel'])

    // ⚠ AND EVERY TERMINAL STATUS OFFERS NOTHING, enumerated rather than sampled: a status
    // added to the contract and not handled here would otherwise fall through to whatever
    // the last branch returns.
    for (const status of ['completed', 'cancelled', 'expired'] as const) {
      expect(legalActions(status), status).toEqual([])
    }
  })
})

describe('the board', () => {
  it('names each order and shows its status as a WORD, never the enum value', () => {
    render(
      <StaffBoard
        {...props({
          // Two DISTINCT references, because the fixture's default would otherwise give
          // both rows the same name and `getByText` would fail on ambiguity -- which it did,
          // and is a fair complaint about the fixture rather than about the board.
          pagers: [pager(), pager({ id: 'b', orderReference: 'B-220', status: 'ready' })],
        })}
      />,
    )

    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeDefined()
    expect(screen.getByText(t('staff.board.order', { reference: 'B-220' }))).toBeDefined()

    // The status words are present...
    expect(everything()).toContain(t('staff.board.status.waiting'))
    expect(everything()).toContain(t('staff.board.status.ready'))

    // ...and §12's real requirement is that the enum value is NOT what a person reads. It
    // is legitimately in `data-status`, which is how a test and a stylesheet find a row, so
    // the assertion is over TEXT rather than over the whole document.
    const text = document.body.textContent

    for (const value of ['waiting', 'ready', 'completed', 'cancelled', 'expired']) {
      expect(text.includes(value), `the board renders the raw status "${value}"`).toBe(false)
    }
  })

  it('renders NOT ONE identifier — no session id anywhere in the document', () => {
    const id = '01a199c4-7f3e-7000-8000-0123456789ab'

    render(<StaffBoard {...props()} />)

    // ⚠ OVER EVERY TEXT NODE **AND** EVERY ATTRIBUTE, which is `StaffDevices`' own property
    // held to the same proof. The id must reach the component -- every action needs it in a
    // path -- and must reach nothing the browser renders or exposes.
    expect(everything()).not.toContain(id)
  })

  it('offers a next action on its empty state, which §12 requires and the type enforces', () => {
    const onRetry = vi.fn()

    render(<StaffBoard {...props({ pagers: [], onRetry })} />)

    expect(screen.getByText(t('staff.board.empty.title'))).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.board.empty.action') })).toBeDefined()
  })

  it('says nothing about our own delivery plan', () => {
    // Inherited from the deleted `StaffHome`'s case. A restaurant owner's screen is not
    // where our ticket numbers belong, and an empty board is the state most tempting to
    // explain with one.
    render(<StaffBoard {...props({ pagers: [] })} />)

    const text = document.body.textContent.toLowerCase()

    for (const word of ['coming soon', 'not built', 'task 10', '10b', 'todo', 'placeholder']) {
      expect(text.includes(word), `the board says "${word}"`).toBe(false)
    }
  })

  it('KEEPS the rows through a failure and shows the staleness marker instead', () => {
    // The design decision `pagers`/`boardPhase` are two pieces of state for. A kitchen
    // mid-service keeps looking at the orders it had; the data is not wrong, it is not fresh.
    for (const phase of ['error', 'offline', 'throttled'] as const) {
      const { unmount } = render(<StaffBoard {...props({ phase, staleSince: '14:32' })} />)

      expect(screen.getByText(t('staff.board.order', { reference: 'A-128' })), phase).toBeDefined()
      expect(screen.getByText(t('staff.board.stale', { time: '14:32' })), phase).toBeDefined()
      unmount()
    }
  })

  it('shows the failure state ONLY when it has nothing to show', () => {
    const { unmount } = render(<StaffBoard {...props({ phase: 'offline', pagers: [] })} />)

    expect(screen.getByText(t('staff.board.offline.title'))).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.board.retry') })).toBeDefined()
    unmount()

    render(<StaffBoard {...props({ phase: 'error', pagers: [] })} />)

    expect(screen.getByText(t('staff.board.error.title'))).toBeDefined()
  })

  it('offers NO button on a cold throttle, and says the person need do nothing — R-224', () => {
    // Muhammad's ruling, at the screen: *"on the board nothing is required of the person and
    // it resumes itself."* With no rows yet there is nothing else to render, so this is the
    // one place the throttle is the whole screen -- and it still offers nothing to press.
    render(<StaffBoard {...props({ phase: 'throttled', pagers: [] })} />)

    expect(screen.getByText(t('staff.board.throttled.title'))).toBeDefined()
    expect(screen.getByText(t('staff.board.throttled.body'))).toBeDefined()
    expect(document.querySelector('button')).toBeNull()

    // ⚠ AND IT DOES NOT SAY OUR SIDE IS BROKEN, which is the defect `R-224` records
    // verbatim: 10a's client mapped 429 onto `server` and a throttled screen read
    // *"Something went wrong on our side."*
    expect(everything()).not.toContain(t('staff.shell.error.title'))
  })
})

describe('the detail panel', () => {
  it('opens on the selected row and offers only that status’s actions', () => {
    const { unmount } = render(
      <StaffBoard {...props({ selected: '01a199c4-7f3e-7000-8000-0123456789ab' })} />,
    )

    expect(screen.getByRole('button', { name: t('staff.detail.ready') })).toBeDefined()
    // A waiting order cannot be collected or re-buzzed, so neither is offered.
    expect(screen.queryByRole('button', { name: t('staff.detail.complete') })).toBeNull()
    expect(screen.queryByRole('button', { name: t('staff.detail.renotify') })).toBeNull()
    unmount()

    render(
      <StaffBoard
        {...props({
          pagers: [pager({ status: 'ready', readyAt: '2026-11-02T10:02:00Z' })],
          selected: '01a199c4-7f3e-7000-8000-0123456789ab',
        })}
      />,
    )

    expect(screen.getByRole('button', { name: t('staff.detail.complete') })).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.detail.renotify') })).toBeDefined()
    expect(screen.queryByRole('button', { name: t('staff.detail.ready') })).toBeNull()
  })

  it('confirms a cancellation and marks it not reversible', () => {
    const onConfirmCancel = vi.fn()

    render(
      <StaffBoard
        {...props({
          selected: '01a199c4-7f3e-7000-8000-0123456789ab',
          detail: 'confirm-cancel',
          onConfirmCancel,
        })}
      />,
    )

    expect(screen.getByText(t('staff.detail.cancel.title'))).toBeDefined()
    // §12: destructive in effect, so it says so in words a person reads rather than in a
    // word like "irreversible".
    expect(screen.getByText(t('staff.detail.cancel.body'))).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.detail.cancel.keep') })).toBeDefined()

    screen.getByRole('button', { name: t('staff.detail.cancel.confirm') }).click()

    expect(onConfirmCancel).toHaveBeenCalledWith('01a199c4-7f3e-7000-8000-0123456789ab')
  })

  it('has a distinct sentence for every server answer, and each says what to DO', () => {
    // ⚠ EVERY ONE OF THESE IS REACHABLE, which is `R-205`'s lesson from Task 9: a screen
    // state no event can produce is a dead state, and that one was found by iterating the
    // list until a case timed out. `staff-flows.test.tsx` drives each from a real answer.
    const expected: Readonly<Record<string, string>> = {
      limit: t('staff.detail.limit'),
      expired: t('staff.detail.expired'),
      gone: t('staff.detail.gone'),
      stale: t('staff.detail.stale'),
      failed: t('staff.detail.failed'),
    }

    for (const [phase, sentence] of Object.entries(expected)) {
      const { unmount } = render(
        <StaffBoard
          {...props({
            selected: '01a199c4-7f3e-7000-8000-0123456789ab',
            detail: phase as DetailPhase,
          })}
        />,
      )

      expect(document.querySelector(`[data-detail-message="${phase}"]`)?.textContent, phase).toBe(
        sentence,
      )
      unmount()
    }

    // And the five really are five different sentences -- a map that pointed two phases at
    // one string would satisfy the loop above.
    expect(new Set(Object.values(expected)).size).toBe(5)
  })

  it('disables the acting buttons while a transition is in flight, and never the cancel', () => {
    render(
      <StaffBoard
        {...props({ selected: '01a199c4-7f3e-7000-8000-0123456789ab', detail: 'working' })}
      />,
    )

    const ready = document.querySelector('[data-action="ready"]')

    expect(ready?.textContent).toBe(t('staff.detail.working'))
    expect((ready as HTMLButtonElement | null)?.disabled).toBe(true)

    // Cancel keeps its own label while something else is in flight, because it opens a
    // CONFIRMATION rather than sending anything -- relabelling it "Sending…" would say an
    // order was being cancelled when nothing had been.
    expect(document.querySelector('[data-action="cancel"]')?.textContent).toBe(
      t('staff.detail.cancel'),
    )
  })
})
