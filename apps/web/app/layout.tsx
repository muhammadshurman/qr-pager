import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

import { resolveColour } from '@qrpager/tokens'

import './globals.css'

/**
 * The customer PWA + staff shell. Its SCREENS are Tasks 9 and 10 (§4.1, §4.2); what this
 * task owns is the application, the token wiring and the document's language and direction.
 *
 * `lang` and `dir` are set together, on `<html>`, because they are two halves of one fact --
 * the same property PlatformPanelAccessibilityTest already pins for the admin panel. They
 * are hard-coded to `en`/`ltr` here and become per-request in Task 9, which is the task that
 * owns the locale decision.
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
    <html lang="en" dir="ltr">
      <body>{children}</body>
    </html>
  )
}
