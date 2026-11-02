import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { StaffApp } from '../src/StaffApp.tsx'
import { translatorFor } from '../src/copy/index.ts'

/**
 * ================================================================================
 * THE ORCHESTRATOR'S 10d AND 10e FLOWS — every handler, and every way each can fail
 * ================================================================================
 *
 * `staff-flows.test.tsx`'s own header records why this shape exists, and it is worth repeating
 * because this file is the same lesson applied to the same file three tickets later:
 *
 *   *"`§11` QA H-1: `StaffApp.tsx` sat at 55.72% statements while the suite read as green, and
 *   the uncovered half was not an edge — it was every credential path and every device handler.
 *   … A flow with no test is a flow whose first run is in a restaurant."*
 *
 * 10d and 10e added seven handlers to that file (`loadHistory`, `onCreateSheet`,
 * `onConfirmReissue`, `loadTeam`, `onAddMember`, `onChangeMemberRole`, `onConfirmRemoveMember`)
 * and three views. The screens themselves have component tests; what those cannot see is the
 * WIRING — which answer maps to which sentence, what is refetched after a write, and which
 * state survives a navigation.
 *
 * Everything goes through the real `src/staff/api.ts` against a scripted `fetch`. The parsers
 * are the half that got `/v1/auth/me`'s `user` nesting wrong at 10a, and mocking them would
 * mock away that bug class.
 */

const t = translatorFor('en')

const RESTAURANT = 'Al Baik Jeddah'
const TENANT = '01a199c4-7f3e-7000-8000-00000000000a'
const USER = '01a199c4-7f3e-7000-8000-ffffffffffff'
const OWN_MEMBERSHIP = '01a199c4-7f3e-7000-8000-00000000m001'.replace('m', 'a')
const OTHER_MEMBERSHIP = '01a199c4-7f3e-7000-8000-00000000m002'.replace('m', 'a')
const SESSION = '01a199c4-7f3e-7000-8000-00000000s001'.replace('s', 'a')

type Answer = { readonly status: number; readonly body?: unknown } | 'unreachable'

function identity(grantable: readonly string[] = ['owner', 'manager', 'staff']) {
  return {
    user: { id: USER, name: 'Hiba Mansour', email: 'hiba@example.test' },
    memberships: [
      {
        tenant_id: TENANT,
        role: 'owner',
        slug: 'al-baik-jeddah',
        name: RESTAURANT,
        grantable_roles: grantable,
      },
    ],
    active_tenant: TENANT,
  }
}

const HISTORY_ROW = {
  id: SESSION,
  order_reference: 'A-128',
  status: 'waiting',
  created_at: '2026-11-02T10:00:00Z',
  ready_at: null,
  completed_at: null,
}

const SHEET = {
  session: { id: SESSION, order_reference: 'A-128', status: 'waiting', changed: true },
  qr_payload: 'https://qrpager.dev/s#0123456789abcdef0123456789abcdef',
  qr_svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect/></svg>',
}

const TEAM = {
  members: [
    {
      id: OWN_MEMBERSHIP,
      user_id: USER,
      name: 'Hiba Mansour',
      email: 'hiba@example.test',
      role: 'owner',
    },
    {
      id: OTHER_MEMBERSHIP,
      user_id: 'u-2',
      name: 'Omar Haddad',
      email: 'omar@example.test',
      role: 'staff',
    },
  ],
}

const SIGNED_IN: Record<string, Answer | readonly Answer[]> = {
  'GET /v1/auth/me': { status: 200, body: identity() },
  'GET /v1/tenant': {
    status: 200,
    body: { tenant: { name: RESTAURANT, timezone: 'Asia/Riyadh' } },
  },
  // The board polls on `/staff`; an empty board keeps a case about something else from also
  // being a case about the board.
  'GET /v1/pager-sessions': { status: 200, body: { sessions: [] } },
  'POST /v1/auth/logout': { status: 204 },
}

