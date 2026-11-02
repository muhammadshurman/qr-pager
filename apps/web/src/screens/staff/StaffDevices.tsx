import { Button, Field, StaffState } from '@qrpager/ui'
import { useState, type SyntheticEvent } from 'react'

import type { Translate } from '../../copy/index.ts'
import type { IssuedDevice, StaffDevice } from '../../staff/api.ts'

/**
 * ================================================================================
 * S4 — KITCHEN SCREENS. The screen where a credential is minted, shown once, and gone.
 * ================================================================================
 *
 * `QRPAGER-25 §12` S4. Three `§12` properties are structural here rather than editorial:
 *
 * **NOT ONE IDENTIFIER IS RENDERED.** Not the token id, not a uuid, not the ability string.
 * A row shows the name the person typed, when it stops working and when it was last used.
 * The id exists in the props because `onRemove` needs it; it reaches no element's text and
 * `apps/web/test/staff-screens.test.tsx` asserts that against the rendered output rather
 * than against this comment.
 *
 * **THE EMPTY STATE CARRIES THE NEXT ACTION**, and `StaffState`'s type would not compile
 * without one.
 *
 * **REMOVAL IS CONFIRMED AND THE CONFIRMATION SAYS WHAT HAPPENS**, not what it is called:
 * *"The screen will stop working straight away. You can set up a new one afterwards."* No
 * "revoke", no "token", no "credential" — `§12`'s bar is a restaurant owner who reads no
 * documentation.
 *
 * ⚠ AND THE ONE-TIME CREDENTIAL IS NEVER PUT ANYWHERE IT PERSISTS. It is React state on this
 * component and nothing else: no `localStorage`, no `sessionStorage`, no URL, no `history`
 * entry. It is dropped when the person dismisses it, and leaving this screen drops it too.
 * `DeviceTokens::issue()` returns it exactly once and nothing can read it back, so a client
 * that cached it would be the only copy at rest in the system.
 */

export interface StaffDevicesProps {
  readonly t: Translate
  readonly state: 'loading' | 'ready' | 'error' | 'offline' | 'permission-denied'
  readonly devices: readonly StaffDevice[]
  readonly issued: IssuedDevice | null
  readonly onRetry: () => void
  readonly onIssue: (name: string) => void
  readonly onDismissIssued: () => void
  readonly onRemove: (id: string) => void
  /** Formats an ISO timestamp for a person. Injected, so the test is not clock-dependent. */
  readonly formatDate: (iso: string) => string
}

export function StaffDevices({
  t,
  state,
  devices,
  issued,
  onRetry,
  onIssue,
  onDismissIssued,
  onRemove,
  formatDate,
}: StaffDevicesProps) {
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [removing, setRemoving] = useState<string | null>(null)

  if (state === 'loading') {
    return <StaffState state="loading" loadingLabel={t('staff.shell.loading')} />
  }

  if (state === 'permission-denied') {
    return (
      <StaffState
        state="permission-denied"
        title={t('staff.devices.denied.title')}
        description={t('staff.devices.denied.body')}
      />
    )
  }

  if (state === 'error' || state === 'offline') {
    return (
      <StaffState
        state={state}
        title={t(state === 'offline' ? 'staff.devices.offline.title' : 'staff.devices.error.title')}
        description={t(
          state === 'offline' ? 'staff.devices.offline.body' : 'staff.devices.error.body',
        )}
        action={{ label: t('staff.shell.error.retry'), onAction: onRetry }}
      />
    )
  }

  if (issued !== null) {
    return (
      <section>
        <h2 className="qr-staff-list__name">{t('staff.devices.issued.title')}</h2>
        <p className="qr-staff-warning">{t('staff.devices.issued.warning')}</p>
        {/* `<code>` so a screen reader announces it as code rather than reading it as
            prose, and `data-one-time-secret` so the browser test can assert it appears
            exactly once and never again after dismissal. */}
        <code className="qr-staff-secret" data-one-time-secret="true">
          {issued.token}
        </code>
        <div className="qr-staff-actions">
          <Button
            variant="primary"
            onClick={() => {
              onDismissIssued()
              setAdding(false)
              setName('')
            }}
          >
            {t('staff.devices.issued.done')}
          </Button>
        </div>
      </section>
    )
  }

  const submit = (event: SyntheticEvent): void => {
    event.preventDefault()
    onIssue(name)
  }

  return (
    <section>
      <h2 className="qr-staff-list__name">{t('staff.devices.title')}</h2>
      <p className="qr-staff-state__description">{t('staff.devices.lede')}</p>

      {devices.length === 0 && !adding ? (
        <StaffState
          state="empty"
          title={t('staff.devices.empty.title')}
          description={t('staff.devices.empty.body')}
          action={{
            label: t('staff.devices.empty.action'),
            onAction: () => {
              setAdding(true)
            },
          }}
        />
      ) : (
        <ul className="qr-staff-list">
          {devices.map((device) => (
            <li className="qr-staff-list__item" key={device.id}>
              <div>
                <p className="qr-staff-list__name">{device.name}</p>
                <p className="qr-staff-list__meta">
                  {device.expiresAt !== null && (
                    <span>
                      {t('staff.devices.expires', { date: formatDate(device.expiresAt) })}
                    </span>
                  )}
                  <span>
                    {device.lastUsedAt === null
                      ? t('staff.devices.neverUsed')
                      : t('staff.devices.lastUsed', { when: formatDate(device.lastUsedAt) })}
                  </span>
                </p>
              </div>
              {removing === device.id ? (
                <div className="qr-staff-form">
                  <p className="qr-staff-list__name">{t('staff.devices.remove.title')}</p>
                  <p className="qr-staff-state__description">{t('staff.devices.remove.body')}</p>
                  <div className="qr-staff-actions">
                    <Button
                      variant="danger"
                      onClick={() => {
                        onRemove(device.id)
                        setRemoving(null)
                      }}
                    >
                      {t('staff.devices.remove.confirm')}
                    </Button>
                    <Button
                      variant="quiet"
                      onClick={() => {
                        setRemoving(null)
                      }}
                    >
                      {t('staff.devices.remove.keep')}
                    </Button>
                  </div>
                </div>
              ) : (
                <Button
                  variant="secondary"
                  onClick={() => {
                    setRemoving(device.id)
                  }}
                >
                  {t('staff.devices.remove')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <form className="qr-staff-form" onSubmit={submit}>
          <Field
            label={t('staff.devices.name.label')}
            hint={t('staff.devices.name.hint')}
            name="name"
            type="text"
            value={name}
            onChange={(event) => {
              setName(event.target.value)
            }}
            maxLength={60}
            required
          />
          <div className="qr-staff-actions">
            <Button type="submit" data-issue-device="true">
              {t('staff.devices.create')}
            </Button>
            <Button
              variant="quiet"
              onClick={() => {
                setAdding(false)
                setName('')
              }}
            >
              {t('staff.devices.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        devices.length > 0 && (
          <div className="qr-staff-actions">
            <Button
              variant="secondary"
              onClick={() => {
                setAdding(true)
              }}
            >
              {t('staff.devices.add')}
            </Button>
          </div>
        )
      )}
    </section>
  )
}
