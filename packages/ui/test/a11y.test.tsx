import { useRef, useState } from 'react'

import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import {
  ThemeProvider,
  focusableWithin,
  nextId,
  resetIdCounter,
  useFocusTrap,
  useRovingTabIndex,
} from '../src/index.ts'

afterEach(cleanup)

/**
 * The two primitives with real behaviour, driven rather than read.
 *
 * `§4.2` of the ticket answers argues these are written here instead of installed, which
 * only holds if they actually work -- an untested hand-written focus trap is worse than a
 * dependency, because the dependency has other people's tests behind it.
 */

// ====================================================================== focus trap

function Dialog({ open }: { readonly open: boolean }) {
  const ref = useRef<HTMLDivElement>(null)
  useFocusTrap(ref, open)
  return (
    <>
      <button type="button" id="outside">
        Outside
      </button>
      {open && (
        <div ref={ref} role="dialog" aria-label="A dialog">
          <button type="button" id="first">
            First
          </button>
          <input id="middle" />
          <button type="button" id="last">
            Last
          </button>
        </div>
      )}
    </>
  )
}

describe('useFocusTrap', () => {
  it('moves focus to the first focusable element when it opens', () => {
    render(<Dialog open />)
    expect(document.activeElement?.id).toBe('first')
  })

  it('does nothing while inactive', () => {
    render(<Dialog open={false} />)
    // Not focused into anything: an inactive trap must not steal focus.
    expect(document.activeElement?.id).not.toBe('first')
  })

  it('wraps Tab from the last element back to the first', () => {
    render(<Dialog open />)
    screen.getByText('Last').focus()
    // The trap's listener is a capture listener on `document`, not a React handler, so a
    // real dispatch is the right driver here -- and focus moves synchronously.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
    expect(document.activeElement?.id).toBe('first')
  })

  it('wraps Shift+Tab from the first element to the last', () => {
    render(<Dialog open />)
    screen.getByText('First').focus()
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }),
    )
    expect(document.activeElement?.id).toBe('last')
  })

  it('pulls focus back in when it has escaped the container', () => {
    render(<Dialog open />)
    screen.getByText('Outside').focus()
    document.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }),
    )
    expect(document.activeElement?.id).toBe('last')
  })

  it('ignores keys that are not Tab', () => {
    render(<Dialog open />)
    screen.getByText('Last').focus()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }))
    expect(document.activeElement?.id).toBe('last')
  })

  it('RESTORES focus to where it was when it closes', () => {
    // The half that is usually missed: a trap that works and then drops the user at the top
    // of the document has moved the problem rather than solved it.
    const { rerender } = render(<Dialog open={false} />)
    screen.getByText('Outside').focus()
    expect(document.activeElement?.id).toBe('outside')

    rerender(<Dialog open />)
    expect(document.activeElement?.id).toBe('first')

    rerender(<Dialog open={false} />)
    expect(document.activeElement?.id).toBe('outside')
  })

  it('does not throw when the container holds nothing focusable', () => {
    function Empty() {
      const ref = useRef<HTMLDivElement>(null)
      useFocusTrap(ref, true)
      return <div ref={ref} role="dialog" aria-label="Empty" />
    }
    expect(() => render(<Empty />)).not.toThrow()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
  })

  it('does not throw when the ref is never attached', () => {
    function Detached() {
      const ref = useRef<HTMLDivElement>(null)
      useFocusTrap(ref, true)
      return <p>no container</p>
    }
    expect(() => render(<Detached />)).not.toThrow()
  })
})

describe('focusableWithin', () => {
  it('finds focusable elements in document order and skips disabled ones', () => {
    const { container } = render(
      <div>
        <a href="#x">link</a>
        <button type="button">enabled</button>
        <button type="button" disabled>
          disabled
        </button>
        <input />
        <input disabled />
        <input type="hidden" />
        <select>
          <option>a</option>
        </select>
        <textarea />
        <span tabIndex={0}>focusable span</span>
        <span tabIndex={-1}>not in the sequence</span>
      </div>,
    )
    const found = focusableWithin(container.firstElementChild as HTMLElement)
    expect(found.map((e) => e.tagName)).toEqual([
      'A',
      'BUTTON',
      'INPUT',
      'SELECT',
      'TEXTAREA',
      'SPAN',
    ])
  })
})

// ================================================================= roving tabindex

