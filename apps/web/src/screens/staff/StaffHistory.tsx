import { Button, Field, StaffState, StatusBadge } from '@qrpager/ui'
import { PAGER_STATUS, type PagerStatus } from '@qrpager/contracts'

import type { CopyKey, Translate } from '../../copy/index.ts'
import type { HistoryFilters, HistoryOrder } from '../../staff/api.ts'

/**
 * ================================================================================
 * S8 — PAST ORDERS, WITH FILTERS. 10d (`QRPAGER-28`).
 * ================================================================================
 *
 * `§4.2`'s *History with filters*, and 10d's own `§3` makes the zero-technical-background bar
 * the hard half of this screen rather than the accessibility half:
 *
 *   *"A restaurant owner reads no documentation. No unexplained identifier anywhere on either
 *   screen. No empty state without a next action. Every error says what to do rather than what
 *   failed."*
 *
 * ================================================================================
 * THE FILTER CONTROLS ARE THE INVARIANT's OTHER END
 * ================================================================================
 *
 * 10d's named invariant is *"every filter combination **reachable from the UI** returns only
 * rows of the caller's own tenant"*, and `PagerHistoryIsolationTest` derives its 128
 * combinations from `PagerHistoryFilters::DIMENSIONS`. **So what this screen can ASK is part
 * of that proof**: a control here that produced a filter the PHP constant does not name would
 * be a combination the sweep never drives.
 *
 * The four controls below are exactly those four dimensions, and `HISTORY_DIMENSIONS` in
 * `staff/api.ts` is the TypeScript end of the same list — held to the PHP one by
 * `apps/web/test/history-filters.test.ts`, which reads the PHP file rather than trusting the
 * two to agree.
 *
 * ⚠ **AND THE STATUS TOGGLES ARE DERIVED FROM THE GENERATED CONTRACT, NOT LISTED.**
 * `PAGER_STATUS` is mirrored from the PHP enum, so a status added there grows a toggle here
 * and a combination in the sweep, with no edit to either. A hand-written list of five would be
 * the thing that silently stops matching.
 *
 * ================================================================================
 * NO ACTION ON A ROW, AND THAT IS A SCOPE DECISION RATHER THAN AN OVERSIGHT
 * ================================================================================
 *
 * A past order is a record. The four transitions belong to the live board (10b) and
 * re-issuing belongs to the sheet screen — which this screen LINKS to with the order it is
 * for, because *"open the order from Past orders and make a new one"* is the path
 * `staff.issue.sheet.once` promises a staff member who has lost a sheet.
 */

export type HistoryPhase = 'loading' | 'ready' | 'error' | 'offline' | 'permission-denied'

export interface StaffHistoryProps {
  readonly t: Translate
  readonly phase: HistoryPhase
  readonly orders: readonly HistoryOrder[]
  /** The filters currently IN FORCE — what the last answer was for. */
  readonly applied: HistoryFilters
  /** The filters being edited, which may differ until *Show these orders* is pressed. */
  readonly draft: HistoryFilters
  readonly onDraft: (next: HistoryFilters) => void
  readonly onApply: () => void
  readonly onClear: () => void
  readonly onRetry: () => void
  /**
   * Go to the screen that starts a pager.
   *
   * ⚠ A SEPARATE CALLBACK FROM `onRetry`, AND THE FIRST VERSION REUSED IT — so the *no orders
   * yet* empty state offered a button reading *"Start a pager"* that REFETCHED THE HISTORY.
   * `§12`'s clause is *no empty state without a next action*, and an action whose label
   * promises one thing and does another is worse than none: a person presses it, the screen
   * does not change, and they conclude the product is broken.
   */
  readonly onStart: () => void
  /** Null when nothing is wrong with the draft; otherwise the sentence to show. */
  readonly rangeProblem: 'tooWide' | 'backwards' | null
  /** Open the sheet screen for one order — the lost-sheet path. */
  readonly onReissue: (order: HistoryOrder) => void
  /** Formats a server timestamp in the restaurant's own timezone. */
  readonly formatTime: (iso: string) => string
}

const STATUS_COPY: Readonly<Record<PagerStatus, CopyKey>> = {
  waiting: 'staff.board.status.waiting',
  ready: 'staff.board.status.ready',
  completed: 'staff.board.status.completed',
  cancelled: 'staff.board.status.cancelled',
  expired: 'staff.board.status.expired',
}

/** Whether any filter is set. Decides WHICH empty state, which decides the next action. */
export function hasAnyFilter(filters: HistoryFilters): boolean {
  return (
    filters.statuses.length > 0 ||
    filters.from !== null ||
    filters.to !== null ||
    filters.reference !== null
  )
}