/** A scripted stack. A LIST is consumed one answer per call and sticks on the last. */
function stack(routes: Record<string, Answer | readonly Answer[]>) {
  const seen: { key: string; body: unknown }[] = []
  const queues = new Map<string, Answer[]>()

  for (const [key, value] of Object.entries(routes)) {
    queues.set(key, Array.isArray(value) ? [...(value as Answer[])] : [value as Answer])
  }

  const answerFor = (key: string): Answer | undefined => {
    const exact = queues.get(key)

    if (exact !== undefined) return exact.length === 1 ? exact[0] : exact.shift()

    for (const [pattern, queue] of queues) {
      if (!pattern.endsWith('/*')) continue
      if (!key.startsWith(pattern.slice(0, -1))) continue

      return queue.length === 1 ? queue[0] : queue.shift()
    }

    return undefined
  }

  const fetchMock = vi.fn((input: unknown, init?: RequestInit) => {
    const path = String(input).replace(/^https?:\/\/[^/]+/, '')
    const key = `${init?.method ?? 'GET'} ${path}`
    const raw = init?.body

    seen.push({ key, body: typeof raw === 'string' ? JSON.parse(raw) : null })

    const answer = answerFor(key)

    // ⚠ A THROW AND NOT A DEFAULT. An unscripted call is a call the test did not mean to make,
    // and answering it with a 200 would hide a request nobody asked for.
    if (answer === undefined) throw new Error(`the test did not script ${key}`)
    if (answer === 'unreachable') return Promise.reject(new TypeError('Failed to fetch'))

    return Promise.resolve({
      status: answer.status,
      ok: answer.status >= 200 && answer.status < 300,
      json: () => Promise.resolve(answer.body ?? {}),
      headers: { get: () => null },
    } as unknown as Response)
  })

  vi.stubGlobal('fetch', fetchMock)

  return { seen, calls: (key: string) => seen.filter((call) => call.key === key) }
}

afterEach(() => {
  vi.unstubAllGlobals()
})

/** Render on one view and wait for the shell to resolve. */
async function open(view: 'history' | 'issue' | 'team', onNavigate = vi.fn()) {
  render(<StaffApp apiBase="" view={view} locale="en" onNavigate={onNavigate} />)

  await screen.findByText(RESTAURANT)

  return { onNavigate }
}

/* ------------------------------------------------------------------ 10d: past orders */

describe('the history view', () => {
  it('loads unfiltered on arrival and renders the rows', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [HISTORY_ROW] } },
    })

    await open('history')

    expect(await screen.findByText(t('staff.board.order', { reference: 'A-128' }))).toBeTruthy()
    // No query string on the first load, which is the `(no filters)` combination the PHP sweep
    // drives first.
    expect(scripted.calls('GET /v1/pager-sessions/history')).toHaveLength(1)
  })

  it('sends the draft filters when they are applied, in the shape the FormRequest validates', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [HISTORY_ROW] } },
      'GET /v1/pager-sessions/history?status%5B%5D=waiting': {
        status: 200,
        body: { sessions: [HISTORY_ROW] },
      },
    })

    await open('history')
    await screen.findByText(t('staff.board.order', { reference: 'A-128' }))

    fireEvent.click(document.querySelector('[data-history-status="waiting"]')!)
    fireEvent.submit(document.querySelector('[data-history-filters="true"]')!)

    await waitFor(() => {
      expect(scripted.calls('GET /v1/pager-sessions/history?status%5B%5D=waiting')).toHaveLength(1)
    })
  })

  it('shows the FILTERED empty state after a filtered search, and the other one before', async () => {
    /*
     * The two empty states depend on the APPLIED set, so this drives the transition: unfiltered
     * and empty says *no orders yet*; filtered and empty says *nothing matches*. A single state
     * would be wrong in one of the two, and wrong in the worse direction.
     */
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [] } },
      'GET /v1/pager-sessions/history?reference=ZZZ': { status: 200, body: { sessions: [] } },
    })

    await open('history')

    expect(await screen.findByText(t('staff.history.none.title'))).toBeTruthy()

    fireEvent.change(document.querySelector('[data-history-reference="true"]')!, {
      target: { value: 'ZZZ' },
    })
    fireEvent.submit(document.querySelector('[data-history-filters="true"]')!)

    expect(await screen.findByText(t('staff.history.empty.title'))).toBeTruthy()
    expect(scripted.calls('GET /v1/pager-sessions/history?reference=ZZZ')).toHaveLength(1)
  })

  it('maps a dead network to offline and a 5xx to error, each with a next action', async () => {
    for (const [answer, key] of [
      ['unreachable', 'staff.history.offline.title'],
      [{ status: 503 }, 'staff.history.error.title'],
    ] as const) {
      stack({ ...SIGNED_IN, 'GET /v1/pager-sessions/history': answer })

      const view = render(<StaffApp apiBase="" view="history" locale="en" />)

      expect(await screen.findByText(t(key))).toBeTruthy()
      expect(screen.getByRole('button', { name: t('staff.history.retry') })).toBeTruthy()

      view.unmount()
      vi.unstubAllGlobals()
    }
  })

  it('maps a 403 to the refusal, which has no retry because retrying changes nothing', async () => {
    stack({ ...SIGNED_IN, 'GET /v1/pager-sessions/history': { status: 403 } })

    render(<StaffApp apiBase="" view="history" locale="en" />)

    expect(await screen.findByText(t('staff.team.denied.title'))).toBeTruthy()
    expect(screen.queryByRole('button', { name: t('staff.history.retry') })).toBeNull()
  })

  it('signs out on a 401, because that is the normal end of a shift', async () => {
    stack({ ...SIGNED_IN, 'GET /v1/pager-sessions/history': { status: 401 } })

    render(<StaffApp apiBase="" view="history" locale="en" />)

    expect(await screen.findByText(t('staff.signin.title'))).toBeTruthy()
  })
})

