import { Button, ElapsedTimer } from '@qrpager/ui'

import type { Translate } from '../copy/index.ts'
import { Frame, type BrandSlot } from './Frame.tsx'

/**
 * `§3.3` of the ticket. `§4.1`: *"Live elapsed timer, status, restaurant branding,
 * keep-screen-awake toggle, quiet 'what happens next'"*.
 *
 * ⚠ THE THREE STATES ARE A PROPERTY OF THE CONNECTION, NOT OF THE ORDER, and the degraded
 * one is `§4 › Edge`'s shedding order made visible: *"Load shedding drops nice-to-haves
 * (live timer, analytics) before alerts."* A 503 from the polling route IS the server
 * shedding the live timer -- `ShedLoad` is on that route and on neither of the other two --
 * so the degraded copy says the timer is behind AND THAT THE BUZZ IS NOT. Without that
 * sentence a customer cannot tell a shed timer from a broken product.
 */
export type WaitingState = 'healthy' | 'degraded' | 'offline'

export interface WaitingProps {
  readonly state: WaitingState
  readonly t: Translate
  readonly reference: string
  readonly elapsedSeconds: number
  /** The server's own last confirmed timestamp, already formatted for the locale. */
  readonly asOfLabel: string
  /** `'held'` renders the toggle; `'unavailable'` renders the honest line instead. */
  readonly wake: 'held' | 'unavailable'
  readonly wakeOn: boolean
  readonly onToggleWake: () => void
  readonly onRetry: () => void
  readonly brand?: BrandSlot | undefined
}

export function Waiting({
  state,
  t,
  reference,
  elapsedSeconds,
  asOfLabel,
  wake,
  wakeOn,
  onToggleWake,
  onRetry,
  brand,
}: WaitingProps) {
  const degraded = state === 'degraded'
  const offline = state === 'offline'

  return (
    <Frame
      screen="waiting"
      state={state}
      t={t}
      brand={brand}
      actions={
        // The retry is the only control here, and it exists only when a retry can do
        // something. `§4.1`'s *"no dead end"* for the two states that are stuck.
        degraded || offline ? (
          <Button variant="secondary" onClick={onRetry}>
            {t('waiting.retry')}
          </Button>
        ) : undefined
      }
    >
      <p className="pg-eyebrow">{t('landing.ref.label')}</p>
      <p className="pg-reference" dir="ltr">
        {reference}
      </p>
      <h1 className="pg-title">{t('waiting.title')}</h1>

      <div className="pg-status" role="group" aria-label={t('a11y.statusRegion')}>
        {/* The status in WORDS. `§4.1`'s own requirement and `R-118`'s shape: a state carried
            by colour alone is a state some customers cannot read. */}
        <p className="pg-note">{t('waiting.status.waiting')}</p>
        {/* `ElapsedTimer` is the kit's: `role="timer"`, `aria-live="off"` so a screen reader
            is not read a number every second, and `dir="ltr"` on the readout itself so
            `04:12` does not become `12:04` inside Arabic. The seconds come from the SERVER's
            timestamp, never from a local tick -- see `poll.ts elapsedSeconds`. */}
        <ElapsedTimer seconds={elapsedSeconds} label={t('waiting.elapsed.label')} />
      </div>

      {degraded && (
        <div className="pg-banner" data-tone="quiet" role="status">
          <p className="pg-banner__title">{t('waiting.degraded.title')}</p>
          <p className="pg-banner__body">{t('waiting.degraded.body')}</p>
          {/* The honest timestamp. The clock above is frozen at the last answer, so saying
              WHEN that was is the difference between a stale number and a lie. */}
          <p className="pg-banner__body">{t('waiting.degraded.asOf', { time: asOfLabel })}</p>
        </div>
      )}

      {offline && (
        <div className="pg-banner" data-tone="stopped" role="alert">
          <p className="pg-banner__title">{t('waiting.offline.title')}</p>
          <p className="pg-banner__body">{t('waiting.offline.body')}</p>
        </div>
      )}

      {wake === 'held' ? (
        <div className="pg-awake">
          <label htmlFor="pg-awake-toggle">{t('waiting.awake.label')}</label>
          {/* A real checkbox. `§12`'s "no library default look" is about a FRAMEWORK's
              signature, not about native controls, and a native checkbox is keyboard
              reachable and screen-reader-labelled with no JavaScript. */}
          <input
            id="pg-awake-toggle"
            className="qr-field__input"
            type="checkbox"
            checked={wakeOn}
            onChange={onToggleWake}
          />
        </div>
      ) : (
        // ⚠ NO TOGGLE AT ALL. `navigator.wakeLock` is present in all three test engines and
        // non-functional in standalone iOS web apps from 16.4 to 18.4 (measured, `§1.1`), so
        // a detection-driven switch would flip and change nothing. One honest line instead.
        <p className="pg-note">{t('waiting.awake.unavailable')}</p>
      )}

      <details className="pg-disclosure">
        <summary className="pg-disclosure__summary">{t('waiting.next.summary')}</summary>
        <p className="pg-disclosure__body">{t('waiting.next.body')}</p>
      </details>
    </Frame>
  )
}
