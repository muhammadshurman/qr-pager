import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { StaffShell } from '../src/staff/StaffShell.tsx'

afterEach(cleanup)

/**
 * `QRPAGER-48` item 3 — the staff app's way into the studio.
 *
 * Muhammad, 2026-11-13: *"the button is shown only to someone who can actually reach the
 * studio, and the check is the same one the panel itself uses, not a second implementation
 * that can drift from it."* The shell's part of that bargain is narrow and is what these
 * cases pin: it draws the link when given one and draws NOTHING when not. The decision lives
 * with the caller, which holds the server's published capability.
 */
const base = {
  restaurant: 'مطعم السَّرو',
  person: 'Salma Haddad',
  roleWord: 'Owner',
  signedInLabel: 'Signed in as Salma Haddad',
  signOutLabel: 'Sign out',
  onSignOut: () => {},
}

describe('the way into the studio, from the staff shell', () => {
  it('is drawn when the caller supplies it', () => {
    render(
      <StaffShell {...base} studioHref="/studio" studioLabel="Edit your page">
        <p>board</p>
      </StaffShell>,
    )

    const link = screen.getByRole('link', { name: 'Edit your page' })

    expect(link.getAttribute('href')).toBe('/studio')
    // A real anchor, not a button with a handler: it navigates to another surface, and §12
    // wants every control to be the element it actually is.
    expect(link.tagName).toBe('A')
  })

  /**
   * ⚠ THE HALF THAT MATTERS. `/studio` is gated by `TenantPanelAccess::allows()` — Owner or
   * Manager — so a staff member who is neither must not be shown a control that answers 401.
   * The shell never decides this: the caller passes nothing when the server's published
   * capability is false, and nothing is what must render.
   */
  it('is ABSENT entirely when the caller supplies nothing', () => {
    render(
      <StaffShell {...base}>
        <p>board</p>
      </StaffShell>,
    )

    expect(screen.queryByRole('link', { name: 'Edit your page' })).toBeNull()
    // Not merely unnamed: no studio control of any kind, disabled or otherwise. A visibly
    // locked door still invites the question, and there is nothing this person can do.
    expect(document.querySelector('[data-studio-link="true"]')).toBeNull()
  })

  it('is absent when only one half is supplied, so a missing label cannot leak a bare link', () => {
    render(
      <StaffShell {...base} studioHref="/studio">
        <p>board</p>
      </StaffShell>,
    )

    expect(document.querySelector('[data-studio-link="true"]')).toBeNull()
  })
})
