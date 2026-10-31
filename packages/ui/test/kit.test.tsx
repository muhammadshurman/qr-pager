import { cleanup, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ALL_PAGER_STATUSES,
  Button,
  Card,
  DIRECTIONAL_ICONS,
  ElapsedTimer,
  Field,
  Icon,
  LiveRegion,
  OrderReady,
  ProgressTrack,
  STATUS_TONES,
  Spinner,
  StateView,
  StatusBadge,
  ThemeProvider,
  VIEW_STATES,
  VisuallyHidden,
  directionFor,
  formatElapsed,
} from '../src/index.ts'

afterEach(cleanup)

/** Render inside a theme, because direction and theme are context, not props. */
function inTheme(
  node: React.ReactNode,
  { theme = 'light', locale = 'en' }: { theme?: 'light' | 'dark'; locale?: 'en' | 'ar' } = {},
) {
  return render(
    <ThemeProvider theme={theme} locale={locale}>
      {node}
    </ThemeProvider>,
  )
}

// =========================================================================== theme

describe('ThemeProvider', () => {
  it('sets lang and dir together, on the same element', () => {
    // Two halves of one fact. Separating them is how a page ends up with Arabic text laid
    // out left to right -- the property PlatformPanelAccessibilityTest already pins for the
    // admin panel.
    const { container } = inTheme(<p>hello</p>, { locale: 'ar' })
    const root = container.firstElementChild!
    expect(root.getAttribute('lang')).toBe('ar')
    expect(root.getAttribute('dir')).toBe('rtl')
  })

  it.each([
    ['en', 'ltr'],
    ['ar', 'rtl'],
  ])('maps locale %s to direction %s', (locale, dir) => {
    expect(directionFor(locale as 'en' | 'ar')).toBe(dir)
  })

  it('carries the theme as an attribute, not a class', () => {
    // An attribute, so a tenant's staff can be offered a choice later without the token
    // file changing, and so the generated CSS can scope [data-theme='dark'].
    const { container } = inTheme(<p>hi</p>, { theme: 'dark' })
    expect(container.firstElementChild!.getAttribute('data-theme')).toBe('dark')
  })

  it('defaults to light and ltr rather than to whatever the host is', () => {
    const { container } = render(
      <ThemeProvider>
        <p>hi</p>
      </ThemeProvider>,
    )
    const root = container.firstElementChild!
    expect(root.getAttribute('data-theme')).toBe('light')
    expect(root.getAttribute('dir')).toBe('ltr')
  })
})

// ============================================================ RTL is not direction: rtl

describe('RTL -- what mirrors and what does not (§6 trap 2)', () => {
  it('mirrors a directional icon under RTL', () => {
    inTheme(<Icon name="arrow-next" label="Next" />, { locale: 'ar' })
    expect(screen.getByRole('img', { name: 'Next' }).getAttribute('data-mirrored')).toBe('true')
  })

  it('does NOT mirror the same icon under LTR', () => {
    inTheme(<Icon name="arrow-next" label="Next" />, { locale: 'en' })
    expect(screen.getByRole('img', { name: 'Next' }).getAttribute('data-mirrored')).toBeNull()
  })

  it.each([['clock'], ['check'], ['bell'], ['alert'], ['wifi-off']] as const)(
    'does NOT mirror %s even under RTL -- a mirrored clock is a broken clock',
    (name) => {
      inTheme(<Icon name={name} label={name} />, { locale: 'ar' })
      expect(screen.getByRole('img', { name }).getAttribute('data-mirrored')).toBeNull()
    },
  )

  it('declares a mirroring decision for EVERY icon, so a new glyph cannot default silently', () => {
    const names = Object.keys(DIRECTIONAL_ICONS) as (keyof typeof DIRECTIONAL_ICONS)[]
    expect(names).toHaveLength(7)
    for (const name of names) expect(typeof DIRECTIONAL_ICONS[name]).toBe('boolean')
    // Exactly two are directional. An exact count, so a glyph flipped by accident fails.
    expect(names.filter((n) => DIRECTIONAL_ICONS[n])).toEqual(['arrow-next', 'arrow-back'])
  })

  it('anchors the progress fill to the reading origin, which flips under RTL', () => {
    const ltr = inTheme(<ProgressTrack value={0.4} label="Progress" />, { locale: 'en' })
    expect(
      ltr.getByRole('progressbar').querySelector<HTMLElement>('.qr-progress__fill')!.style
        .transformOrigin,
    ).toBe('left')
    cleanup()
    const rtl = inTheme(<ProgressTrack value={0.4} label="Progress" />, { locale: 'ar' })
    expect(
      rtl.getByRole('progressbar').querySelector<HTMLElement>('.qr-progress__fill')!.style
        .transformOrigin,
    ).toBe('right')
  })

  it('reports the same aria-valuenow in both directions -- the VALUE does not mirror', () => {
    inTheme(<ProgressTrack value={0.4} label="Progress" />, { locale: 'ar' })
    expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('40')
  })

  it('keeps the timer readout LTR inside an RTL page -- numbers do not mirror', () => {
    // Without dir="ltr" the surrounding direction reorders the colon-separated parts and
    // 04:12 reads as 12:04.
    inTheme(<ElapsedTimer seconds={252} label="Waiting" />, { locale: 'ar' })
    const value = screen.getByRole('timer').querySelector('.qr-timer__value')!
    expect(value.getAttribute('dir')).toBe('ltr')
    expect(value.textContent).toBe('04:12')
  })

  it('does not announce every tick -- aria-live is off on the timer', () => {
    inTheme(<ElapsedTimer seconds={5} label="Waiting" />)
    expect(screen.getByRole('timer').getAttribute('aria-live')).toBe('off')
  })

  it.each([
    [0, '00:00'],
    [9, '00:09'],
    [59, '00:59'],
    [60, '01:00'],
    [252, '04:12'],
    [3599, '59:59'],
    [3600, '1:00:00'],
    [3661, '1:01:01'],
    [-5, '00:00'],
  ])('formats %s seconds as %s', (seconds, expected) => {
    expect(formatElapsed(seconds)).toBe(expected)
  })
})

