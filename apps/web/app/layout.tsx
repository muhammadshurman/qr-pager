import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

import { directionFor } from '@qrpager/ui/locale'

import { resolveColour } from '@qrpager/tokens'

import { FALLBACK } from '../src/pager/locale.ts'

import './globals.css'

/**
 * The customer PWA + staff shell. Its SCREENS are Tasks 9 and 10 (§4.1, §4.2); what this
 * task owns is the application, the token wiring and the document's language and direction.
 *
 * `lang` and `dir` are set together, on `<html>`, because they are two halves of one fact --
 * the same property PlatformPanelAccessibilityTest already pins for the admin panel.
 *
 * ================================================================================
 * THEY ARE THE PRE-HYDRATION FALLBACK, AND THEY ARE NOT PER-REQUEST. R-221, QRPAGER-30.
 * ================================================================================
 *
 * This comment used to say they *"become per-request in Task 9, which is the task that owns
 * the locale decision"*. Task 9 took that decision and it went the other way, which is what
 * left `R-221` open with nobody acting on it: `§4.1` rules the customer surface has **zero
 * chrome and no language switcher**, so the language is the VISITOR's, read from
 * `navigator.languages`; and the QR token that identifies the restaurant lives in the URL
 * **fragment**, which is never sent to the server (RFC 3986 §3.5). So there is no
 * per-request language to render here -- measured for `QRPAGER-30`, and `force-dynamic`
 * would buy a per-request render with the same information: none.
 *
 * What these two attributes are, therefore, is the value a reader sees for the one paint
 * before hydration. `ThemeProvider` sets the real pair on `document.documentElement` from the
 * same locale the surface renders with, which is what closes `R-221`.
 *
 * ⚠ READ FROM `FALLBACK`, NOT TYPED, and `dir` DERIVED rather than written beside it. The
 * fallback is `resolveLocale`'s own answer for a browser whose list matches no launch
 * language, so a literal `"en"` here would be a second copy of it that a change to the
 * fallback would not reach -- and `directionFor` is the kit's one mapping, the same shape the
 * ruling of 2026-11-03 requires of the tenant's language (*"`dir` is a DERIVATION ... two
 * columns that must agree are two sources of truth"*).
 */
export const metadata: Metadata = {
  title: 'QR Pager',
  description: 'Scan, sit down, and we will page you when your order is ready.',
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // No `maximumScale` and no `userScalable: false`: pinch-zoom is an accessibility
  // affordance and disabling it is a §12 failure, not a polish decision.
  // ⚠ READ FROM THE TOKEN SOURCE, NOT TYPED. These two were literal hexes when this file
  // was first written and the §12 lint rule caught them -- which is the rule working on
  // real code rather than on its fixture. A browser chrome colour is a design value like
  // any other, and `themeColor` is one of the few places it has to be a string rather than
  // a CSS custom property, because the browser reads it from the document head.
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: resolveColour('light', 'paper') },
    { media: '(prefers-color-scheme: dark)', color: resolveColour('dark', 'paper') },
  ],
}

export default function RootLayout({ children }: { readonly children: ReactNode }) {
  return (
    <html lang={FALLBACK} dir={directionFor(FALLBACK)}>
      <body>{children}</body>
    </html>
  )
}
