import type { ReactNode } from 'react'

import { LiveRegion, VisuallyHidden } from '../a11y.tsx'
import { Button } from '../Button.tsx'
import { Spinner } from '../Spinner.tsx'

/**
 * ================================================================================
 * THE STAFF STATES — `StateView`'s five, PLUS A REQUIREMENT THE TYPE ENFORCES
 * ================================================================================
 *
 * `StateView` is the kit's one implementation of the five `§12` states and this is not a
 * second one: a staff screen needs the same five, at a scale readable from two metres, with
 * one rule added that the customer kit cannot express.
 *
 * **THE RULE: `empty`, `error` and `offline` REQUIRE AN ACTION.** It comes from `§12`'s
 * zero-technical-background clause — *"no empty state without a next action, every error
 * saying what to do rather than what failed"* — and it is enforced by the DISCRIMINATED
 * UNION below rather than by a reviewer noticing. `StateView`'s `onRetry` is optional,
 * which is right for a customer screen with nowhere else to go and wrong here: a restaurant
 * owner looking at *"No kitchen screen is set up yet."* with no button has been told their
 * situation and not what to do about it.
 *
 * So an actionless empty state **does not compile**. `packages/ui/test/staff-state.test.ts`
 * proves that against a committed bad fixture, because an unproven guard is not a guard —
 * a union that happened to admit the bad shape would be indistinguishable from this one by
 * reading.
 *
 * **`permission-denied` TAKES NO ACTION, AND THAT IS THE SECOND HALF OF THE SAME RULE.**
 * Retrying changes nothing and there is no button that would, so offering one is a lie.
 * Its `description` carries the next action in words instead — *"Ask one of them."* — which
 * is why `description` is required on it and not optional.
 */

/** A next action. Required wherever `§12` says an empty or failed state must offer one. */
export interface StaffAction {
  readonly label: string
  readonly onAction: () => void
}

interface ReadyState {
  readonly state: 'ready'
  readonly children: ReactNode
}

interface LoadingState {
  readonly state: 'loading'
  /** Announced rather than drawn. A spinner with no accessible name says nothing. */
  readonly loadingLabel: string
}

interface ActionableState {
  readonly state: 'empty' | 'error' | 'offline'
  readonly title: string
  readonly description: string
  /** NOT OPTIONAL. See the note above; this is the whole point of the type. */
  readonly action: StaffAction
}

interface DeniedState {
  readonly state: 'permission-denied'
  readonly title: string
  /** Required: it is where the next action lives, since no button can be offered. */
  readonly description: string
}

export type StaffStateProps = ReadyState | LoadingState | ActionableState | DeniedState

/** Every state this primitive knows, for a test that must cover all of them. */
export const STAFF_STATES = [
  'ready',
  'loading',
  'empty',
  'error',
  'offline',
  'permission-denied',
] as const

export type StaffStateName = (typeof STAFF_STATES)[number]

export function StaffState(props: StaffStateProps) {
  if (props.state === 'ready') return <>{props.children}</>

  if (props.state === 'loading') {
    return (
      <div className="qr-staff-state qr-staff-state--loading" data-state="loading">
        <Spinner />
        <VisuallyHidden>{props.loadingLabel}</VisuallyHidden>
        <LiveRegion message={props.loadingLabel} />
      </div>
    )
  }

  // `error` and `offline` interrupt; `empty` and `permission-denied` are answers rather
  // than events, so they are not announced. Same split as `StateView`, for the same reason.
  const announce = props.state === 'error' || props.state === 'offline'

  return (
    <div
      className={`qr-staff-state qr-staff-state--${props.state}`}
      data-state={props.state}
      role={announce ? 'alert' : undefined}
    >
      <p className="qr-staff-state__title">{props.title}</p>
      <p className="qr-staff-state__description">{props.description}</p>
      {props.state !== 'permission-denied' && (
        <Button variant="secondary" onClick={props.action.onAction}>
          {props.action.label}
        </Button>
      )}
    </div>
  )
}