// ========================================================================== the states

describe('the five states (§12)', () => {
  it('knows exactly six states, including ready', () => {
    expect(VIEW_STATES).toEqual([
      'ready',
      'loading',
      'empty',
      'error',
      'offline',
      'permission-denied',
    ])
  })

  it('renders children only when ready', () => {
    inTheme(
      <StateView state="ready">
        <p>the content</p>
      </StateView>,
    )
    expect(screen.getByText('the content')).toBeTruthy()
  })

  it.each(['loading', 'empty', 'error', 'offline', 'permission-denied'] as const)(
    'does NOT render children in the %s state',
    (state) => {
      inTheme(
        <StateView state={state} title="t">
          <p>the content</p>
        </StateView>,
      )
      expect(screen.queryByText('the content')).toBeNull()
    },
  )

  it.each(VIEW_STATES.filter((s) => s !== 'ready'))(
    'marks the %s state on the element',
    (state) => {
      inTheme(<StateView state={state} title="A title" />)
      expect(document.querySelector(`[data-state='${state}']`)).toBeTruthy()
    },
  )

  it('announces the loading state without drawing a label', () => {
    inTheme(<StateView state="loading" loadingLabel="Fetching orders" />)
    // role=status is the live region; the text is present but visually hidden.
    expect(screen.getByRole('status').textContent).toBe('Fetching orders')
  })

  it.each(['error', 'offline'] as const)(
    'gives %s an alert role, because it interrupts',
    (state) => {
      inTheme(<StateView state={state} title="Something went wrong" />)
      expect(screen.getByRole('alert').textContent).toContain('Something went wrong')
    },
  )

  it.each(['empty', 'permission-denied'] as const)(
    'does NOT give %s an alert role -- it is not an interruption',
    (state) => {
      inTheme(<StateView state={state} title="Nothing here" />)
      expect(screen.queryByRole('alert')).toBeNull()
    },
  )

  it.each(['error', 'offline'] as const)(
    'offers a retry in the %s state -- no dead end',
    (state) => {
      const onRetry = vi.fn()
      inTheme(<StateView state={state} title="t" onRetry={onRetry} retryLabel="Retry" />)
      const button = screen.getByRole('button', { name: 'Retry' })
      button.click()
      expect(onRetry).toHaveBeenCalledOnce()
    },
  )

  it('offers NO retry on permission-denied, because retrying changes nothing', () => {
    // Offering one would be a lie: §4.1's "no dead end" is about honesty, and a button that
    // cannot work is less honest than its absence.
    const onRetry = vi.fn()
    inTheme(<StateView state="permission-denied" title="t" onRetry={onRetry} />)
    expect(screen.queryByRole('button')).toBeNull()
  })

  it('offers no retry when the caller supplied no handler', () => {
    inTheme(<StateView state="error" title="t" />)
    expect(screen.queryByRole('button')).toBeNull()
  })

  it('renders the description when given one, and omits it otherwise', () => {
    inTheme(<StateView state="empty" title="No orders" description="They will appear here." />)
    expect(screen.getByText('They will appear here.')).toBeTruthy()
    cleanup()
    inTheme(<StateView state="empty" title="No orders" />)
    expect(document.querySelector('.qr-state__description')).toBeNull()
  })
})

// ============================================================================= button

