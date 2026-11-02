import { Button, ElapsedTimer, StaffState, StatusBadge } from '@qrpager/ui'
import { type PagerStatus } from '@qrpager/contracts'

import type { CopyKey, Translate } from '../../copy/index.ts'
import type { KdsOrder } from '../../kds/api.ts'

/**
 * ================================================================================
 * S2 — THE KITCHEN DISPLAY. Read-only, read from two metres, nobody signed in.
 * ================================================================================
 *
 * `§4.2`'s *KDS view*, and `QRPAGER-27 §6` is the clause that shapes every decision below:
 * *"A kitchen screen is greasy, bright and viewed from two metres — contrast and target size
 * are **functional** requirements here."*
 *
 * ================================================================================
 * WHAT IS ON IT, AND WHAT IS DELIBERATELY NOT
 * ================================================================================
 *
 * The order reference, how long it has been waiting, and its state. That is all, and the
 * absences are each a decision:
 *
 *   - **No action of any kind on an order.** `Ability::issuableToDevice()` is a closed list of
 *     one (`pager:read`) and its own docblock says why marking ready is absent: *"a kitchen
 *     screen reads the board; it does not open sessions, manage staff or mint tokens"*, and
 *     *"PagerNotify is absent because marking ready is Task 10's staff action, not the
 *     screen's."* So a *Tell them it is ready* button here would be a control the server
 *     refuses — a screen that lies to the person using it — and widening that list is a
 *     stop-and-report rather than a builder's call. The acting board is `/staff` (10b).
 *   - **No restaurant name.** `Ability::TenantRead` is deliberately not held by
 *     `Principal::Device`, and its docblock calls the alternative *"a vacuous grant reading as
 *     a capability"*. A wall screen is in one kitchen and the people in front of it know which.
 *   - **No sign-out, no person's name, no navigation.** There is no session and nowhere to go.
 *     `kds.forget` is the one control, and it is at the BOTTOM, small, and guarded — see below.
 *
 * ================================================================================
 * THE TERMINAL STATUSES NEVER APPEAR, AND THAT IS THE SERVER'S DOING
 * ================================================================================
 *
 * `PagerSessions::board()` selects `status in ('waiting','ready')`, so `completed`,
 * `cancelled` and `expired` cannot reach this screen. The status map below still covers all
 * five, because `StatusBadge` takes a `Record<PagerStatus, string>` and the generated mirror
 * is the source — a status added in PHP must fail the TypeScript build here rather than
 * render its enum value in a kitchen. That is the mirror doing its job on a screen where none
 * of the extra entries is reachable, which is the cheap half of `§6.2` and worth keeping.
 */

/** Every screen state. `throttled` is a peer of `offline`, which is `R-224`'s whole point. */
export type KdsPhase = 'loading' | 'ready' | 'offline' | 'throttled' | 'error'

export interface KdsBoardProps {
  readonly t: Translate
  readonly phase: KdsPhase
  readonly orders: readonly KdsOrder[]
  /** Whole seconds per order id, computed by the caller from the SERVER's timestamp. */
  readonly elapsed: Readonly<Record<string, number>>
  /** When the newest answer arrived, already formatted, or null while fresh. */
  readonly staleSince: string | null
  readonly onRetry: () => void
  /** Two-step, because a mis-tap would take a kitchen screen off the air mid-service. */
  readonly forgetting: boolean
  readonly onForget: () => void
  readonly onConfirmForget: () => void
  readonly onCancelForget: () => void
}

const STATUS_COPY: Readonly<Record<PagerStatus, CopyKey>> = {
  waiting: 'kds.status.waiting',
  ready: 'kds.status.ready',
  completed: 'kds.status.completed',
  cancelled: 'kds.status.cancelled',
  expired: 'kds.status.expired',
}

