import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffDevices } from '../src/screens/staff/StaffDevices.tsx'
import { StaffHome } from '../src/screens/staff/StaffHome.tsx'
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
  const parts: string[] = [container.textContent ?? '']

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
    const body = document.body.textContent ?? ''

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
        restaurantNames={{}}
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
        memberships={[{ tenantId: 'tenant-a', role: 'owner' }]}
        restaurantNames={{ 'tenant-a': 'Al Baik' }}
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
        restaurantNames={{}}
        onChoose={vi.fn()}
      />,
    )

    expect(screen.getByText(t('staff.signin.failed'))).toBeDefined()

    const body = document.body.textContent ?? ''

    // None of the words that would tell a caller WHICH half failed.
    for (const word of ['password is', 'no such', 'not found', 'unknown email', 'incorrect']) {
      expect(body.toLowerCase().includes(word), `the failure names "${word}"`).toBe(false)
    }
  })

  it('offers each restaurant by NAME when choosing, and never by id', () => {
    const { container } = render(
      <StaffSignIn
        t={t}
        stage="choose"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[
          { tenantId: 'tenant-a', role: 'owner' },
          { tenantId: 'tenant-b', role: 'manager' },
        ]}
        restaurantNames={{ 'tenant-a': 'Al Baik', 'tenant-b': 'Shawarma House' }}
        onChoose={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: 'Al Baik' })).toBeDefined()
    expect(screen.getByRole('button', { name: 'Shawarma House' })).toBeDefined()

    const rendered = everythingRendered(container)

    expect(rendered).not.toContain('tenant-a')
    expect(rendered).not.toContain('tenant-b')
  })

  it('falls back to the role WORD rather than an id while the names are unknown', () => {
    // The state between "signed in" and "the names have arrived". A button with a uuid on it
    // is the thing §12 forbids most explicitly, so the fallback is a true word instead.
    render(
      <StaffSignIn
        t={t}
        stage="choose"
        onPasskey={vi.fn()}
        onPassword={vi.fn()}
        onTwoFactor={vi.fn()}
        memberships={[{ tenantId: '01a199c4-7f3e-7000-8000-0123456789ab', role: 'manager' }]}
        restaurantNames={{}}
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

describe('the shell home', () => {
  it('says what is true and points at the work that exists, with an action', () => {
    const onDevices = vi.fn()

    render(<StaffHome t={t} onDevices={onDevices} />)

    expect(screen.getByText(t('staff.home.title'))).toBeDefined()
    expect(screen.getByRole('button', { name: t('staff.home.action') })).toBeDefined()

    // And it does not say "not built yet", "coming soon" or "Task 10b" -- system vocabulary
    // about our own delivery plan, on a restaurant owner's screen.
    const body = (document.body.textContent ?? '').toLowerCase()

    for (const word of ['coming soon', 'not built', 'task 10', 'todo', 'placeholder']) {
      expect(body.includes(word), `the home screen says "${word}"`).toBe(false)
    }
  })
})
