import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

import { EN } from '../src/copy/en.ts'

/**
 * ================================================================================
 * THE KITCHEN DISPLAY's PRERENDERED DOCUMENT — the property an HTTP sweep cannot see
 * ================================================================================
 *
 * `staff-build-surface.test.ts` derives its subjects from `app/staff` and therefore does not
 * reach `/kds` at all. This is its sibling, for the same property and one more that is
 * specific to this surface.
 *
 * **The shared property:** `/kds` is served at a public URL to any caller, before any
 * credential. If its prerendered document carried a tenant datum, that datum would be in a
 * static file on a CDN — and no amount of server-side isolation would help, because the leak
 * would already have shipped.
 *
 * **The one that is only this surface's:** `app/kds/layout.tsx` takes the installability
 * decision that `app/staff/layout.tsx` explicitly handed to 10c, and the answer is **no
 * manifest**. That is a decision with a reason (a manifest's `start_url` would be a screen
 * whose credential lives in `localStorage`, and an installed app is a thing people share,
 * screenshot and reinstall), and a reason is worth nothing if a later change can quietly
 * reverse it. So the absence is asserted against the built HTML.
 *
 * ⚠ IT FAILS RATHER THAN SKIPS WHEN THE BUILD IS ABSENT, which is `build-surface.test.ts`'s
 * own rule and its reason: a skip is a green gate with the load-bearing assertion silently not
 * evaluated, which is the vacuous shape `PLAN §4a` records four instances of. `pnpm gate`
 * builds both apps before Vitest.
 */

const WEB = join(import.meta.dirname, '..')
const DOCUMENT = join(WEB, '.next', 'server', 'app', 'kds.html')

function kdsDocument(): string {
  if (!existsSync(DOCUMENT)) {
    throw new Error(
      `the kitchen display's prerendered document is absent at ${relative(WEB, DOCUMENT)}. Run ` +
        '`pnpm --filter @qrpager/web run build` first. This assertion is not skipped when the ' +
        'build is missing: a skip would make the clause pass without being evaluated — and the ' +
        'ABSENCE of this file is itself a failure, because the route is declared as static and ' +
        'a route that stopped prerendering would be serving tenant data from the server.',
    )
  }

  return readFileSync(DOCUMENT, 'utf8')
}

describe('the kitchen display’s prerendered document', () => {
  it('exists, which is what makes every assertion below about a STATIC file', () => {
    // The route declares no dynamic segment, no build-time fetch and no server action, so Next
    // must emit one HTML file for it. If this ever fails, the route has become dynamic and the
    // "nothing protected is in this document" property no longer follows from the build.
    expect(kdsDocument().length).toBeGreaterThan(0)
  })

  it('carries no authenticated chrome and no order', () => {
    const html = kdsDocument()

    /*
     * The markers of every state that requires a credential. The document should contain the
     * SETUP screen's copy and nothing else — a person with no code is what an anonymous
     * caller is.
     */
    for (const marker of [
      'data-kds-view="ready"',
      'data-kds-view="empty"',
      'data-kds="true"',
      'data-kds-order',
      'data-kds-forget',
      'data-kds-stale',
    ]) {
      expect(html.includes(marker), `the prerender carries "${marker}"`).toBe(false)
    }

    // And none of the board's own copy, which is the same assertion read from the other side:
    // a marker could be renamed, and this catches a prerender that renders the board anyway.
    for (const key of ['kds.title', 'kds.empty.title', 'kds.forget', 'kds.stale'] as const) {
      // `kds.stale` carries a placeholder, so the literal value is checked up to it.
      const text = EN[key].split('{')[0]?.trim() ?? EN[key]

      expect(html.includes(text), `the prerender carries the copy for ${key}`).toBe(false)
    }
  })

  it('declares NO manifest and is NOT iOS-installable — 10c’s own decision, pinned', () => {
    const html = kdsDocument()

    /*
     * ⚠ THIS IS A DECISION BEING HELD TO, NOT AN INCIDENTAL ABSENCE.
     *
     * `app/staff/layout.tsx` says: *"10c owns the kitchen display, which is the surface that
     * genuinely wants to launch full-screen on a wall-mounted device … That decision is 10c's
     * to take, and it is named here so it is taken rather than inherited."*
     *
     * It was taken, and the answer is no. `app/kds/layout.tsx` carries the reasoning: the
     * install's only benefit — full-screen without browser chrome — is already available on
     * every kiosk tablet and signage player this runs on, while its cost is a second home for
     * a thirty-day bearer credential. A later change that adds a manifest fails here.
     */
    expect(html.includes('rel="manifest"'), '/kds declares a manifest').toBe(false)
    expect(html.includes('apple-mobile-web-app-capable'), '/kds is iOS-installable').toBe(false)
    expect(html.includes('mobile-web-app-capable'), '/kds is installable').toBe(false)
  })

  it('is noindex, because the code field is served at a public URL', () => {
    // A crawler would otherwise index a code-entry form under a restaurant's domain, inviting
    // people to a screen they have no business at.
    expect(kdsDocument().includes('noindex')).toBe(true)
  })

  it('prerenders no loading state, so a wall tablet’s first paint is not a spinner that never resolves', () => {
    /*
     * `staff-build-surface.test.ts` asserts the same of the staff routes. It matters more here:
     * a kitchen tablet is power-cycled with the lights and nobody is watching it boot, so a
     * document whose static paint is a loading state would show a spinner to a room until the
     * JavaScript arrived — and would look identical to a screen that had failed.
     */
    expect(kdsDocument().includes('qr-staff-state--loading')).toBe(false)
  })

  it('declares exactly one route under /kds, so a child route cannot join the surface unswept', () => {
    /*
     * Derived from the app directory rather than listed, which is `staff-build-surface.test.ts`'s
     * mechanical-totality shape: a second `/kds/*` route added later fails here rather than
     * being served with none of the assertions above applied to it.
     */
    const root = join(WEB, 'app', 'kds')
    const entries = existsSync(root) ? readFileSync(join(root, 'page.tsx'), 'utf8') : ''

    expect(entries.length).toBeGreaterThan(0)

    // The directory holds a page and a layout and nothing else. `readdirSync` is imported at
    // the top of this file rather than `require`d inline -- the inline form needed two lint
    // suppressions (`no-require-imports` and `consistent-type-imports`) to say what one import
    // says plainly.
    const names = existsSync(root) ? readdirSync(root).sort() : []

    expect(names).toEqual(['layout.tsx', 'page.tsx'])
  })
})
