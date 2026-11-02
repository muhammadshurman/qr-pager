import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import {
  SIGN_IN_STAGES,
  StaffSignIn,
  chooserLabel,
  type SignInStage,
} from '../src/screens/staff/StaffSignIn.tsx'

/**
 * ================================================================================
 * EVERY STAGE S1 CAN BE IN, RENDERED — one case per member of `SIGN_IN_STAGES`
 * ================================================================================
 *
 * `§11` QA M-1: **four of the seven stages were rendered by no test.** `staff-screens.test.tsx`
 * drove `idle`, `failed` and `choose`; `working`, `offline`, `unsupported` and `two-factor`
 * existed only as members of a union. A stage nothing renders is a stage whose first render is
 * in a restaurant — which is what `R-205` cost Task 9 a round for.
 *
 * ⚠ THE TABLE IS KEYED BY THE EXPORTED STAGE LIST AND CHECKED AGAINST IT, so this file cannot
 * drift behind the component. A stage added to `SIGN_IN_STAGES` with no row here fails the
 * completeness case below — at type-check time via `Record<SignInStage, …>` and again at run
 * time, because only the second of those runs in CI's `pnpm test`.
 *
 * Each row asserts what that stage shows **and what it does not**. Without the absence half the
 * rows are interchangeable: `offline` and `failed` both render a `StaffState` with an action, and
 * a case that only checked "some text is present" would pass for either against the other.
 */

const t = translatorFor('en')

const MEMBERSHIPS = [
  { tenantId: 'tenant-a', role: 'owner', slug: 'al-baik' },
  { tenantId: 'tenant-b', role: 'owner', slug: 'kudu' },
] as const

function mount(stage: SignInStage) {
  const props = {
    t,
    stage,
    onPasskey: vi.fn(),
    onPassword: vi.fn(),
    onTwoFactor: vi.fn(),
    memberships: MEMBERSHIPS,
    onChoose: vi.fn(),
  }

  return { props, ...render(<StaffSignIn {...props} />) }
}

/**
 * Is this sentence on the screen?
 *
 * ⚠ ASKED OF THE RENDERED TEXT RATHER THAN THROUGH `getByText`, because two of these phrases
 * legitimately appear twice and `getByText` throws on the second. `staff.signin.offline`'s
 * state renders *"Try again"* as both its own retry affordance and the action label, and
 * `staff.signin.code` is both a field label and part of its hint — so the element query failed
 * on a screen that was CORRECT, which is the failure mode that teaches you to loosen the
 * assertion. This is a boolean about the copy, which is exactly the claim each row makes;
 * structure is asserted by `getByRole` / `getByLabelText` in the rows that care about it.
 */
function shows(phrase: string): boolean {
  return document.body.textContent.includes(phrase)
}

/** The passkey button, or `null` when this stage does not render one at all. */
function passkeyButton(): HTMLElement | null {
  return document.querySelector('[data-passkey-signin="true"]')
}

interface StageExpectation {
  /** Text this stage must put on the screen, verbatim from the copy table. */
  readonly says: readonly string[]
  /** Text this stage must NOT put on the screen. */
  readonly silentOn: readonly string[]
  /** Any further exact facts about the stage. */
  readonly andAlso?: () => void
}

