import { Button } from '@qrpager/ui'

import type { Translate } from '../copy/index.ts'
import type { ErrorState } from '../pager/journey.ts'
import { Frame, type BrandSlot } from './Frame.tsx'

/**
 * `§3.6` of the ticket. `§4.1`: *"Honest message, retry, no dead end."*
 *
 * ⚠ `§4.1` LISTS NO STATES FOR THIS SCREEN, WHICH IS NOT THE SAME AS ONE. There are three,
 * and they differ in the only way that matters to a customer -- what a retry can do:
 *
 *   offline   the network is gone. A retry works the moment it is back, so it is automatic
 *             on `online` AND available manually.
 *   server    we answered badly. A retry may work; it uses the same backoff.
 *   unknown   we answered something this build cannot interpret -- reachable only across a
 *             deploy, because `PagerStatus` is a generated union. The copy does not pretend
 *             to know what happened, because claiming to would be the dishonesty `§4.1`'s
 *             *"honest message"* is about.
 *
 * This screen is for failures that are NOT a state of the journey: the first resolve could
 * not be made at all. Once a snapshot exists, a failure degrades the WAITING screen instead
 * -- which is `§4 › Edge`'s shedding order, and is why losing the connection mid-wait does
 * not throw the customer out of their session.
 *
 * ⚠ ITS STATE UNION IS IMPORTED, NOT DECLARED. `journey.ts` derives all six per-screen unions
 * from `JOURNEY_STATES` with a template-literal `infer`, so this screen's `state` prop cannot
 * fall out of step with the journey that drives it. The copy map below stays -- it is
 * exhaustive because the compiler requires a key for every member of the imported union,
 * which is the same derivation, and none of these state names collides with a `pager_status`
 * value. `SessionEnded`'s three do, which is why that one screen has no map.
 */

export type { ErrorState }

export interface OfflineErrorProps {
  readonly state: ErrorState
  readonly t: Translate
  readonly onRetry: () => void
  readonly brand?: BrandSlot | undefined
}

export function OfflineError({ state, t, onRetry, brand }: OfflineErrorProps) {
  const title = (
    {
      offline: 'error.offline.title',
      server: 'error.server.title',
      unknown: 'error.unknown.title',
    } as const
  )[state]

  const body = (
    {
      offline: 'error.offline.body',
      server: 'error.server.body',
      unknown: 'error.unknown.body',
    } as const
  )[state]

  return (
    <Frame
      screen="error"
      state={state}
      t={t}
      brand={brand}
      actions={
        <Button variant="primary" onClick={onRetry}>
          {t('error.retry')}
        </Button>
      }
    >
      {/* `role="alert"` on all three: the customer did not cause this and is not expecting
          it, which is exactly the case an assertive announcement is for -- the kit's
          `StateView` makes the same distinction between `error`/`offline` (announced) and
          `empty`/`permission-denied` (not). */}
      <div role="alert">
        <h1 className="pg-title">{t(title)}</h1>
        <p className="pg-note">{t(body)}</p>
      </div>
    </Frame>
  )
}
