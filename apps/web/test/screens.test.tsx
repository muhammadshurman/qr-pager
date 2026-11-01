import { render, within } from '@testing-library/react'
import type { ReactElement } from 'react'
import { describe, expect, it } from 'vitest'

import { translatorFor, type Translate } from '../src/copy/index.ts'
import { JOURNEY_STATES, screenOf, type ScreenState } from '../src/pager/journey.ts'
import { EnableAlerts } from '../src/screens/EnableAlerts.tsx'
import { OfflineError } from '../src/screens/OfflineError.tsx'
import { OrderReadyScreen } from '../src/screens/OrderReadyScreen.tsx'
import { ScanLanding } from '../src/screens/ScanLanding.tsx'
import { SessionEnded } from '../src/screens/SessionEnded.tsx'
import { Waiting } from '../src/screens/Waiting.tsx'

/**
 * ================================================================================
 * EVERY STATE OF EVERY SCREEN, IN BOTH LAUNCH LANGUAGES
 * ================================================================================
 *
 * `§12`: *"Every screen is specified before it is coded: purpose, all states … and the
 * exact copy."* `§4.1` is the inventory and every state in it is a deliverable.
 *
 * ⚠ THE SUBJECT LIST IS `JOURNEY_STATES`, THE SAME EXPORT THE REDUCER'S TEST AND THE
 * ONE-HANDED MEASUREMENT ITERATE. So a state that exists and is not rendered here is
 * impossible rather than merely unlikely -- *every table is generated or gate-checked*, and
 * a subject list typed into a test is that defect one layer down.
 *
 * WHAT IS NOT ASSERTED HERE, and it is not an omission: a visible focus ring, a 44x44
 * rendered box, a resolved colour pair, and whether `prefers-reduced-motion` suppressed
 * anything. All four are computed styles and jsdom computes no layout, so asserting them
 * here would pass and mean nothing -- the vacuous shape `PLAN §4a` records four instances
 * of. They are in `browser/`, in three real engines.
 */

const noop = () => undefined

/** Every state, rendered by the component that owns it. One place, so nothing is missed. */
function renderState(state: ScreenState, t: Translate): ReactElement {
  switch (state) {
    case 'landing.valid':
      return (
        <ScanLanding state="valid" t={t} reference="A-42" onEnableAlerts={noop} onSkip={noop} />
      )
    case 'landing.invalid':
      return (
        <ScanLanding state="invalid" t={t} reference={null} onEnableAlerts={noop} onSkip={noop} />
      )
    case 'alerts.notAsked':
      return (
        <EnableAlerts
          state="notAsked"
          t={t}
          install="not-applicable"
          onAsk={noop}
          onContinue={noop}
        />
      )
    case 'alerts.granted':
      return (
        <EnableAlerts
          state="granted"
          t={t}
          install="not-applicable"
          onAsk={noop}
          onContinue={noop}
        />
      )
    case 'alerts.denied':
      return (
        <EnableAlerts
          state="denied"
          t={t}
          install="not-applicable"
          onAsk={noop}
          onContinue={noop}
        />
      )
    case 'alerts.unsupported':
      return (
        <EnableAlerts
          state="unsupported"
          t={t}
          install="not-applicable"
          onAsk={noop}
          onContinue={noop}
        />
      )
    case 'waiting.healthy':
      return (
        <Waiting
          state="healthy"
          t={t}
          reference="A-42"
          elapsedSeconds={252}
          asOfLabel="13:40"
          wake="held"
          wakeOn
          onToggleWake={noop}
          onRetry={noop}
        />
      )
    case 'waiting.degraded':
      return (
        <Waiting
          state="degraded"
          t={t}
          reference="A-42"
          elapsedSeconds={252}
          asOfLabel="13:40"
          wake="unavailable"
          wakeOn={false}
          onToggleWake={noop}
          onRetry={noop}
        />
      )
    case 'waiting.offline':
      return (
        <Waiting
          state="offline"
          t={t}
          reference="A-42"
          elapsedSeconds={252}
          asOfLabel="13:40"
          wake="unavailable"
          wakeOn={false}
          onToggleWake={noop}
          onRetry={noop}
        />
      )
    case 'ready.fresh':
      return <OrderReadyScreen state="fresh" t={t} reference="A-42" onAcknowledge={noop} />
    case 'ready.acknowledged':
      return <OrderReadyScreen state="acknowledged" t={t} reference="A-42" onAcknowledge={noop} />
    case 'ended.completed':
      return <SessionEnded state="completed" t={t} reference="A-42" />
    case 'ended.cancelled':
      return <SessionEnded state="cancelled" t={t} reference="A-42" />
    case 'ended.expired':
      return <SessionEnded state="expired" t={t} reference="A-42" />
    case 'error.offline':
      return <OfflineError state="offline" t={t} onRetry={noop} />
    case 'error.server':
      return <OfflineError state="server" t={t} onRetry={noop} />
    case 'error.unknown':
      return <OfflineError state="unknown" t={t} onRetry={noop} />
  }
}

