import { notFound } from 'next/navigation'

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

/**
 * ⛔ THE HARNESS IS NOT PART OF A DEFAULT PRODUCTION BUILD. `§11` round two, SEC-R2 M-5.
 *
 * Nothing gated this route: `next.config.mjs` gates only the `/v1` REWRITE, so `/harness/push`
 * shipped in every build of the customer origin. Round one then repointed it at the PRODUCTION
 * credential entry (`qrpager-pager-credential` / `/pager-credential`) to satisfy SEC M-3 — which
 * made it a WRITER of the one entry the shipped worker trusts, so the fix widened the planting
 * surface instead of narrowing it.
 *
 * The attack the reviewer named: link a customer to `/harness/push#t=<attacker token>`; after
 * they grant notifications and tap subscribe, their device's push endpoint is registered against
 * the attacker's session and their own rotation handler no longer holds their token. It needs a
 * user gesture, which is why it was Medium and not High — and it needs the route to exist.
 *
 * `QRPAGER_HARNESS_API_ORIGIN` is the flag `next.config.mjs` already uses for the same harness,
 * so there is ONE switch rather than two that can disagree. Read at module scope, not per
 * request: these are statically prerendered routes, so an unset flag makes the route a 404 in
 * the built output rather than at run time. `build-surface.test.ts` asserts that against the
 * build.
 *
 * ⚠ CORRECTED 2026-11-02: UNTIL THAT DATE IT DID NOT, and the sentence above was false (`§8`:
 * do not claim a test passed). `build-surface.test.ts` read only `server/app/s.html` and
 * asserted nothing whatever about `/harness/**`, so SEC-R2 M-5's fix rested on a module-scope
 * constant that nothing held to the artefact -- the next refactor moving the flag would have
 * reopened M-5 with a green gate. Found by the scoped review of tonight's diff, by grepping the
 * test directory rather than by trusting this comment. The assertions exist now: `push.meta`
 * carries status 404, the built scan landing mentions no harness, and no per-session manifest
 * route is in the build.
 *
 * ⚠ AND THE *"statically prerendered routes"* REASONING DOES NOT EXTEND TO A ROUTE HANDLER. A
 * `force-dynamic` handler keeps its `process.env` read in the artefact and resolves it per
 * server start, so a flag-unset BUILD still ships a live handler. That is why the per-session
 * manifest route was REMOVED rather than gated, and why one of the new cases pins its absence.
 */
const HARNESS_ENABLED = (process.env.QRPAGER_HARNESS_API_ORIGIN ?? '') !== ''

export default function PushHarnessLayout({ children }: { readonly children: ReactNode }) {
  if (!HARNESS_ENABLED) {
    // A 404 in the BUILT output, not a redirect: there is nothing here to tell anyone about.
    notFound()
  }

  return children
}
