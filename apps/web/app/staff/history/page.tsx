import { StaffNavigator } from '../../../src/staff/StaffNavigator.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'

/**
 * PAST ORDERS, WITH FILTERS — `§4.2`'s *History with filters*, 10d (`QRPAGER-28`).
 *
 * STATICALLY PRERENDERED, like every other staff route: no `fetch` at build time, no dynamic
 * segment, no server action. **Nothing protected is in this document** — every byte of tenant
 * data arrives from `/v1` at run time into a client component's state, and
 * `apps/web/test/staff-build-surface.test.ts` asserts that against the BUILT HTML of every
 * staff route, derived from the build rather than from a list.
 *
 * ⚠ **THE FILTERS ARE NOT IN THE URL, AND THAT IS A DECISION.** A filtered history is a
 * bookmarkable thing and putting the filters in a query string would make it shareable — which
 * is the ordinary web answer and the wrong one here. A shared `/staff/history?reference=A-14`
 * is a URL that means nothing to whoever opens it (they resolve a different restaurant), and
 * it would make this route's prerendered document depend on its search params, which Next
 * answers by making the route DYNAMIC. That would trade the *nothing protected is in this
 * document* property above for a convenience nobody asked for.
 */
export default function StaffHistoryRoute() {
  return <StaffNavigator apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} view="history" />
}
