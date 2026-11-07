import { Button, Field, StaffState } from '@qrpager/ui'

import type { Translate } from '../../copy/index.ts'

/**
 * ================================================================================
 * S1 — SETTING UP A KITCHEN SCREEN. The only screen on this surface with an input.
 * ================================================================================
 *
 * The one job: get the code a manager generated on `/staff/devices` into this tablet, once.
 * After that this screen is never seen again until the code is revoked or expires.
 *
 * ⚠ `§12`'s ZERO-TECHNICAL-BACKGROUND BAR IS THE HARD HALF HERE, AND THE WORD THAT BREAKS IT
 * IS *TOKEN*. The thing being pasted is a `personal_access_tokens` row's plaintext half; the
 * person pasting it is whoever is standing in the kitchen when the tablet is mounted. So the
 * copy calls it **the code** throughout, which is what `staff.devices.issued.*` already calls
 * it on the screen that produces it — the two screens are the two ends of one errand and they
 * use one word for the thing being carried.
 *
 * ⚠ AND THE FIELD IS NOT A PASSWORD FIELD, which looks like the careful choice and is the
 * wrong one. A masked field on a wall tablet means a person who mis-pastes cannot see that
 * they did, and the failure arrives later as a refusal with no visible cause. The value is a
 * bearer credential, which is an argument for not putting it in a URL (`kds/storage.ts` makes
 * it) and not an argument for hiding it from the one person who is holding both ends of it.
 * `autoComplete="off"` and `spellCheck={false}` are set instead, so no password manager and no
 * autocorrect touches it — autocorrect on a base64-ish string is a real failure mode on a
 * tablet keyboard.
 */
export interface KdsSetupProps {
  readonly t: Translate
  readonly value: string
  readonly onChange: (value: string) => void
  readonly onSubmit: () => void
  /** True once a pasted code has been refused, so the screen says so rather than looping. */
  readonly refused: boolean
  readonly busy: boolean
}

export function KdsSetup({ t, value, onChange, onSubmit, refused, busy }: KdsSetupProps) {
  return (
    <main className="qr-kds qr-kds--setup" data-kds-view="setup">
      <StaffState state="ready">
        <section className="qr-staff-form" aria-label={t('kds.setup.title')}>
          <h1 className="qr-staff-state__title">{t('kds.setup.title')}</h1>
          <p className="qr-staff-state__description">{t('kds.setup.body')}</p>

          {/*
            The refusal. `role="alert"` because it follows an action the person just took and
            is the answer to it -- the one thing on this screen worth interrupting for.
            `§12`: every error says what to DO, so the copy sends them back to the staff
            surface for a new code rather than reporting that a credential was rejected.
          */}
          {refused && (
            <p className="qr-staff-warning" role="alert" data-kds-refused="true">
              {t('kds.setup.refused')}
            </p>
          )}

          <form
            onSubmit={(event) => {
              // A form rather than a bare button, so Enter on a tablet keyboard submits --
              // which is what a person who has just pasted will press.
              event.preventDefault()
              onSubmit()
            }}
          >
            <Field
              label={t('kds.setup.code.label')}
              hint={t('kds.setup.code.hint')}
              value={value}
              onChange={(event) => {
                onChange(event.target.value)
              }}
              autoComplete="off"
              spellCheck={false}
              autoCapitalize="none"
              data-kds-code="true"
            />

            <div className="qr-staff-actions">
              <Button
                variant="primary"
                type="submit"
                // Empty is not submittable: the request would be a 401 and the screen would
                // report a refusal the person did not cause.
                disabled={busy || value.trim() === ''}
              >
                {busy ? t('kds.setup.working') : t('kds.setup.submit')}
              </Button>
            </div>
          </form>
        </section>
      </StaffState>
    </main>
  )
}
