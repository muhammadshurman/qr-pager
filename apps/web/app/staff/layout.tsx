import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

/**
 * ================================================================================
 * THE STAFF SURFACE's OWN HEAD. Scoped to `/staff`, and DELIBERATELY NOT INSTALLABLE.
 * ================================================================================
 *
 * `app/s/layout.tsx` declares the customer manifest and says why it is scoped: *"a staff
 * device installing 'your order pager' is a scope leak in the other direction."* This layout
 * is the other half of that decision and it declares **no manifest at all**. A staff shell
 * that offered an install prompt on a shared kitchen tablet would give it an app identity
 * whose `start_url` is an authenticated surface, and whose session the next person to pick up
 * the tablet inherits.
 *
 * 10c owns the kitchen display, which is the surface that genuinely wants to launch
 * full-screen on a wall-mounted device — and it is a `Principal::Device` surface holding a
 * device token rather than a human session, which is what makes that safe. That decision is
 * 10c's to take, and it is named here so it is taken rather than inherited.
 *
 * ⚠ `robots: noindex, nofollow`. There is nothing here a search engine should hold, and the
 * sign-in view is served at every `/staff/*` URL to an unauthenticated caller -- which is a
 * page a crawler would otherwise index under a restaurant's domain.
 */
export const metadata: Metadata = {
  title: 'Staff',
  robots: { index: false, follow: false },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // No `maximumScale` and no `userScalable: false`: pinch-zoom is an accessibility
  // affordance and disabling it is a `§12` failure. Repeated from the root layout because a
  // route's viewport REPLACES the root's rather than merging with it.
}

export default function StaffLayout({ children }: { readonly children: ReactNode }) {
  return children
}
