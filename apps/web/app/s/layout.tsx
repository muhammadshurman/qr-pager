import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

/**
 * ================================================================================
 * THE CUSTOMER PWA's OWN HEAD. Scoped to `/s`, like the harness's is to `/harness/push`.
 * ================================================================================
 *
 * WHY A NESTED LAYOUT RATHER THAN THE ROOT. QRPAGER-24 made this choice first and stated
 * the reason: declaring a manifest at the root would make every future route installable
 * under one app's identity. `apps/web` carries Task 10's staff dashboard and KDS as well,
 * and a staff device installing "your order pager" is a scope leak in the other direction.
 * Scoped here, the customer journey is installable and nothing else is.
 *
 * TWO MANIFESTS IN THIS APP, DELIBERATELY, and they are not duplicates:
 * `/manifest.webmanifest` is QRPAGER-24's push harness, whose `start_url` is
 * `/harness/push`; `/pager.webmanifest` is this one. A single manifest would make an install
 * from either route launch the other.
 */
export const metadata: Metadata = {
  title: 'Your order',
  description: 'Scan, sit down, and we will page you when your order is ready.',
  manifest: '/pager.webmanifest',

  appleWebApp: {
    capable: true,
    title: 'Your order',
    // `default`, not `black-translucent`: translucent puts the page under the status bar,
    // and the scan landing's top content would sit behind the clock.
    statusBarStyle: 'default',
  },

  /*
   * ⚠ `apple-mobile-web-app-capable` BY HAND, AND IT IS NOT REDUNDANT WITH `appleWebApp`.
   * QRPAGER-24 measured it on the built page: Next 15.5.28 renders `appleWebApp.capable:
   * true` as the STANDARD `<meta name="mobile-web-app-capable">` and emits no `apple-`
   * prefixed tag, while emitting `apple-mobile-web-app-title` and
   * `apple-mobile-web-app-status-bar-style` from the same object -- which is what makes the
   * omission easy to miss. Older iOS reads only the prefixed name for a standalone launch,
   * and `§4 › Realtime` makes the install the only path to a notification on iOS.
   *
   * `apps/web/test/build-surface.test.ts` asserts BOTH names are in the built HTML, so this
   * cannot be tidied away by someone who reads the object above and assumes it covers it.
   */
  other: { 'apple-mobile-web-app-capable': 'yes' },

  // A pager URL is a per-session secret in a fragment. Nothing here should be indexed.
  robots: { index: false, follow: false },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // No `maximumScale`, no `userScalable: false`. Pinch-zoom is an accessibility affordance
  // and disabling it is a `§12` failure -- the root layout says the same, and this repeats it
  // because a route's viewport REPLACES the root's rather than merging with it.
  viewportFit: 'cover',
}

export default function PagerLayout({ children }: { readonly children: ReactNode }) {
  return children
}
