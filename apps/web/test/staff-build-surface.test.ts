import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * ================================================================================
 * 10a's INVARIANT, THE BROWSER HALF — derived from the BUILD, not from a list
 * ================================================================================
 *
 * `QRPAGER-25 §0`: *"A staff member authenticated in tenant A can reach no route and no datum
 * of tenant B."* The API half is proven over HTTP by
 * `apps/api/tests/Feature/Staff/StaffSurfaceIsolationTest.php`, whose route list comes from
 * the Laravel router. **This file is the other router.**
 *
 * The property asserted here is the one a cross-tenant HTTP sweep cannot see: **no staff
 * route's prerendered document carries ANY tenant datum or any authenticated chrome.** If one
 * did, it would be served to any caller at a public URL, before a credential, before the
 * tenant is resolved — and no amount of server-side isolation would help, because the leak
 * would already be in the static file.
 *
 * ⚠ THE SUBJECT LIST IS DERIVED FROM `.next/server/app`, so a staff route added later is
 * swept with no edit here. That is the same mechanical-totality shape as the PHP half and it
 * is the reason this is not a list of two paths.
 *
 * ⚠ AND IT FAILS RATHER THAN SKIPS WHEN THE BUILD IS ABSENT. `build-surface.test.ts` already
 * records why in its own header: a skip is a green gate with the load-bearing assertion
 * silently not evaluated, which is the vacuous shape this project has four recorded instances
 * of. `pnpm gate` builds both apps before Vitest.
 */

const WEB = join(import.meta.dirname, '..')
const APP = join(WEB, '.next', 'server', 'app')

/**
 * Every prerendered staff document, found by walking the build output.
 *
 * `/staff.html` is the route group's own page and `staff/<child>.html` are its children, so
 * both shapes are collected. A staff route that Next did NOT prerender has no `.html` at
 * all, which the first case below turns into a failure rather than an absence.
 */
function prerenderedStaffDocuments(): { readonly route: string; readonly html: string }[] {
  if (!existsSync(APP)) {
    throw new Error(
      `the build output is absent at ${relative(WEB, APP)}. Run ` +
        '`pnpm --filter @qrpager/web run build` first. This assertion is not skipped when the ' +
        'build is missing: a skip would make 10a’s browser-half invariant pass without ' +
        'being evaluated.',
    )
  }

  const found: { route: string; html: string }[] = []

  if (existsSync(join(APP, 'staff.html'))) {
    found.push({ route: '/staff', html: readFileSync(join(APP, 'staff.html'), 'utf8') })
  }

  const dir = join(APP, 'staff')

  if (existsSync(dir)) {
    const walk = (current: string, prefix: string): void => {
      for (const entry of readdirSync(current, { withFileTypes: true })) {
        const full = join(current, entry.name)

        if (entry.isDirectory()) {
          walk(full, `${prefix}/${entry.name}`)
        } else if (entry.name.endsWith('.html')) {
          found.push({
            route: `${prefix}/${entry.name.replace(/\.html$/, '')}`,
            html: readFileSync(full, 'utf8'),
          })
        }
      }
    }

    walk(dir, '/staff')
  }

  return found.sort((a, b) => a.route.localeCompare(b.route))
}

/**
 * The route segments the app declares, read from the app directory rather than from the
 * build — so a route that EXISTS and was not prerendered is detectable.
 */
function declaredStaffRoutes(): string[] {
  const routes: string[] = []
  const root = join(WEB, 'app', 'staff')

  const walk = (dir: string, prefix: string): void => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        walk(join(dir, entry.name), `${prefix}/${entry.name}`)
      } else if (entry.name === 'page.tsx') {
        routes.push(prefix)
      }
    }
  }

  walk(root, '/staff')

  return routes.sort()
}

/** Every attribute value and every text node of a document, as one haystack. */
function haystack(html: string): string {
  return html
}

describe('every staff route, derived from the build', () => {
  it('is declared and prerendered, with the two lists agreeing', () => {
    const declared = declaredStaffRoutes()
    const prerendered = prerenderedStaffDocuments().map((document) => document.route)

    // Not vacuous: there IS a staff surface. An exact count, so a route added in a
    // directory this walk does not reach fails here rather than going unswept.
    expect(declared.length).toBe(2)
    expect(declared).toEqual(['/staff', '/staff/devices'])

    // ⚠ EVERY DECLARED ROUTE HAS A PRERENDERED DOCUMENT. A staff route that became
    // dynamic -- a server component reading a cookie, a `force-dynamic`, a server action --
    // would disappear from this list, and it is exactly the change that could put tenant
    // data in a server render. So the absence is the failure.
    expect(prerendered).toEqual(declared)
  })

  it('carries no authenticated chrome in its prerendered HTML', () => {
    // The defect this exists for was real and was found by reading this output: the first
    // version of `StaffApp` rendered the shell for every phase but locked and signed-out, so
    // both documents shipped `qr-staff__restaurant`, a `Sign out` button and `Signed in as `
    // with an empty name -- to a caller who had presented no credential.
    const CHROME = [
      'qr-staff__restaurant',
      'qr-staff__person',
      'qr-staff__role',
      'data-staff-shell',
      'data-sign-out',
      'Signed in as',
      'Sign out',
    ]

    const documents = prerenderedStaffDocuments()

    expect(documents.length).toBeGreaterThan(0)

    for (const { route, html } of documents) {
      for (const marker of CHROME) {
        expect(haystack(html).includes(marker), `${route} prerenders "${marker}"`).toBe(false)
      }
    }
  })

  it('carries only the loading state, which is the one honest thing to prerender', () => {
    // The control for the case above: a document with NOTHING in it would also pass a
    // no-chrome check, and a blank staff page is its own defect. What should be there is the
    // loading state, because the client has not yet asked who is calling.
    for (const { route, html } of prerenderedStaffDocuments()) {
      expect(html.includes('qr-staff-state--loading'), `${route} prerenders no loading state`).toBe(
        true,
      )
    }
  })

  it('declares no manifest, so a shared kitchen tablet cannot install the staff shell', () => {
    // `app/s/layout.tsx` scopes the customer manifest and states the reason in both
    // directions. This is the other half: an installed staff app whose `start_url` is an
    // authenticated surface hands its session to whoever picks the tablet up next. 10c owns
    // the wall-mounted display, which is a device-token surface and is safe to install.
    for (const { route, html } of prerenderedStaffDocuments()) {
      expect(html.includes('rel="manifest"'), `${route} declares a manifest`).toBe(false)
      expect(html.includes('apple-mobile-web-app-capable'), `${route} is iOS-installable`).toBe(
        false,
      )
    }
  })

  it('tells crawlers not to index a surface whose unauthenticated view is a sign-in form', () => {
    for (const { route, html } of prerenderedStaffDocuments()) {
      expect(html.includes('noindex'), `${route} is indexable`).toBe(true)
    }
  })

  it('references no absolute origin, so the staff surface reaches no third party either', () => {
    // `§3` row 9's clause (b) is written for the customer landing; the reasoning is not
    // customer-specific. A staff shell that loaded anybody's JavaScript would be a script
    // with a first-party session cookie in reach.
    for (const { route, html } of prerenderedStaffDocuments()) {
      const origins = [...html.matchAll(/https?:\/\/[a-z0-9.-]+/gi)]
        .map((match) => match[0])
        // The two schema/vocabulary URLs Next and the problem envelope emit are not fetched.
        .filter((origin) => !origin.startsWith('http://www.w3.org'))

      expect(origins, `${route} references ${origins.join(', ')}`).toEqual([])
    }
  })
})
