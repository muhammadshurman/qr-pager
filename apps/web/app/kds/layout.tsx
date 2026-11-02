import type { Metadata, Viewport } from 'next'
import type { ReactNode } from 'react'

/**
 * ================================================================================
 * THE KITCHEN DISPLAY's OWN HEAD — AND 10c TAKING THE DECISION `/staff` HANDED IT
 * ================================================================================
 *
 * `app/staff/layout.tsx` declares **no manifest** and says why, then names this file as the
 * place the opposite decision gets made:
 *
 *   *"10c owns the kitchen display, which is the surface that genuinely wants to launch
 *   full-screen on a wall-mounted device — and it is a `Principal::Device` surface holding a
 *   device token rather than a human session, which is what makes that safe. That decision is
 *   10c's to take, and it is named here so it is taken rather than inherited."*
 *
 * ⚠ **IT IS TAKEN, AND THE ANSWER IS NO MANIFEST.** The reasoning that made the staff shell
 * un-installable does not apply here — there is no human session for the next person to pick
 * up the tablet and inherit — but a second one does, and it is the stronger of the two:
 *
 * **A manifest's `start_url` would be `/kds`, and `/kds` is a screen whose credential lives in
 * `localStorage`.** An installed app is a thing a person taps, shares, screenshots and
 * occasionally reinstalls, and each of those is a way for a thirty-day bearer credential for a
 * whole restaurant's order board to end up somewhere nobody intended. The *benefit* of the
 * install — launching full-screen without browser chrome — is available with no manifest at
 * all on the devices this actually runs on: every kiosk-mode tablet, every digital-signage
 * player and Chrome's own `--kiosk` take a URL and hide their own chrome. So the install would
 * buy a convenience these devices already have, at the cost of a second home for a credential.
 *
 * **What this means in practice, said out loud so it is a decision and not an omission:** a
 * kitchen screen is set up by typing this URL into a kiosk browser, once, and pasting the
 * code. It is not installed from a browser prompt. `kds-build-surface.test.ts` asserts no
 * manifest link and no `apple-mobile-web-app-capable` tag reaches this document, so a later
 * change that adds one fails rather than shipping.
 *
 * ⚠ `robots: noindex, nofollow`. The setup field is served at this public URL to any caller,
 * before any credential — which is a page a crawler would otherwise index under a
 * restaurant's domain, inviting people to a code-entry form they have no business at.
 */
export const metadata: Metadata = {
  title: 'Kitchen screen',
  robots: { index: false, follow: false },
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // No `maximumScale` and no `userScalable: false`: pinch-zoom is an accessibility affordance
  // and disabling it is a `§12` failure. Repeated from the root layout because a route's
  // viewport REPLACES the root's rather than merging with it.
}

export default function KdsLayout({ children }: { readonly children: ReactNode }) {
  return children
}
