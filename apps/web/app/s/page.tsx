import { PagerJourney } from '../../src/PagerJourney.tsx'
import '../../src/styles/pager.css'

/**
 * ================================================================================
 * THE SCAN LANDING ROUTE. One document, six screens, token in the fragment.
 * ================================================================================
 *
 * ⚠ THE PATH CARRIES NO TOKEN, AND THAT IS `SR-1`. `§4.1` spells this screen `/s/{token}`;
 * `§3` row 3 as amended says *"The token goes in the fragment … The path and the query
 * string stay forbidden"*, and `PagerToken::qrPayload()` -- the only producer of a scannable
 * payload in the repository -- emits `{landing_url}#{token}`. The conflict is reported in
 * `docs/tickets/QRPAGER-16.md SR-1` rather than resolved here; this route is built against
 * the fragment, which is the only shape compatible with `§4 › Edge`'s *"a scan burst must
 * never reach origin"* (a token in the path is one cache key per scan) and the only shape a
 * scanned code can actually reach.
 *
 * STATICALLY PRERENDERED. No `fetch` at build time, no dynamic segment, no server action --
 * so `next build` emits one HTML file, which is `§4.1`'s *"static shell from CDN"* and what
 * makes one cache entry serve every scan in a burst.
 *
 * ⚠ BOTH INLINED VALUES ARE PUBLIC BY DESIGN. `NEXT_PUBLIC_*` is substituted into the
 * bundle, so neither may ever be a secret: the API base is a hostname, and a VAPID
 * application server key is the PUBLIC half of the pair -- it is what the browser hands to
 * the push service, and `minishlink/web-push` keeps the private half in `apps/api`'s
 * environment, which this build cannot read.
 */
export default function ScanLandingRoute() {
  return (
    <PagerJourney
      apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''}
      vapidPublicKey={process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? ''}
    />
  )
}
