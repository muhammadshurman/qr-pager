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
  /** Offered only when this browser can run a ceremony. */
  readonly onAddPasskey?: (() => void) | undefined
  /**
   * What happened last time the person asked. `null` while they have not.
   *
   * ⚠ NOT OPTIONAL-AND-FORGOTTEN, which it was. The first version called `registerPasskey()`
   * and DISCARDED its result, so the button did nothing observable whether the ceremony
   * succeeded, was cancelled or was refused by the server. `§12`'s zero-technical-background
   * bar is *every error says what to do*, and silence says nothing at all -- the person taps
   * again, which is the behaviour a silent failure always produces.
   */
  readonly passkeyNotice?: 'added' | 'failed' | null
}

export function StaffHome({ t, onDevices, onAddPasskey, passkeyNotice }: StaffHomeProps) {
  return (
    <>
      <StaffState
        state="empty"
        title={t('staff.home.title')}
        description={t('staff.home.body')}
        action={{ label: t('staff.home.action'), onAction: onDevices }}
      />
      {onAddPasskey !== undefined && (
        <div className="qr-staff-actions">
          <Button variant="quiet" onClick={onAddPasskey} data-add-passkey="true">
            {t('staff.passkey.add')}
          </Button>
        </div>
      )}
      {passkeyNotice === 'added' && (
        // `role="status"` rather than `alert`: it is good news and not an interruption.
        <p className="qr-staff-state__description" role="status" data-passkey-notice="added">
          {t('staff.passkey.added')}
        </p>
      )}
      {passkeyNotice === 'failed' && (
        <p className="qr-staff-state__title" role="alert" data-passkey-notice="failed">
          {t('staff.passkey.failed')}
        </p>
      )}
    </>
  )
}
