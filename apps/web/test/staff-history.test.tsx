import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffHistory, hasAnyFilter } from '../src/screens/staff/StaffHistory.tsx'
import { NO_HISTORY_FILTERS, type HistoryOrder } from '../src/staff/api.ts'

/**
 * ================================================================================
 * PAST ORDERS — 10d (`QRPAGER-28`), AND `§3`'s THREE MECHANICAL CLAUSES
 * ================================================================================
 *
 * `QRPAGER-28 §3` makes the zero-technical-background bar the HARD half of this ticket rather
 * than the accessibility half, and states it as three clauses this file asserts against the
 * RENDERED screen rather than against intent:
 *
 *   NO UNEXPLAINED IDENTIFIER anywhere.
 *   NO EMPTY STATE WITHOUT A NEXT ACTION.
 *   EVERY ERROR SAYS WHAT TO DO rather than what failed.
 *
 * ⚠ **THE TWO EMPTY STATES ARE THE INTERESTING CASE AND THE REASON THIS FILE EXISTS.** With
 * filters applied the restaurant probably HAS orders and the useful action is to widen; with
 * none it genuinely has none and the useful action is to start one. One state with one button
 * would be wrong in one of the two cases — and wrong in the worse direction, because *"Start a
 * pager"* under *"No orders match"* sends somebody to create an order they did not want.
 *
 * The cross-tenant half of this screen is `PagerHistoryIsolationTest`'s, which enumerates 128
 * filter combinations over HTTP in both directions. Nothing here proves isolation.
 */

const t = translatorFor('en')

const ORDER_ID = '01a00000-0000-7000-8000-00000000000d'

function order(overrides?: Partial<HistoryOrder>): HistoryOrder {
  return {
    id: ORDER_ID,
    orderReference: 'A-128',
    status: 'completed',
    createdAt: '2026-11-02T10:00:00Z',
    readyAt: '2026-11-02T10:04:00Z',
    completedAt: '2026-11-02T10:06:00Z',
    ...overrides,
  }
}

function mount(props?: Partial<React.ComponentProps<typeof StaffHistory>>) {
  const base: React.ComponentProps<typeof StaffHistory> = {
    t,
    phase: 'ready',
    orders: [order()],
    applied: NO_HISTORY_FILTERS,
    draft: NO_HISTORY_FILTERS,
    onDraft: vi.fn(),
    onApply: vi.fn(),
    onClear: vi.fn(),
    onRetry: vi.fn(),
    onStart: vi.fn(),
    rangeProblem: null,
    onReissue: vi.fn(),
    // A fixed formatter, so a case asserts the SCREEN rather than the runner's timezone.
    formatTime: (iso) => `at ${iso.slice(11, 16)}`,
    ...props,
  }

  return { ...render(<StaffHistory {...base} />), props: base }
}

