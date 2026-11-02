import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffDevices } from '../src/screens/staff/StaffDevices.tsx'
import { StaffSignIn, roleKey } from '../src/screens/staff/StaffSignIn.tsx'

/**
 * ================================================================================
 * THE STAFF SCREENS, AGAINST THE DOM THEY PRODUCE
 * ================================================================================
 *
 * jsdom computes no layout, so nothing here asserts a box, a colour or a focus ring —
 * `browser/staff.spec.ts` does that in three engines. What this file asserts is the half
 * jsdom answers completely and which is most of `§12`'s zero-technical-background bar: **what
 * text and what attributes reach the document.**
 *
 * The load-bearing case is *"not one identifier is rendered"*, and it is written as a sweep
 * over the whole rendered subtree rather than as a check of the fields the screen happens to
 * print — a test that asserted `device.id` is absent from one element would pass against a
 * screen that put it in a `title`, a `data-` attribute or a hidden span.
 */

const t = translatorFor('en')
const tAr = translatorFor('ar')

const DEVICES = [
  {
    id: '01a199c4-7f3e-7000-8000-0123456789ab',
    name: 'Pass counter',
    expiresAt: '2026-12-01T10:00:00Z',
    lastUsedAt: null,
  },
  {
    id: '01a199c4-7f3e-7000-8000-ba9876543210',
    name: 'Grill',
    expiresAt: '2026-12-02T10:00:00Z',
    lastUsedAt: '2026-11-01T09:30:00Z',
  },
] as const

/** Every text node and every attribute value in the rendered tree, as one string. */
function everythingRendered(container: HTMLElement): string {
  const parts: string[] = [container.textContent]

  for (const element of container.querySelectorAll('*')) {
    for (const attribute of element.attributes) {
      parts.push(attribute.value)
    }
  }

  return parts.join(' | ')
}

describe('kitchen screens — §12: not one identifier reaches the screen', () => {
  it('renders no uuid, in text or in any attribute', () => {
    const { container } = render(
      <StaffDevices
        t={t}
        state="ready"
        devices={DEVICES}
        issued={null}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={(iso) => iso}
      />,
    )

    const rendered = everythingRendered(container)

    // Not vacuous: the names ARE rendered, so the sweep is looking at a populated tree.
    expect(rendered).toContain('Pass counter')
    expect(rendered).toContain('Grill')

    for (const device of DEVICES) {
      expect(rendered.includes(device.id), `the uuid of ${device.name} reached the screen`).toBe(
        false,
      )
    }

    // And no ability string either. `pager:read` is what the credential carries and it is
    // system vocabulary; the screen's word for it is "kitchen screen".
    expect(rendered).not.toContain('pager:read')
    expect(rendered).not.toContain('devices:write')
  })

  it('offers a next action on the empty state, which is what §12 requires', () => {
    render(
      <StaffDevices
        t={t}
        state="ready"
        devices={[]}
        issued={null}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={(iso) => iso}
      />,
    )

    expect(screen.getByText(t('staff.devices.empty.title'))).toBeDefined()
    // A BUTTON, not a sentence about what could be done. `StaffState`'s type would not
    // compile without it, and this is the rendered half of that guarantee.
    expect(screen.getByRole('button', { name: t('staff.devices.empty.action') })).toBeDefined()
  })

  it('offers NO retry on permission-denied, because retrying changes nothing', () => {
    render(
      <StaffDevices
        t={t}
        state="permission-denied"
        devices={[]}
        issued={null}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={(iso) => iso}
      />,
    )

    expect(screen.getByText(t('staff.devices.denied.title'))).toBeDefined()
    // The next action is social and is in the words: "Ask one of them."
    expect(screen.getByText(t('staff.devices.denied.body'))).toBeDefined()
    expect(screen.queryAllByRole('button')).toEqual([])
  })

  it('shows the one-time credential with the warning, and names no identifier beside it', () => {
    const { container } = render(
      <StaffDevices
        t={t}
        state="ready"
        devices={DEVICES}
        issued={{
          id: DEVICES[0].id,
          token: 'one-time-secret-value',
          expiresAt: '2026-12-01T10:00:00Z',
        }}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={(iso) => iso}
      />,
    )

    const secret = container.querySelector('[data-one-time-secret="true"]')

    expect(secret?.textContent).toBe('one-time-secret-value')
    expect(screen.getByText(t('staff.devices.issued.warning'))).toBeDefined()

    // The id of the thing just created must not appear beside its credential: a person
    // copying the secret has no use for it, and §12 forbids it on the screen.
    expect(everythingRendered(container)).not.toContain(DEVICES[0].id)
  })

  it('shows a credential in hand even when the list reload FAILED, so it cannot be lost', () => {
    // ⚠ THE DEFECT THIS PINS WAS FOUND BY READING THE FILE. `onIssue()` sets `issued` and then
    // reloads the list; the first version of this screen checked `loading`, `error` and
    // `offline` BEFORE `issued`, so a failed reload left the token set and never rendered.
    // The credential exists on the server, `DeviceTokens::issue()` returns it exactly once,
    // and nothing can read it back -- so the only recovery is removing the screen just
    // created, which the person would have no way of knowing.
    for (const state of ['error', 'offline', 'loading'] as const) {
      const { unmount } = render(
        <StaffDevices
          t={t}
          state={state}
          devices={[]}
          issued={{ id: 'x', token: 'must-not-be-lost', expiresAt: '2026-12-01T10:00:00Z' }}
          onRetry={vi.fn()}
          onIssue={vi.fn()}
          onDismissIssued={vi.fn()}
          onRemove={vi.fn()}
          formatDate={(iso) => iso}
        />,
      )

      expect(
        document.querySelector('[data-one-time-secret="true"]')?.textContent,
        `the credential was hidden by the ${state} state`,
      ).toBe('must-not-be-lost')

      unmount()
    }
  })

  it('states what removal DOES rather than what it is called', () => {
    render(
      <StaffDevices
        t={t}
        state="ready"
        devices={DEVICES}
        issued={null}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={(iso) => iso}
      />,
    )

    // The copy nowhere says "revoke", "token" or "credential" -- the three words a
    // developer would choose and a restaurant owner would not understand.
    const body = document.body.textContent

    for (const word of ['revoke', 'token', 'credential', 'session', 'API']) {
      expect(body.toLowerCase().includes(word.toLowerCase()), `the screen says "${word}"`).toBe(
        false,
      )
    }
  })
})

