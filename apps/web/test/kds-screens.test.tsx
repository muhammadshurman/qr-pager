import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import type { KdsOrder } from '../src/kds/api.ts'
import { KdsBoard } from '../src/screens/kds/KdsBoard.tsx'
import { KdsSetup } from '../src/screens/kds/KdsSetup.tsx'

/**
 * ================================================================================
 * THE KITCHEN SCREEN's TWO SCREENS, EVERY STATE — 10c
 * ================================================================================
 *
 * `kds-app.test.tsx` drives these through the orchestrator, which is where the WIRING lives
 * and where the resync invariant is asserted. This file renders them directly, which is the
 * only cheap way to reach the states a polling component only produces after a run of
 * failures — and `§12` requires all of them to exist and to say something useful.
 *
 * ⚠ **THE BRANCH THAT MATTERS MOST HERE IS *A FAILURE WITH ROWS IN HAND*.** `KdsBoard` keeps
 * the orders through an outage and shows a banner, rather than replacing them with a failure
 * state — because the kitchen's only source of truth about what to cook is whatever is on the
 * wall, and blanking it over a dropped packet stops a service. The two shapes (failure with
 * rows, failure without) are different renders and both are below.
 */

const t = translatorFor('en')
const ar = translatorFor('ar')

const ORDER: KdsOrder = {
  id: '01a00000-0000-7000-8000-00000000000a',
  orderReference: 'KDS-1',
  status: 'waiting',
  createdAt: '2026-11-02T10:00:00Z',
}

function board(props?: Partial<React.ComponentProps<typeof KdsBoard>>) {
  const base: React.ComponentProps<typeof KdsBoard> = {
    t,
    phase: 'ready',
    orders: [ORDER],
    elapsed: { [ORDER.id]: 125 },
    staleSince: null,
    onRetry: vi.fn(),
    forgetting: false,
    onForget: vi.fn(),
    onConfirmForget: vi.fn(),
    onCancelForget: vi.fn(),
    ...props,
  }

  return { ...render(<KdsBoard {...base} />), props: base }
}

