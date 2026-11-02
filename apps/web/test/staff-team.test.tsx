import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffTeam, roleWord } from '../src/screens/staff/StaffTeam.tsx'
import type { TeamMember } from '../src/staff/api.ts'

/**
 * ================================================================================
 * THE STAFF SCREEN — 10e (`QRPAGER-29`), AND `§12`'s HARD HALF IS A REFUSAL
 * ================================================================================
 *
 * `QRPAGER-29 §6`: *"The hard half here is the zero-technical-background bar on a REFUSAL:
 * 'you cannot give someone a role above your own' has to read as a sentence a restaurant
 * manager understands, in both launch languages, and a removal is destructive in effect so
 * `§12` requires it confirmed."*
 *
 * ⚠ **WHAT THIS FILE DOES NOT PROVE, SAID FIRST SO THE COVERAGE IS NOT OVERSTATED.** It proves
 * nothing about enforcement. `MemberSeniorityTest` drives all three invariant clauses over HTTP
 * and both guards are revert-proved there with a measured split. What is asserted here is the
 * SCREEN's half of `§12`: that it never offers an action the server refuses, that a refusal
 * reads as a sentence with a next action, and that a removal is confirmed.
 *
 * A screen that offered nothing would still not be a security control — `R-106` was found by
 * driving the route, not the screen.
 */

const t = translatorFor('en')
const ar = translatorFor('ar')

const OWN = '01a00000-0000-7000-8000-000000000001'

function member(overrides?: Partial<TeamMember>): TeamMember {
  return {
    id: '01a00000-0000-7000-8000-00000000000b',
    userId: '01a00000-0000-7000-8000-00000000000c',
    name: 'Hiba Mansour',
    email: 'hiba@example.test',
    role: 'staff',
    ...overrides,
  }
}

function mount(props?: Partial<React.ComponentProps<typeof StaffTeam>>) {
  const base: React.ComponentProps<typeof StaffTeam> = {
    t,
    phase: 'ready',
    members: [member()],
    ownMembershipId: OWN,
    grantableRoles: ['manager', 'staff'],
    notice: null,
    onRetry: vi.fn(),
    adding: false,
    busy: false,
    onOpenAdd: vi.fn(),
    onCancelAdd: vi.fn(),
    email: '',
    onEmail: vi.fn(),
    addRole: 'staff',
    onAddRole: vi.fn(),
    onAdd: vi.fn(),
    onChangeRole: vi.fn(),
    removing: null,
    onOpenRemove: vi.fn(),
    onConfirmRemove: vi.fn(),
    onCancelRemove: vi.fn(),
    ...props,
  }

  return { ...render(<StaffTeam {...base} />), props: base }
}

describe('the picker offers only what the server would accept', () => {
  it('offers a manager no OWNER button anywhere on the screen', () => {
    /*
     * ⚠ `§12`'s *a screen never offers an action the server refuses*, and the list comes from
     * the SERVER (`grantableRoles` on `/v1/auth/me`, derived from
     * `TenantRole::maySeniorityGrant()`). Computing it here would be a second implementation of
     * the ladder in TypeScript — the duplicated-source defect `§6.2` mechanises against.
     */
    mount({ grantableRoles: ['manager', 'staff'], members: [member({ role: 'staff' })] })

    expect(screen.queryByRole('button', { name: t('staff.role.owner') })).toBeNull()
    // And it DOES offer the one it may grant, so the absence above is about seniority rather
    // than about a screen with no controls.
    expect(screen.getByRole('button', { name: t('staff.role.manager') })).toBeTruthy()
  })

  it('offers an owner every role', () => {
    mount({ grantableRoles: ['owner', 'manager', 'staff'], members: [member({ role: 'staff' })] })

    expect(screen.getByRole('button', { name: t('staff.role.owner') })).toBeTruthy()
    expect(screen.getByRole('button', { name: t('staff.role.manager') })).toBeTruthy()
  })

  it('offers nothing at all when the server published an empty list', () => {
    // The safe direction. An empty list offers nothing rather than everything, which is what a
    // person reads as *ask a manager* -- and it is what happens when there is no active
    // membership to read a ceiling from.
    mount({ grantableRoles: [], members: [member()] })

    for (const role of ['owner', 'manager', 'staff'] as const) {
      expect(
        screen.queryByRole('button', { name: t(`staff.role.${role}`) }),
        `${role} was offered with no ceiling`,
      ).toBeNull()
    }
  })

  it('does not offer the role a member already holds', () => {
    // A button that would be a no-op is a button a person presses and learns nothing from.
    mount({ grantableRoles: ['manager', 'staff'], members: [member({ role: 'manager' })] })

    expect(screen.queryByRole('button', { name: t('staff.role.manager') })).toBeNull()
    expect(screen.getByRole('button', { name: t('staff.role.staff') })).toBeTruthy()
  })
})