describe('sign-in', () => {
  it('puts the passkey first in the DOM as well as visually', () => {
    const { container } = render(
      <StaffSignIn
        t={t}
        stage="idle"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[]}
        onChoose={vi.fn()}
      />,
    )

    const buttons = [...container.querySelectorAll('button')].map((b) => b.textContent)

    // First in document order, which is what a keyboard user reaches first. The two orders
    // agreeing is what §12's keyboard clause means in practice.
    expect(buttons[0]).toBe(t('staff.signin.passkey'))
  })

  it('names no restaurant, because no tenant is resolved before a credential is', () => {
    const { container } = render(
      <StaffSignIn
        t={t}
        stage="idle"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[{ tenantId: 'tenant-a', role: 'owner', name: 'Al Baik', slug: 'al-baik' }]}
        onChoose={vi.fn()}
      />,
    )

    // A sign-in screen that said which restaurant a device was last used for would disclose
    // that to anybody who walks up to it. The names are a prop because the CHOOSE stage
    // needs them; the idle stage must not render one.
    expect(everythingRendered(container)).not.toContain('Al Baik')
  })

  it('gives one failure message for every cause, so the form is not an oracle', () => {
    render(
      <StaffSignIn
        t={t}
        stage="failed"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[]}
        onChoose={vi.fn()}
      />,
    )

    expect(screen.getByText(t('staff.signin.failed'))).toBeDefined()

    const body = document.body.textContent

    // None of the words that would tell a caller WHICH half failed.
    for (const word of ['password is', 'no such', 'not found', 'unknown email', 'incorrect']) {
      expect(body.toLowerCase().includes(word), `the failure names "${word}"`).toBe(false)
    }
  })

  it('distinguishes TWO memberships of the SAME role, which is what the chooser is for', () => {
    /*
     * ⚠ THE CASE `§11` QA H-2 SAID DID NOT EXIST, AND THE ONE THAT MATTERS.
     *
     * The old pair of cases asserted names were shown — with a `restaurantNames` map the TEST
     * supplied — and the fallback case used ONE membership, so the ambiguity was never
     * rendered. Meanwhile the only production call site passed `{}`, so the fallback was the
     * permanent state and an owner of two restaurants saw two buttons both reading *"Owner"*
     * under *"Which restaurant?"*. That actor is `R-119`'s own residual.
     *
     * Two memberships of the SAME role now, and the labels must differ. Nothing is injected:
     * the label comes from the membership the server sent.
     */
    render(
      <StaffSignIn
        t={t}
        stage="choose"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[
          { tenantId: 'tenant-a', role: 'owner', name: 'Al Baik Jeddah', slug: 'al-baik' },
          {
            tenantId: 'tenant-b',
            role: 'owner',
            name: 'Shawarma House',
            slug: 'shawarma-house',
          },
        ]}
        onChoose={vi.fn()}
      />,
    )

    const labels = [...document.querySelectorAll('button')].map((b) => b.textContent)

    expect(labels).toHaveLength(2)
    expect(labels[0]).not.toBe(labels[1])

    // ⚠ THE NAME, SINCE 10b — `R-222`. This asserted the SLUG, which was the right label
    // until Muhammad ruled: `al-baik · Owner` is a DNS label with a role word beside it, and
    // `§12` forbids an unexplained identifier on a screen. The two-same-role case is kept
    // exactly as it was, because it is the case `§11` QA H-2 found unrendered — what changed
    // is which unique string distinguishes the two buttons.
    expect(labels[0]).toContain('Al Baik Jeddah')
    expect(labels[1]).toContain('Shawarma House')

    // AND THE SLUG IS NOT ON THE SCREEN ANY MORE, which is the half of the ruling a
    // name-only assertion would miss: the client still RECEIVES it and no longer shows it.
    expect(document.body.textContent).not.toContain('al-baik')
    expect(document.body.textContent).not.toContain('shawarma-house')
    // The role word is still there as context -- it is what you would be acting AS.
    expect(labels[0]).toContain(t('staff.role.owner'))

    // And no uuid, which is the thing §12 forbids outright.
    expect(document.body.textContent).not.toContain('tenant-a')
    expect(document.body.textContent).not.toContain('tenant-b')
  })

  it('falls back name -> slug -> role WORD, and never to an id', () => {
    // `/v1/auth/me` always carries both -- `StaffContractTest` and `StaffBoardEndpointsTest`
    // pin the keys -- so these are the malformed-payload paths. Each renders a cautious true
    // thing rather than a blank button or a uuid, which is the §12 order of preference.
    //
    // ⚠ THE MIDDLE RUNG IS ASSERTED TOO, since 10b: the slug was the label and is now the
    // fallback, so a payload carrying one and not the other still renders something unique.
    const { unmount } = render(
      <StaffSignIn
        t={t}
        stage="choose"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[
          {
            tenantId: '01a199c4-7f3e-7000-8000-0123456789ab',
            role: 'manager',
            name: '',
            slug: 'kudu-riyadh',
          },
        ]}
        onChoose={vi.fn()}
      />,
    )

    expect(document.querySelector('button')?.textContent).toContain('kudu-riyadh')
    expect(document.body.textContent).not.toContain('01a199c4')
    unmount()
    render(
      <StaffSignIn
        t={t}
        stage="choose"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[
          { tenantId: '01a199c4-7f3e-7000-8000-0123456789ab', role: 'manager', name: '', slug: '' },
        ]}
        onChoose={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: t('staff.role.manager') })).toBeDefined()
    expect(document.body.textContent).not.toContain('01a199c4')
  })
})