const LOCALES = ['en', 'ar'] as const

describe('every state of every screen renders in both launch languages', () => {
  it('renders all 17, with the data-screen and data-state a locator reads', () => {
    for (const locale of LOCALES) {
      const t = translatorFor(locale)

      for (const state of JOURNEY_STATES) {
        const { container, unmount } = render(renderState(state, t))
        const root = container.querySelector('[data-screen]')

        expect(root, `${state} / ${locale}`).not.toBeNull()
        expect(root?.getAttribute('data-screen'), state).toBe(screenOf(state))
        expect(root?.getAttribute('data-state'), state).toBe(state.split('.')[1])
        unmount()
      }
    }
  })

  it('leaves no unsubstituted placeholder anywhere', () => {
    // A `{reference}` on screen is the failure `interpolate()` leaves VISIBLE on purpose,
    // and this is where it is caught.
    for (const locale of LOCALES) {
      const t = translatorFor(locale)

      for (const state of JOURNEY_STATES) {
        const { container, unmount } = render(renderState(state, t))

        expect(container.textContent, `${state} / ${locale}`).not.toMatch(/\{\w+\}/)
        unmount()
      }
    }
  })

  it('renders no English string on an Arabic screen', () => {
    // ⚠ THE CONTROL AGAINST A MISSING TRANSLATION REACHING A SCREEN. The type catches a
    // missing key and `copy.test.ts` catches an untranslated value; this catches a component
    // that bypassed the catalogue for one of its own strings.
    const ALLOWED = ['A-42', '13:40', 'QR Pager', 'iPhone', 'iPad', 'Add to Home Screen', '04:12']
    const t = translatorFor('ar')

    for (const state of JOURNEY_STATES) {
      const { container, unmount } = render(renderState(state, t))
      let text = container.textContent

      for (const allowed of ALLOWED) text = text.split(allowed).join('')

      // Three or more consecutive latin letters is a word, not a code.
      expect(text.match(/[A-Za-z]{3,}/g) ?? [], `${state} carries latin prose in Arabic`).toEqual(
        [],
      )
      unmount()
    }
  })
})