describe('the caller’s own row', () => {
  it('is marked, and carries no controls at all', () => {
    /*
     * `§4 › Security`'s third limb: *no actor may … change its own role at all*. The server
     * refuses it with a 403, so offering a picker there would be a control that cannot work —
     * the row says *This is you* instead, which answers the question rather than raising it.
     */
    const you = member({ id: OWN, name: 'Omar Haddad', email: 'omar@example.test', role: 'owner' })

    mount({ members: [you], ownMembershipId: OWN, grantableRoles: ['owner', 'manager', 'staff'] })

    expect(screen.getByText(t('staff.team.you'))).toBeTruthy()

    const row = document.querySelector('[data-team-member="omar@example.test"]')

    expect(row).toBeTruthy()
    // No role buttons and no removal on that row.
    expect(row?.querySelectorAll('button')).toHaveLength(0)
  })

  it('marks no row when the caller’s membership id is unknown', () => {
    // Null marks nothing, which leaves a control the server refuses -- caught by the 403 and
    // its sentence. The alternative reading (mark every row) would hide controls on everybody
    // else's row and show them on the caller's, i.e. exactly backwards.
    mount({ members: [member()], ownMembershipId: null })

    expect(screen.queryByText(t('staff.team.you'))).toBeNull()
  })
})

describe('the refusals — §12’s hard half on this screen', () => {
  it('says what to do, in a sentence, for each of the three', () => {
    for (const [notice, key] of [
      ['seniority', 'staff.team.denied.seniority'],
      ['own-role', 'staff.team.denied.ownRole'],
      ['last-owner', 'staff.team.denied.lastOwner'],
    ] as const) {
      const view = mount({ notice })

      expect(screen.getByRole('alert').textContent).toBe(t(key))
      view.unmount()
    }
  })

  it('names no role, privilege, permission or seniority in any refusal, in EITHER language', () => {
    /*
     * ⚠ THE CLAUSE `QRPAGER-29 §6` ACTUALLY STATES, ASSERTED AS A PROPERTY OF THE COPY rather
     * than by reading it. *"'You cannot give someone a role above your own' has to read as a
     * sentence a restaurant manager understands, in both launch languages."*
     *
     * Both catalogues are scanned, because an Arabic translation that reached for «دور» or
     * «صلاحية» would satisfy every key-parity and non-empty check in `copy.test.ts` and still
     * fail this ticket's own bar.
     */
    const forbidden = {
      en: ['role', 'privilege', 'permission', 'senior', 'grant', 'escalat'],
      ar: ['دور', 'صلاحي', 'رتبة', 'امتياز'],
    }

    for (const key of [
      'staff.team.denied.seniority',
      'staff.team.denied.ownRole',
      'staff.team.denied.lastOwner',
    ] as const) {
      for (const word of forbidden.en) {
        expect(t(key).toLowerCase(), `EN ${key} uses "${word}"`).not.toContain(word)
      }

      for (const word of forbidden.ar) {
        expect(ar(key), `AR ${key} uses "${word}"`).not.toContain(word)
      }
    }
  })

  it('every refusal offers a next action in its own words', () => {
    // `§12`: every error says what to DO. There is no button for any of these -- the remedy is
    // somebody else -- so the sentence has to carry it.
    expect(t('staff.team.denied.seniority')).toContain('Ask an owner')
    expect(t('staff.team.denied.ownRole')).toContain('Ask somebody else')
    expect(t('staff.team.denied.lastOwner')).toContain('Make somebody else an owner first')
  })

  it('tells somebody with an unknown address what to do, without saying whether the account exists', () => {
    /*
     * ⚠ ONE MESSAGE, AND IT MUST STAY ONE. `MemberController::store()` answers 404 for an
     * address with no account AND for one this caller may not see, deliberately, so the global
     * `users` table is not an account-existence oracle. Two sentences here would undo on the
     * screen what the server is careful about.
     */
    mount({ notice: 'unknown-address' })

    const text = screen.getByRole('alert').textContent

    expect(text).toBe(t('staff.team.add.unknown'))
    // It does not assert the address is unknown -- it says what to do either way.
    expect(text.toLowerCase()).not.toContain('does not exist')
    expect(text.toLowerCase()).not.toContain('no account with')
    expect(text).toContain('ask them to sign up')
  })
})