/* ------------------------------------------------------------- 10d: issuing a sheet */

describe('the issue view', () => {
  it('creates a sheet and shows the server’s own QR', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'POST /v1/pager-sessions': { status: 201, body: SHEET },
    })

    await open('issue')

    fireEvent.change(document.querySelector('[data-issue-reference="true"]')!, {
      target: { value: 'A-128' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.create') }))

    expect(await screen.findByText(t('staff.issue.sheet.title'))).toBeTruthy()
    expect(document.querySelector('[data-issue-code="true"] svg')).toBeTruthy()
    // The reference is trimmed and sent as the body's one field.
    expect(scripted.calls('POST /v1/pager-sessions')[0]?.body).toEqual({
      order_reference: 'A-128',
    })
  })

  it('clears the field after a success, so two orders cannot share one number', async () => {
    stack({ ...SIGNED_IN, 'POST /v1/pager-sessions': { status: 201, body: SHEET } })

    await open('issue')

    fireEvent.change(document.querySelector('[data-issue-reference="true"]')!, {
      target: { value: 'A-128' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.create') }))
    await screen.findByText(t('staff.issue.sheet.title'))

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.sheet.done') }))

    expect(document.querySelector('[data-issue-reference="true"]')!.value).toBe('')
  })

  it('maps a 422 to the character-class sentence rather than an error state', async () => {
    stack({ ...SIGNED_IN, 'POST /v1/pager-sessions': { status: 422 } })

    await open('issue')

    fireEvent.change(document.querySelector('[data-issue-reference="true"]')!, {
      target: { value: 'A/128' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.create') }))

    expect(await screen.findByText(t('staff.issue.reference.invalid'))).toBeTruthy()
  })

  it('maps a dead network and a 5xx to their own sentences', async () => {
    for (const [answer, key] of [
      ['unreachable', 'staff.issue.offline.title'],
      [{ status: 503 }, 'staff.issue.error.title'],
    ] as const) {
      stack({ ...SIGNED_IN, 'POST /v1/pager-sessions': answer })

      const view = render(<StaffApp apiBase="" view="issue" locale="en" />)

      await screen.findByText(RESTAURANT)
      fireEvent.change(document.querySelector('[data-issue-reference="true"]')!, {
        target: { value: 'A-1' },
      })
      fireEvent.click(screen.getByRole('button', { name: t('staff.issue.create') }))

      expect(await screen.findByText(t(key))).toBeTruthy()

      view.unmount()
      vi.unstubAllGlobals()
    }
  })
})

/* ------------------------------------------------- 10d: the lost-sheet path, end to end */

describe('re-issuing a sheet from the history screen', () => {
  it('carries the order to the issue view, confirms, then shows the NEW sheet', async () => {
    /*
     * ⚠ THE WHOLE PATH `staff.issue.sheet.once` PROMISES: *"open the order from Past orders and
     * make a new one — the old sheet then stops working."* It crosses a navigation, which is
     * why it is driven here rather than in either screen's own test.
     */
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [HISTORY_ROW] } },
      [`POST /v1/pager-sessions/${SESSION}/reissue`]: { status: 200, body: SHEET },
    })

    const onNavigate = vi.fn()

    render(<StaffApp apiBase="" view="history" locale="en" onNavigate={onNavigate} />)
    await screen.findByText(t('staff.board.order', { reference: 'A-128' }))

    fireEvent.click(document.querySelector('[data-history-reissue="A-128"]')!)

    // It asks the router for the issue view rather than re-issuing from the list: the ruling
    // requires a confirmation, and one fired from a row is one a mis-tap reaches while scrolling.
    expect(onNavigate).toHaveBeenCalledWith('issue')
    expect(scripted.calls(`POST /v1/pager-sessions/${SESSION}/reissue`)).toHaveLength(0)
  })

  it('shows the confirmation on the issue view and sends only after it is confirmed', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/pager-sessions/history': [
        { status: 200, body: { sessions: [HISTORY_ROW] } },
        { status: 200, body: { sessions: [HISTORY_ROW] } },
      ],
      [`POST /v1/pager-sessions/${SESSION}/reissue`]: { status: 200, body: SHEET },
    })

    // The navigator is simulated by re-rendering on the new view, which is what the real router
    // does — `StaffApp` holds the order in state across it rather than in a URL.
    const view = render(<StaffApp apiBase="" view="history" locale="en" />)

    await screen.findByText(t('staff.board.order', { reference: 'A-128' }))
    fireEvent.click(document.querySelector('[data-history-reissue="A-128"]')!)

    view.rerender(<StaffApp apiBase="" view="issue" locale="en" />)

    expect(await screen.findByText(t('staff.issue.reissue.title'))).toBeTruthy()
    expect(screen.getByText(t('staff.issue.reissue.body'))).toBeTruthy()
    expect(scripted.calls(`POST /v1/pager-sessions/${SESSION}/reissue`)).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.reissue.confirm') }))

    expect(await screen.findByText(t('staff.issue.sheet.title'))).toBeTruthy()
    expect(scripted.calls(`POST /v1/pager-sessions/${SESSION}/reissue`)).toHaveLength(1)
  })

  it('maps the two 409s apart by the problem type, and a 404 to its own sentence', async () => {
    /*
     * ⚠ EVERY BRANCH IS A REAL SERVER ANSWER, which is `R-205`'s lesson: a screen state no
     * event can produce is a dead state, and Task 9 shipped one. `reissue` answers 404 for an
     * order this restaurant cannot see, and 409 for one that is finished OR past its expiry —
     * told apart by the problem `type`, because the remedies differ.
     */
    for (const [answer, key] of [
      [
        { status: 409, body: { type: 'https://qrpager.dev/problems/conflict' } },
        'staff.issue.reissue.finished',
      ],
      [
        { status: 409, body: { type: 'https://qrpager.dev/problems/expired' } },
        'staff.issue.reissue.expired',
      ],
      [{ status: 404 }, 'staff.issue.reissue.gone'],
    ] as const) {
      stack({
        ...SIGNED_IN,
        'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [HISTORY_ROW] } },
        [`POST /v1/pager-sessions/${SESSION}/reissue`]: answer,
      })

      const view = render(<StaffApp apiBase="" view="history" locale="en" />)

      await screen.findByText(t('staff.board.order', { reference: 'A-128' }))
      fireEvent.click(document.querySelector('[data-history-reissue="A-128"]')!)

      view.rerender(<StaffApp apiBase="" view="issue" locale="en" />)
      await screen.findByText(t('staff.issue.reissue.title'))
      fireEvent.click(screen.getByRole('button', { name: t('staff.issue.reissue.confirm') }))

      expect(await screen.findByText(t(key))).toBeTruthy()

      view.unmount()
      vi.unstubAllGlobals()
    }
  })

  it('drops the sheet when the person leaves the screen, because it is a live credential', async () => {
    /*
     * `qrPayload` is a working pager: anyone who scans it is that customer. A tablet left on
     * the staff screen must not still hold it, and coming back to *Start a pager* must not
     * redisplay a sheet for an order already handed over — which a person would read as the
     * CURRENT order's code and print.
     */
    stack({
      ...SIGNED_IN,
      'POST /v1/pager-sessions': { status: 201, body: SHEET },
      'GET /v1/pager-sessions/history': { status: 200, body: { sessions: [] } },
    })

    const view = render(<StaffApp apiBase="" view="issue" locale="en" />)

    await screen.findByText(RESTAURANT)
    fireEvent.change(document.querySelector('[data-issue-reference="true"]')!, {
      target: { value: 'A-128' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.create') }))
    await screen.findByText(t('staff.issue.sheet.title'))

    view.rerender(<StaffApp apiBase="" view="history" locale="en" />)
    await screen.findByText(t('staff.history.none.title'))

    view.rerender(<StaffApp apiBase="" view="issue" locale="en" />)

    /*
     * ⚠ ASSERTED ON THE SCREEN's MARKER, NOT ITS TITLE. `staff.nav.issue`, `staff.issue.title`
     * and `staff.history.none.action` are all the words *Start a pager* — deliberately, because
     * that is the honest label in all three places — so `findByText` on the title is ambiguous
     * the moment the nav is on screen. The marker names the screen unambiguously.
     */
    expect(await screen.findByText(t('staff.issue.lede'))).toBeTruthy()
    expect(document.querySelector('[data-issue="idle"]')).not.toBeNull()
    expect(screen.queryByText(t('staff.issue.sheet.title'))).toBeNull()
    expect(document.body.innerHTML).not.toContain(SHEET.qr_payload)
  })
})

/* ------------------------------------------------------------------- 10e: the staff */

describe('the team view', () => {
  it('loads the staff and marks the caller’s own row', async () => {
    stack({ ...SIGNED_IN, 'GET /v1/members': { status: 200, body: TEAM } })

    await open('team')

    expect(await screen.findByText('Omar Haddad')).toBeTruthy()
    // The own row is found by matching `user_id` against the identity's own id -- the only
    // available join, because `/v1/auth/me` does not publish a `tenant_users.id`.
    expect(screen.getByText(t('staff.team.you'))).toBeTruthy()

    const own = document.querySelector('[data-team-member="hiba@example.test"]')

    expect(own?.querySelectorAll('button')).toHaveLength(0)
  })

  it('adds a member, lower-cases the address, and refetches', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/members': [
        { status: 200, body: TEAM },
        { status: 200, body: TEAM },
      ],
      'POST /v1/members': { status: 201, body: { member: {} } },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.add') }))
    fireEvent.change(document.querySelector('[data-team-email="true"]')!, {
      // Mixed case on purpose: a tablet keyboard capitalises the first letter, and
      // `StoreMemberRequest` requires `lowercase` -- so a correctly typed address would 422.
      target: { value: 'New.Person@Example.test' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.team.add.submit') }))

    await waitFor(() => {
      expect(scripted.calls('POST /v1/members')).toHaveLength(1)
    })
    expect(scripted.calls('POST /v1/members')[0]?.body).toEqual({
      email: 'new.person@example.test',
      role: 'staff',
    })
    // Refetched, so the list shows the new member rather than waiting for a reload.
    expect(scripted.calls('GET /v1/members').length).toBeGreaterThanOrEqual(2)
  })

  it('defaults the picker to the LEAST senior role this caller may grant', async () => {
    // A form whose default is `owner` is one somebody submits. The list arrives from the
    // server in `cases()` order, so the last entry is the most junior.
    stack({ ...SIGNED_IN, 'GET /v1/members': { status: 200, body: TEAM } })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.add') }))

    expect(document.querySelector('[data-team-role="staff"]')!.checked).toBe(true)
    expect(document.querySelector('[data-team-role="owner"]')!.checked).toBe(false)
  })

  it('maps a 404 on ADD to the address sentence and NOT to the last-owner one', async () => {
    /*
     * ⚠ `store()` CANNOT REACH THE LAST-OWNER RULE AT ALL, so a 404 here is *no account with
     * that address*. Mapping it through the shared refusal reader would tell a manager about
     * owners when the answer is about an address — one mapping serving two situations that
     * differ in exactly the way that matters, which is what `R-224` was.
     */
    stack({
      ...SIGNED_IN,
      'GET /v1/members': { status: 200, body: TEAM },
      'POST /v1/members': { status: 404 },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.add') }))
    fireEvent.change(document.querySelector('[data-team-email="true"]')!, {
      target: { value: 'nobody@example.test' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.team.add.submit') }))

    expect(await screen.findByText(t('staff.team.add.unknown'))).toBeTruthy()
    expect(screen.queryByText(t('staff.team.denied.lastOwner'))).toBeNull()
  })

  it('changes a role, and maps a 403 to the SELF-ROLE sentence because the picker offered it', async () => {
    /*
     * The client distinguishes the two 403 causes by what it ASKED FOR, which is sound here and
     * only here: the picker offers a role only if it is in `grantableRoles`, so a 403 on a role
     * the picker offered cannot be a seniority refusal. `MemberSeniorityTest` drives both
     * server-side, so neither sentence rests on this inference being right about the server.
     */
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/members': [
        { status: 200, body: TEAM },
        { status: 200, body: TEAM },
      ],
      [`PATCH /v1/members/${OTHER_MEMBERSHIP}`]: { status: 403 },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(document.querySelector('[data-team-action="set-manager"]')!)

    expect(await screen.findByText(t('staff.team.denied.ownRole'))).toBeTruthy()
    expect(scripted.calls(`PATCH /v1/members/${OTHER_MEMBERSHIP}`)[0]?.body).toEqual({
      role: 'manager',
    })
  })

  it('maps a 404 on a role change to the LAST-OWNER sentence', async () => {
    // `MemberController` answers 404 for a member this tenant cannot see AND for a refused
    // demotion, indistinguishably — so a row the screen just listed answering 404 is far more
    // likely to be the rule. The list is refetched either way, so a row that really vanished
    // disappears.
    stack({
      ...SIGNED_IN,
      'GET /v1/members': [
        { status: 200, body: TEAM },
        { status: 200, body: TEAM },
      ],
      [`PATCH /v1/members/${OTHER_MEMBERSHIP}`]: { status: 404 },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(document.querySelector('[data-team-action="set-manager"]')!)

    expect(await screen.findByText(t('staff.team.denied.lastOwner'))).toBeTruthy()
  })

  it('removes a member only after the confirmation, and refetches', async () => {
    const scripted = stack({
      ...SIGNED_IN,
      'GET /v1/members': [
        { status: 200, body: TEAM },
        { status: 200, body: { members: [TEAM.members[0]] } },
      ],
      [`DELETE /v1/members/${OTHER_MEMBERSHIP}`]: { status: 204 },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(document.querySelector('[data-team-action="open-remove"]')!)

    expect(screen.getByText(t('staff.team.remove.body'))).toBeTruthy()
    expect(scripted.calls(`DELETE /v1/members/${OTHER_MEMBERSHIP}`)).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: t('staff.team.remove.confirm') }))

    await waitFor(() => {
      expect(scripted.calls(`DELETE /v1/members/${OTHER_MEMBERSHIP}`)).toHaveLength(1)
    })
    await waitFor(() => {
      expect(screen.queryByText('Omar Haddad')).toBeNull()
    })
  })

  it('maps a 403 on a REMOVAL to the seniority sentence, because a removal asks for no role', async () => {
    // `offeredRole` is false here: a removal names no role, so a 403 cannot be the self-role
    // clause and the remaining reading is the ceiling.
    stack({
      ...SIGNED_IN,
      'GET /v1/members': [
        { status: 200, body: TEAM },
        { status: 200, body: TEAM },
      ],
      [`DELETE /v1/members/${OTHER_MEMBERSHIP}`]: { status: 403 },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    fireEvent.click(document.querySelector('[data-team-action="open-remove"]')!)
    fireEvent.click(screen.getByRole('button', { name: t('staff.team.remove.confirm') }))

    expect(await screen.findByText(t('staff.team.denied.seniority'))).toBeTruthy()
  })

  it('shows a staff member the refusal, with no retry', async () => {
    stack({ ...SIGNED_IN, 'GET /v1/members': { status: 403 } })

    render(<StaffApp apiBase="" view="team" locale="en" />)

    expect(await screen.findByText(t('staff.team.denied.title'))).toBeTruthy()
    expect(screen.queryByRole('button', { name: t('staff.team.retry') })).toBeNull()
  })

  it('maps a dead network to offline and a 5xx to error', async () => {
    for (const [answer, key] of [
      ['unreachable', 'staff.team.offline.title'],
      [{ status: 503 }, 'staff.team.error.title'],
    ] as const) {
      stack({ ...SIGNED_IN, 'GET /v1/members': answer })

      const view = render(<StaffApp apiBase="" view="team" locale="en" />)

      expect(await screen.findByText(t(key))).toBeTruthy()

      view.unmount()
      vi.unstubAllGlobals()
    }
  })

  it('offers no OWNER button to a manager, from the server’s own grantable list', async () => {
    stack({
      ...SIGNED_IN,
      'GET /v1/auth/me': { status: 200, body: identity(['manager', 'staff']) },
      'GET /v1/members': { status: 200, body: TEAM },
    })

    await open('team')
    await screen.findByText('Omar Haddad')

    expect(document.querySelector('[data-team-action="set-owner"]')).toBeNull()
    expect(document.querySelector('[data-team-action="set-manager"]')).not.toBeNull()
  })
})
