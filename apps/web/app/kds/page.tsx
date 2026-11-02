import { KdsApp } from '../../src/KdsApp.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'
import '../../src/styles/kds.css'

/**
 * THE KITCHEN DISPLAY — `§4.2`'s *KDS view*, 10c (`QRPAGER-27`).
 *
 * STATICALLY PRERENDERED, like `/s` and `/staff`: no `fetch` at build time, no dynamic
 * segment, no server action. **Nothing protected is in this document** — the credential is a
 * bearer token in `localStorage` and every byte of tenant data arrives from `/v1` at run time
 * into a client component's state. `apps/web/test/kds-build-surface.test.ts` asserts that
 * against the built HTML rather than against this comment, which is the same mechanical shape
 * `staff-build-surface.test.ts` holds the staff routes to.
 *
 * ⚠ `staff.css` IS IMPORTED AS WELL AS `kds.css`, AND THAT IS NOT ACCIDENTAL REUSE. The
 * screens here are built from `StaffState`, `Button` and `Field` — the kit's staff layer —
 * because `§12`'s five states and the 44px target floor are the same requirement on both
 * surfaces and a second implementation of them would be a second thing to keep correct.
 * `kds.css` adds only what genuinely differs: the type scale for a two-metre reading
 * distance, and the grid.
 *
 * ⚠ THERE IS NO `/kds` ENTRY IN ANY NAVIGATION, AND THAT IS DELIBERATE. `staff.nav.*` has
 * `Today` and `Kitchen screens`; neither points here, because this route is not somewhere a
 * signed-in person goes — it is a URL typed once into a wall tablet's kiosk start page. A nav
 * link to it from the staff shell would invite a person with a session to open a screen whose
 * credential they do not have, land on the setup field, and read it as a fault.
 */
export default function KdsRoute() {
  return <KdsApp apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} />
}