function Toolbar({ locale }: { readonly locale: 'en' | 'ar' }) {
  const items = ['One', 'Two', 'Three']
  const { activeIndex, onKeyDown, itemProps, setActiveIndex } = useRovingTabIndex(
    items.length,
    locale === 'ar',
  )
  const [clicked, setClicked] = useState(-1)

  return (
    <div role="toolbar" aria-label="Tools" onKeyDown={onKeyDown} data-active-index={activeIndex}>
      {items.map((item, i) => (
        <button
          type="button"
          key={item}
          {...itemProps(i)}
          onClick={() => {
            setActiveIndex(i)
            setClicked(i)
          }}
        >
          {item}
        </button>
      ))}
      <output>{clicked}</output>
    </div>
  )
}

function renderToolbar(locale: 'en' | 'ar') {
  return render(
    <ThemeProvider locale={locale}>
      <Toolbar locale={locale} />
    </ThemeProvider>,
  )
}

/**
 * React delegates its event listeners to the root and BATCHES the state update, so a raw
 * `dispatchEvent` returns before the DOM reflects it and an assertion straight after reads
 * the old value. `fireEvent` wraps the dispatch in `act()`, which flushes it.
 */
function press(key: string, shiftKey = false) {
  const toolbar = screen.getByRole('toolbar')
  fireEvent.keyDown(toolbar, { key, shiftKey })
  return toolbar
}

describe('useRovingTabIndex', () => {
  it('gives exactly ONE tab stop', () => {
    renderToolbar('en')
    const buttons = screen.getAllByRole('button')
    expect(buttons.map((b) => b.getAttribute('tabindex'))).toEqual(['0', '-1', '-1'])
  })

  it('moves forward with ArrowRight under LTR', () => {
    renderToolbar('en')
    expect(press('ArrowRight').getAttribute('data-active-index')).toBe('1')
  })

  it('moves BACKWARD with ArrowRight under RTL -- the trap §6 names', () => {
    // A component that hard-codes ArrowRight -> next is broken in Arabic and looks correct
    // in English, which is the way this defect always ships.
    renderToolbar('ar')
    expect(press('ArrowRight').getAttribute('data-active-index')).toBe('2')
  })

  it('moves FORWARD with ArrowLeft under RTL', () => {
    renderToolbar('ar')
    expect(press('ArrowLeft').getAttribute('data-active-index')).toBe('1')
  })

  it('moves backward with ArrowLeft under LTR', () => {
    renderToolbar('en')
    press('ArrowRight')
    press('ArrowRight')
    expect(press('ArrowLeft').getAttribute('data-active-index')).toBe('1')
  })

  it('uses ArrowDown and ArrowUp regardless of direction -- vertical does not mirror', () => {
    renderToolbar('ar')
    expect(press('ArrowDown').getAttribute('data-active-index')).toBe('1')
    expect(press('ArrowUp').getAttribute('data-active-index')).toBe('0')
  })

  it('wraps at both ends', () => {
    renderToolbar('en')
    expect(press('ArrowLeft').getAttribute('data-active-index')).toBe('2')
    expect(press('ArrowRight').getAttribute('data-active-index')).toBe('0')
  })

  it('jumps to the ends with Home and End', () => {
    renderToolbar('en')
    expect(press('End').getAttribute('data-active-index')).toBe('2')
    expect(press('Home').getAttribute('data-active-index')).toBe('0')
  })

  it('ignores an unrelated key', () => {
    renderToolbar('en')
    expect(press('a').getAttribute('data-active-index')).toBe('0')
  })

  it('marks the active item, so a style can follow focus', () => {
    renderToolbar('en')
    press('ArrowRight')
    expect(screen.getByText('Two').getAttribute('data-active')).toBe('true')
    expect(screen.getByText('One').getAttribute('data-active')).toBeNull()
  })

  it('lets a click set the active item', () => {
    renderToolbar('en')
    // fireEvent, not .click(): the same batching reason as press() above.
    fireEvent.click(screen.getByText('Three'))
    expect(screen.getByRole('toolbar').getAttribute('data-active-index')).toBe('2')
  })

  it('does nothing with an empty collection', () => {
    function Empty() {
      const { activeIndex, onKeyDown } = useRovingTabIndex(0, false)
      return (
        <div
          role="toolbar"
          aria-label="Empty"
          onKeyDown={onKeyDown}
          data-active-index={activeIndex}
        />
      )
    }
    render(<Empty />)
    expect(press('ArrowRight').getAttribute('data-active-index')).toBe('0')
  })
})

describe('nextId', () => {
  it('produces a distinct id per call, with the prefix', () => {
    resetIdCounter()
    expect(nextId('field')).toBe('field-1')
    expect(nextId('field')).toBe('field-2')
    expect(nextId('other')).toBe('other-3')
  })

  it('resets, so an id assertion does not depend on test order', () => {
    resetIdCounter()
    expect(nextId('x')).toBe('x-1')
  })
})