describe('no dead end -- PLAN 4.1s own clause, asserted per state', () => {
  it('gives every state either an enabled control or a named physical action', () => {
    /**
     * ⚠ THE TWO STATES THAT LEGITIMATELY OFFER NO BUTTON ARE NAMED, not excepted silently.
     * `alerts.denied` and `alerts.unsupported` each carry a *Continue* rather than a retry,
     * because the browser will not ask again and a retry would be a lie -- which is why the
     * kit's own `StateView` gives `permission-denied` no retry either. And
     * `landing.invalid` offers no button at all: its way forward is physical (re-scan, ask
     * the counter), and a "try again" would re-read a fragment that has already been
     * stripped and fail identically every time.
     */
    /**
     * ⚠ THE FOUR THAT OFFER NO BUTTON, EACH NAMED WITH ITS REASON. This list was
     * `['landing.invalid']` and the assertion caught the other three, which is the finding
     * rather than the failure: `§4.1`'s *"no dead end"* clause is on the **Offline / error**
     * row, and its Session-ended row asks instead for *"a path back to the restaurant
     * page"* -- which is `SR-4`, because no endpoint on this surface carries one.
     *
     * So all four are physical-only on purpose: `landing.invalid` because a retry would
     * re-read a fragment that has already been stripped and fail identically every time, and
     * the three `ended` states because the session is over and the way forward is a new code
     * from the counter. Each carries copy naming an action the customer can take, which is
     * what the assertion below requires of them. When `homeHref` exists they gain a link --
     * asserted separately -- and this list shrinks by three.
     */
    const PHYSICAL_ONLY: readonly ScreenState[] = [
      'landing.invalid',
      'ended.completed',
      'ended.cancelled',
      'ended.expired',
    ]
    const t = translatorFor('en')

    for (const state of JOURNEY_STATES) {
      const { container, unmount } = render(renderState(state, t))
      const enabled = [...container.querySelectorAll('button, a[href], input, summary')].filter(
        (element) => !element.hasAttribute('disabled'),
      )

      if (PHYSICAL_ONLY.includes(state)) {
        // Still not a dead end: the copy names an action the customer can take.
        expect(container.textContent, state).toMatch(/scan|counter/i)
      } else {
        expect(enabled.length, `${state} has no enabled control`).toBeGreaterThan(0)
      }

      unmount()
    }
  })

  it('offers a retry on exactly the states where a retry can do something', () => {
    const t = translatorFor('en')
    const RETRYABLE: readonly ScreenState[] = [
      'waiting.degraded',
      'waiting.offline',
      'error.offline',
      'error.server',
      'error.unknown',
    ]

    for (const state of JOURNEY_STATES) {
      const { container, unmount } = render(renderState(state, t))
      const retry = [...container.querySelectorAll('button')].some((button) =>
        /try again/i.test(button.textContent),
      )

      expect(retry, state).toBe(RETRYABLE.includes(state))
      unmount()
    }
  })
})

describe('the waiting screen', () => {
  it('states the status in WORDS, not by colour alone', () => {
    // `§4.1`'s own requirement, and `R-118`'s shape: a state carried by colour alone is a
    // state some customers cannot read.
    const { container } = render(renderState('waiting.healthy', translatorFor('en')))

    expect(container.textContent).toContain('Being prepared')
  })

  it('says the ALERT IS UNAFFECTED when the live timer is degraded', () => {
    /**
     * ⚠ `§4 › Edge`'s SHEDDING ORDER, MADE VISIBLE. *"Load shedding drops nice-to-haves
     * (live timer, analytics) before alerts."* The 503 the client receives IS the server
     * shedding the live timer -- `ShedLoad` is on the polling route and on neither of the
     * other two. Without this sentence a customer cannot tell a shed timer from a broken
     * product.
     */
    const { container } = render(renderState('waiting.degraded', translatorFor('en')))

    expect(container.textContent).toContain('Your alert is not affected')
    expect(container.textContent).toContain('Last confirmed 13:40')
  })

  it('renders NO awake toggle when the lock was refused, and one honest line instead', () => {
    const refused = render(renderState('waiting.degraded', translatorFor('en')))

    expect(refused.container.querySelector('#pg-awake-toggle')).toBeNull()
    expect(refused.container.textContent).toContain('Your screen may dim')
    refused.unmount()

    const held = render(renderState('waiting.healthy', translatorFor('en')))

    expect(held.container.querySelector('#pg-awake-toggle')).not.toBeNull()
    expect(held.container.textContent).not.toContain('Your screen may dim')
  })

  it('keeps the elapsed clock and the order reference LTR inside an RTL page', () => {
    // `QRPAGER-15 §6` trap 2: *"logos and numbers do not [mirror]"*. `04:12` must not become
    // `12:04` and an order code must not reverse.
    const { container } = render(renderState('waiting.healthy', translatorFor('ar')))
    const timer = container.querySelector('.qr-timer__value')
    const reference = container.querySelector('.pg-reference')

    expect(timer?.getAttribute('dir')).toBe('ltr')
    expect(timer?.textContent).toBe('04:12')
    expect(reference?.getAttribute('dir')).toBe('ltr')
  })

  it('does not announce the ticking clock', () => {
    // `role="timer"` with `aria-live="off"`: a timer that announces every second makes a
    // screen reader unusable. The ORDER BEING READY is announced; the seconds are not.
    const { container } = render(renderState('waiting.healthy', translatorFor('en')))
    const timer = container.querySelector('[role="timer"]')

    expect(timer?.getAttribute('aria-live')).toBe('off')
  })
})

