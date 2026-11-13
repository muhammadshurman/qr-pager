import type { ReactNode } from 'react'

import { Button } from '../Button.tsx'

/**
 * ================================================================================
 * THE AUTHENTICATED CHROME. It says where you are, and how to leave.
 * ================================================================================
 *
 * `QRPAGER-25 §12` S2: the restaurant's NAME, the person's name, a role word in plain
 * language, navigation, and sign-out. 10b's board and 10c's KDS mount in `children`.
 *
 * ⚠ IT TAKES NO IDENTIFIER AND HAS NOWHERE TO PUT ONE. There is no `tenantId` prop and no
 * `userId` prop on this component — not "they are not rendered", but *they are not
 * accepted*. `§12`'s zero-technical-background bar forbids an unexplained identifier on a
 * screen, and a uuid is the purest example of one; a prop that exists is a prop a later
 * change renders into a debug line. The role arrives as the WORD to display rather than as
 * a `tenant_role` value, for the same reason: `'staff'` is an enum case and *"Staff"* is a
 * word, and this component cannot tell them apart, so the caller translates.
 *
 * `§12`'s "no library default look": every class here is ours and the chrome is a single
 * bar with a hairline, which is the approved direction rather than a framework's header.
 */

export interface StaffShellProps {
  /** The restaurant, by name. Never a slug and never an id. */
  readonly restaurant: string
  /** The person, by name. */
  readonly person: string
  /** Their role, already translated into a word. Never a `tenant_role` value. */
  readonly roleWord: string
  /** `Signed in as {name}`, already interpolated by the caller's translator. */
  readonly signedInLabel: string
  readonly signOutLabel: string
  readonly onSignOut: () => void
  /** The navigation, if this surface has any yet. */
  readonly nav?: ReactNode
  /**
   * A way into the studio, or nothing at all. `QRPAGER-48` item 3.
   *
   * ⚠⚠ ABSENT IS THE DEFAULT, AND THE SHELL NEVER DECIDES. Muhammad's ruling of 2026-11-13:
   * `/studio` requires `TenantPanelAccess::allows()` — Owner or Manager — so *"a staff member
   * who is neither would click a button and get a 401"*. The capability is published by the
   * API as `memberships[].can_access_studio`, computed from the same predicate the panel's
   * own gate uses, and the CALLER passes this prop only when it is true. A shell that decided
   * for itself would be the second implementation that ruling exists to prevent.
   */
  readonly studioHref?: string | undefined
  readonly studioLabel?: string | undefined
  readonly children: ReactNode
}

export function StaffShell({
  restaurant,
  person,
  roleWord,
  signedInLabel,
  signOutLabel,
  onSignOut,
  nav,
  studioHref,
  studioLabel,
  children,
}: StaffShellProps) {
  return (
    <div className="qr-staff" data-staff-shell="true">
      <header className="qr-staff__bar">
        <div className="qr-staff__identity">
          {/* The restaurant is the h1: it is the thing a person glances up to confirm. */}
          <h1 className="qr-staff__restaurant">{restaurant}</h1>
          <p className="qr-staff__person">
            <span data-person-name="true">{signedInLabel}</span>
            {/* The role is a word beside the name, not a badge: a badge reads as a status
                and this is not one. `title` is deliberately absent -- a tooltip is not an
                explanation on a touch screen. */}
            <span className="qr-staff__role" data-role-word="true">
              {roleWord}
            </span>
          </p>
          <span className="qr-visually-hidden" data-person-plain="true">
            {person}
          </span>
        </div>
        {nav !== undefined && <nav className="qr-staff__nav">{nav}</nav>}
        {studioHref !== undefined && studioLabel !== undefined && (
          /*
           * A real anchor, not a button with a handler: it is a navigation to another
           * surface, and `§12` wants every control to be the element it actually is. It also
           * means the session rides along as it would for any link — which is the whole of
           * how this works, because both surfaces share one origin (`§1`, 2026-11-13).
           */
          <a className="qr-staff__studio" href={studioHref} data-studio-link="true">
            {studioLabel}
          </a>
        )}
        <Button variant="quiet" onClick={onSignOut} data-sign-out="true">
          {signOutLabel}
        </Button>
      </header>
      <main className="qr-staff__main">{children}</main>
    </div>
  )
}
