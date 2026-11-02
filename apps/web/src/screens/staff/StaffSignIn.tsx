import { Button, Field, StaffState } from '@qrpager/ui'
import { useState, type SyntheticEvent } from 'react'

import type { Translate } from '../../copy/index.ts'
import type { StaffMembership } from '../../staff/api.ts'

/**
 * ================================================================================
 * S1 — STAFF SIGN-IN. Passkey first, password second, and nothing else on the screen.
 * ================================================================================
 *
 * `QRPAGER-25 §12` S1. The passkey button is the primary action and the password form is
 * behind a disclosure, because a shared kitchen device is the worst place to type a password
 * and the best place to tap a key.
 *
 * ⚠ THE RESTAURANT'S NAME IS NOT ON THIS SCREEN AND CANNOT BE. No tenant is resolved before
 * a credential is — `RequireTenantScope` derives it from the caller's own memberships — so
 * naming a restaurant here would mean either accepting one from the URL (a caller-supplied
 * tenant, which `PagerIdorTest` drives five shapes of and refuses) or disclosing which
 * restaurant a device was last used for, on a screen anybody can walk up to.
 *
 * ⚠ ONE FAILURE MESSAGE FOR EVERY CAUSE. Wrong password, no such account, a passkey the
 * server refused, a 2FA code that did not match: all one string. Distinguishing them lets
 * the form answer *"does this address have an account here"*, which is the oracle `§4 ›
 * Security`'s single-message refusal rule exists to close — and that rule is already how the
 * server answers, so a client that split them would re-open it from this side.
 */

export type SignInStage =
  'idle' | 'working' | 'failed' | 'offline' | 'unsupported' | 'two-factor' | 'choose'

export interface StaffSignInProps {
  readonly t: Translate
  readonly stage: SignInStage
  readonly onPasskey: () => void
  readonly onPassword: (email: string, password: string) => void
  readonly onTwoFactor: (code: string) => void
  readonly memberships: readonly StaffMembership[]
  /** The restaurant names, by tenant id. Empty while they are still being read. */
  readonly restaurantNames: Readonly<Record<string, string>>
  readonly onChoose: (tenantId: string) => void
}

export function StaffSignIn({
  t,
  stage,
  onPasskey,
  onPassword,
  onTwoFactor,
  memberships,
  restaurantNames,
  onChoose,
}: StaffSignInProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const submitPassword = (event: SyntheticEvent): void => {
    event.preventDefault()
    onPassword(email, password)
  }

  const submitCode = (event: SyntheticEvent): void => {
    event.preventDefault()
    onTwoFactor(code)
  }

  if (stage === 'choose') {
    return (
      <div className="qr-staff-signin">
        <div className="qr-staff-signin__surface">
          <h1 className="qr-staff-signin__title">{t('staff.signin.choose')}</h1>
          <div className="qr-staff-actions">
            {memberships.map((membership) => (
              <Button
                key={membership.tenantId}
                variant="secondary"
                onClick={() => {
                  onChoose(membership.tenantId)
                }}
              >
                {/* The NAME when it is known. While the names are still loading the button
                    would otherwise have to show a uuid, which §12 forbids -- so it shows the
                    role word instead, which is true, readable and not an identifier. */}
                {restaurantNames[membership.tenantId] ?? t(roleKey(membership.role))}
              </Button>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="qr-staff-signin">
      <div className="qr-staff-signin__surface">
        <h1 className="qr-staff-signin__title">{t('staff.signin.title')}</h1>

        {stage === 'failed' && (
          <StaffState
            state="error"
            title={t('staff.signin.failed')}
            description={t('staff.shell.error.body')}
            action={{ label: t('staff.signin.passkey'), onAction: onPasskey }}
          />
        )}

        {stage === 'offline' && (
          <StaffState
            state="offline"
            title={t('staff.signin.offline')}
            description={t('staff.shell.offline.body')}
            action={{ label: t('staff.shell.error.retry'), onAction: onPasskey }}
          />
        )}

        {stage === 'unsupported' && (
          <StaffState
            state="permission-denied"
            title={t('staff.signin.unsupported')}
            description={t('staff.signin.password')}
          />
        )}

        {stage === 'two-factor' ? (
          <form className="qr-staff-form" onSubmit={submitCode}>
            <Field
              label={t('staff.signin.code')}
              hint={t('staff.signin.twoFactor')}
              name="code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              value={code}
              onChange={(event) => {
                setCode(event.target.value)
              }}
              required
            />
            <Button type="submit" loading={false}>
              {t('staff.signin.submit')}
            </Button>
          </form>
        ) : (
          <>
            {/* THE PRIMARY ACTION, AND IT IS FIRST IN THE DOM as well as first visually --
                a keyboard user reaches it first for the same reason a touch user sees it
                first, and the two orders agreeing is what `§12`'s keyboard clause means in
                practice. */}
            <Button
              variant="primary"
              onClick={onPasskey}
              loading={stage === 'working'}
              loadingLabel={t('staff.signin.working')}
              disabled={stage === 'unsupported'}
              data-passkey-signin="true"
            >
              {t('staff.signin.passkey')}
            </Button>

            {showPassword || stage === 'unsupported' ? (
              <form className="qr-staff-form" onSubmit={submitPassword}>
                <Field
                  label={t('staff.signin.email')}
                  name="email"
                  type="email"
                  autoComplete="username"
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value)
                  }}
                  required
                />
                <Field
                  label={t('staff.signin.passwordLabel')}
                  name="password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(event) => {
                    setPassword(event.target.value)
                  }}
                  required
                />
                <Button type="submit" variant="secondary" data-password-signin="true">
                  {t('staff.signin.submit')}
                </Button>
              </form>
            ) : (
              <Button
                variant="quiet"
                onClick={() => {
                  setShowPassword(true)
                }}
              >
                {t('staff.signin.password')}
              </Button>
            )}
          </>
        )}
      </div>
    </div>
  )
}

/**
 * A `tenant_role` value → the copy key for the word a person reads.
 *
 * ⚠ IT IS TOTAL BY CONSTRUCTION AND FALLS BACK TO `staff`, which is the LEAST privileged
 * word rather than the most. A role the client does not recognise is a role added on the
 * server after this build shipped; describing it as "Owner" would overstate what the person
 * can do, and `§12` would rather show a cautious true-ish word than an enum case.
 */
export function roleKey(
  role: string,
): 'staff.role.owner' | 'staff.role.manager' | 'staff.role.staff' {
  if (role === 'owner') return 'staff.role.owner'
  if (role === 'manager') return 'staff.role.manager'

  return 'staff.role.staff'
}