export function KdsBoard({
  t,
  phase,
  orders,
  elapsed,
  staleSince,
  onRetry,
  forgetting,
  onForget,
  onConfirmForget,
  onCancelForget,
}: KdsBoardProps) {
  if (phase === 'loading') {
    return (
      <main className="qr-kds" data-kds-view="loading">
        <StaffState state="loading" loadingLabel={t('kds.title')} />
      </main>
    )
  }

  /*
   * ⚠ A FAILED POLL NEVER BLANKS THE ORDERS. `staff/StaffBoard.tsx` makes this argument for
   * the staff board and it is stronger here: that board is in somebody's hand and they can see
   * the network icon on their own tablet. This one is on a wall, and the kitchen's only source
   * of truth about what to cook is whatever is on it. Blanking it over a dropped packet stops
   * a service.
   *
   * So the rows stay and `staleSince` says when they were last confirmed. The failure states
   * below replace the board ONLY when there is nothing to show at all.
   */
  if (phase !== 'ready' && orders.length === 0) {
    return (
      <main className="qr-kds" data-kds-view={phase}>
        {phase === 'throttled' ? (
          // R-224: no action. The screen resumes itself, so a button would be a second 429
          // and `StaffState`'s `throttled` case has no `action` member to render one with.
          <StaffState
            state="throttled"
            title={t('kds.throttled.title')}
            description={t('kds.throttled.body')}
          />
        ) : (
          <StaffState
            state={phase === 'offline' ? 'offline' : 'error'}
            title={t(phase === 'offline' ? 'kds.offline.title' : 'kds.error.title')}
            description={t(phase === 'offline' ? 'kds.offline.body' : 'kds.error.body')}
            action={{ label: t('kds.retry'), onAction: onRetry }}
          />
        )}
      </main>
    )
  }

  if (orders.length === 0) {
    return (
      <main className="qr-kds" data-kds-view="empty">
        <StaffState
          state="empty"
          title={t('kds.empty.title')}
          description={t('kds.empty.body')}
          // Checking now is a REAL action -- it polls immediately rather than waiting for the
          // tick -- which is what `§12` requires of an empty state and what
          // `staff.board.empty.action` already does one surface over.
          action={{ label: t('kds.empty.action'), onAction: onRetry }}
        />
      </main>
    )
  }

  return (
    <main className="qr-kds" data-kds-view="ready">
      {/*
        ⚠ `aria-live="polite"` ON THE LIST'S WRAPPER AND NOT ON EACH ROW. A kitchen screen is
        the one surface in this product where a screen reader is a plausible primary channel --
        a cook with their hands full, a visually impaired member of staff -- and the event
        worth announcing is *the list changed*, once, rather than five rows re-reading
        themselves every five seconds. `role="status"` would do the same job; the explicit
        pair is what `a11y.tsx`'s `LiveRegion` uses and this matches it.
      */}
      <header className="qr-kds__head">
        <h1 className="qr-kds__title">{t('kds.title')}</h1>

        {staleSince !== null && (
          <p className="qr-kds__stale" role="status" data-kds-stale="true">
            {t('kds.stale', { time: staleSince })}
          </p>
        )}

        {/* The throttle notice sits ABOVE the orders when there are orders — `R-224` says the
            copy appears once the window is exhausted, not that the board disappears, and a
            kitchen mid-service needs the orders more than the notice. */}
        {phase === 'throttled' && (
          <p className="qr-kds__stale" role="status" data-kds-throttled="true">
            {t('kds.throttled.body')}
          </p>
        )}

        {phase === 'offline' && (
          <p className="qr-kds__stale" role="status" data-kds-offline="true">
            {t('kds.offline.body')}
          </p>
        )}
      </header>

      <ul className="qr-kds__list" aria-label={t('kds.title')} aria-live="polite" data-kds="true">
        {orders.map((order) => (
          /*
            ⚠ KEYED BY `id`, AND `data-kds-order` CARRIES THE REFERENCE RATHER THAN THE ID.
            The key is React's identity and must be the stable server id; the attribute is what
            the browser proof counts rows by, and it must be something that is ALSO on the
            screen -- `§12` forbids an unexplained identifier, and `kds-screens.test.tsx`
            asserts no uuid reaches the rendered output. An assertion that counted a
            `data-kds-id` would be counting something the person cannot see.
          */
          <li className="qr-kds__order" key={order.id} data-kds-order={order.orderReference}>
            <p className="qr-kds__ref">{t('kds.order', { reference: order.orderReference })}</p>
            <div className="qr-kds__meta">
              <StatusBadge
                status={order.status}
                labels={{
                  waiting: t(STATUS_COPY.waiting),
                  ready: t(STATUS_COPY.ready),
                  completed: t(STATUS_COPY.completed),
                  cancelled: t(STATUS_COPY.cancelled),
                  expired: t(STATUS_COPY.expired),
                }}
              />
              <span className="qr-kds__waiting">{t('kds.waitingLabel')}</span>
              {/* A `div`, not a `p` — `ElapsedTimer` renders its own `<p>`, and `§11` QA M-12
                  measured what a `<p>` inside a `<p>` costs: a server render and a client tree
                  that disagree about the DOM, with the suite passing. */}
              <ElapsedTimer seconds={elapsed[order.id] ?? 0} label={t('kds.waitingLabel')} />
            </div>
          </li>
        ))}
      </ul>

      {/*
        ⚠ THE ONE CONTROL ON THIS SCREEN, AND IT IS CONFIRMED. Removing the code takes the
        screen off the air until somebody with a staff account comes back with a new one, so
        `§12`'s *destructive in effect → confirmed and clearly marked as not reversible*
        applies exactly as it does to cancelling an order (10b) and re-issuing a sheet (10d).
        The body says what happens to the SCREEN, which is the consequence being decided about.
      */}
      <footer className="qr-kds__foot">
        {forgetting ? (
          <div className="qr-staff-form" data-kds-forget="confirm">
            <p className="qr-kds__ref">{t('kds.forget.title')}</p>
            <p className="qr-staff-state__description">{t('kds.forget.body')}</p>
            <div className="qr-staff-actions">
              <Button variant="danger" onClick={onConfirmForget}>
                {t('kds.forget.confirm')}
              </Button>
              <Button variant="quiet" onClick={onCancelForget}>
                {t('kds.forget.keep')}
              </Button>
            </div>
          </div>
        ) : (
          <Button variant="quiet" data-kds-forget="open" onClick={onForget}>
            {t('kds.forget')}
          </Button>
        )}
      </footer>
    </main>
  )
}