describe('the role word', () => {
  it('maps each tenant_role to a word, and an unknown role to the LEAST privileged one', () => {
    expect(roleKey('owner')).toBe('staff.role.owner')
    expect(roleKey('manager')).toBe('staff.role.manager')
    expect(roleKey('staff')).toBe('staff.role.staff')

    // A role added on the server after this build shipped. Describing it as "Owner" would
    // overstate what the person can do; `§12` would rather show a cautious true-ish word
    // than an enum case, and the least privileged word is the cautious one.
    expect(roleKey('regional-director')).toBe('staff.role.staff')
    expect(roleKey('')).toBe('staff.role.staff')
  })

  it('is a word in both launch languages and never the enum value', () => {
    for (const role of ['owner', 'manager', 'staff']) {
      expect(t(roleKey(role))).not.toBe(role)
      expect(tAr(roleKey(role))).not.toBe(role)
      expect(tAr(roleKey(role))).not.toBe(t(roleKey(role)))
    }
  })
})

/*
 * ⚠ `StaffHome` IS GONE AND SO IS ITS CASE, 10b. That screen said *you are signed in, the
 * board arrives next* and its own docblock named this moment: *"When 10b lands, this is the
 * file whose contents the board replaces."* Its two properties did not evaporate with it,
 * and both have a new home rather than being dropped:
 *
 *   the §12 empty state with a next action  ->  apps/web/test/staff-board.test.tsx, where
 *                                               the BOARD's empty state is asserted;
 *   the no-system-vocabulary sweep          ->  the same file, over the board's own copy,
 *                                               which is now what /staff renders.
 *
 * Recorded here rather than deleted silently: a case that disappears in a diff reads as
 * coverage lost, and this one was moved.
 */