const EXPECTATIONS: Record<SignInStage, StageExpectation> = {
  idle: {
    says: [t('staff.signin.title'), t('staff.signin.passkey'), t('staff.signin.password')],
    silentOn: [t('staff.signin.failed'), t('staff.signin.offline'), t('staff.signin.unsupported')],
    andAlso: () => {
      const passkey = passkeyButton()

      // Offered, not merely present: the primary action of the screen is actionable.
      expect(passkey).not.toBeNull()
      expect(passkey?.hasAttribute('disabled')).toBe(false)

      // The password form is BEHIND the disclosure -- §12 S1 puts the passkey first and this
      // is the half of that claim a screenshot cannot show.
      expect(screen.queryByLabelText(t('staff.signin.email'))).toBeNull()
    },
  },

  working: {
    says: [t('staff.signin.working')],
    silentOn: [t('staff.signin.failed'), t('staff.signin.offline')],
    andAlso: () => {
      const passkey = passkeyButton()

      /*
       * ⚠ DISABLED **AND** `aria-busy`, which is the pair `§11` SECURITY L-2 turns on. A
       * disabled button that does not say why is indistinguishable from "not allowed"; a
       * busy one that is still clickable is the double-submit itself.
       */
      expect(passkey?.hasAttribute('disabled')).toBe(true)
      expect(passkey?.getAttribute('aria-busy')).toBe('true')
    },
  },

  failed: {
    says: [t('staff.signin.failed'), t('staff.shell.error.body')],
    silentOn: [t('staff.signin.offline'), t('staff.signin.unsupported')],
    andAlso: () => {
      // ⚠ ONE MESSAGE FOR EVERY CAUSE. The screen must not name which half failed -- that is
      // the account-existence oracle §4 › Security refuses, asserted here as the absence of
      // the words a helpful-sounding version would use.
      const body = document.body.textContent.toLowerCase()

      for (const word of ['password is', 'no account', 'not found', 'unknown', 'incorrect']) {
        expect(body.includes(word), `the failure message says "${word}"`).toBe(false)
      }
    },
  },

  offline: {
    says: [t('staff.signin.offline'), t('staff.shell.error.retry')],
    silentOn: [t('staff.signin.failed'), t('staff.signin.unsupported')],
  },

  unsupported: {
    says: [t('staff.signin.unsupported')],
    silentOn: [t('staff.signin.failed'), t('staff.signin.offline')],
    andAlso: () => {
      /*
       * ⚠ THE PASSWORD FORM IS ALREADY OPEN, with no disclosure to find. A browser that
       * cannot do WebAuthn leaves the person with exactly one way in, and §12's
       * zero-technical-background bar means the screen offers it rather than explaining it.
       */
      expect(screen.getByLabelText(t('staff.signin.email'))).toBeTruthy()
      expect(screen.getByLabelText(t('staff.signin.passwordLabel'))).toBeTruthy()

      // And the passkey button is rendered-but-refused rather than hidden: it is the thing
      // the screen is about, and silently dropping it reads as a broken page.
      expect(passkeyButton()?.hasAttribute('disabled')).toBe(true)
    },
  },

  'two-factor': {
    says: [t('staff.signin.twoFactor'), t('staff.signin.code'), t('staff.signin.submit')],
    silentOn: [t('staff.signin.passkey'), t('staff.signin.password')],
    andAlso: () => {
      const code = screen.getByLabelText(t('staff.signin.code'))

      // `numeric` and `one-time-code`: a 6-digit code on a kitchen tablet should raise a
      // number pad and be offered by the OS, not typed from a password manager's guess.
      expect(code.getAttribute('inputmode')).toBe('numeric')
      expect(code.getAttribute('autocomplete')).toBe('one-time-code')

      // ⚠ AND NO PASSKEY BUTTON AT ALL. The second factor replaces the whole first-factor
      // surface; leaving the passkey offered mid-challenge invites a restart that drops the
      // half-authenticated session on the floor.
      expect(passkeyButton()).toBeNull()
    },
  },

  choose: {
    says: [t('staff.signin.choose')],
    silentOn: [t('staff.signin.title'), t('staff.signin.passkey')],
    andAlso: () => {
      // One button per membership, each NAMING its restaurant -- §11 QA H-2's defect was two
      // identical buttons here, so the assertion is on the distinct labels, exactly.
      for (const membership of MEMBERSHIPS) {
        expect(screen.getByRole('button', { name: chooserLabel(membership, t) })).toBeTruthy()
      }

      expect(document.querySelectorAll('button')).toHaveLength(MEMBERSHIPS.length)
    },
  },
}

describe('S1 sign-in — every stage renders', () => {
  it('has an expectation for every stage the component declares, and no others', () => {
    // The guard that keeps this file honest. Exact set equality, sorted so the comparison is
    // about membership rather than about declaration order.
    expect(Object.keys(EXPECTATIONS).sort()).toEqual([...SIGN_IN_STAGES].sort())
  })

  it.each([...SIGN_IN_STAGES])('renders the %s stage', (stage) => {
    const expectation = EXPECTATIONS[stage]

    mount(stage)

    for (const phrase of expectation.says) {
      expect(shows(phrase), `stage ${stage} omits "${phrase}"`).toBe(true)
    }

    for (const phrase of expectation.silentOn) {
      expect(shows(phrase), `stage ${stage} should not say "${phrase}"`).toBe(false)
    }

    expectation.andAlso?.()
  })
})
