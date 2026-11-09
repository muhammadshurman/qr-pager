import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffDevices } from '../src/screens/staff/StaffDevices.tsx'
import { StaffSignIn } from '../src/screens/staff/StaffSignIn.tsx'

/**
 * ================================================================================
 * THE HANDLERS — what happens when somebody actually touches the screens
 * ================================================================================
 *
 * `staff-screens.test.tsx` asserts what REACHES the document: the copy, the absence of every
 * identifier, which states offer an action. **This file drives the interactions**, which is
 * the half that was uncovered — and the half where a `§12` claim is either true or decorative.
 *
 * The destructive ones carry the weight: removal must be confirmed and must be ABANDONABLE,
 * and the one-time credential must be dismissable without being lost on the way. Those are
 * properties of handlers, not of rendered text.
 */

const t = translatorFor('en')

const DEVICE = {
  id: '01a199c4-7f3e-7000-8000-0123456789ab',
  name: 'Pass counter',
  expiresAt: '2026-12-01T10:00:00Z',
  lastUsedAt: null,
} as const

function devices(overrides: Partial<Parameters<typeof StaffDevices>[0]> = {}) {
  const props = {
    t,
    state: 'ready' as const,
    devices: [DEVICE],
    issued: null,
    onRetry: vi.fn(),
    onIssue: vi.fn(),
    onDismissIssued: vi.fn(),
    onRemove: vi.fn(),
    formatDate: (iso: string) => iso,
    ...overrides,
  }

  return { props, ...render(<StaffDevices {...props} />) }
}

