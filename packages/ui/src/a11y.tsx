import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
  type RefObject,
} from 'react'

/**
 * ================================================================================
 * ACCESSIBILITY PRIMITIVES. WRITTEN HERE RATHER THAN INSTALLED.
 * ================================================================================
 *
 * `§4.2` of the ticket answers: this kit ships NO runtime dependency beyond React. A
 * headless-UI package would bring a license, a maintenance surface and a CVE surface
 * (`§11.7`) to replace a few dozen lines written against the WAI-ARIA patterns -- and it
 * would bring a look, which `§12`'s "no library default look" forbids.
 */

/**
 * Content for assistive technology only, with no visual presence.
 *
 * NOT `display: none` and not `visibility: hidden`, either of which removes it from the
 * accessibility tree as well -- which would make it useless for the thing it exists for.
 */
export function VisuallyHidden({ children }: { readonly children: ReactNode }) {
  return <span className="qr-visually-hidden">{children}</span>
}

export type Politeness = 'polite' | 'assertive'

/**
 * An ARIA live region.
 *
 * The region must be in the DOM BEFORE the message changes, or a screen reader has nothing
 * to observe and announces nothing. That is why this renders an always-present element and
 * changes its text, rather than mounting an element when there is something to say.
 *
 * `assertive` interrupts the user and is for the order being ready; `polite` waits, and is
 * for everything else. A product that announces everything assertively has announced
 * nothing.
 */
export function LiveRegion({
  message,
  politeness = 'polite',
}: {
  readonly message: string
  readonly politeness?: Politeness
}) {
  return (
    <div className="qr-visually-hidden" role="status" aria-live={politeness} aria-atomic="true">
      {message}
    </div>
  )
}

/** Everything focusable inside a container, in document order. */
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

/**
 * ⚠ NO LAYOUT PROBE HERE, AND THAT WAS A DEFECT ONCE.
 *
 * This filtered on `element.offsetParent !== null || element.getClientRects().length > 0` to
 * skip hidden elements. Both are layout queries, and anything without a layout engine --
 * jsdom, a server render, a hidden ancestor during a transition -- answers "nothing is
 * visible". So the trap found NO focusable elements and trapped nothing, which is the
 * failure mode a focus trap exists to prevent, arriving by the back door.
 *
 * It is not replaced with a cleverer probe, because there is no reliable one that does not
 * need layout. Instead `useFocusTrap` tries each candidate in order until one actually takes
 * focus: `.focus()` on a `display: none` element is a no-op, so the DOM itself answers the
 * question the probe was guessing at.
 */
export function focusableWithin(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE))
}

/**
 * Keep Tab inside a container while it is active, and restore focus when it closes.
 *
 * Restoring focus is the half that is usually missed: a dialog that traps focus correctly
 * and then drops the user at the top of the document on close has moved the problem rather
 * than solved it.
 */
export function useFocusTrap(containerRef: RefObject<HTMLElement | null>, active: boolean): void {
  const previouslyFocused = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!active) return
    const container = containerRef.current
    if (!container) return

    previouslyFocused.current = document.activeElement as HTMLElement | null

    // Try each in order until one actually takes focus. `.focus()` on an element that
    // cannot receive it is a no-op, so this asks the DOM rather than guessing from layout.
    for (const candidate of focusableWithin(container)) {
      candidate.focus()
      if (document.activeElement === candidate) break
    }

    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key !== 'Tab') return
      const focusable = focusableWithin(container)
      // A destructure plus an `at(-1)`, rather than `[0]!` and `[len - 1]!`. The `!` was
      // asserting away exactly what `noUncheckedIndexedAccess` exists to surface, and an
      // empty container is a real case -- a dialog whose content has not rendered yet.
      const [firstEl] = focusable
      const lastEl = focusable.at(-1)
      if (firstEl === undefined || lastEl === undefined) {
        event.preventDefault()
        return
      }
      const activeEl = document.activeElement

      if (event.shiftKey && (activeEl === firstEl || !container.contains(activeEl))) {
        event.preventDefault()
        lastEl.focus()
      } else if (!event.shiftKey && activeEl === lastEl) {
        event.preventDefault()
        firstEl.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      previouslyFocused.current?.focus()
    }
  }, [containerRef, active])
}

/**
 * Roving tabindex over a list: one tab stop, arrow keys to move within it.
 *
 * ⚠ THE ARROW KEYS DEPEND ON DIRECTION, and this is exactly the trap QRPAGER-15 §6 names.
 * Under RTL, ArrowLeft moves to the NEXT item, because "next" is leftward. A component that
 * hard-codes ArrowRight -> next is broken in Arabic and looks correct in English, which is
 * the way this defect always ships.
 */
export function useRovingTabIndex(count: number, mirrors: boolean) {
  const [activeIndex, setActiveIndex] = useState(0)

  const onKeyDown = useCallback(
    (event: ReactKeyboardEvent): void => {
      if (count === 0) return
      const forward = mirrors ? 'ArrowLeft' : 'ArrowRight'
      const backward = mirrors ? 'ArrowRight' : 'ArrowLeft'

      let next: number | null = null
      if (event.key === forward || event.key === 'ArrowDown') next = (activeIndex + 1) % count
      else if (event.key === backward || event.key === 'ArrowUp')
        next = (activeIndex - 1 + count) % count
      else if (event.key === 'Home') next = 0
      else if (event.key === 'End') next = count - 1

      if (next !== null) {
        event.preventDefault()
        setActiveIndex(next)
      }
    },
    [activeIndex, count, mirrors],
  )

  const itemProps = useCallback(
    (index: number) => ({
      tabIndex: index === activeIndex ? 0 : -1,
      'data-active': index === activeIndex ? 'true' : undefined,
    }),
    [activeIndex],
  )

  return { activeIndex, setActiveIndex, onKeyDown, itemProps }
}

/** A stable id for associating a label, a hint and an error with a control. */
let idCounter = 0
export function nextId(prefix: string): string {
  idCounter += 1
  return `${prefix}-${String(idCounter)}`
}

/** Reset between tests, so an id assertion does not depend on test order. */
export function resetIdCounter(): void {
  idCounter = 0
}
