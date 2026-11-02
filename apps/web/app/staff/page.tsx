import { StaffNavigator } from '../../src/staff/StaffNavigator.tsx'

import '@qrpager/ui/kit.css'
import '@qrpager/ui/staff.css'

/**
 * THE ACTIVE PAGER BOARD — `§4.2`'s own first staff surface, mounted in 10a's chrome.
 *
 * ⚠ THIS DOCBLOCK DESCRIBED A SCREEN THAT NO LONGER EXISTS UNTIL 10b's FIX ROUND (`§11` QA
 * L-22). It read *"`§4.2`'s active pager board is 10b's; this is the chrome it mounts in, and
 * `StaffHome` says so to the person in plain words rather than as a placeholder"* — written
 * while the board was still ahead, and left standing in the commit that delivered it.
 * `StaffHome` is deleted and the board is here.
 *
 * STATICALLY PRERENDERED, like `/s`: no `fetch` at build time, no dynamic segment, no server
 * action. **Nothing protected is in this document** -- every byte of tenant data arrives
 * from `/v1` at run time into a client component's state, which is what makes the lock's
 * "the shell holds no tenant datum" checkable rather than aspirational.
 *
 * `NEXT_PUBLIC_QRPAGER_API_BASE` is inlined and is PUBLIC BY DESIGN: it is a hostname, and
 * it is empty in every real build, which makes every call same-origin. The staff credential
 * is a first-party cookie and never reaches this bundle.
 */
export default function StaffBoardRoute() {
  return <StaffNavigator apiBase={process.env.NEXT_PUBLIC_QRPAGER_API_BASE ?? ''} view="home" />
}
