import { Button, StaffState } from '@qrpager/ui'

import type { Translate } from '../../copy/index.ts'

/**
 * ================================================================================
 * S2's BODY — what the shell shows when nothing else is selected.
 * ================================================================================
 *
 * `§4.2`'s inventory puts the ACTIVE PAGER BOARD here, and the board is **10b's** by the
 * 2026-11-02 split. So this screen's honest content is: you are signed in, the board arrives
 * next, and here is the one thing you can do now.
 *
 * ⚠ IT IS NOT A PLACEHOLDER AND NOT A "coming soon". `§12`'s zero-technical-background bar
 * forbids an empty state without a next action, and a screen that says *"the board is not
 * built yet"* fails it twice over — it is system vocabulary, and it offers nothing. It says
 * what is true for the person in front of it and points at the work they can do, which is
 * setting up the screens the board will appear on.
 *
 * When 10b lands, this is the file whose contents the board replaces.
 */

export interface StaffHomeProps {
  readonly t: Translate
  readonly onDevices: () => void
  /** Offered only when this browser can run a ceremony and the person may add one. */
  readonly onAddPasskey?: () => void
  readonly addPasskeyLabel?: string
}

export function StaffHome({ t, onDevices, onAddPasskey, addPasskeyLabel }: StaffHomeProps) {
  return (
    <>
      <StaffState
        state="empty"
        title={t('staff.home.title')}
        description={t('staff.home.body')}
        action={{ label: t('staff.home.action'), onAction: onDevices }}
      />
      {onAddPasskey !== undefined && addPasskeyLabel !== undefined && (
        <div className="qr-staff-actions">
          <Button variant="quiet" onClick={onAddPasskey} data-add-passkey="true">
            {addPasskeyLabel}
          </Button>
        </div>
      )}
    </>
  )
}