describe('setting up a kitchen screen', () => {
  it('asks for a name before issuing anything, and passes the name through', () => {
    const { props } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.add') }))

    // ⚠ NOTHING IS ISSUED BY OPENING THE FORM. Minting a credential is not idempotent, so the
    // only safe moment to do it is on an explicit submit with a name the person typed.
    expect(props.onIssue).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(t('staff.devices.name.label')), {
      target: { value: 'Grill' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.create') }))

    expect(props.onIssue).toHaveBeenCalledTimes(1)
    expect(props.onIssue).toHaveBeenCalledWith('Grill')
  })

  it('abandons the form without issuing, which is the half a confirm dialog usually forgets', () => {
    const { props } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.add') }))
    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.cancel') }))

    expect(props.onIssue).not.toHaveBeenCalled()
    // And the form is gone, so the list is reachable again.
    expect(screen.queryByLabelText(t('staff.devices.name.label'))).toBeNull()
  })

  it('offers the form from the EMPTY state too, which is §12’s next action', () => {
    const { props } = devices({ devices: [] })

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.empty.action') }))
    fireEvent.change(screen.getByLabelText(t('staff.devices.name.label')), {
      target: { value: 'First screen' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.create') }))

    expect(props.onIssue).toHaveBeenCalledWith('First screen')
  })

  it('dismisses the one-time credential explicitly, and only when asked', () => {
    const { props } = devices({
      issued: { id: DEVICE.id, token: 'one-time', expiresAt: '2026-12-01T10:00:00Z' },
    })

    // It is on screen until the person says they have copied it. Nothing dismisses it for
    // them -- no timer, no navigation -- because it cannot be shown again.
    expect(document.querySelector('[data-one-time-secret="true"]')).not.toBeNull()
    expect(props.onDismissIssued).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.issued.done') }))

    expect(props.onDismissIssued).toHaveBeenCalledTimes(1)
  })
})

describe('removing a kitchen screen', () => {
  it('confirms first, and one click on Remove removes nothing', () => {
    const { props } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.remove') }))

    // ⚠ THE CONFIRMATION IS THE CONTROL, and this is the assertion that proves it. §12 makes a
    // destructive action confirmed and clearly marked; a confirm step that still fired the
    // action on the first click would satisfy the copy and none of the clause.
    expect(props.onRemove).not.toHaveBeenCalled()
    expect(screen.getByText(t('staff.devices.remove.title'))).toBeDefined()
    expect(screen.getByText(t('staff.devices.remove.body'))).toBeDefined()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.remove.confirm') }))

    expect(props.onRemove).toHaveBeenCalledTimes(1)
    expect(props.onRemove).toHaveBeenCalledWith(DEVICE.id)
  })

  it('is abandonable, and leaves the row alone', () => {
    const { props } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.remove') }))
    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.remove.keep') }))

    expect(props.onRemove).not.toHaveBeenCalled()
    // The confirmation is gone and the screen is still listed.
    expect(screen.queryByText(t('staff.devices.remove.title'))).toBeNull()
    expect(screen.getByText(DEVICE.name)).toBeDefined()
  })

  it('retries a failed load on the person’s own action, and not by itself', () => {
    for (const state of ['error', 'offline'] as const) {
      const onRetry = vi.fn()
      const { unmount } = render(
        <StaffDevices
          t={t}
          state={state}
          devices={[]}
          issued={null}
          onRetry={onRetry}
          onIssue={vi.fn()}
          onDismissIssued={vi.fn()}
          onRemove={vi.fn()}
          formatDate={(iso) => iso}
        />,
      )

      expect(onRetry).not.toHaveBeenCalled()
      fireEvent.click(screen.getByRole('button', { name: t('staff.shell.error.retry') }))
      expect(onRetry, state).toHaveBeenCalledTimes(1)

      unmount()
    }
  })

  it('renders the last-used date when there is one, and the honest absence when there is not', () => {
    render(
      <StaffDevices
        t={t}
        state="ready"
        devices={[{ ...DEVICE, lastUsedAt: '2026-11-01T09:30:00Z' }]}
        issued={null}
        onRetry={vi.fn()}
        onIssue={vi.fn()}
        onDismissIssued={vi.fn()}
        onRemove={vi.fn()}
        formatDate={() => '1 Nov, 09:30'}
      />,
    )

    expect(screen.getByText('Last used 1 Nov, 09:30')).toBeDefined()
    // "Not used yet" rather than a blank or a dash: §12's bar is a person who reads no
    // documentation, and an empty cell is a question rather than an answer.
    expect(screen.queryByText(t('staff.devices.neverUsed'))).toBeNull()
  })
})

describe('signing in', () => {
  function signIn(overrides: Partial<Parameters<typeof StaffSignIn>[0]> = {}) {
    const props = {
      t,
      stage: 'idle' as const,
      onPasskey: vi.fn(),
      onPassword: vi.fn(),
      onTwoFactor: vi.fn(),
      memberships: [],
      onChoose: vi.fn(),
      ...overrides,
    }

    return { props, ...render(<StaffSignIn {...props} />) }
  }

  it('runs the ceremony on the primary action, with no credential typed anywhere', () => {
    const { props } = signIn()

    fireEvent.click(screen.getByRole('button', { name: t('staff.signin.passkey') }))

    expect(props.onPasskey).toHaveBeenCalledTimes(1)

    /*
     * ⚠ AND NOTHING WAS TYPED, PROVED ON A SCREEN THAT NOW SHOWS THE FIELDS. Until
     * QRPAGER-38 this read `queryByLabelText(passwordLabel)).toBeNull()` — "no form was
     * ever shown, so nothing could have been typed into one" — which was a claim about the
     * old disclosure and not about the ceremony. The fields are visible from the first
     * paint now, so the same property is asserted directly: they are EMPTY, and the
     * password path was never called.
     */
    expect(screen.getByLabelText<HTMLInputElement>(t('staff.signin.email')).value).toBe('')
    expect(screen.getByLabelText<HTMLInputElement>(t('staff.signin.passwordLabel')).value).toBe('')
    expect(props.onPassword).not.toHaveBeenCalled()
  })

  it('submits both fields together, from a form that needed no revealing', () => {
    const { props } = signIn()

    /*
     * ⚠ PRESENT ON THE FIRST PAINT. The case was "reveals the password form only when
     * asked", and the reveal is gone: QRPAGER-38 puts email and password on the screen
     * unconditionally, so the disclosure step is deleted rather than renamed and the
     * assertion is inverted into the new guarantee.
     */
    expect(screen.getByLabelText(t('staff.signin.email'))).toBeDefined()
    expect(screen.getByLabelText(t('staff.signin.passwordLabel'))).toBeDefined()

    fireEvent.change(screen.getByLabelText(t('staff.signin.email')), {
      target: { value: 'hiba@example.test' },
    })
    fireEvent.change(screen.getByLabelText(t('staff.signin.passwordLabel')), {
      target: { value: 'correct-horse' },
    })
    fireEvent.click(screen.getByRole('button', { name: t('staff.signin.submit') }))

    expect(props.onPassword).toHaveBeenCalledWith('hiba@example.test', 'correct-horse')
  })

  it('submits the second factor, and shows no passkey button while it is pending', () => {
    const { props } = signIn({ stage: 'two-factor' })

    // The ceremony is not offered mid-challenge: the credential half is done and what is
    // outstanding is six digits, so a passkey button here would restart something finished.
    expect(screen.queryByRole('button', { name: t('staff.signin.passkey') })).toBeNull()

    fireEvent.change(screen.getByLabelText(t('staff.signin.code')), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: t('staff.signin.submit') }))

    expect(props.onTwoFactor).toHaveBeenCalledWith('123456')
  })

  it('passes the chosen tenant id, which is the one place the client names one', () => {
    const { props } = signIn({
      stage: 'choose',
      memberships: [
        {
          tenantId: 'tenant-a',
          role: 'owner',
          name: 'Al Baik Jeddah',
          slug: 'al-baik',
          grantableRoles: [],
        },
        {
          tenantId: 'tenant-b',
          role: 'manager',
          name: 'Shawarma House',
          slug: 'shawarma-house',
          grantableRoles: [],
        },
      ],
    })

    // The label is the NAME plus the role word since 10b (`R-222`) -- `chooserLabel()` -- so
    // the button is found by what the app actually renders rather than by a string this test
    // chose. That indirection is the point: when the ruling moved the label from the slug to
    // the name, this case needed no edit beyond its fixture.
    fireEvent.click(screen.getByRole('button', { name: /Shawarma House/ }))

    // ⚠ THE ONE PLACE A TENANT ID LEAVES THE CLIENT, and it is always one the server offered
    // in `memberships` -- never typed, never from a URL. `POST /v1/auth/tenant` re-verifies
    // the membership anyway, which is what makes this safe rather than trusted.
    expect(props.onChoose).toHaveBeenCalledWith('tenant-b')
  })

  it('shows the password form unprompted when the browser cannot run a ceremony', () => {
    signIn({ stage: 'unsupported' })

    // No disclosure to find: a browser with no `navigator.credentials` has one way in, and
    // making the person discover it behind a link is a dead end wearing a control.
    expect(screen.getByLabelText(t('staff.signin.email'))).toBeDefined()
    expect(screen.getByText(t('staff.signin.unsupported'))).toBeDefined()
  })

  it('offers the retry on a failure and on an offline state, both through the ceremony', () => {
    for (const stage of ['failed', 'offline'] as const) {
      const onPasskey = vi.fn()
      const { unmount } = render(
        <StaffSignIn
          t={t}
          stage={stage}
          onPasskey={onPasskey}
          onPassword={vi.fn()}
          onTwoFactor={vi.fn()}
          memberships={[]}
          onChoose={vi.fn()}
        />,
      )

      /*
       * ⚠ ONE CONTROL FOR BOTH STAGES SINCE QRPAGER-38. The offline stage used to render its
       * own *"Try again"* button beside the passkey one, so this read
       * `stage === 'failed' ? passkey : t('staff.shell.error.retry')`. The card has exactly
       * one primary action and the alert says what to do, so the retry IS the ceremony in
       * both stages — which is what this case was always about: that the way out of a
       * refusal is the same way in.
       */
      const [first] = screen.getAllByRole('button', { name: t('staff.signin.passkey') })

      expect(first).toBeDefined()
      fireEvent.click(first!)

      expect(onPasskey, stage).toHaveBeenCalled()
      unmount()
    }
  })
})

/*
 * ⚠ `StaffHome`'s TWO CASES ARE GONE FROM HERE AND THE PROPERTIES ARE NOT, 10b.
 *
 * That screen is deleted — the board replaces it at `/staff`, which its own docblock said
 * would happen — and the passkey affordance it carried moved onto `StaffApp`, below the
 * board, because it is a thing a person does once and the board is what they look at all
 * day. So the two properties moved with it:
 *
 *   the ceremony runs only when offered, and reports BOTH outcomes
 *      -> apps/web/test/staff-flows.test.tsx, driven through the real orchestrator rather
 *         than against a screen in isolation, which is strictly stronger: it also proves the
 *         button is reachable from the shell a person actually sees.
 *   nothing is shown for a CANCELLED ceremony
 *      -> the same case, and it is the half most worth keeping: telling somebody their own
 *         dismissal did not work is noise.
 *
 * Written out rather than deleted quietly, because a case that vanishes in a diff reads as
 * coverage lost.
 */

/**
 * Submit a form the way a double-tap does: twice more in the SAME tick.
 *
 * ⚠ RAW `dispatchEvent`, NOT `fireEvent.submit`: `fireEvent` wraps each call in `act()`, which
 * flushes React's pending state between dispatches and hands the next handler a fresh closure
 * — so a guard that reads STATE rather than a ref passes an `act()`-driven test and still lets
 * two taps in one tick through. `staff-flows.test.tsx` caught exactly that: three submits,
 * three `POST /v1/devices`.
 *
 * ⚠ AND TO BE EXACT ABOUT WHAT THESE CASES PROVE: the FIRST submit in each is still
 * `fireEvent`, so the flag is flushed before these repeats arrive and a state-only guard would
 * refuse them too. **The case that distinguishes a ref guard from a state guard is
 * `staff-flows.test.tsx`'s "posts ONE issue request for two submits"**, where all three
 * dispatches are raw — measured 2026-11-02 by reverting the ref: that case reddens, these stay
 * green. What these hold is the handler's refusal and the button's `disabled` + `aria-busy`
 * under ordinary interaction, which is the half a person sees.
 */
function submitTwiceMoreInOneTick(form: HTMLFormElement): void {
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}

describe('a credential is submitted once, however hard the form is pressed', () => {
  /*
   * ================================================================================
   * `§11` SECURITY L-2 / QA M-8 — the double-submit
   * ================================================================================
   *
   * What a second in-flight attempt costs, and none of it is theoretical:
   *
   *   - `auth-login` is a named limiter, and `§11` round 1's own e2e run tripped it at 42
   *     logins — so each wasted attempt is budget a real person on a shared kitchen device
   *     then does not have;
   *   - two credential posts race to issue a session and the client keeps whichever lands
   *     last;
   *   - a wrong 2FA code sent twice counts twice against the server's attempt ceiling, which
   *     is the one place a double-submit can lock a real person OUT.
   *
   * ⚠ DRIVEN THROUGH `fireEvent.submit`, NOT A SECOND CLICK. A click on the disabled button
   * is swallowed by the DOM, so a case written that way passes with no guard in the handler at
   * all — it proves the `disabled` attribute and nothing else. `submit` is the path a
   * `requestSubmit()` and the Enter key take, so it reaches the handler, which is where the
   * "already in flight" state has to be checked.
   */

  it('sends the password once even when the form is submitted again', () => {
    const props = {
      t,
      stage: 'idle' as const,
      onPasskey: vi.fn(),
      onPassword: vi.fn(),
      onTwoFactor: vi.fn(),
      memberships: [],
      onChoose: vi.fn(),
    }

    const { container } = render(<StaffSignIn {...props} />)

    // No disclosure step: QRPAGER-38 renders both fields from the first paint.
    fireEvent.change(screen.getByLabelText(t('staff.signin.email')), {
      target: { value: 'cook@al-baik.test' },
    })
    fireEvent.change(screen.getByLabelText(t('staff.signin.passwordLabel')), {
      target: { value: 'correct horse battery staple' },
    })

    const form = container.querySelector('form')

    if (form === null) throw new Error('the password form did not render')

    fireEvent.submit(form)

    expect(props.onPassword).toHaveBeenCalledTimes(1)
    expect(props.onPassword).toHaveBeenCalledWith(
      'cook@al-baik.test',
      'correct horse battery staple',
    )

    submitTwiceMoreInOneTick(form)

    expect(props.onPassword).toHaveBeenCalledTimes(1)

    // And the button SAYS it is busy rather than merely refusing: `disabled` alone is
    // indistinguishable from "not allowed", which on a sign-in screen reads as a lockout.
    const submit = container.querySelector('[data-password-signin="true"]')

    expect(submit?.hasAttribute('disabled')).toBe(true)
    expect(submit?.getAttribute('aria-busy')).toBe('true')
  })

  it('mints ONE kitchen screen however many times the submit is pressed', () => {
    /*
     * ⚠ `§11` SECURITY L-2 / QA M-8, AT THE COMPONENT. Two taps used to mint two 30-day
     * `pager:read` credentials, and `setIssued()` overwrote the first plaintext — an
     * unintended live row nobody was shown. The reviewer's own criterion was *"disable the
     * submit while a request is outstanding, and a test that fires two submits and asserts
     * exactly one POST"*; this is the first half and `staff-flows.test.tsx` drives the second
     * over the real client.
     */
    const { props, container } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.add') }))
    fireEvent.change(screen.getByLabelText(t('staff.devices.name.label')), {
      target: { value: 'Pass counter' },
    })

    const form = container.querySelector('form')

    if (form === null) throw new Error('the add-a-screen form did not render')

    fireEvent.submit(form)
    submitTwiceMoreInOneTick(form)

    expect(props.onIssue).toHaveBeenCalledExactlyOnceWith('Pass counter')

    // And it SAYS it is working rather than only refusing: a disabled control with no reason
    // reads as "not allowed", which on this screen means "you cannot add a screen".
    const submit = container.querySelector('[data-issue-device="true"]')

    expect(submit?.hasAttribute('disabled')).toBe(true)
    expect(submit?.getAttribute('aria-busy')).toBe('true')
    expect(submit?.textContent).toContain(t('staff.devices.creating'))
  })

  it('lets the person try again after a failed issue, so the guard cannot latch', () => {
    // A one-shot flag would turn one 500 into a dead screen needing a reload. The flag clears
    // when the outcome arrives, and `StaffApp` always moves `state` on failure.
    const { props, container, rerender } = devices()

    fireEvent.click(screen.getByRole('button', { name: t('staff.devices.add') }))
    fireEvent.change(screen.getByLabelText(t('staff.devices.name.label')), {
      target: { value: 'Grill' },
    })

    const form = container.querySelector('form')

    if (form === null) throw new Error('the add-a-screen form did not render')

    fireEvent.submit(form)
    expect(props.onIssue).toHaveBeenCalledTimes(1)

    // The failure the parent reports, then the retry that brings the list back. The form is
    // still open across both -- `adding` and the typed name are this component's state and it
    // is never unmounted -- so the person is looking at the name they typed, as they should be.
    rerender(<StaffDevices {...props} state="error" />)
    rerender(<StaffDevices {...props} state="ready" />)

    const again = container.querySelector('form')

    if (again === null) throw new Error('the form did not come back after the failure')

    // Read off the DOM: `@testing-library/jest-dom` is not installed here, so `toHaveValue`
    // is not a matcher this suite has -- it fails as `Invalid Chai property`, which looks like
    // a wrong value rather than a missing matcher.
    const field = screen.getByLabelText<HTMLInputElement>(t('staff.devices.name.label'))

    expect(field.value).toBe('Grill')

    fireEvent.submit(again)

    expect(props.onIssue).toHaveBeenCalledTimes(2)
  })

  it('sends the 2FA code once, on the form where the stage cannot carry the flag', () => {
    const props = {
      t,
      stage: 'two-factor' as const,
      onPasskey: vi.fn(),
      onPassword: vi.fn(),
      onTwoFactor: vi.fn(),
      memberships: [],
      onChoose: vi.fn(),
    }

    const { container } = render(<StaffSignIn {...props} />)

    fireEvent.change(screen.getByLabelText(t('staff.signin.code')), { target: { value: '123456' } })

    const form = container.querySelector('form')

    if (form === null) throw new Error('the code form did not render')

    fireEvent.submit(form)
    submitTwiceMoreInOneTick(form)

    /*
     * ⚠ THIS IS THE CASE THAT CAUGHT THE VACUOUS VERSION OF THE FIX. `StaffApp.onTwoFactor`
     * never sets `working`, and this form renders only while the stage IS `two-factor`, so a
     * guard written as `loading={stage === 'working'}` is false here every time. The stage
     * prop does not move for the whole round trip; the component's own in-flight state is
     * the only thing that can refuse the second submit.
     */
    expect(props.onTwoFactor).toHaveBeenCalledTimes(1)
    expect(props.onTwoFactor).toHaveBeenCalledWith('123456')

    /*
     * Queried structurally, because the accessible NAME changes when the button goes busy.
     * It used to become *"Signing you in… Sign in"* — `Button` prepended a visually-hidden
     * `loadingLabel` to its label — and QRPAGER-38's submit is a plain button whose own text
     * SWAPS, so the name is the waiting sentence alone. Either way a
     * `getByRole({ name: 'Sign in' })` throws here, and that is the correct behaviour: it is
     * how a screen-reader user learns the press registered.
     */
    const submit = container.querySelector('form button[type="submit"]')

    expect(submit?.hasAttribute('disabled')).toBe(true)
    expect(submit?.getAttribute('aria-busy')).toBe('true')
    expect(submit?.textContent).toBe(t('staff.signin.working'))
  })

  it('accepts a second attempt once the first has resolved', () => {
    /*
     * ⚠ THE GUARD MUST NOT BE A ONE-SHOT. A flag that latched would turn one wrong password
     * into a dead screen needing a reload, which on a wall-mounted kitchen tablet is worse
     * than the double-submit it prevents. It clears on every `stage` change, and a resolved
     * attempt always changes the stage — so the proof is a re-render with the stage the
     * parent really sets on failure.
     */
    const props = {
      t,
      stage: 'idle' as const,
      onPasskey: vi.fn(),
      onPassword: vi.fn(),
      onTwoFactor: vi.fn(),
      memberships: [],
      onChoose: vi.fn(),
    }

    const { container, rerender } = render(<StaffSignIn {...props} />)

    // No disclosure step: QRPAGER-38 renders both fields from the first paint.
    fireEvent.change(screen.getByLabelText(t('staff.signin.email')), {
      target: { value: 'cook@al-baik.test' },
    })
    fireEvent.change(screen.getByLabelText(t('staff.signin.passwordLabel')), {
      target: { value: 'wrong' },
    })

    const form = container.querySelector('form')

    if (form === null) throw new Error('the password form did not render')

    fireEvent.submit(form)
    expect(props.onPassword).toHaveBeenCalledTimes(1)

    // `working` then `failed`, which is exactly what `StaffApp` does to this prop.
    rerender(<StaffSignIn {...props} stage="working" />)
    rerender(<StaffSignIn {...props} stage="failed" />)

    const retryForm = container.querySelector('form')

    if (retryForm === null) throw new Error('the password form did not survive the failure')

    fireEvent.submit(retryForm)

    expect(props.onPassword).toHaveBeenCalledTimes(2)
  })
})
