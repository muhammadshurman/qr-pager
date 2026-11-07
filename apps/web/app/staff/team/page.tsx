import { StaffNavigator } from '../../../src/staff/StaffNavigator.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'

/**
 * THE RESTAURANT's STAFF — `§4.2`'s *Staff management*, 10e (`QRPAGER-29`), and the last
 * screen of Task 10.
 *
 * That inventory did not carry this surface until Muhammad's `R-218` ruling added it: it was
 * `R-115`'s third item, owned by Task 10 with the clause *"its gate must name all three"*, and
 * in no ticket's scope — *"an omission one layer up from the split rather than one the split
 * introduced"*.
 *
 * STATICALLY PRERENDERED and holding nothing protected, like every other staff route. That
 * property matters more here than on any of them: this document is served to any caller at a
 * public URL, and the data behind it is the names and email addresses of a restaurant's staff.
 * `apps/web/test/staff-build-surface.test.ts` asserts the absence against the built HTML.
 */
export default function StaffTeamRoute() {
  return <StaffNavigator apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} view="team" />
}
