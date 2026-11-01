/**
 * ================================================================================
 * THE HARNESS MANIFEST, SERVED PER SESSION SO `start_url` CARRIES THE TOKEN
 * ================================================================================
 *
 * ⚠ WHY THIS EXISTS: THE DEVICE RUN OF 2026-11-02 WAS BLOCKED HERE, AND THE PAGE WAS NOT THE
 * BUG. Measured on a real iPhone, installed to the Home Screen, verbatim:
 *
 *     [standalone] no token yet -- open this page as /harness/push#t=<token>
 *     [standalone] Notification.requestPermission() -> granted
 *     [standalone] refusing to subscribe: no token.
 *
 * Two of the four preconditions PASSED — the install really was `standalone` and permission was
 * `granted`. Only the token was missing, and two iOS behaviours together explain it exactly:
 *
 *   1. AN INSTALLED WEB APP LAUNCHES AT THE MANIFEST'S `start_url`, not at the URL that was
 *      bookmarked. The static `public/manifest.webmanifest` says `/harness/push` with no token,
 *      so the fragment never reaches the standalone context at all.
 *   2. A HOME-SCREEN WEB APP HAS ITS OWN STORAGE, separate from Safari's. So the `localStorage`
 *      the page writes on first read **in Safari** is invisible to the installed app.
 *
 * ⚠ AND `page.tsx` HAD ALREADY REASONED ABOUT (1) AND CHOSE (2) AS THE ANSWER — its comment
 * says the launch "carries NO fragment — so a token held only in memory ... goes to
 * `localStorage`". That is a correct reading of (1) and a wrong assumption about (2), which is
 * why the harness passed every automated check and failed on the one thing it exists to test.
 * `R-214` records the gap in the gate; this file is the fix.
 *
 * ⚠ THE TOKEN IS IN A QUERY STRING HERE, AND THAT IS A HARNESS-ONLY DEVIATION, RULED BY
 * MUHAMMAD ON 2026-11-02. `PLAN §3` row 3 as amended keeps the raw token in the URL **fragment**
 * for the customer path, and **PRODUCTION'S QR PAYLOAD IS UNCHANGED**: `PagerToken::qrPayload()`
 * still emits `{landing_url}#{token}`, and nothing on the customer surface moves. This is an
 * INSTALL-PATH ARTEFACT of a development-only harness, not a token-transport change — a
 * `start_url` cannot carry a fragment to a standalone launch, so for this one route the choice is
 * a query string or no device proof at all. The cost is that the token reaches this server's
 * access log for the length of one run with a two-hour throwaway session behind it.
 */
import { notFound } from 'next/navigation'

import type { NextRequest } from 'next/server'

/**
 * Per request, because the whole point is a `start_url` that differs per session. The sibling
 * routes are statically prerendered and must stay that way; this one cannot be.
 */
export const dynamic = 'force-dynamic'

/**
 * The same single switch the layout and `next.config.mjs` use (`§11` round two, SEC-R2 M-5), so
 * the harness cannot be half-present: with the flag unset this is a 404 exactly as
 * `/harness/push` is.
 */
const HARNESS_ENABLED = (process.env.QRPAGER_HARNESS_API_ORIGIN ?? '') !== ''

/** The token's shape, from the one source: `@qrpager/contracts`' generated alphabet and length. */
const TOKEN_PATTERN = /^[A-Za-z0-9_-]{8,64}$/

export function GET(request: NextRequest) {
  if (!HARNESS_ENABLED) {
    notFound()
  }

  const token = request.nextUrl.searchParams.get('t') ?? ''

  /*
   * ⚠ A BAD TOKEN GETS A MANIFEST WITH NO TOKEN, NOT AN ERROR, and the reason is the device
   * run rather than tidiness: a 4xx here makes the page uninstallable, and an operator holding
   * a phone would see "Add to Home Screen" greyed out with nothing saying why. A manifest whose
   * `start_url` has no token installs fine and the page then says `no token yet` in its own
   * transcript — which is the diagnosable failure, and is how this bug was found.
   */
  const valid = TOKEN_PATTERN.test(token)
  const startUrl = valid ? `/harness/push?t=${encodeURIComponent(token)}` : '/harness/push'

  const manifest = {
    name: 'QR Pager push harness',
    short_name: 'Pager harness',
    description:
      'QRPAGER-24: an unstyled harness that proves Web Push reaches an iOS PWA installed to the Home Screen.',
    start_url: startUrl,
    scope: '/',
    display: 'standalone',
    orientation: 'portrait',
    icons: [
      { src: '/harness-icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
      { src: '/harness-icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'maskable' },
    ],
  }

  return new Response(JSON.stringify(manifest, null, 2), {
    headers: {
      'content-type': 'application/manifest+json',
      // Never cached: the whole value of this route is that it differs per session, and a
      // cached copy would hand the next run the previous run's token.
      'cache-control': 'no-store, max-age=0',
      // The tunnel hostname is public while it lives, and this body carries a session token.
      'x-robots-tag': 'noindex, nofollow',
    },
  })
}