describe('the kitchen board’s states', () => {
  it('announces loading rather than drawing a nameless spinner', () => {
    board({ phase: 'loading' })

    expect(document.querySelector('[data-kds-view="loading"]')).not.toBeNull()
    expect(screen.getAllByText(t('kds.title')).length).toBeGreaterThanOrEqual(1)
  })

  it('shows an order with its state word and the waiting label beside the clock', () => {
    board()

    expect(screen.getByText(t('kds.order', { reference: 'KDS-1' }))).toBeTruthy()
    expect(screen.getByText(t('kds.status.waiting'))).toBeTruthy()
    // ⚠ THE WORD IS RENDERED, NOT ONLY ANNOUNCED — `§11` QA M-13's lesson from the staff board,
    // where a bare `mm:ss` on a screen read at two metres could be taken for a time of day.
    expect(screen.getAllByText(t('kds.waitingLabel')).length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText('02:05')).toBeTruthy()
  })

  it('offers a next action on the empty state, which polls immediately', () => {
    const { props } = board({ orders: [] })

    expect(screen.getByText(t('kds.empty.title'))).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: t('kds.empty.action') }))

    expect(props.onRetry).toHaveBeenCalledTimes(1)
  })

  describe('a failure with NO rows replaces the board', () => {
    it('offers a retry on offline and on error', () => {
      for (const [phase, key] of [
        ['offline', 'kds.offline.title'],
        ['error', 'kds.error.title'],
      ] as const) {
        const view = board({ phase, orders: [] })

        expect(screen.getByText(t(key))).toBeTruthy()
        expect(screen.getByRole('button', { name: t('kds.retry') })).toBeTruthy()

        view.unmount()
      }
    })

    it('offers NO action on a throttle, because the screen resumes itself', () => {
      // `R-224`'s third clause: nothing is required of the person, so a button would be a
      // second 429. `StaffState`'s `throttled` case has no `action` member, which makes that
      // structural rather than a thing to remember.
      board({ phase: 'throttled', orders: [] })

      expect(screen.getByText(t('kds.throttled.title'))).toBeTruthy()
      expect(screen.queryByRole('button', { name: t('kds.retry') })).toBeNull()
    })
  })

  describe('a failure WITH rows keeps them and says so', () => {
    it('keeps the orders and shows the offline banner', () => {
      /*
       * ⚠ THE BRANCH THIS FILE EXISTS FOR. A wall screen that blanked itself over a dropped
       * packet mid-service is worse than one that says when it last looked — and the rows are
       * genuinely still the best information the kitchen has.
       */
      board({ phase: 'offline' })

      expect(screen.getByText(t('kds.order', { reference: 'KDS-1' }))).toBeTruthy()
      expect(document.querySelector('[data-kds-offline="true"]')).not.toBeNull()
      expect(screen.getByText(t('kds.offline.body'))).toBeTruthy()
      // The full-screen state is NOT used, so the rows are visible.
      expect(screen.queryByText(t('kds.offline.title'))).toBeNull()
    })

    it('keeps the orders and shows the throttle banner above them', () => {
      board({ phase: 'throttled' })

      expect(screen.getByText(t('kds.order', { reference: 'KDS-1' }))).toBeTruthy()
      expect(document.querySelector('[data-kds-throttled="true"]')).not.toBeNull()
      expect(screen.getByText(t('kds.throttled.body'))).toBeTruthy()
    })

    it('keeps the orders on an error with no banner of its own, which is the quiet case', () => {
      // `error` with rows shows the staleness marker when there is one and otherwise nothing:
      // the rows are right, they are merely not fresh, and `R-224`'s reasoning about not
      // shouting applies to a quiet degradation too.
      board({ phase: 'error' })

      expect(screen.getByText(t('kds.order', { reference: 'KDS-1' }))).toBeTruthy()
      expect(screen.queryByText(t('kds.error.title'))).toBeNull()
    })

    it('shows when the rows were last confirmed', () => {
      board({ staleSince: '14:32' })

      const marker = document.querySelector('[data-kds-stale="true"]')

      expect(marker?.textContent).toBe(t('kds.stale', { time: '14:32' }))
      // `role="status"`, not `alert`: it is not an interruption.
      expect(marker?.getAttribute('role')).toBe('status')
    })
  })

  describe('taking the screen off the wall', () => {
    it('confirms, and sends nothing until it is confirmed', () => {
      const closed = board()

      fireEvent.click(screen.getByRole('button', { name: t('kds.forget') }))
      expect(closed.props.onForget).toHaveBeenCalledTimes(1)
      closed.unmount()

      const open = board({ forgetting: true })

      expect(screen.getByText(t('kds.forget.body'))).toBeTruthy()
      expect(open.props.onConfirmForget).not.toHaveBeenCalled()

      fireEvent.click(screen.getByRole('button', { name: t('kds.forget.confirm') }))
      expect(open.props.onConfirmForget).toHaveBeenCalledTimes(1)
    })

    it('offers a way out that sends nothing', () => {
      const { props } = board({ forgetting: true })

      fireEvent.click(screen.getByRole('button', { name: t('kds.forget.keep') }))

      expect(props.onCancelForget).toHaveBeenCalledTimes(1)
      expect(props.onConfirmForget).not.toHaveBeenCalled()
    })
  })

  it('renders every status word the generated contract declares, when the server sends one', () => {
    /*
     * Four of the five are unreachable through `PagerSessions::board()`, which selects only
     * `waiting` and `ready` — and the map covers all five because `StatusBadge` takes a
     * `Record<PagerStatus, string>` and the generated mirror is the source. This drives each so
     * a status added in PHP and mirrored without copy fails here rather than rendering its enum
     * value in a kitchen.
     */
    for (const [status, key] of [
      ['waiting', 'kds.status.waiting'],
      ['ready', 'kds.status.ready'],
      ['completed', 'kds.status.completed'],
      ['cancelled', 'kds.status.cancelled'],
      ['expired', 'kds.status.expired'],
    ] as const) {
      const view = board({ orders: [{ ...ORDER, status }] })

      expect(screen.getByText(t(key)), `${status} rendered no word`).toBeTruthy()

      view.unmount()
    }
  })

  it('falls back to a zero clock for an order with no elapsed entry', () => {
    // `elapsed[id] ?? 0` -- a row the caller has not measured renders `00:00` rather than
    // `NaN:NaN`, which is what `formatElapsed` would produce from `undefined`.
    board({ elapsed: {} })

    expect(screen.getByText('00:00')).toBeTruthy()
  })
})