describe('removing somebody', () => {
  it('confirms first, says what does and does not happen, and sends nothing until confirmed', () => {
    const target = member({ name: 'Hiba Mansour' })
    const { props } = mount({ removing: target })

    expect(screen.getByText(t('staff.team.remove.title', { name: 'Hiba Mansour' }))).toBeTruthy()

    const body = t('staff.team.remove.body')

    expect(screen.getByRole('alert').textContent).toBe(body)
    // The reassurance is the load-bearing half: *their account is deleted* is the thing a
    // manager would otherwise fear, and it is false.
    expect(body).toContain('account itself is not deleted')
    expect(body).toContain('add them back')

    expect(props.onConfirmRemove).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.remove.confirm') }))
    expect(props.onConfirmRemove).toHaveBeenCalledTimes(1)
  })

  it('names the person by their ADDRESS when they have no name', () => {
    // Both are things a manager recognises; a blank confirmation is not, and *"Remove ?"* is
    // the `§12` defect `interpolate()` leaves a visible `{name}` to prevent.
    const nameless = member({ name: '', email: 'nobody@example.test' })

    mount({ removing: nameless })

    expect(
      screen.getByText(t('staff.team.remove.title', { name: 'nobody@example.test' })),
    ).toBeTruthy()
  })

  it('offers a way out that sends nothing', () => {
    const { props } = mount({ removing: member() })

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.remove.keep') }))

    expect(props.onCancelRemove).toHaveBeenCalledTimes(1)
    expect(props.onConfirmRemove).not.toHaveBeenCalled()
  })
})

describe('the states', () => {
  it('shows a staff member a refusal with no retry button, because retrying changes nothing', () => {
    mount({ phase: 'permission-denied' })

    expect(screen.getByText(t('staff.team.denied.title'))).toBeTruthy()
    expect(screen.queryByRole('button', { name: t('staff.team.retry') })).toBeNull()
  })

  it('offers a next action on empty, offline and error', () => {
    for (const [phase, key] of [
      ['offline', 'staff.team.offline.title'],
      ['error', 'staff.team.error.title'],
    ] as const) {
      const view = mount({ phase })

      expect(screen.getByText(t(key))).toBeTruthy()
      expect(screen.getByRole('button', { name: t('staff.team.retry') })).toBeTruthy()
      view.unmount()
    }

    const empty = mount({ members: [] })

    expect(screen.getByText(t('staff.team.empty.title'))).toBeTruthy()

    /*
     * ⚠ EXACTLY ONE *Add somebody* BUTTON, AND THIS ASSERTION IS WHY THE SCREEN CHANGED.
     * `staff.team.empty.action` and `staff.team.add` are the same words, and both controls
     * rendered — two identical primary buttons, which a person cannot tell apart and which
     * `getAllByRole` is the only way to notice. The empty state now carries the action alone.
     */
    expect(screen.getAllByRole('button', { name: t('staff.team.empty.action') })).toHaveLength(1)
    expect(document.querySelector('[data-team-action="open-add"]')).toBeNull()
    empty.unmount()
  })

  it('renders no uuid anywhere', () => {
    const { container } = mount({ members: [member()] })

    expect(container.innerHTML).not.toContain('01a00000-0000-7000-8000-00000000000b')
    expect(container.innerHTML).not.toContain('01a00000-0000-7000-8000-00000000000c')
  })

  it('renders the translated role WORD in the row, never the raw enum value', () => {
    /*
     * ⚠ ASSERTED ON THE ELEMENT THAT CARRIES IT, NOT ON THE WHOLE DOCUMENT's TEXT. The first
     * version read `container.textContent).not.toContain('staff"')`, which is nonsense — a
     * `textContent` has no quotes in it, so the assertion could not fail. And a scan for a bare
     * `staff` over the whole screen would be wrong in the other direction: the screen's own
     * TITLE is *Staff*, and copy may legitimately contain the word.
     *
     * The property is narrow and local: the row's role element shows the translated word. So it
     * is read off that element.
     */
    mount({ members: [member({ role: 'staff' })], grantableRoles: [] })

    const word = document.querySelector('[data-team-role-word="true"]')

    expect(word?.textContent).toBe(t('staff.role.staff'))
    // And it is not the enum value, which is what `roleWord`'s empty fallback protects against
    // for a role this catalogue has no word for.
    expect(word?.textContent).not.toBe('staff')
  })
})

describe('roleWord', () => {
  it('translates each role the enum mirror declares', () => {
    expect(roleWord(t, 'owner')).toBe(t('staff.role.owner'))
    expect(roleWord(t, 'manager')).toBe(t('staff.role.manager'))
    expect(roleWord(t, 'staff')).toBe(t('staff.role.staff'))
  })

  it('answers EMPTY for a role it has no word for, never the raw value', () => {
    /*
     * ⚠ THE FALLBACK DIRECTION IS THE WHOLE POINT. A role added in PHP that this catalogue has
     * no word for must not render as `regional_manager` on a screen — `§12`'s
     * no-unexplained-identifier clause. An empty label is a row that looks incomplete, which
     * is a bug report; a lower-case enum value beside two translated words is a bug nobody
     * reports.
     */
    expect(roleWord(t, 'regional_manager')).toBe('')
    expect(roleWord(t, '')).toBe('')
  })
})