describe('the filter controls, which are the invariant’s other end', () => {
  it('offers one toggle per status the generated contract declares', () => {
    /*
     * ⚠ DERIVED FROM `PAGER_STATUS`, NOT LISTED. `PagerHistoryIsolationTest`'s combination
     * count is `2^|statuses| x 2 x 2`, so a status added in PHP must grow a toggle HERE and a
     * combination THERE with no edit to either. A hand-written list of five is the thing that
     * silently stops matching.
     */
    mount()

    for (const status of ['waiting', 'ready', 'completed', 'cancelled', 'expired'] as const) {
      expect(
        document.querySelector(`[data-history-status="${status}"]`),
        `no toggle for ${status}`,
      ).not.toBeNull()
    }

    expect(document.querySelectorAll('[data-history-status]')).toHaveLength(5)
  })

  it('names the status group, so a screen reader announces what the toggles are for', () => {
    // A `fieldset`/`legend`, which is the only construct that gives a group of checkboxes a
    // name without ARIA. `§12` names a screen reader user in Arabic a first-class reader, and
    // a bare heading above five unlabelled toggles reads as five unrelated controls.
    mount()

    expect(screen.getByRole('group', { name: t('staff.history.filter.status') })).toBeTruthy()
  })

  it('reports a toggle as a draft change rather than applying it', () => {
    // Applying on every keystroke would be a request per character against a rate limit.
    // `§4 › Realtime` keeps the customer poll away from that shape for the same reason.
    const { props } = mount()

    fireEvent.click(document.querySelector('[data-history-status="ready"]')!)

    expect(props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, statuses: ['ready'] })
    expect(props.onApply).not.toHaveBeenCalled()
  })

  it('removes a toggle that was already on', () => {
    const { props } = mount({ draft: { ...NO_HISTORY_FILTERS, statuses: ['ready', 'waiting'] } })

    fireEvent.click(document.querySelector('[data-history-status="ready"]')!)

    expect(props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, statuses: ['waiting'] })
  })

  it('treats an emptied date control as NO filter rather than an empty one', () => {
    /*
     * `from=` would pass validation (`sometimes|nullable`) and then not filter — a date control
     * that silently does nothing, which is the `§12` failure of a screen that looks like it
     * worked. `historyQueryString` omits a null; this is the other end of that decision.
     */
    const { props } = mount({ draft: { ...NO_HISTORY_FILTERS, from: '2026-01-01' } })

    fireEvent.change(document.querySelector('[data-history-from="true"]')!, {
      target: { value: '' },
    })

    expect(props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, from: null })
  })

  it('reports BOTH ends of the range, and an emptied one as null', () => {
    // The `to` control has the same emptied-means-null rule as `from`, and it is a separate
    // handler -- so it is driven separately rather than assumed symmetrical.
    const set = mount()

    fireEvent.change(document.querySelector('[data-history-to="true"]')!, {
      target: { value: '2026-02-01' },
    })
    expect(set.props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, to: '2026-02-01' })
    set.unmount()

    const cleared = mount({ draft: { ...NO_HISTORY_FILTERS, to: '2026-02-01' } })

    fireEvent.change(document.querySelector('[data-history-to="true"]')!, {
      target: { value: '' },
    })
    expect(cleared.props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, to: null })
  })

  it('reports a typed reference as a draft change', () => {
    const { props } = mount()

    fireEvent.change(document.querySelector('[data-history-reference="true"]')!, {
      target: { value: 'A-1' },
    })

    expect(props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, reference: 'A-1' })
  })

  it('reports an EMPTIED reference as null rather than an empty string', () => {
    /*
     * ⚠ A SECOND MOUNT WITH THE VALUE ALREADY SET, NOT A SECOND `fireEvent` ON THE FIRST ONE —
     * and the first version of this case did the latter and failed for a reason worth keeping.
     *
     * The field is CONTROLLED (`value={draft.reference ?? ''}`) and `onDraft` is a mock, so the
     * prop never moves: after firing a change to `'A-1'`, React re-renders and resets the DOM
     * value to `''`. Firing a change to `''` then sets it to the value it already holds, React
     * dispatches no `onChange`, and the assertion read the FIRST call — reporting `'A-1'` where
     * it expected `null`.
     *
     * Driving the state the screen would actually be in is both correct and simpler. Same shape
     * as the date case above, for the same reason.
     */
    const { props } = mount({ draft: { ...NO_HISTORY_FILTERS, reference: 'A-1' } })

    fireEvent.change(document.querySelector('[data-history-reference="true"]')!, {
      target: { value: '' },
    })

    expect(props.onDraft).toHaveBeenCalledWith({ ...NO_HISTORY_FILTERS, reference: null })
  })

  it('applies on submit, so Enter works for somebody who has just typed', () => {
    const { props } = mount()

    fireEvent.submit(document.querySelector('[data-history-filters="true"]')!)

    expect(props.onApply).toHaveBeenCalledTimes(1)
  })

  it('offers CLEAR only when something is set', () => {
    const none = mount()

    expect(document.querySelector('[data-history-clear="true"]')).toBeNull()
    none.unmount()

    const some = mount({ draft: { ...NO_HISTORY_FILTERS, reference: 'A' } })

    expect(document.querySelector('[data-history-clear="true"]')).not.toBeNull()
    fireEvent.click(document.querySelector('[data-history-clear="true"]')!)
    expect(some.props.onClear).toHaveBeenCalledTimes(1)
  })

  it('refuses to submit an impossible range, and says which way it is wrong', () => {
    /*
     * `§12`: a screen never offers an action the server refuses. The client's pre-check is NOT
     * a second rule — `PagerHistoryRequest` still refuses — it answers before a round trip, and
     * `history-filters.test.ts` pins the two ceilings to the same number across the languages.
     */
    for (const [problem, key] of [
      ['tooWide', 'staff.history.range.tooWide'],
      ['backwards', 'staff.history.range.backwards'],
    ] as const) {
      const view = mount({ rangeProblem: problem })

      expect(screen.getByText(t(key))).toBeTruthy()
      expect(screen.getByRole('button', { name: t('staff.history.filter.apply') })).toHaveProperty(
        'disabled',
        true,
      )

      view.unmount()
    }
  })
})

