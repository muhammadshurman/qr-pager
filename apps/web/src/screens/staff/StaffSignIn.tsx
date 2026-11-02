import { Button, Field, StaffState } from '@qrpager/ui'
import { useEffect, useRef, useState, type SyntheticEvent } from 'react'

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

/**
 * Every stage this screen knows, exported so a test can be made to cover all of them.
 *
 * ⚠ EXPORTED BECAUSE FOUR OF THE SEVEN WERE RENDERED BY NO TEST — `§11` QA M-1. `working`,
 * `offline`, `unsupported` and `two-factor` existed only as members of an inline union, so
 * nothing could enumerate them and nothing did: the suite drove `idle`, `failed` and `choose`
 * and read as coverage of the screen. A union written out at its use site cannot be iterated;
 * `STAFF_STATES` in `@qrpager/ui` is the shape that can be, and this follows it.
 *
 * `staff-signin-stages.test.tsx` maps over this and asserts a non-empty render per stage, so a
 * stage added here without a case fails rather than ships dark.
 */
export const SIGN_IN_STAGES = [
  'idle',
  'working',
  'failed',
  'offline',
  'unsupported',
  'two-factor',
  'choose',
  /**
   * Too many tries — ADDED AT 10b, `R-224`, and its copy is NOT the board's.
   *
   * Muhammad's ruling: *"on the board nothing is required of the person and it resumes
   * itself; on sign-in they must wait before trying again."* So this stage keeps the form
   * usable and the sentence tells them to wait, where the board's `throttled` state offers
   * nothing to press at all. One sentence covering both would be wrong somewhere, which is
   * how `R-224` came to exist in the first place — one mapping serving two situations that
   * differ in exactly the way that matters.
   */
  'throttled',
] as const

export type SignInStage = (typeof SIGN_IN_STAGES)[number]

export interface StaffSignInProps {
  readonly t: Translate
  readonly stage: SignInStage
  readonly onPasskey: () => void
  readonly onPassword: (email: string, password: string) => void
  readonly onTwoFactor: (code: string) => void
  readonly memberships: readonly StaffMembership[]
  readonly onChoose: (tenantId: string) => void
}

