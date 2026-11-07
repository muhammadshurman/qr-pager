import { Button, Field, StaffState } from '@qrpager/ui'

import type { CopyKey, Translate } from '../../copy/index.ts'
import type { TeamMember } from '../../staff/api.ts'

/**
 * ================================================================================
 * S10 — THE RESTAURANT's STAFF. 10e (`QRPAGER-29`).
 * ================================================================================
 *
 * `§4.2`'s *Staff management*, added to that inventory by Muhammad's `R-218` ruling, and the
 * last screen of Task 10.
 *
 * `QRPAGER-29 §6` names the hard half, and it is not the accessibility one:
 *
 *   *"The hard half here is the zero-technical-background bar on a REFUSAL: 'you cannot give
 *   someone a role above your own' has to read as a sentence a restaurant manager understands,
 *   in both launch languages, and a removal is destructive in effect so `§12` requires it
 *   confirmed."*
 *
 * So `staff.team.denied.seniority` is *"You cannot give somebody more than you have yourself.
 * Ask an owner to do it."* — no *role*, no *privilege*, no *senior*, and a next action.
 *
 * ================================================================================
 * THE PICKER OFFERS ONLY WHAT THE SERVER WOULD ACCEPT, AND THAT LIST IS NOT COMPUTED HERE
 * ================================================================================
 *
 * `grantableRoles` arrives on `/v1/auth/me`, derived from `TenantRole::maySeniorityGrant()`.
 * Computing it in TypeScript would be a second implementation of the ladder — the
 * duplicated-source defect `§6.2` mechanises against for the status enum — and the two would
 * drift the first time the ladder changed.
 *
 * ⚠ **AND THE PICKER IS NOT THE ENFORCEMENT.** `MemberDirectory` checks on every write,
 * reading the actor's role from the database inside the tenant's scope. `R-106` was found by
 * driving the route, not the screen, and `MemberSeniorityTest` drives all three clauses over
 * HTTP. A screen that offered nothing would still not be a security control.
 *
 * ================================================================================
 * WHAT IS DELIBERATELY ABSENT
 * ================================================================================
 *
 * **No invite.** `QRPAGER-29 §2` puts it out of scope and says why: minting a credential and
 * delivering mail is `R-119` + `R-124`, *"and they are a product decision first"* — what an
 * invitation says and how long it lives. So this screen adds somebody who **already has an
 * account**, and `staff.team.add.email.hint` says so rather than leaving a manager to discover
 * it from a refusal.
 *
 * **No *change my own role* control on the person's own row.** It is refused by the server
 * (`§4 › Security`'s third limb) so offering it would be a control that cannot work — the row
 * is marked *This is you* instead, which answers the question the control would have raised.
 */

export type TeamPhase = 'loading' | 'ready' | 'error' | 'offline' | 'permission-denied'

/** What the screen is saying about the last action, each one a real server answer. */
export type TeamNotice =
  'seniority' | 'own-role' | 'last-owner' | 'unknown-address' | 'invalid-address' | 'failed'

export interface StaffTeamProps {
  readonly t: Translate
  readonly phase: TeamPhase
  readonly members: readonly TeamMember[]
  /** The caller's own membership id, so their row can be marked and not offered a role picker. */
  readonly ownMembershipId: string | null
  /** The roles this caller may grant, from the server. Empty offers nothing. */
  readonly grantableRoles: readonly string[]
  readonly notice: TeamNotice | null
  readonly onRetry: () => void
  readonly adding: boolean
  readonly busy: boolean
  readonly onOpenAdd: () => void
  readonly onCancelAdd: () => void
  readonly email: string
  readonly onEmail: (value: string) => void
  readonly addRole: string
  readonly onAddRole: (role: string) => void
  readonly onAdd: () => void
  readonly onChangeRole: (member: TeamMember, role: string) => void
  /** The member a removal is being confirmed for, or null. */
  readonly removing: TeamMember | null
  readonly onOpenRemove: (member: TeamMember) => void
  readonly onConfirmRemove: () => void
  readonly onCancelRemove: () => void
}

const ROLE_COPY: Readonly<Record<string, CopyKey>> = {
  owner: 'staff.role.owner',
  manager: 'staff.role.manager',
  staff: 'staff.role.staff',
}

const NOTICE_COPY: Readonly<Record<TeamNotice, CopyKey>> = {
  seniority: 'staff.team.denied.seniority',
  'own-role': 'staff.team.denied.ownRole',
  'last-owner': 'staff.team.denied.lastOwner',
  'unknown-address': 'staff.team.add.unknown',
  'invalid-address': 'staff.team.add.invalid',
  failed: 'staff.team.failed',
}

