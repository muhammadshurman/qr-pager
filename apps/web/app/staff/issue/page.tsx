import { StaffNavigator } from '../../../src/staff/StaffNavigator.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'

/**
 * STARTING A PAGER, AND RE-ISSUING A SHEET — `§4.2`'s *Create pager + print QR*, 10d.
 *
 * STATICALLY PRERENDERED and holding nothing protected, like every other staff route.
 *
 * ⚠ **THE ORDER BEING RE-ISSUED IS CARRIED IN MEMORY, NOT IN THE PATH.** The lost-sheet path
 * starts on the history screen and navigates here, and the obvious shape is
 * `/staff/issue/{id}` — which would make this a DYNAMIC route with a session id in a URL. A
 * pager session id is not a credential (the token is, and it is never in a URL), but a route
 * that takes one in its path cannot be prerendered, and the chain of that is in the docblock
 * above: the static document is what makes *nothing protected ships at a public URL* checkable
 * rather than aspirational.
 *
 * So `StaffApp` holds the order in state across the navigation, which is also the behaviour
 * the ruling wants: the confirmation is reached by a deliberate act on the history row and is
 * gone if the person navigates away, rather than living at a URL somebody can return to and
 * re-fire.
 */
export default function StaffIssueRoute() {
  return <StaffNavigator apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} view="issue" />
}
