import type { ReactNode } from 'react'

import { LiveRegion, VisuallyHidden } from './a11y.tsx'
import { Spinner } from './Spinner.tsx'

/**
 * ================================================================================
 * THE FIVE STATES, IN ONE PLACE
 * ================================================================================
 *
 * `§12`: "Every screen is specified before it is coded: purpose, all states (empty /
 * loading / error / offline / permission-denied), content inventory, and the exact copy."
 * QRPAGER-15 §7 repeats it per COMPONENT.
 *
 * Five states on twelve components is sixty decisions if each component invents its own.
 * This is the one implementation, so a component declares WHICH state it is in and the
 * rendering, the roles, the live-region politeness and the copy slots are decided once.
 *
 * WHY EACH STATE IS DISTINCT RATHER THAN "an error":
 *
 *   loading            nothing is wrong; do not alarm anyone
 *   empty              the request succeeded and the answer is nothing -- not an error
 *   error              something is wrong on our side; offer a retry
 *   offline            something is wrong on the NETWORK; a retry may work in a moment,
 *                      and the copy must not blame the user or claim we are broken
 *   permission-denied  nothing will fix this by retrying, so offering a retry is a lie
 *
 * `§4.1`'s "Offline / error -- honest message, retry, NO DEAD END" is why `offline` and
 * `error` both take a retry and `permission-denied` deliberately does not.
 */

export type ViewState = 'ready' | 'loading' | 'empty' | 'error' | 'offline' | 'permission-denied'

/** Every state this kit knows, for a test that must cover all of them. */
export const VIEW_STATES: readonly ViewState[] = [
  'ready',
  'loading',
  'empty',
  'error',
  'offline',
  'permission-denied',
]

export interface StateViewProps {
  readonly state: ViewState
  /** Rendered only when `state` is `ready`. */
  readonly children?: ReactNode
  /** The heading for a non-ready state. Required copy -- §12 forbids inventing it here. */
  readonly title?: string
  readonly description?: string
  readonly onRetry?: () => void
  readonly retryLabel?: string
  /** A label for the loading state, announced rather than shown. */
  readonly loadingLabel?: string
}

/** Which states offer a retry. `permission-denied` does not: retrying changes nothing. */
const RETRYABLE: readonly ViewState[] = ['error', 'offline']

export function StateView({
  state,
  children,
  title,
  description,
  onRetry,
  retryLabel,
  loadingLabel = 'Loading',
}: StateViewProps) {
  if (state === 'ready') return <>{children}</>

  if (state === 'loading') {
    return (
      <div className="qr-state qr-state--loading" data-state="loading">
        <Spinner />
        {/* aria-busy on the region, and the label announced rather than drawn: a spinner
            with no accessible name is a decoration that tells a screen reader nothing. */}
        <VisuallyHidden>{loadingLabel}</VisuallyHidden>
        <LiveRegion message={loadingLabel} />
      </div>
    )
  }

  const retryable = RETRYABLE.includes(state) && onRetry !== undefined
  // error and offline are announced; empty and permission-denied are not an interruption.
  const announce = state === 'error' || state === 'offline'

  return (
    <div
      className={`qr-state qr-state--${state}`}
      data-state={state}
      role={announce ? 'alert' : undefined}
    >
      {title !== undefined && <p className="qr-state__title">{title}</p>}
      {description !== undefined && <p className="qr-state__description">{description}</p>}
      {retryable && (
        <button type="button" className="qr-button qr-button--secondary" onClick={onRetry}>
          {retryLabel ?? 'Try again'}
        </button>
      )}
    </div>
  )
}
