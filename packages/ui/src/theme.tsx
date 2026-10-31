import { createContext, useContext, useMemo, type ReactNode } from 'react'

import { THEMES, type Theme } from '@qrpager/tokens'

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

export type Direction = 'ltr' | 'rtl'

/** The launch languages. `ar` is not a later addition (§12). */
export const LOCALES = ['en', 'ar'] as const
export type Locale = (typeof LOCALES)[number]

/** Which locales are written right to left. The one place that mapping exists. */
const RTL_LOCALES: readonly Locale[] = ['ar']

export function directionFor(locale: Locale): Direction {
  return RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr'
}

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
