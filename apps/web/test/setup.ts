import '@testing-library/dom'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

/**
 * ⚠ `cleanup` IS REGISTERED BY HAND AND IT HAS TO BE. `@testing-library/react` installs its
 * own `afterEach` only when the test framework exposes `afterEach` as a GLOBAL, and this
 * workspace runs Vitest without globals. Without this, every render accumulates in one
 * `document.body` and `findByText` fails with *"Found multiple elements"* -- measured: 24 of
 * 24 orchestrator cases, and the error names the assertion rather than the cause.
 */
afterEach(() => {
  cleanup()
})

/**
 * jsdom, and the boundary is the same one `packages/ui`'s setup draws.
 *
 * What this environment CAN answer: the journey reducer's every branch, the cadence
 * arithmetic at exact values, the token handling against a real `location` and `history`,
 * the copy catalogue's completeness, and the DOM every screen produces -- roles,
 * attributes, direction, structure. That is most of what `§12`'s accessibility bar is about.
 *
 * What it CANNOT answer, and therefore what is NOT asserted here: a computed outline, a
 * rendered bounding box, a resolved colour pair, whether `prefers-reduced-motion` actually
 * suppressed anything, and whether a real engine sends `Sec-Fetch-Dest`. jsdom computes no
 * layout, so `getComputedStyle` returns declared values and `getBoundingClientRect` returns
 * zeros. Every one of those is in `browser/`, in three real engines.
 *
 * ⚠ ASSERTING ANY OF THEM HERE WOULD PASS AND MEAN NOTHING, which is the vacuous-assertion
 * defect `PLAN §4a` records four instances of -- three of them guarding named gate
 * invariants of tasks that were already tagged.
 */

/**
 * `matchMedia` is not implemented by jsdom at all, and three modules read it: the install
 * guidance, the alert's motion preference and the locale-independent display-mode probe.
 *
 * ⚠ IT DEFAULTS TO `matches: false` AND EVERY TEST THAT CARES SETS IT EXPLICITLY. A default
 * of `true` would make the reduced-motion assertion pass without the code doing anything,
 * and a default that throws would make every screen test fail for a reason unrelated to its
 * subject.
 */
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  configurable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => undefined,
    removeListener: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    dispatchEvent: () => false,
  }),
})