describe('the enable-alerts screen', () => {
  it('offers NO retry on a denial -- structurally, not by omission', () => {
    const { container } = render(renderState('alerts.denied', translatorFor('en')))
    const labels = [...container.querySelectorAll('button')].map((b) => b.textContent)

    expect(labels).toEqual(['Continue'])
  })

  it('shows the install flow as its own labelled section when iOS needs it', () => {
    const t = translatorFor('en')
    const required = render(
      <EnableAlerts state="notAsked" t={t} install="required" onAsk={noop} onContinue={noop} />,
    )
    const section = required.container.querySelector('.pg-install')

    expect(section).not.toBeNull()
    // `§4.1`: *"a first-class flow"* -- its own heading, and the three steps as a list.
    expect(within(section as HTMLElement).getByRole('heading').textContent).toContain(
      'Add this to your Home Screen',
    )
    expect(section?.querySelectorAll('ol li').length).toBe(3)
    // And it SELF-LABELS, which is QRPAGER-24's inherited pattern.
    expect(section?.textContent).toContain("alerts can't reach you yet")
    required.unmount()

    const installed = render(
      <EnableAlerts state="notAsked" t={t} install="installed" onAsk={noop} onContinue={noop} />,
    )

    expect(installed.container.querySelector('.pg-install')).toBeNull()
  })

  it('keeps "why we ask" keyboard-reachable without JavaScript', () => {
    // A native `<details>`: announced and operable with no script, and every visual property
    // is ours so `§12`'s "no library default look" holds.
    const { container } = render(renderState('alerts.notAsked', translatorFor('en')))
    const summary = container.querySelector('details > summary')

    expect(summary).not.toBeNull()
    expect(summary?.textContent).toBe('Why we ask')
  })
})

describe('the order-ready screen', () => {
  it('announces assertively, and is the only screen that does', () => {
    // `a11y.tsx`: *"a product that announces everything assertively has announced nothing"*.
    // The customer may not be looking at the screen, which is the entire premise of a pager.
    const t = translatorFor('en')
    const ready = render(renderState('ready.fresh', t))

    expect(ready.container.querySelector('[aria-live="assertive"]')).not.toBeNull()
    ready.unmount()

    for (const state of JOURNEY_STATES.filter((s) => screenOf(s) !== 'ready')) {
      const { container, unmount } = render(renderState(state, t))

      expect(container.querySelector('[aria-live="assertive"]'), state).toBeNull()
      unmount()
    }
  })

  it('reads NO tenant-themeable property -- D4, asserted on the rendered markup', () => {
    /**
     * ⚠ D4 AS BUILT, NOT AS INTENDED. The order-ready screen's colours are `--ready-field`,
     * `--ready-ink` and `--ready-signal`, declared once outside both theme blocks in a
     * namespace no tenant theme token can produce. This asserts the DOM carries no
     * `data-theme` and no inline style at all, so there is nothing on it for a theme to
     * reach -- and there is NO clamp and no contrast-adjustment code in this task.
     */
    const { container } = render(renderState('ready.fresh', translatorFor('en')))
    const section = container.querySelector('.qr-ready')

    expect(section?.hasAttribute('data-theme')).toBe(false)

    for (const element of container.querySelectorAll('*')) {
      expect(element.getAttribute('style'), element.className).toBeNull()
    }
  })

  it('changes the label on acknowledge and STAYS on the screen', () => {
    // `§4.1`'s `acknowledged` state keeps the customer here: the order reference is still
    // the thing they are holding up at the counter.
    const t = translatorFor('en')
    const fresh = render(renderState('ready.fresh', t))

    expect(fresh.container.querySelector('.qr-ready__acknowledge')?.textContent).toBe('Got it')
    expect(fresh.container.textContent).toContain('A-42')
    fresh.unmount()

    const acked = render(renderState('ready.acknowledged', t))

    expect(acked.container.querySelector('.qr-ready__acknowledge')?.textContent).toBe('On your way')
    expect(acked.container.textContent).toContain('A-42')
    expect(acked.container.querySelector('.qr-ready')?.getAttribute('data-state')).toBe(
      'acknowledged',
    )
  })
})

