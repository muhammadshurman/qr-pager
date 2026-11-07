import { Button, ElapsedTimer, StaffState, StatusBadge } from '@qrpager/ui'
import { type PagerStatus } from '@qrpager/contracts'

import type { CopyKey, Translate } from '../../copy/index.ts'
import type { BoardPager } from '../../staff/api.ts'

/**
 * ================================================================================
 * S6 + S7 — THE ACTIVE PAGER BOARD, AND THE DETAIL THAT OPENS INSIDE IT
 * ================================================================================
 *
 * `QRPAGER-26 §2`'s whole scope: the board, pager detail, and the three acts. Specified in
 * `docs/tickets/QRPAGER-26-precoding.md §12` before any of it was written.
 *
 * ⚠ **THE DETAIL IS NOT A ROUTE, AND THAT IS A RECORDED DECISION rather than a shortcut.**
 * `apps/web/test/staff-build-surface.test.ts`'s `declaredStaffRoutes()` pins the staff route
 * set at **exactly two** and asserts both prerender with no authenticated chrome. Three
 * reasons it stays two, in order of weight: a wall-mounted tablet in kiosk mode has no URL
 * bar and no back button, so a detail ROUTE is somewhere a person can get stranded; the
 * board and the action are one task in use, and a round trip through a router between them
 * costs a poll interval of freshness; and a third prerendered document is a third surface
 * that must be proven to carry no tenant data.
 *
 * ================================================================================
 * WHICH ACTIONS EXIST IS READ FROM THE STATUS, NEVER REMEMBERED
 * ================================================================================
 *
 * `QRPAGER-26 §7`: *"A double-tap, a slow network retry and two devices are all the same
 * event — the board must converge, not double-count."* So this component holds no optimistic
 * copy of a status it has acted on. `legalActions()` is a pure function of the row the
 * server last sent, which means two staff cannot both be offered *"Tell them it is ready"*
 * for an order one of them has already readied: the other's next poll removes the offer, and
 * the server refuses it anyway with a 409.
 *
 * ================================================================================
 * NOT ONE IDENTIFIER REACHES THE SCREEN
 * ================================================================================
 *
 * `id` is in the props because every action needs it in a path. It reaches no element's
 * text, and `apps/web/test/staff-screens.test.tsx` asserts that against the rendered output
 * rather than against this comment — `StaffDevices`' own property, held to the same proof.
 */

/** What the panel is doing or saying about the row it is open on. */
export type DetailPhase =
  'idle' | 'working' | 'confirm-cancel' | 'limit' | 'expired' | 'gone' | 'stale' | 'failed'

/**
 * Every board state, including `R-224`'s.
 *
 * `throttled` is here as a peer of `error` and `offline` rather than folded into either,
 * which is the whole of `R-224`: a throttle is not a fault and not a disconnection.
 */
export type BoardPhase = 'loading' | 'ready' | 'error' | 'offline' | 'throttled'

export interface StaffBoardProps {
  readonly t: Translate
  readonly phase: BoardPhase
  readonly pagers: readonly BoardPager[]
  /** Whole seconds since each pager was created, by id. Computed by the caller from the
   *  SERVER's timestamp, never from a tick count — see `board.ts`'s `isStale`. */
  readonly elapsed: Readonly<Record<string, number>>
  /** When the newest answer arrived, already formatted, or null while the board is fresh. */
  readonly staleSince: string | null
  readonly onRetry: () => void
  readonly selected: string | null
  readonly onSelect: (id: string | null) => void
  readonly detail: DetailPhase
  readonly onReady: (id: string) => void
  readonly onRenotify: (id: string) => void
  readonly onComplete: (id: string) => void
  readonly onCancel: (id: string) => void
  readonly onConfirmCancel: (id: string) => void
}

/**
 * The actions this status legally offers, in the order a kitchen wants them.
 *
 * Derived from the status and nothing else. The SERVER is the authority — `PagerStatus
 * ::allows()` is the real rule and answers 409 — so this is the screen agreeing with it
 * rather than a second copy of it: offering an action the server would refuse is how a
 * person learns the screen lies to them.
 */
export function legalActions(
  status: PagerStatus,
): readonly ('ready' | 'renotify' | 'complete' | 'cancel')[] {
  if (status === 'waiting') return ['ready', 'cancel']
  if (status === 'ready') return ['complete', 'renotify', 'cancel']

  // Terminal. The board does not list these at all, so this branch is reachable only for a
  // row that went terminal between a poll and a tap -- in which case the honest answer is
  // no actions, and the panel's `stale` message after the attempt.
  return []
}

/**
 * The copy key for each status word.
 *
 * `Record<PagerStatus, CopyKey>` rather than a partial map, so a status the generated
 * contract adds fails this build until its copy exists — which is the point of the mirror
 * rather than a side effect of it. `StatusBadge` takes the same shape, so the two cannot
 * disagree about which statuses exist.
 */
