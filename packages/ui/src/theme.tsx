import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react'

import { THEMES, type Theme } from '@qrpager/tokens'

import { directionFor, type Direction, type Locale } from './locale.ts'

/**
 * ================================================================================
 * THEME AND DIRECTION. TWO AXES, AND RTL IS NOT ONE OF THEM ALONE.
 * ================================================================================
 *
 * `§12`: "RTL-safe layout -- Arabic is a launch language." QRPAGER-15 §6, trap 2 is
 * sharper: **RTL is not `direction: rtl`.** Icons, progress, timers and anything with
 * directional meaning mirror; logos and numbers do not. So direction is a VALUE components
 * read and decide with, not just an attribute set on `<html>` and forgotten.
 *
 * The theme is an ATTRIBUTE (`data-theme`), not a media query alone, so a tenant's staff can
 * be offered a choice later without the token file changing. The generated CSS also honours
 * `prefers-color-scheme` for a visitor who has expressed one and whose attribute has not
 * already decided.
 */

export interface ThemeContextValue {
  readonly theme: Theme
  readonly locale: Locale
  readonly direction: Direction
  /**
   * Whether this direction mirrors relative to the authored LTR layout.
   *
   * Components ask this rather than comparing to `'rtl'` themselves, so "what mirrors" is
   * one decision and not one per component.
   */
  readonly mirrors: boolean
}

const DEFAULT: ThemeContextValue = {
  theme: 'light',
  locale: 'en',
  direction: 'ltr',
  mirrors: false,
}

const ThemeContext = createContext<ThemeContextValue>(DEFAULT)

export interface ThemeProviderProps {
  readonly theme?: Theme
  readonly locale?: Locale
  readonly children: ReactNode
  /** Render as a different element, for a provider that is not the document root. */
  readonly as?: 'div' | 'section' | 'main'
}

/**
 * Sets the theme attribute and the direction, and publishes both as context.
 *
 * `lang` and `dir` are set on the same element, together. They are two halves of one fact
 * and separating them is how a page ends up with Arabic text laid out left to right -- the
 * property `PlatformPanelAccessibilityTest` already asserts for the admin panel.
 */
export function ThemeProvider({
  theme = 'light',
  locale = 'en',
  children,
  as: Element = 'div',
}: ThemeProviderProps) {
  const value = useMemo<ThemeContextValue>(() => {
    const direction = directionFor(locale)
    return { theme, locale, direction, mirrors: direction === 'rtl' }
  }, [theme, locale])

  /**
   * ================================================================================
   * THE DOCUMENT ELEMENT GETS THE SAME TWO ATTRIBUTES. `R-221`, closed by `QRPAGER-30`.
   * ================================================================================
   *
   * `apps/web/app/layout.tsx` renders `<html lang="en" dir="ltr">` into a STATICALLY
   * PRERENDERED document and said those *"become per-request in Task 9"*. Task 9 did not move
   * them, and `R-221` recorded the consequence: *"the DOCUMENT's language and direction are
   * wrong for every Arabic visitor, on both surfaces."*
   *
   * ⚠ **AND THEY CANNOT BECOME PER-REQUEST, WHICH IS WHY THIS IS A CLIENT EFFECT AND NOT A
   * SERVER FIX.** Measured for `QRPAGER-30`: the customer's language comes from
   * `navigator.languages` by `§4.1`'s own ruling (*"zero chrome"*, no switcher), and the QR
   * token that identifies the restaurant lives in the URL **fragment** -- never sent to the
   * server (RFC 3986 §3.5), read and stripped by `pager/token.ts`. So the server has no
   * language to render, statically or dynamically; `force-dynamic` would buy a per-request
   * render with exactly the same information: none. Nothing in this change makes any route
   * dynamic.
   *
   * **WHY HERE AND NOT IN EACH APP.** All four surfaces -- `PagerJourney`, `KdsApp`,
   * `StaffApp` and the landing templates -- resolve a locale with `browserLocale(navigator)`
   * and hand it to this provider. Syncing from `value` means the document can never disagree
   * with what the surface actually rendered, because it is the same value and not a second
   * read of the same source. A per-app `useEffect` would be four copies that agree on the day
   * they are written.
   *
   * `as` is irrelevant here: a provider rendering as the root element would set the same two
   * attributes on itself, and setting them twice with the same values is a no-op.
   * `document.documentElement` is guarded because this module renders on the server too.
   */
  useEffect(() => {
    if (typeof document === 'undefined') return

    document.documentElement.lang = value.locale
    document.documentElement.dir = value.direction
  }, [value.locale, value.direction])

  return (
    <ThemeContext.Provider value={value}>
      <Element data-theme={value.theme} lang={value.locale} dir={value.direction}>
        {children}
      </Element>
    </ThemeContext.Provider>
  )
}

export function useTheme(): ThemeContextValue {
  return useContext(ThemeContext)
}

/** Every theme the token source emits, for a consumer that offers a choice. */
export const AVAILABLE_THEMES = THEMES