describe('the session-ended screen', () => {
  it('renders NO link when no restaurant page URL was supplied -- SR-4', () => {
    /**
     * ⚠ `§4.1` ASKS FOR *"a path back to the restaurant page"* AND NO ENDPOINT CARRIES ONE.
     * `SR-4`. A link to a guessed URL would be a dead end wearing the costume of a path, so
     * the slot renders nothing and the copy names a physical action instead.
     */
    const { container } = render(renderState('ended.completed', translatorFor('en')))

    expect(container.querySelector('a[href]')).toBeNull()
    expect(container.textContent).toContain('Scan a new code')
  })

  it('renders the link, with noopener noreferrer, when one IS supplied', () => {
    const { container } = render(
      <SessionEnded
        state="completed"
        t={translatorFor('en')}
        reference="A-42"
        brand={{ name: 'Zaytoun', homeHref: 'https://zaytoun.example/' }}
      />,
    )
    const link = container.querySelector('a[href]')

    expect(link?.getAttribute('href')).toBe('https://zaytoun.example/')
    // The same posture `packages/ui`'s templates already assert on every external link.
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link?.textContent).toContain('Zaytoun')
  })

  it('keeps the cancellation copy non-accusatory and names the agent', () => {
    // `§3.5`: a cancellation is usually the restaurant's, and a passive "it was cancelled"
    // lets the customer read it as their own fault.
    const { container } = render(renderState('ended.cancelled', translatorFor('en')))

    expect(container.textContent).toContain('The restaurant cancelled it')
  })
})

describe('the error screen', () => {
  it('announces all three states, because the customer did not cause them', () => {
    const t = translatorFor('en')

    for (const state of ['error.offline', 'error.server', 'error.unknown'] as const) {
      const { container, unmount } = render(renderState(state, t))

      expect(container.querySelector('[role="alert"]'), state).not.toBeNull()
      unmount()
    }
  })

  it('does not pretend to know what happened on the unknown state', () => {
    const { container } = render(renderState('error.unknown', translatorFor('en')))

    expect(container.textContent).toContain("We're not sure what")
  })
})

describe('the product mark stands in for the restaurants -- SR-4', () => {
  it('renders the product name when no brand is supplied, on every screen that frames one', () => {
    const t = translatorFor('en')

    for (const state of JOURNEY_STATES.filter((s) => screenOf(s) !== 'ready')) {
      const { container, unmount } = render(renderState(state, t))

      expect(container.querySelector('.pg-mark')?.textContent, state).toBe('QR Pager')
      unmount()
    }
  })

  it('renders the restaurants name and logo when they are supplied', () => {
    const { container } = render(
      <ScanLanding
        state="valid"
        t={translatorFor('en')}
        reference="A-42"
        onEnableAlerts={noop}
        onSkip={noop}
        brand={{ name: 'Zaytoun', logo: <img alt="" src="/logo.svg" /> }}
      />,
    )

    expect(container.querySelector('.pg-mark')?.textContent).toContain('Zaytoun')
    expect(container.querySelector('.pg-mark__logo img')).not.toBeNull()
  })
})

describe('the two tenant-supplied slots, when a value exists', () => {
  it('passes the logo through to the order-ready screen and nothing else', () => {
    // ⚠ D4: *"the only tenant-supplied thing on the screen is the logo slot"*. It is an
    // image, it influences no colour, and it is rendered at a bounded size by the kit.
    const { container } = render(
      <OrderReadyScreen
        state="fresh"
        t={translatorFor('en')}
        reference="A-42"
        onAcknowledge={noop}
        brand={{ name: 'Zaytoun', logo: <img alt="" src="/logo.svg" /> }}
      />,
    )

    expect(container.querySelector('.qr-ready__logo img')).not.toBeNull()
    // And still no theme and no inline style, with a tenant value present.
    expect(container.querySelector('.qr-ready')?.hasAttribute('data-theme')).toBe(false)

    for (const element of container.querySelectorAll('*')) {
      expect(element.getAttribute('style')).toBeNull()
    }
  })

  it('renders the home link with an empty name rather than the literal placeholder', () => {
    // A `homeHref` with no accompanying name is reachable: `SR-4`'s slot is three optional
    // fields and a future response may carry the URL before the name. The label must not
    // read "Back to {restaurant}".
    const { container } = render(
      <SessionEnded
        state="completed"
        t={translatorFor('en')}
        reference="A-42"
        brand={{ homeHref: 'https://zaytoun.example/' }}
      />,
    )

    expect(container.querySelector('a[href]')?.textContent).toBe('Back to ')
    expect(container.textContent).not.toContain('{restaurant}')
  })
})