describe('the setup screen', () => {
  function setup(props?: Partial<React.ComponentProps<typeof KdsSetup>>) {
    const base: React.ComponentProps<typeof KdsSetup> = {
      t,
      value: '',
      onChange: vi.fn(),
      onSubmit: vi.fn(),
      refused: false,
      busy: false,
      ...props,
    }

    return { ...render(<KdsSetup {...base} />), props: base }
  }

  it('asks for the code in a person’s words, never the word “token”', () => {
    const { container } = setup()

    expect(screen.getByText(t('kds.setup.title'))).toBeTruthy()
    expect(screen.getByLabelText(t('kds.setup.code.label'))).toBeTruthy()
    // ⚠ THE WORD `token` APPEARS NOWHERE. What is pasted is a bearer credential; the person
    // pasting it is whoever is mounting the tablet, and `staff.devices.issued.*` already calls
    // it a code on the screen that produces it. One word for one thing.
    expect(container.textContent.toLowerCase()).not.toContain('token')
  })

  it('will not submit an empty or whitespace-only code', () => {
    for (const value of ['', '   '] as const) {
      const view = setup({ value })

      expect(screen.getByRole('button', { name: t('kds.setup.submit') })).toHaveProperty(
        'disabled',
        true,
      )

      view.unmount()
    }
  })

  it('submits on Enter, which is what somebody who has just pasted will press', () => {
    const { props } = setup({ value: 'a-code' })

    fireEvent.submit(document.querySelector('form')!)

    expect(props.onSubmit).toHaveBeenCalledTimes(1)
  })

  it('reports a typed code to the caller', () => {
    const { props } = setup()

    fireEvent.change(document.querySelector('[data-kds-code="true"]')!, {
      target: { value: 'pasted' },
    })

    expect(props.onChange).toHaveBeenCalledWith('pasted')
  })

  it('shows the busy label while a code is being checked, and disables the submit', () => {
    setup({ value: 'a-code', busy: true })

    const submit = screen.getByRole('button', { name: t('kds.setup.working') })

    expect(submit).toHaveProperty('disabled', true)
  })

  it('announces a refused code and says what to do about it', () => {
    setup({ refused: true })

    const alert = screen.getByRole('alert')

    expect(alert.textContent).toBe(t('kds.setup.refused'))
    // `§12`: every error says what to DO. A refused code cannot be repaired from this screen,
    // so the remedy is a NEW one -- and it does not speculate about which of the three causes
    // it was, because all three have the same remedy.
    expect(t('kds.setup.refused')).toContain('Create a new kitchen screen')
  })

  it('is not masked, so a mis-paste is visible to the person who made it', () => {
    // A masked field on a wall tablet means a person who mis-pastes cannot see that they did,
    // and the failure arrives later as a refusal with no visible cause. `kds/storage.ts` has
    // the argument for why that is the right trade on this surface.
    setup()

    const field = screen.getByLabelText(t('kds.setup.code.label'))

    expect(field.getAttribute('type')).not.toBe('password')
    expect(field.getAttribute('autocomplete')).toBe('off')
    expect(field.getAttribute('spellcheck')).toBe('false')
  })

  it('renders in Arabic, because §12 names it a launch language', () => {
    render(<KdsSetup t={ar} value="" onChange={vi.fn()} onSubmit={vi.fn()} refused busy={false} />)

    expect(screen.getByText(ar('kds.setup.title'))).toBeTruthy()
    expect(screen.getByRole('alert').textContent).toBe(ar('kds.setup.refused'))
  })
})
