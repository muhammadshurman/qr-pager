import { useRef } from 'react'

import { Button } from '../Button.tsx'
import { useFocusTrap } from '../a11y.tsx'

/**
 * ================================================================================
 * THE LOCK. A full-cover overlay over a shell that no longer holds a credential.
 * ================================================================================
 *
 * `QRPAGER-25 §12` S3. **The lock is not this component.** By the time this renders, the
 * shell has already called `POST /v1/auth/logout` and dropped every byte of tenant data it
 * held — so what is behind this overlay is an empty shell and not a hidden one. That
 * ordering is the whole security property: a lock that only covered the screen would leave
 * a valid session cookie in a browser a passer-by can open devtools on, and *"a locked
 * shell reveals no tenant datum"* would be a claim about CSS.
 *
 * This component's own job is three things:
 *
 *   1. **cover** — `inset: 0` at the top layer, so nothing behind it is visible;
 *   2. **trap focus** — or tabbing walks into the chrome underneath and reads it out to a
 *      screen reader, which is the accessible-tree version of the same leak;
 *   3. **say what to do** — one sentence and one button, per `§12`'s zero-technical bar.
 *
 * ⚠ NO COUNTDOWN AND NO "locked at HH:MM". A visible timer tells a passer-by how long they
 * have, and a timestamp tells them how long the screen has been unattended. Neither is
 * information the person who walks back deserves less than the stranger does, so neither
 * is shown to either.
 *
 * ⚠ AND NOTHING ON IT NAMES THE RESTAURANT OR THE PERSON. The copy is *"Nobody is signed in
 * on this screen"* rather than *"{name}, you were signed out"*: a lock screen that still
 * says who was using it, and where, has leaked the two facts a shared-device lock exists to
 * stop leaking.
 */

export interface LockScreenProps {
  readonly title: string
  readonly description: string
  readonly signInLabel: string
  readonly onSignIn: () => void
}

export function LockScreen({ title, description, signInLabel, onSignIn }: LockScreenProps) {
  const surface = useRef<HTMLDivElement>(null)

  // `true`, because this component only exists while the shell is locked -- it is unmounted
  // on unlock rather than hidden, so there is no inactive state to pass. The hook also moves
  // focus to the first candidate on activation, which is why there is no second effect here
  // doing it by hand: the sign-in button is the only focusable element inside the surface,
  // so "first candidate" and "the button" are the same element by construction.
  useFocusTrap(surface, true)

  return (
    <div
      className="qr-staff-lock"
      data-locked="true"
      role="dialog"
      aria-modal="true"
      aria-labelledby="qr-staff-lock-title"
    >
      <div className="qr-staff-lock__surface" ref={surface}>
        <h1 className="qr-staff-lock__title" id="qr-staff-lock-title">
          {title}
        </h1>
        <p className="qr-staff-lock__description">{description}</p>
        <Button variant="primary" onClick={onSignIn}>
          {signInLabel}
        </Button>
      </div>
    </div>
  )
}
