import type { Metadata } from 'next'
import type { ReactNode } from 'react'

/**
 * The harness route's own head, kept OFF the root layout.
 *
 * WHY A NESTED LAYOUT RATHER THAN EDITING `app/layout.tsx`. `QRPAGER-24 §2` puts every
 * customer PWA screen in Task 9, and the root layout is the shell those screens will share.
 * Declaring the harness's manifest and its iOS install meta at the root would make every
 * future Task 9 route installable as "QR Pager push harness" — a scope leak into the task
 * this ticket was deliberately kept out of. Scoped here, the harness is installable and
 * nothing else is.
 *
 * `appleWebApp.capable` AND the manifest's `display: standalone` are both set, deliberately.
 * iOS has honoured the manifest's `display` since 16.4, and `apple-mobile-web-app-capable`
 * is what older iOS reads; the ticket's precondition (a) is "a real iOS device" without
 * naming a version, so the harness must not be the reason an older one fails to launch
 * standalone. Two mechanisms for one property, which is the opposite of the duplicated-list
 * shape — they are read by different engine versions, not by the same one twice.
 */
export const metadata: Metadata = {
  title: 'QR Pager push harness',
  description: 'QRPAGER-24: Web Push to an iOS PWA installed to the Home Screen.',
  manifest: '/manifest.webmanifest',
  appleWebApp: {
    capable: true,
    title: 'Pager harness',
    statusBarStyle: 'default',
  },
  // Nothing here is for anyone but the operator, and a tunnel hostname is public while it
  // lives.
  robots: { index: false, follow: false },

  /*
   * ⚠ `apple-mobile-web-app-capable` BY HAND, AND IT IS NOT REDUNDANT WITH `appleWebApp`
   * ABOVE. MEASURED on the built page, 2026-11-01: Next 15.5.28 renders
   * `appleWebApp.capable: true` as the STANDARD `<meta name="mobile-web-app-capable">`
   * and emits no `apple-` prefixed tag at all — it emits `apple-mobile-web-app-title` and
   * `apple-mobile-web-app-status-bar-style` from the same object, which is what makes the
   * omission easy to miss: two of the three apple tags are there.
   *
   * Older iOS reads only the `apple-` prefixed name for standalone launch. The ticket's
   * precondition (a) is "a real iOS device" and names no version, and `§4`'s constraint is
   * that install → permission → subscribe → receive happens in ONE session — so a page
   * that launches in a tab on the operator's actual phone costs the whole run, and the
   * cost of the extra tag is one line. Both names are emitted; whichever the device reads,
   * it finds one.
   *
   * ⚠ WHAT IS NOT PROVIDED, said rather than discovered on the phone: no
   * `apple-touch-icon`. iOS does not accept SVG for it, and the manifest's SVG icons are
   * for Chrome's installability criteria. Without one iOS uses a SCREENSHOT of the page as
   * the Home Screen icon — the install still works and the push path is unaffected, so an
   * ugly icon is the right trade for a harness §1 requires to be unstyled.
   */
  other: { 'apple-mobile-web-app-capable': 'yes' },
}

export default function PushHarnessLayout({ children }: { readonly children: ReactNode }) {
  return children
}
