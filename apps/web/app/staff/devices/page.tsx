import { StaffNavigator } from '../../../src/staff/StaffNavigator.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'

/**
 * KITCHEN SCREENS. `QRPAGER-25 §12` S4.
 *
 * A route of its own rather than a tab inside `/staff`, so it is linkable and so the browser
 * back button means what a person expects. It renders the SAME client shell with a different
 * view, and like `/staff` it is statically prerendered and carries nothing protected: an
 * unauthenticated caller gets the sign-in view AT THIS URL, with no redirect and therefore
 * no protected document that briefly existed.
 */
export default function StaffDevicesRoute() {
  return <StaffNavigator apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} view="devices" />
}