describe('Button', () => {
  it.each([['primary'], ['secondary'], ['quiet'], ['danger']] as const)(
    'renders the %s variant',
    (variant) => {
      inTheme(<Button variant={variant}>Go</Button>)
      expect(screen.getByRole('button').getAttribute('data-variant')).toBe(variant)
    },
  )

  it('defaults to type=button, so it cannot submit a form by accident', () => {
    inTheme(<Button>Go</Button>)
    expect(screen.getByRole('button').getAttribute('type')).toBe('button')
  })

  it('is disabled AND aria-busy while loading, which are different claims', () => {
    // disabled alone is indistinguishable from "not allowed"; aria-busy says why.
    inTheme(
      <Button loading loadingLabel="Saving">
        Save
      </Button>,
    )
    const button = screen.getByRole('button')
    expect((button as HTMLButtonElement).disabled).toBe(true)
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(within(button).getByText('Saving')).toBeTruthy()
  })

  it('is not aria-busy when merely disabled', () => {
    inTheme(<Button disabled>Save</Button>)
    expect(screen.getByRole('button').getAttribute('aria-busy')).toBeNull()
  })

  it('does not fire while loading', () => {
    const onClick = vi.fn()
    inTheme(
      <Button loading onClick={onClick}>
        Save
      </Button>,
    )
    screen.getByRole('button').click()
    expect(onClick).not.toHaveBeenCalled()
  })
})

// ============================================================================== field

describe('Field', () => {
  it('associates the label with the input', () => {
    inTheme(<Field label="Restaurant name" />)
    expect(screen.getByLabelText('Restaurant name')).toBeTruthy()
  })

  it('associates a hint and an error through aria-describedby, both at once', () => {
    inTheme(<Field label="Email" hint="We never share it" error="That is not an email" />)
    const input = screen.getByLabelText('Email')
    const describedBy = input.getAttribute('aria-describedby')!.split(' ')
    expect(describedBy).toHaveLength(2)
    const texts = describedBy.map((id) => document.getElementById(id)!.textContent)
    expect(texts).toEqual(['We never share it', 'That is not an email'])
  })

  it('exposes invalidity as state, not as colour alone', () => {
    inTheme(<Field label="Email" error="Required" />)
    expect(screen.getByLabelText('Email').getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByRole('alert').textContent).toBe('Required')
  })

  it('sets neither aria-invalid nor describedby when valid and unhinted', () => {
    inTheme(<Field label="Email" />)
    const input = screen.getByLabelText('Email')
    expect(input.getAttribute('aria-invalid')).toBeNull()
    expect(input.getAttribute('aria-describedby')).toBeNull()
  })

  it('gives two fields distinct ids, so one label cannot point at the other', () => {
    inTheme(
      <>
        <Field label="First" />
        <Field label="Second" />
      </>,
    )
    const a = screen.getByLabelText('First').id
    const b = screen.getByLabelText('Second').id
    expect(a).not.toBe(b)
  })
})

// ============================================================================== badge

describe('StatusBadge', () => {
  const labels = {
    waiting: 'Waiting',
    ready: 'Ready',
    completed: 'Collected',
    cancelled: 'Cancelled',
    expired: 'Expired',
  } as const

  it('renders the state as TEXT, not colour alone, for every status', () => {
    // §12's bar, and the same property PlatformPanelAccessibilityTest asserts for the panel.
    for (const status of ALL_PAGER_STATUSES) {
      cleanup()
      inTheme(<StatusBadge status={status} labels={labels} />)
      expect(screen.getByText(labels[status])).toBeTruthy()
    }
  })

  it('has a tone for every status the GENERATED contract declares', () => {
    // If PHP gains a status, the generated mirror gains it, and this fails until the tone
    // map is extended -- which is the point of the generated mirror, not a side effect.
    expect(Object.keys(STATUS_TONES).sort()).toEqual([...ALL_PAGER_STATUSES].sort())
  })

  it('exposes the status itself, so a test or a style can target it', () => {
    inTheme(<StatusBadge status="ready" labels={labels} />)
    expect(document.querySelector("[data-status='ready']")).toBeTruthy()
  })
})

// =============================================================================== card

describe('Card', () => {
  it('renders a titled region', () => {
    inTheme(<Card title="Today">body</Card>)
    expect(screen.getByRole('heading', { name: 'Today' })).toBeTruthy()
  })

  it('renders untitled without an empty heading', () => {
    inTheme(<Card>body</Card>)
    expect(screen.queryByRole('heading')).toBeNull()
  })

  it('marks the recessed variant', () => {
    inTheme(<Card recessed>body</Card>)
    expect(document.querySelector("[data-recessed='true']")).toBeTruthy()
  })
})

// =================================================================== a11y primitives