/**
 * The word for a role.
 *
 * ⚠ FALLS BACK TO NOTHING RATHER THAN TO THE ENUM VALUE. `tenant_role` carries
 * `owner`/`manager`/`staff`, and a role PHP adds that this catalogue has no word for must not
 * render as its raw value on a screen — `§12`'s no-unexplained-identifier clause. An empty
 * label is a row that looks incomplete, which is a bug report; `owner` in lower case beside
 * two translated words is a bug nobody reports.
 */
export function roleWord(t: Translate, role: string): string {
  const key = ROLE_COPY[role]

  return key === undefined ? '' : t(key)
}

export function StaffTeam({
  t,
  phase,
  members,
  ownMembershipId,
  grantableRoles,
  notice,
  onRetry,
  adding,
  busy,
  onOpenAdd,
  onCancelAdd,
  email,
  onEmail,
  addRole,
  onAddRole,
  onAdd,
  onChangeRole,
  removing,
  onOpenRemove,
  onConfirmRemove,
  onCancelRemove,
}: StaffTeamProps) {
  if (phase === 'loading') {
    return <StaffState state="loading" loadingLabel={t('staff.team.title')} />
  }

  if (phase === 'permission-denied') {
    // `members:read` is held by owner and manager and by neither staff nor a device token, so
    // a staff member reaching this screen is the normal case rather than an error. No action,
    // because retrying changes nothing -- the description carries the next step in words,
    // which is what `StaffState`'s `permission-denied` case requires.
    return (
      <StaffState
        state="permission-denied"
        title={t('staff.team.denied.title')}
        description={t('staff.team.denied.body')}
      />
    )
  }

  if (phase !== 'ready') {
    return (
      <StaffState
        state={phase}
        title={t(phase === 'offline' ? 'staff.team.offline.title' : 'staff.team.error.title')}
        description={t(phase === 'offline' ? 'staff.team.offline.body' : 'staff.team.error.body')}
        action={{ label: t('staff.team.retry'), onAction: onRetry }}
      />
    )
  }

  /* The removal confirmation replaces the screen, for `StaffIssue`'s reason: while it is up it
     should be the only actionable thing. */
  if (removing !== null) {
    const who = removing.name === '' ? removing.email : removing.name

    return (
      <section
        className="qr-staff-form"
        aria-label={t('staff.team.remove.title', { name: who })}
        data-team="confirm-remove"
      >
        <h1 className="qr-staff-state__title">{t('staff.team.remove.title', { name: who })}</h1>
        {/* Destructive in effect, so `§12` wants it confirmed — and the body says what happens
            to the PERSON and what does not, because *their account is deleted* is the thing a
            manager would otherwise fear. */}
        <p className="qr-staff-warning" role="alert">
          {t('staff.team.remove.body')}
        </p>
        <div className="qr-staff-actions">
          <Button
            variant="danger"
            disabled={busy}
            data-team-action="confirm-remove"
            onClick={onConfirmRemove}
          >
            {t('staff.team.remove.confirm')}
          </Button>
          <Button variant="quiet" data-team-action="keep" onClick={onCancelRemove}>
            {t('staff.team.remove.keep')}
          </Button>
        </div>
      </section>
    )
  }

  return (
    <section aria-label={t('staff.team.title')} data-team="true">
      <h1 className="qr-staff-state__title">{t('staff.team.title')}</h1>
      <p className="qr-staff-state__description">{t('staff.team.lede')}</p>

      {notice !== null && (
        <p className="qr-staff-warning" role="alert" data-team-notice={notice}>
          {t(NOTICE_COPY[notice])}
        </p>
      )}

      {adding ? (
        <form
          className="qr-staff-form"
          data-team-add="true"
          onSubmit={(event) => {
            event.preventDefault()
            onAdd()
          }}
        >
          <p className="qr-staff-list__name">{t('staff.team.add.title')}</p>

          <Field
            label={t('staff.team.add.email')}
            hint={t('staff.team.add.email.hint')}
            type="email"
            value={email}
            data-team-email="true"
            autoComplete="off"
            onChange={(event) => {
              onEmail(event.target.value)
            }}
          />

          {/*
            ⚠ A `fieldset` WITH A `legend` AND RADIO BUTTONS, NOT A `<select>`. Three options on
            a wall-mounted or hand-held tablet are three 44px targets; a native select is one
            target that opens an OS picker whose options this app cannot size, which is the one
            place `§12`'s target-size clause cannot be met by our own CSS.
          */}
          <fieldset className="qr-staff-filter__group">
            <legend className="qr-field__label">{t('staff.team.add.role')}</legend>
            {grantableRoles.map((role) => (
              <label className="qr-staff-filter__toggle" key={role}>
                <input
                  type="radio"
                  name="team-add-role"
                  value={role}
                  checked={addRole === role}
                  data-team-role={role}
                  onChange={() => {
                    onAddRole(role)
                  }}
                />
                <span>{roleWord(t, role)}</span>
              </label>
            ))}
          </fieldset>

          <div className="qr-staff-actions">
            <Button
              variant="primary"
              type="submit"
              disabled={busy || email.trim() === '' || addRole === ''}
            >
              {busy ? t('staff.team.add.working') : t('staff.team.add.submit')}
            </Button>
            <Button variant="quiet" data-team-action="cancel-add" onClick={onCancelAdd}>
              {t('staff.team.add.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        /*
         * ⚠ THE TOP *Add somebody* BUTTON IS WITHHELD WHEN THE LIST IS EMPTY, AND A TEST FOUND
         * THE REASON RATHER THAN A REVIEWER.
         *
         * `staff.team.empty.action` and `staff.team.add` are the same words — *Add somebody* —
         * because in both places that is the honest label. With no members, BOTH rendered, so
         * the screen had two identical primary buttons doing the same thing;
         * `staff-team.test.tsx`'s own case reported *"found multiple elements"* for that name.
         *
         * Two identical controls is a `§12` defect rather than a test inconvenience: a person
         * cannot tell why there are two, and one of them looks like it must do something else.
         * With an empty list the EMPTY STATE is the call to action — which is what `§12` means
         * by *no empty state without a next action* — so the duplicate goes.
         */
        members.length > 0 && (
          <div className="qr-staff-actions">
            <Button variant="primary" data-team-action="open-add" onClick={onOpenAdd}>
              {t('staff.team.add')}
            </Button>
          </div>
        )
      )}

      {members.length === 0 ? (
        <StaffState
          state="empty"
          title={t('staff.team.empty.title')}
          description={t('staff.team.empty.body')}
          action={{ label: t('staff.team.empty.action'), onAction: onOpenAdd }}
        />
      ) : (
        <ul className="qr-staff-list">
          {members.map((member) => {
            const isYou = ownMembershipId !== null && member.id === ownMembershipId
            // A member with no name is rendered by their address: both are things a manager
            // recognises, and a blank row is not.
            const who = member.name === '' ? member.email : member.name

            return (
              <li
                className="qr-staff-list__item"
                key={member.id}
                // The EMAIL, not the membership uuid -- it is already on the screen, so it is
                // not an unexplained identifier, and a test can target a row by it.
                data-team-member={member.email}
                data-team-member-role={member.role}
              >
                <div>
                  <p className="qr-staff-list__name">{who}</p>
                  <div className="qr-staff-list__meta">
                    <span data-team-role-word="true">{roleWord(t, member.role)}</span>
                    {/* The email is shown beside the name when both exist, because two
                        people called Ahmed is the ordinary case in a restaurant. */}
                    {member.name !== '' && <span>{member.email}</span>}
                    {isYou && <span data-team-you="true">{t('staff.team.you')}</span>}
                  </div>
                </div>

                {/*
                  ⚠ NO CONTROLS ON THE CALLER'S OWN ROW, AND THAT IS THE SERVER's RULE RENDERED.
                  `§4 › Security`: *no actor may … change its own role at all*. Offering a
                  picker there would be a control the server refuses with a 403; the row says
                  *This is you* instead, which answers the question rather than raising it.
                  Removal is also withheld: leaving a restaurant is not what this screen is for,
                  and the last-owner rule makes it refusable in a way a manager cannot predict.
                */}
                {!isYou && (
                  <div className="qr-staff-actions">
                    {grantableRoles
                      .filter((role) => role !== member.role)
                      .map((role) => (
                        <Button
                          key={role}
                          variant="secondary"
                          disabled={busy}
                          data-team-action={`set-${role}`}
                          onClick={() => {
                            onChangeRole(member, role)
                          }}
                        >
                          {busy ? t('staff.team.role.working') : roleWord(t, role)}
                        </Button>
                      ))}
                    <Button
                      variant="quiet"
                      data-team-action="open-remove"
                      onClick={() => {
                        onOpenRemove(member)
                      }}
                    >
                      {t('staff.team.remove', { name: who })}
                    </Button>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