const STATUS_COPY: Readonly<Record<PagerStatus, CopyKey>> = {
  waiting: 'staff.board.status.waiting',
  ready: 'staff.board.status.ready',
  completed: 'staff.board.status.completed',
  cancelled: 'staff.board.status.cancelled',
  expired: 'staff.board.status.expired',
}

const ACTION_COPY: Readonly<Record<'ready' | 'renotify' | 'complete' | 'cancel', CopyKey>> = {
  ready: 'staff.detail.ready',
  renotify: 'staff.detail.renotify',
  complete: 'staff.detail.complete',
  cancel: 'staff.detail.cancel',
}

/** A detail phase that is a sentence rather than a state of the controls. */
const DETAIL_MESSAGE: Readonly<Partial<Record<DetailPhase, CopyKey>>> = {
  limit: 'staff.detail.limit',
  expired: 'staff.detail.expired',
  gone: 'staff.detail.gone',
  stale: 'staff.detail.stale',
  failed: 'staff.detail.failed',
}

export function StaffBoard({
  t,
  phase,
  pagers,
  elapsed,
  staleSince,
  onRetry,
  selected,
  onSelect,
  detail,
  onReady,
  onRenotify,
  onComplete,
  onCancel,
  onConfirmCancel,
}: StaffBoardProps) {
  if (phase === 'loading') {
    return <StaffState state="loading" loadingLabel={t('staff.board.title')} />
  }

  /*
   * ⚠ THE FAILURE STATES ONLY REPLACE THE BOARD WHEN THERE IS NOTHING TO SHOW.
   *
   * With rows in hand the rows STAY and the staleness marker appears instead, because the
   * data is not wrong -- it is merely not fresh, and a board that blanked itself over one
   * dropped packet during a service would be worse than one that says when it last looked.
   * That is `§4.1`'s *polling degraded* for the customer, one surface over, and it is the
   * same reasoning 10a's S2 note records for the shell's `offline` phase pointed the other
   * way: there the chrome goes because the restaurant's name is genuinely unknown; here the
   * orders are genuinely known.
   */
  if (phase !== 'ready' && pagers.length === 0) {
    if (phase === 'throttled') {
      // R-224: no action, and `StaffState`'s type is what makes that structural.
      return (
        <StaffState
          state="throttled"
          title={t('staff.board.throttled.title')}
          description={t('staff.board.throttled.body')}
        />
      )
    }

    return (
      <StaffState
        state={phase}
        title={t(phase === 'offline' ? 'staff.board.offline.title' : 'staff.board.error.title')}
        description={t(phase === 'offline' ? 'staff.board.offline.body' : 'staff.board.error.body')}
        action={{ label: t('staff.board.retry'), onAction: onRetry }}
      />
    )
  }

  if (pagers.length === 0) {
    return (
      <StaffState
        state="empty"
        title={t('staff.board.empty.title')}
        description={t('staff.board.empty.body')}
        action={{ label: t('staff.board.empty.action'), onAction: onRetry }}
      />
    )
  }

  return (
    <section aria-label={t('staff.board.title')} data-board="true">
      {/*
        The staleness marker. `role="status"` rather than `alert`: it is not an
        interruption, and `R-224`'s reasoning about not shouting applies to a quiet
        degradation too.
      */}
      {staleSince !== null && (
        <p className="qr-staff-board__stale" role="status" data-board-stale="true">
          {t('staff.board.stale', { time: staleSince })}
        </p>
      )}

      {/*
        AND THE THROTTLE NOTICE SITS ABOVE THE ROWS WHEN THERE ARE ROWS. `R-224` says the
        copy appears once the backoff window is exhausted; it does not say the board
        disappears, and a kitchen mid-service needs the orders more than it needs the
        notice.
      */}
      {phase === 'throttled' && (
        <p className="qr-staff-board__stale" role="status" data-board-throttled="true">
          {t('staff.board.throttled.body')}
        </p>
      )}

      <ul className="qr-staff-list">
        {pagers.map((pager) => {
          const open = selected === pager.id
          // Bound once rather than indexed twice: the second read needed a cast to tell the
          // compiler it was defined, and a cast that only exists because of the shape of
          // the expression around it is noise a reader has to decode.
          const sentence = DETAIL_MESSAGE[detail]

          return (
            <li className="qr-staff-list__item" key={pager.id} data-pager-status={pager.status}>
              <div>
                <p className="qr-staff-list__name">
                  {t('staff.board.order', { reference: pager.orderReference })}
                </p>
                {/*
                  ⚠ A `div`, NOT A `p` — `§11` QA M-12. `ElapsedTimer` renders its own `<p
                  className="qr-timer">`, so a `<p>` wrapper put a paragraph inside a
                  paragraph: React logged *"In HTML, <p> cannot be a descendant of <p>"* on
                  every render and the suite passed anyway. A browser's parser closes the
                  outer `<p>` at the inner one, so a server render of this subtree and the
                  client tree would disagree about the DOM. `StaffDevices` uses `<span>`
                  children in this same class, which is why its own test logs nothing.
                */}
                <div className="qr-staff-list__meta">
                  <StatusBadge
                    status={pager.status}
                    // Derived from the one Record above rather than written out here: an
                    // inline object is a second list of statuses, which is exactly the
                    // drift `§6.2` mechanises against.
                    labels={{
                      waiting: t(STATUS_COPY.waiting),
                      ready: t(STATUS_COPY.ready),
                      completed: t(STATUS_COPY.completed),
                      cancelled: t(STATUS_COPY.cancelled),
                      expired: t(STATUS_COPY.expired),
                    }}
                  />
                  {/*
                    ⚠ THE WORD IS RENDERED, NOT ONLY ANNOUNCED — `§11` QA M-13. This built the
                    accessible name by interpolating an EMPTY duration and trimming, so the
                    visible row was a bare `mm:ss` with *"Waiting"* living only in
                    `aria-label`: `§12` S6 specifies *"Waiting {duration}"* and what shipped
                    was a clock-shaped number that, on a screen read at two metres, could be
                    taken for a time of day. The label is now beside the readout, and the
                    timer's own `aria-label` still carries the pair for a screen reader.
                  */}
                  <span className="qr-staff-list__waiting">{t('staff.board.waitingLabel')}</span>
                  <ElapsedTimer
                    seconds={elapsed[pager.id] ?? 0}
                    label={t('staff.board.waitingLabel')}
                  />
                </div>
              </div>

              {open ? (
                <div className="qr-staff-form" data-detail="true">
                  {/* The sentence states, each one a real server answer rather than a
                      generic failure. `§12`: every error says what to DO. */}
                  {sentence !== undefined && (
                    <p className="qr-staff-warning" role="alert" data-detail-message={detail}>
                      {t(sentence)}
                    </p>
                  )}

                  {detail === 'confirm-cancel' ? (
                    <>
                      <p className="qr-staff-list__name">{t('staff.detail.cancel.title')}</p>
                      <p className="qr-staff-state__description">{t('staff.detail.cancel.body')}</p>
                      <div className="qr-staff-actions">
                        <Button
                          variant="danger"
                          onClick={() => {
                            onConfirmCancel(pager.id)
                          }}
                        >
                          {t('staff.detail.cancel.confirm')}
                        </Button>
                        <Button
                          variant="quiet"
                          onClick={() => {
                            onSelect(pager.id)
                          }}
                        >
                          {t('staff.detail.cancel.keep')}
                        </Button>
                      </div>
                    </>
                  ) : (
                    <div className="qr-staff-actions">
                      {legalActions(pager.status).map((action) => (
                        <Button
                          key={action}
                          variant={action === 'cancel' ? 'quiet' : 'primary'}
                          /*
                           * ⚠ CANCEL IS NEVER DISABLED, AND THE TEST NAME SAID SO BEFORE THE
                           * CODE DID — `§11` QA L-17. The case is called *"disables the acting
                           * buttons while a transition is in flight, and never the cancel"*,
                           * and `disabled={detail === 'working'}` applied to every button
                           * `legalActions()` produces, cancel included; the body only asserted
                           * cancel's label, so the name stated a contract the body never
                           * reached. The NAME is the right behaviour: cancel opens a
                           * CONFIRMATION and sends nothing, so there is no in-flight request
                           * for it to guard against, and greying it out while something else
                           * is sending reads as *you may not cancel this*.
                           */
                          disabled={detail === 'working' && action !== 'cancel'}
                          data-action={action}
                          onClick={() => {
                            if (action === 'ready') onReady(pager.id)
                            if (action === 'renotify') onRenotify(pager.id)
                            if (action === 'complete') onComplete(pager.id)
                            if (action === 'cancel') onCancel(pager.id)
                          }}
                        >
                          {detail === 'working' && action !== 'cancel'
                            ? t('staff.detail.working')
                            : t(ACTION_COPY[action])}
                        </Button>
                      ))}
                      <Button
                        variant="quiet"
                        data-action="close"
                        onClick={() => {
                          onSelect(null)
                        }}
                      >
                        {t('staff.detail.close')}
                      </Button>
                    </div>
                  )}
                </div>
              ) : (
                <Button
                  variant="secondary"
                  data-action="open"
                  onClick={() => {
                    onSelect(pager.id)
                  }}
                >
                  {t('staff.board.open', { reference: pager.orderReference })}
                </Button>
              )}
            </li>
          )
        })}
      </ul>
    </section>
  )
}