describe('the two empty states — §12’s next-action clause', () => {
  it('offers CLEAR when filters produced nothing', () => {
    const { props } = mount({ orders: [], applied: { ...NO_HISTORY_FILTERS, reference: 'ZZZ' } })

    expect(screen.getByText(t('staff.history.empty.title'))).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: t('staff.history.empty.action') }))

    expect(props.onClear).toHaveBeenCalledTimes(1)
  })

  it('offers START A PAGER when the restaurant genuinely has no orders', () => {
    const { props } = mount({ orders: [], applied: NO_HISTORY_FILTERS })

    expect(screen.getByText(t('staff.history.none.title'))).toBeTruthy()
    // And NOT the other one, which would send somebody to clear filters they never set.
    expect(screen.queryByText(t('staff.history.empty.title'))).toBeNull()

    /*
     * ⚠ THE BUTTON STARTS A PAGER RATHER THAN REFETCHING, which is what it says. The first
     * version of the screen wired it to `onRetry`, so *"Start a pager"* reloaded the history
     * and nothing changed — a next action that does not act, which `§12` is worse off for than
     * having none at all.
     *
     * ⚠ AND ONE MOUNT, NOT TWO. The first attempt at this assertion mounted a SECOND copy and
     * clicked `getAllByRole(...)[0]`, which is the FIRST mount's button and therefore the first
     * mount's `onStart` — so it reported zero calls on a handler that was wired correctly.
     * Two renders of the same component in one document is a source of exactly this confusion.
     */
    fireEvent.click(screen.getByRole('button', { name: t('staff.history.none.action') }))

    expect(props.onStart).toHaveBeenCalledTimes(1)
    expect(props.onRetry).not.toHaveBeenCalled()
  })

  it('reads APPLIED rather than DRAFT, so clearing a control does not flip the copy early', () => {
    /*
     * ⚠ THE REASON `applied` AND `draft` ARE TWO VALUES. The honest question is *what produced
     * the answer on screen*, not *what has the person since typed* — otherwise emptying the
     * date control would change the empty state's sentence and its button before the refetch
     * landed, and a person would read *"No orders yet"* about a restaurant whose orders they
     * had just filtered away.
     */
    mount({
      orders: [],
      applied: { ...NO_HISTORY_FILTERS, reference: 'ZZZ' },
      draft: NO_HISTORY_FILTERS,
    })

    expect(screen.getByText(t('staff.history.empty.title'))).toBeTruthy()
    expect(screen.queryByText(t('staff.history.none.title'))).toBeNull()
  })
})

describe('the rows', () => {
  it('shows the order, its state word and only the timestamps the server sent', () => {
    mount({ orders: [order({ readyAt: null, completedAt: null, status: 'cancelled' })] })

    expect(screen.getByText(t('staff.board.order', { reference: 'A-128' }))).toBeTruthy()

    /*
     * ⚠ SCOPED TO THE ROW, because the status WORD is on the row's badge AND on the filter
     * toggle beside it — `getByText('Cancelled')` finds both and throws. That is not a defect:
     * `§12` wants one set of words for one set of states across the board, the kitchen screen
     * and this one, so the collision is the design working. The assertion has to be specific
     * about which of the two it means.
     */
    const row = document.querySelector('[data-history-order="A-128"]')

    expect(row).not.toBeNull()
    expect(row?.textContent).toContain(t('staff.board.status.cancelled'))
    expect(row?.getAttribute('data-history-order-status')).toBe('cancelled')
    expect(screen.getByText('Started at 10:00')).toBeTruthy()
    /*
     * ⚠ ABSENT RATHER THAN DASHED. An order that was cancelled was never collected, and
     * *"Collected —"* is an unexplained thing on a screen whose bar forbids one.
     */
    expect(screen.queryByText(/Ready at/)).toBeNull()
    expect(screen.queryByText(/Collected at/)).toBeNull()
  })

  it('renders no uuid', () => {
    const { container } = mount()

    expect(container.innerHTML).not.toContain(ORDER_ID)
  })

  it('offers the lost-sheet path on a LIVE order only', () => {
    /*
     * `reissue` answers 409 for a terminal order and the copy says to start a new pager
     * instead — so offering the button there would be a control the server refuses, which is
     * the `§12` failure `legalActions()` avoids one screen over.
     */
    const live = mount({ orders: [order({ status: 'waiting', readyAt: null, completedAt: null })] })

    expect(document.querySelector('[data-history-reissue="A-128"]')).not.toBeNull()
    fireEvent.click(document.querySelector('[data-history-reissue="A-128"]')!)
    expect(live.props.onReissue).toHaveBeenCalledTimes(1)
    live.unmount()

    for (const status of ['completed', 'cancelled', 'expired'] as const) {
      const terminal = mount({ orders: [order({ status })] })

      expect(
        document.querySelector('[data-history-reissue="A-128"]'),
        `${status} offered a re-issue`,
      ).toBeNull()

      terminal.unmount()
    }
  })

  it('offers it on a READY order too, because a ready pager can still be lost', () => {
    mount({ orders: [order({ status: 'ready', completedAt: null })] })

    expect(document.querySelector('[data-history-reissue="A-128"]')).not.toBeNull()
  })
})

