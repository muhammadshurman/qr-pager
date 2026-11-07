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

    /*
     * Not vacuous: there IS a staff surface. An exact count, so a route added in a directory
     * this walk does not reach fails here rather than going unswept.
     *
     * FIVE at 10e, and the list is sorted: `/staff` (the board, 10b), `/staff/devices` (kitchen
     * screens, 10a), `/staff/history` and `/staff/issue` (10d), `/staff/team` (10e). It matches
     * `StaffView`'s union and `StaffNavigator`'s `PATHS` map — three lists that must agree, and
     * the other two are held to each other by the compiler.
     *
     * ⚠ `/kds` IS DELIBERATELY NOT HERE. It is a different authentication surface with its own
     * app, and its prerendered document has its own sweep in `kds-build-surface.test.ts` —
     * including the one property this file cannot state for it, that it declares no manifest.
     */
    expect(declared.length).toBe(5)
    expect(declared).toEqual([
      '/staff',
      '/staff/devices',
      '/staff/history',
      '/staff/issue',
      '/staff/team',
    ])

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

    /*
     * ⚠ THE EXACT PAIR, BY NAME, AND IT WAS `toBeGreaterThan(0)` — `§11` QA L-4. Two
     * documents are built and both are swept below; a floor of zero would pass against a
     * build that emitted one of them, which is exactly the half-swept state this case is
     * about. The same two routes are pinned in the case above, from the ROUTER's side, so
     * the build and the declaration are held to each other.
     */
    expect(documents.map((document) => document.route)).toEqual([
      '/staff',
      '/staff/devices',
      '/staff/history',
      '/staff/issue',
      '/staff/team',
    ])

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

/**
 * ================================================================================
 * THE `§12` SPEC's OWN PATHS, HELD AGAINST THE ROUTES THAT EXIST — `QRPAGER-32`
 * ================================================================================
 *
 * **WHY THIS BLOCK EXISTS, MEASURED.** `QRPAGER-25-precoding.md §12` headed screen S1
 * *"Staff sign-in — `/staff/sign-in`"*, and no such route has ever existed: `GET
 * /staff/sign-in` answers **404** with Next's own error document. The spec and the
 * implementation disagreed from 10a's first commit to 2026-11-03, and nothing anywhere
 * noticed — because nothing read the spec.
 *
 * Everything in this file above derives its subjects from the BUILD, which is the right shape
 * for *"no document carries a tenant datum"* and is exactly the wrong shape for this: a list
 * derived from the build can never disagree with the build. `§12`'s whole claim is that the
 * screens are *"specified before they are coded"*, which makes the spec a second source that
 * has to be reconciled with the first, and the only way to reconcile two sources is to read
 * both.
 *
 * ⚠ **IT PARSES THE SPEC RATHER THAN RESTATING IT.** A list of paths copied out of the
 * markdown into this file would be a third source, and the drift it is meant to catch is
 * precisely the one where two sources are left to agree by hand. The headings are the
 * contract: `## S<n> · <name> — <anything containing a backticked path>`.
 *
 * ⚠ **AND "NOT A ROUTE" IS AN ALLOWED ANSWER, SPELLED IN THE HEADING.** S3 is *"Locked — an
 * overlay, not a route"* and S7 is *"Pager detail — a panel over the board, not a route"*;
 * S1 is now *"served at EVERY `/staff/*` URL, canonically `/staff`"*. A screen that is a STATE
 * rather than a URL is a real and common answer, so the test's rule is: a heading that names a
 * `/staff` path must name one the build serves, and a heading that claims no route must not
 * name a path at all. Both halves are drivable, which is what keeps the escape hatch from
 * being a hole.
 */
const SPEC_FILES = ['QRPAGER-25-precoding.md', 'QRPAGER-26-precoding.md'] as const

/** Every `§12` screen heading in the staff tickets, with any `/staff…` paths it names. */
function specifiedScreens(): {
  readonly file: string
  readonly heading: string
  readonly paths: string[]
}[] {
  const docs = join(WEB, '..', '..', 'docs', 'tickets')
  const screens: { file: string; heading: string; paths: string[] }[] = []

  for (const file of SPEC_FILES) {
    const path = join(docs, file)

    if (!existsSync(path)) {
      // Not skipped, for the reason this file's header already gives: an absent subject must
      // fail, or the assertion passes without being evaluated.
      throw new Error(`the §12 screen spec is absent at ${file}`)
    }

    for (const line of readFileSync(path, 'utf8').split('\n')) {
      if (!/^## S\d+ /.test(line)) {
        continue
      }

      screens.push({
        file,
        heading: line.replace(/^##\s*/, ''),
        // `filter` before the cast rather than `?? ''`: an empty string would be a path that
        // silently matches nothing, which is the vacuous shape. Group 1 is always present
        // when the regex matches at all, so a dropped entry here would be a regex change.
        paths: [...line.matchAll(/`(\/staff[a-z0-9/-]*)`/gi)]
          .map((match) => match[1])
          .filter((path): path is string => path !== undefined),
      })
    }
  }

  return screens
}

describe('every path the §12 staff screen spec names', () => {
  it('is a route this build actually serves', () => {
    const served = prerenderedStaffDocuments().map((document) => document.route)
    const screens = specifiedScreens()

    // Not vacuous: there ARE screens, and some of them DO name paths. Without these two the
    // whole block would pass against a spec file that had been emptied or renamed.
    expect(screens.length, 'no §12 screen headings were parsed out of the spec').toBeGreaterThan(5)
    expect(
      screens.filter((screen) => screen.paths.length > 0).length,
      'no §12 screen heading names a path, so nothing was compared',
    ).toBeGreaterThan(2)

    for (const screen of screens) {
      for (const path of screen.paths) {
        expect(served, `${screen.file} · ${screen.heading} names ${path}, which 404s`).toContain(
          path,
        )
      }
    }
  })

  it('is absent from any heading that claims the screen is not a route', () => {
    // The other half of the escape hatch. A heading may say "not a route" OR name a path, and
    // saying both is the drift in its most confusing form: a reader takes the path and a
    // reviewer takes the disclaimer.
    for (const screen of specifiedScreens()) {
      if (!/not a route/i.test(screen.heading)) {
        continue
      }

      expect(
        screen.paths,
        `${screen.file} · ${screen.heading} says it is not a route and names one anyway`,
      ).toEqual([])
    }
  })
})