export function StaffSignIn({
  t,
  stage,
  onPasskey,
  onPassword,
  onTwoFactor,
  memberships,
  onChoose,
}: StaffSignInProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  /*
   * ⚠ BOTH SUBMITS REFUSE TO FIRE TWICE, AND THE REFUSAL IS IN THE HANDLER AS WELL AS ON THE
   * BUTTON — `§11` SECURITY L-2 / QA M-8.
   *
   * The passkey button has always carried `loading={stage === 'working'}`, and `Button`
   * disables a loading button, so the PRIMARY action was guarded. Neither submit button was:
   * the password one passed no `loading` at all and the 2FA one passed the literal
   * `loading={false}`, which reads as a considered guard and is the vacuous form of one.
   *
   * What a second submit costs, measured rather than assumed:
   *
   *   - `auth-login` is a named limiter and `§11` round 1's own e2e run tripped it at 42
   *     logins, so each wasted attempt is budget a real person on a shared kitchen device
   *     then does not have;
   *   - two `POST /v1/auth/login` in flight against the same credential race to issue
   *     sessions, and the client keeps whichever resolves last;
   *   - a wrong 2FA code submitted twice counts twice against the server's own attempt
   *     ceiling, which is the one place a double-submit can lock a real person out.
   *
   * The handler guard is not redundant with the disabled button. A disabled default button
   * suppresses implicit submission in current browsers, but the form is also reachable by a
   * `requestSubmit()` from anywhere and by the Enter key through any user agent that treats
   * implicit submission differently; the state this guards is "a request is already in
   * flight", so it belongs where the request is made, not only where it is clicked.
   */
  const [inFlight, setInFlight] = useState(false)
  /*
   * ⚠ THE REF IS THE GUARD AND THE STATE IS THE BUTTON, for the reason
   * `StaffDevices.tsx` records at length: every handler in one tick reads the same render's
   * closure, so a state-only check lets a same-tick second submit straight through, and
   * `fireEvent`'s `act()` flush hides that from a component test.
   */
  const inFlightRef = useRef(false)

  /*
   * ⚠ THE IN-FLIGHT FLAG IS THIS COMPONENT'S OWN, BECAUSE `stage` CANNOT CARRY IT FOR THE 2FA
   * FORM. The first version of this fix passed `loading={stage === 'working'}` to both submit
   * buttons and would have been the SAME VACUOUS GUARD in a new costume:
   * `StaffApp.onTwoFactor` never sets `working` (it awaits `completeTwoFactor` and then sets
   * `failed` / `offline`, or unmounts the screen on success), and the code form renders only
   * while `stage === 'two-factor'` — so on that form `stage === 'working'` is false for the
   * whole round trip, every time. A guard that is structurally unreachable reads exactly like
   * one that works.
   *
   * It resets on every `stage` change, which is bounded and is the only signal a child gets
   * that the parent's attempt resolved: a password attempt moves `idle → working → failed`,
   * a code attempt moves `two-factor → failed | offline`, and a success unmounts the screen.
   */
  useEffect(() => {
    inFlightRef.current = false
    setInFlight(false)
  }, [stage])

  const working = stage === 'working' || inFlight
  const alreadySent = (): boolean => stage === 'working' || inFlightRef.current

  const submitPassword = (event: SyntheticEvent): void => {
    event.preventDefault()
    if (alreadySent()) return

    inFlightRef.current = true
    setInFlight(true)
    onPassword(email, password)
  }

  const submitCode = (event: SyntheticEvent): void => {
    event.preventDefault()
    if (alreadySent()) return

    inFlightRef.current = true
    setInFlight(true)
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
                {/*
                 * ⚠ FROM THE MEMBERSHIP ITSELF, AND A PROP THE APP NEVER FILLED IS WHY -- §11
                 * QA H-2. This read `restaurantNames[membership.tenantId] ?? t(roleKey(...))`,
                 * and the only production call site passed `{}` -- so the fallback was the
                 * PERMANENT state and two memberships of the same role rendered two identical
                 * buttons under "Which restaurant?". The test that looked like its coverage
                 * supplied the map itself, and its fallback case used ONE membership, so the
                 * ambiguity was never rendered anywhere.
                 */}
                {chooserLabel(membership, t)}
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

        {/*
          `R-224`. The `throttled` STATE from the kit, so there is no button here either —
          and that is correct for a different reason than the board's: a *"Try again"* inside
          this notice would be another 429, while the FORM BELOW stays usable so the person
          can try once they have waited. The sentence is the action, which is what `§12`
          means by *every error says what to do*.
        */}
        {stage === 'throttled' && (
          <StaffState
            state="throttled"
            title={t('staff.signin.throttled')}
            description={t('staff.signin.passkey')}
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
            <Button type="submit" loading={working} loadingLabel={t('staff.signin.working')}>
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
                <Button
                  type="submit"
                  variant="secondary"
                  loading={working}
                  loadingLabel={t('staff.signin.working')}
                  data-password-signin="true"
                >
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
 * What a restaurant's button says in the chooser.
 *
 * ⚠ **THE RESTAURANT'S NAME, SINCE 10b — `R-222`, ruled by Muhammad on 2026-11-02.** With
 * the role word beside it, so the person knows what they would be acting AS: two memberships
 * of the same restaurant cannot exist (`tenant_users` is unique on `(tenant_id, user_id)`),
 * so the name alone is already unique and the role is context rather than disambiguation.
 *
 * ================================================================================
 * THE TWO-STEP FALLBACK, AND BOTH STEPS HAVE BEEN THE WHOLE LABEL AT SOME POINT
 * ================================================================================
 *
 *   name  ->  slug  ->  the role word
 *
 * The history is worth keeping because each step was somebody's bug. The FIRST version
 * indexed a name map the app never filled, so every button fell back to the role word and
 * two owner memberships rendered two identical buttons under *"Which restaurant?"* — `§11`
 * QA H-2, and that actor is not hypothetical: it is `R-119`'s own residual. The fix carried
 * the **slug**, which is unique and readable and is a DNS label — `al-baik-jeddah · Owner` —
 * which `§12`'s zero-technical-background bar forbids on a screen. `R-222` carried the
 * proper label with the `§4` tension that made it a decision rather than a patch, and the
 * ruling is: publish the name, because `/v1/auth/me` returns only the caller's own
 * memberships so the disclosure is bounded.
 *
 * So the slug is now the MIDDLE rung rather than the label: it is still better than a role
 * word, and a payload that somehow carries one and not the other still renders something
 * unique. `StaffBoardEndpointsTest` pins `name` on the real endpoint and asserts it differs
 * from the slug, so this function cannot silently fall through to the old label.
 */
export function chooserLabel(membership: StaffMembership, t: Translate): string {
  const role = t(roleKey(membership.role))

  if (membership.name !== '') return `${membership.name} · ${role}`
  if (membership.slug !== '') return `${membership.slug} · ${role}`

  return role
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
