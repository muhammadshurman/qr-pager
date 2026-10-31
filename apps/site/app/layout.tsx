import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

import { resolveColour } from '@qrpager/tokens'

import './globals.css'

/**
 * The public tenant landing shell.
 *
 * ⚠ NO THIRD-PARTY SCRIPT REACHES THIS ORIGIN, and that is a gate clause rather than a
 * preference: `§3` row 9 clause (b) requires the landing origin to load no analytics, no tag
 * manager and no error reporter, because Task 3 puts the raw QR token in the URL fragment
 * and any script on this origin can read `location.hash`. The CSP that makes it impossible
 * rather than merely untrue is Task 9's and Task 12's.
 *
 * The per-tenant `lang`, `dir`, theme and SEO are Task 11's, which owns the request and
 * therefore knows the tenant.
 */
export const metadata: Metadata = {
  title: 'QR Pager',
  description: 'Tenant landing pages, rendered from the approved token set.',
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
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