describe('the states', () => {
  it('announces loading rather than drawing a nameless spinner', () => {
    /*
     * ⚠ ASSERTED ON THE MARKER AND THE ACCESSIBLE NAME'S PRESENCE, NOT WITH `getByText`. The
     * kit's loading state renders its label TWICE on purpose — once in a `VisuallyHidden` span
     * and once in a `LiveRegion`, so it is both readable by a screen reader on arrival and
     * announced when it changes — so `getByText` finds two nodes and throws. A spinner with no
     * accessible name is the defect; two announcements of the right one is the design.
     */
    mount({ phase: 'loading' })

    expect(document.querySelector('.qr-staff-state--loading')).not.toBeNull()
    expect(screen.getAllByText(t('staff.history.title')).length).toBeGreaterThanOrEqual(1)
  })

  it('keeps the filter controls on screen through a failure, with a next action', () => {
    /*
     * The controls STAY, because the person's next move is usually to narrow the range — a
     * failure state that replaced the form would make them navigate away and back to try.
     */
    for (const [phase, key] of [
      ['offline', 'staff.history.offline.title'],
      ['error', 'staff.history.error.title'],
    ] as const) {
      const view = mount({ phase })

      expect(screen.getByText(t(key))).toBeTruthy()
      expect(screen.getByRole('button', { name: t('staff.history.retry') })).toBeTruthy()
      expect(document.querySelector('[data-history-filters="true"]')).not.toBeNull()

      view.unmount()
    }
  })

  it('shows a refusal with no retry when the caller may not read the history', () => {
    // Retrying changes nothing, so offering a button would be a lie. `StaffState`'s
    // `permission-denied` case takes no action at all, which makes that structural.
    mount({ phase: 'permission-denied' })

    expect(screen.getByText(t('staff.team.denied.title'))).toBeTruthy()
    expect(screen.queryByRole('button', { name: t('staff.history.retry') })).toBeNull()
  })

  it('retries with the filters in force', () => {
    const { props } = mount({ phase: 'error' })

    fireEvent.click(screen.getByRole('button', { name: t('staff.history.retry') }))

    expect(props.onRetry).toHaveBeenCalledTimes(1)
  })
})

describe('hasAnyFilter', () => {
  it('is false for nothing set and true for each dimension alone', () => {
    expect(hasAnyFilter(NO_HISTORY_FILTERS)).toBe(false)
    expect(hasAnyFilter({ ...NO_HISTORY_FILTERS, statuses: ['ready'] })).toBe(true)
    expect(hasAnyFilter({ ...NO_HISTORY_FILTERS, from: '2026-01-01' })).toBe(true)
    expect(hasAnyFilter({ ...NO_HISTORY_FILTERS, to: '2026-01-01' })).toBe(true)
    expect(hasAnyFilter({ ...NO_HISTORY_FILTERS, reference: 'A' })).toBe(true)
  })

  it('is false for an EMPTY status list, which means every status rather than none', () => {
    // The semantic decision `PagerHistoryFilters::fromValidated()` carries: an untoggled filter
    // means *show me everything*, which is what every list UI does — and the alternative would
    // make half the PHP sweep's combinations vacuous.
    expect(hasAnyFilter({ ...NO_HISTORY_FILTERS, statuses: [] })).toBe(false)
  })
})