describe('accessibility primitives', () => {
  it('keeps visually hidden content in the accessibility tree', () => {
    // Not display:none and not visibility:hidden, either of which would remove it from the
    // tree as well -- which would defeat the one thing it exists for.
    inTheme(<VisuallyHidden>For screen readers</VisuallyHidden>)
    const el = screen.getByText('For screen readers')
    expect(el.className).toBe('qr-visually-hidden')
    expect(el.getAttribute('aria-hidden')).toBeNull()
  })

  it('renders the live region BEFORE there is a message, so a change is observable', () => {
    // A region mounted at the moment there is something to say has nothing observing it.
    const { rerender } = inTheme(<LiveRegion message="" />)
    const region = screen.getByRole('status')
    expect(region).toBeTruthy()
    rerender(
      <ThemeProvider>
        <LiveRegion message="Order ready" />
      </ThemeProvider>,
    )
    expect(screen.getByRole('status').textContent).toBe('Order ready')
  })

  it.each([['polite'], ['assertive']] as const)('sets aria-live=%s', (politeness) => {
    inTheme(<LiveRegion message="x" politeness={politeness} />)
    expect(screen.getByRole('status').getAttribute('aria-live')).toBe(politeness)
  })

  it('marks the spinner aria-hidden when it has no name', () => {
    inTheme(<Spinner />)
    expect(document.querySelector('.qr-spinner')!.getAttribute('aria-hidden')).toBe('true')
  })

  it('exposes the spinner as an image when it IS the whole message', () => {
    inTheme(<Spinner label="Loading" />)
    expect(screen.getByRole('img', { name: 'Loading' })).toBeTruthy()
  })
})

// ======================================================================== order ready

describe('OrderReady -- outside theming (D4)', () => {
  const props = {
    reference: 'A-114',
    heading: 'Your order is ready',
    instruction: 'Please come to the counter',
    acknowledgeLabel: 'On my way',
    onAcknowledge: () => undefined,
  }

  it('announces assertively -- the customer may not be looking at the screen', () => {
    // The one place in the product that earns `assertive`: not looking at the screen is the
    // entire premise of a pager.
    inTheme(<OrderReady {...props} />)
    expect(screen.getByRole('status').getAttribute('aria-live')).toBe('assertive')
  })

  it('shows the reference LTR, because an order reference is a code', () => {
    inTheme(<OrderReady {...props} />, { locale: 'ar' })
    const reference = document.querySelector('.qr-ready__reference')!
    expect(reference.getAttribute('dir')).toBe('ltr')
    expect(reference.textContent).toBe('A-114')
  })

  it('declares NO data-theme of its own, in either theme', () => {
    // Its absence is the point: a screen outside theming must not look theme-aware to a
    // future reader, and it must not re-declare a theme a parent already set.
    for (const theme of ['light', 'dark'] as const) {
      cleanup()
      inTheme(<OrderReady {...props} />, { theme })
      expect(document.querySelector('.qr-ready')!.getAttribute('data-theme')).toBeNull()
    }
  })

  it('renders an IDENTICAL DOM in light and dark', () => {
    // D4 as a property of the output rather than an intention. Any theme-dependent
    // branching inside this component would show up here as a diff.
    const light = inTheme(<OrderReady {...props} />, { theme: 'light' })
    const lightHtml = light.container.querySelector('.qr-ready')!.outerHTML
    cleanup()
    const dark = inTheme(<OrderReady {...props} />, { theme: 'dark' })
    const darkHtml = dark.container.querySelector('.qr-ready')!.outerHTML
    expect(darkHtml).toBe(lightHtml)
  })

  it('takes a logo slot and renders nothing when there is none', () => {
    inTheme(<OrderReady {...props} logo={<img src="/logo.svg" alt="Cafe" />} />)
    expect(screen.getByAltText('Cafe')).toBeTruthy()
    cleanup()
    inTheme(<OrderReady {...props} />)
    expect(document.querySelector('.qr-ready__logo')).toBeNull()
  })

  it('moves to the acknowledged state and reports the click', () => {
    const onAcknowledge = vi.fn()
    const { rerender } = inTheme(<OrderReady {...props} onAcknowledge={onAcknowledge} />)
    expect(document.querySelector('.qr-ready')!.getAttribute('data-state')).toBe('fresh')
    screen.getByRole('button', { name: 'On my way' }).click()
    expect(onAcknowledge).toHaveBeenCalledOnce()
    rerender(
      <ThemeProvider>
        <OrderReady {...props} onAcknowledge={onAcknowledge} acknowledged />
      </ThemeProvider>,
    )
    expect(document.querySelector('.qr-ready')!.getAttribute('data-state')).toBe('acknowledged')
  })

  it('contains no inline colour at all, so nothing can be themed into it', () => {
    inTheme(<OrderReady {...props} />)
    const html = document.querySelector('.qr-ready')!.outerHTML
    expect(html).not.toMatch(/#[0-9a-f]{3,8}\b/i)
    expect(html).not.toMatch(/rgba?\(|hsla?\(|oklch\(/i)
  })
})