export function StaffHistory({
  t,
  phase,
  orders,
  applied,
  draft,
  onDraft,
  onApply,
  onClear,
  onRetry,
  onStart,
  rangeProblem,
  onReissue,
  formatTime,
}: StaffHistoryProps) {
  if (phase === 'loading') {
    return <StaffState state="loading" loadingLabel={t('staff.history.title')} />
  }

  if (phase === 'permission-denied') {
    return (
      <StaffState
        state="permission-denied"
        title={t('staff.team.denied.title')}
        description={t('staff.team.denied.body')}
      />
    )
  }

  /*
   * ⚠ ANNOTATED `Record<PagerStatus, string>`, AND `StatusMirrorContractTest` IS WHY — it
   * flagged this file as declaring *"a second, hand-written copy of the status enum"*, and it
   * was right to.
   *
   * Unannotated, this is five status keys typed out by hand. Exhaustiveness happened to be
   * enforced anyway, because `StatusBadge`'s `labels` prop is `Readonly<Record<PagerStatus,
   * string>>` and a missing key would not compile at the call site — but that is a property of
   * the CONSUMER, invisible here and one refactor away from being gone. `StaffBoard` avoids the
   * question by inlining the object into the JSX prop, so it is never a declaration at all.
   *
   * With the annotation the exhaustiveness is local and stated: a status added to the generated
   * mirror fails THIS line, which is `§6.2`'s one-source rule doing its job rather than being
   * satisfied by luck.
   */
  const statusLabels: Readonly<Record<PagerStatus, string>> = {
    waiting: t(STATUS_COPY.waiting),
    ready: t(STATUS_COPY.ready),
    completed: t(STATUS_COPY.completed),
    cancelled: t(STATUS_COPY.cancelled),
    expired: t(STATUS_COPY.expired),
  }

  const filters = (
    <form
      className="qr-staff-form"
      aria-label={t('staff.history.filter.status')}
      data-history-filters="true"
      onSubmit={(event) => {
        event.preventDefault()
        onApply()
      }}
    >
      {/*
        ⚠ A `fieldset` WITH A `legend`, NOT A `div` WITH A HEADING. A group of related
        checkboxes needs a group NAME a screen reader announces when focus enters it, and
        `fieldset`/`legend` is the only construct that does that without ARIA. `§12` names a
        screen reader user in Arabic as a first-class reader, and a bare heading above five
        unlabelled toggles reads as five unrelated controls.
      */}
      <fieldset className="qr-staff-filter__group">
        <legend className="qr-field__label">{t('staff.history.filter.status')}</legend>
        {/*
          Derived from the GENERATED contract. A status added in PHP grows a toggle here, and
          `STATUS_COPY` is a total `Record`, so it also fails the build until its copy exists.
        */}
        {PAGER_STATUS.map((status) => {
          const on = draft.statuses.includes(status)

          return (
            <label className="qr-staff-filter__toggle" key={status}>
              <input
                type="checkbox"
                checked={on}
                data-history-status={status}
                onChange={() => {
                  onDraft({
                    ...draft,
                    statuses: on
                      ? draft.statuses.filter((value) => value !== status)
                      : [...draft.statuses, status],
                  })
                }}
              />
              <span>{t(STATUS_COPY[status])}</span>
            </label>
          )
        })}
      </fieldset>

      <Field
        label={t('staff.history.filter.from')}
        type="date"
        value={draft.from ?? ''}
        data-history-from="true"
        onChange={(event) => {
          // An empty date control means NO filter, not an empty one -- `historyQueryString`
          // omits a null rather than sending `from=`, which would validate and then not filter.
          onDraft({ ...draft, from: event.target.value === '' ? null : event.target.value })
        }}
      />

      <Field
        label={t('staff.history.filter.to')}
        type="date"
        value={draft.to ?? ''}
        data-history-to="true"
        {...(rangeProblem === null
          ? {}
          : {
              error: t(
                rangeProblem === 'tooWide'
                  ? 'staff.history.range.tooWide'
                  : 'staff.history.range.backwards',
              ),
            })}
        onChange={(event) => {
          onDraft({ ...draft, to: event.target.value === '' ? null : event.target.value })
        }}
      />

      <Field
        label={t('staff.history.filter.reference')}
        hint={t('staff.history.filter.reference.hint')}
        value={draft.reference ?? ''}
        data-history-reference="true"
        autoComplete="off"
        onChange={(event) => {
          onDraft({ ...draft, reference: event.target.value === '' ? null : event.target.value })
        }}
      />

      <div className="qr-staff-actions">
        {/* Disabled while the range is impossible, because sending it would be a 422 the
            person can already see the cause of. */}
        <Button variant="primary" type="submit" disabled={rangeProblem !== null}>
          {t('staff.history.filter.apply')}
        </Button>
        {hasAnyFilter(draft) && (
          <Button variant="quiet" data-history-clear="true" onClick={onClear}>
            {t('staff.history.filter.clear')}
          </Button>
        )}
      </div>
    </form>
  )

  if (phase !== 'ready') {
    return (
      <section aria-label={t('staff.history.title')}>
        <h1 className="qr-staff-state__title">{t('staff.history.title')}</h1>
        {filters}
        <StaffState
          state={phase}
          title={t(
            phase === 'offline' ? 'staff.history.offline.title' : 'staff.history.error.title',
          )}
          description={t(
            phase === 'offline' ? 'staff.history.offline.body' : 'staff.history.error.body',
          )}
          action={{ label: t('staff.history.retry'), onAction: onRetry }}
        />
      </section>
    )
  }

  return (
    <section aria-label={t('staff.history.title')} data-history="true">
      <h1 className="qr-staff-state__title">{t('staff.history.title')}</h1>
      <p className="qr-staff-state__description">{t('staff.history.lede')}</p>

      {filters}

      {orders.length === 0 ? (
        /*
          ⚠ TWO EMPTY STATES, BECAUSE THE NEXT ACTION DIFFERS AND `§12` REQUIRES ONE THAT
          WORKS. With filters applied, the restaurant probably HAS orders and the useful action
          is to widen; with none, it genuinely has none and the useful action is to start one.
          One state with one button would be wrong in one of the two cases — and the wrong one
          is the worse: *"Start a pager"* under *"No orders match"* sends somebody to create an
          order they did not want.

          Read from `applied` rather than `draft`: the question is what produced THIS answer,
          not what the person has since typed.
        */
        hasAnyFilter(applied) ? (
          <StaffState
            state="empty"
            title={t('staff.history.empty.title')}
            description={t('staff.history.empty.body')}
            action={{ label: t('staff.history.empty.action'), onAction: onClear }}
          />
        ) : (
          <StaffState
            state="empty"
            title={t('staff.history.none.title')}
            description={t('staff.history.none.body')}
            // `onStart`, not `onRetry` -- the label says *Start a pager* and must do that.
            action={{ label: t('staff.history.none.action'), onAction: onStart }}
          />
        )
      ) : (
        <ul className="qr-staff-list">
          {orders.map((order) => (
            <li
              className="qr-staff-list__item"
              key={order.id}
              // The REFERENCE, not the id -- `§12` forbids an unexplained identifier, and
              // `staff-screens.test.tsx` asserts no uuid reaches the rendered output.
              data-history-order={order.orderReference}
              /*
               * ⚠ `data-history-ORDER-status`, NOT `data-history-status` — the two collided and
               * a test found it. The status TOGGLES above carry `data-history-status`, so one
               * selector matched five toggles and every row: a browser case counting toggles
               * got six, and a case selecting a row by status would have matched a filter
               * control. Two different things cannot share an attribute name.
               */
              data-history-order-status={order.status}
            >
              <div>
                <p className="qr-staff-list__name">
                  {t('staff.board.order', { reference: order.orderReference })}
                </p>
                <div className="qr-staff-list__meta">
                  <StatusBadge status={order.status} labels={statusLabels} />
                  {/*
                    The three timestamps, each rendered only when the server sent one — a
                    *"Collected —"* with an em dash is an unexplained thing, and an order that
                    was cancelled was never collected.
                  */}
                  <span>{t('staff.history.started', { time: formatTime(order.createdAt) })}</span>
                  {order.readyAt !== null && (
                    <span>{t('staff.history.ready', { time: formatTime(order.readyAt) })}</span>
                  )}
                  {order.completedAt !== null && (
                    <span>
                      {t('staff.history.collected', { time: formatTime(order.completedAt) })}
                    </span>
                  )}
                </div>
              </div>

              {/*
                The lost-sheet path, and it is offered ONLY on a live order. `reissue()` refuses
                a terminal one with a 409 and the copy says to start a new pager instead — so
                offering the button there would be a control the server refuses, which is the
                `§12` failure `legalActions()` avoids one screen over.
              */}
              {(order.status === 'waiting' || order.status === 'ready') && (
                <Button
                  variant="secondary"
                  data-history-reissue={order.orderReference}
                  onClick={() => {
                    onReissue(order)
                  }}
                >
                  {t('staff.issue.reissue')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
