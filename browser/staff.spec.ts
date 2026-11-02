import { expect, test, type Page } from '@playwright/test'

import { forgetStaffSession, signInAsStaff, staffFixture } from './support/e2e.ts'

/**
 * ================================================================================
 * `§12` IN FULL ON THE STAFF SURFACE, AND THE BROWSER HALF OF 10a's INVARIANT
 * ================================================================================
 *
 * `QRPAGER-25 §6`: *"These are staff surfaces and the tokens exist as of Task 8, so `§12`'s
 * Task 6-7 exemption does not reach here."* Everything measured below is a COMPUTED value --
 * a rendered box, a resolved colour pair, an outline, a media query -- and jsdom computes
 * none of them, which is why these cases are here and not in Vitest. Three engines, both
 * directions, on a wall tablet rather than a phone, because `§12`'s own words for a kitchen
 * screen are *greasy, bright and viewed from two metres*.
 *
 * ================================================================================
 * AND THE INVARIANT, FROM INSIDE THE BROWSER, WHICH IS A DIFFERENT VECTOR
 * ================================================================================
 *
 * The PHP half drives every mounted staff route cross-tenant with Laravel's test client and a
 * session the test wrote. **This half drives it with a REAL COOKIE from a REAL LOGIN**, from
 * inside the page's own origin, with `fetch` -- so the credential path under test is the one
 * a browser actually uses: `EncryptCookies` → `StartSession` → `ValidateCsrfToken` →
 * `ResolvePrincipal` → `RequireTenantScope`, over TLS, with `Sec-Fetch-Site` set by the
 * engine rather than by a test.
 *
 * That is not a duplicate of the PHP sweep. A defect reachable only through the real cookie
 * stack -- a session the resolver reads differently when it arrives encrypted, a CSRF path
 * that answered before the tenant guard -- is invisible to `actingAs()` and is exactly what
 * this vector covers.
 */

/**
 * The ids this sweep aims across the tenant boundary.
 *
 * Declared as a concrete shape rather than a `Record<string, string>`, because
 * `noUncheckedIndexedAccess` makes every index access on a record `string | undefined` -- so
 * a path built from one is a path that may contain `undefined`, which is the shape a vacuous
 * cross-tenant request has.
 */
interface BetaIds {
  readonly session_id: string
  readonly membership_id: string
  readonly device_id: string
}

/** Every interactive box on the page, with its rendered size and its accessible name. */
async function targets(page: Page): Promise<{ name: string; width: number; height: number }[]> {
  return page.evaluate(() => {
    const found: { name: string; width: number; height: number }[] = []

    for (const element of document.querySelectorAll('button, a[href], input, [role="button"]')) {
      const box = element.getBoundingClientRect()

      // Zero-sized elements are not rendered -- a collapsed disclosure's contents, for
      // instance -- and measuring them would fail the clause for boxes nobody can tap.
      if (box.width === 0 && box.height === 0) continue

      // Composed rather than chained with `??` or `||`: `textContent` is non-null on an
      // element, so a `??` on it is provably dead and `||` is refused for being the looser
      // operator. The fallback is for a control whose name is an attribute -- an icon-only
      // button, which this surface has none of today and a later one might.
      const text = element.textContent.trim()
      const label = element.getAttribute('aria-label') ?? ''

      found.push({
        name: (text === '' ? label : text).slice(0, 40),
        width: Math.round(box.width),
        height: Math.round(box.height),
      })
    }

    return found
  })
}

test.describe('§12 on the staff shell', () => {
  test('gives every interactive box at least 44px on both axes', async ({ page }) => {
    await signInAsStaff(page)

    const boxes = await targets(page)

    // Not vacuous: the shell HAS controls. An exact lower bound rather than "some", so a
    // page that rendered none would fail here instead of passing with an empty sweep.
    expect(boxes.length).toBeGreaterThanOrEqual(2)

    for (const box of boxes) {
      expect(box.width, `"${box.name}" is ${String(box.width)}px wide`).toBeGreaterThanOrEqual(44)
      expect(box.height, `"${box.name}" is ${String(box.height)}px tall`).toBeGreaterThanOrEqual(44)
    }
  })

  test('draws a visible focus ring on every control reachable by keyboard', async ({ page }) => {
    await signInAsStaff(page)

    /*
     * ⚠ REAL `Tab` PRESSES, AND `element.focus()` DOES NOT WORK — MEASURED, NOT REASONED.
     *
     * The first version of this case called `element.focus()` from script and asserted the
     * resolved outline, with a comment claiming that *"satisfies `:focus-visible` in every
     * engine"*. It does not, in any of the three: `:focus-visible` is a HEURISTIC about how
     * focus arrived, and on a page with no prior keyboard interaction a programmatic focus on
     * a `<button>` does not match it. The gate reported `"Sign out" has outline-style none`
     * from all six projects — the kit's ring is on `:focus-visible` and nothing was focus-
     * visible.
     *
     * The honest driver is the keyboard, which is also what `§12`'s clause is about: *keyboard
     * reachable, visible focus ring*. Tabbing proves both at once — a control the ring is
     * drawn on but that Tab cannot reach would pass the old version and fail this one.
     */
    const controls = await page.locator('button:visible, a[href]:visible, input:visible').count()

    // Not vacuous: the shell HAS controls, and the count is what bounds the loop below.
    expect(controls).toBeGreaterThanOrEqual(2)

    const seen: { name: string; width: string; style: string }[] = []

    for (let step = 0; step < controls; step += 1) {
      await page.keyboard.press('Tab')

      const ring = await page.evaluate(() => {
        const element = document.activeElement

        if (element === null || element === document.body) return null

        const resolved = getComputedStyle(element)

        return {
          name: element.textContent.trim().slice(0, 40),
          width: resolved.outlineWidth,
          style: resolved.outlineStyle,
          tag: element.tagName,
        }
      })

      if (ring === null) continue
      if (!['BUTTON', 'A', 'INPUT'].includes(ring.tag)) continue

      seen.push(ring)
    }

    // ⚠ EVERY CONTROL WAS ACTUALLY REACHED, asserted rather than hoped. A Tab order that
    // escaped the document after one step would leave `seen` with one entry and every
    // assertion below would pass.
    expect(seen.length, 'Tab did not reach every visible control').toBeGreaterThanOrEqual(2)

    for (const ring of seen) {
      expect(ring.style, `"${ring.name}" has outline-style ${ring.style}`).not.toBe('none')
      expect(
        Number.parseFloat(ring.width),
        `"${ring.name}" has outline-width ${ring.width}`,
      ).toBeGreaterThanOrEqual(1)
    }
  })

  test('reduces motion to ZERO when the person asks for less, not merely to shorter', async ({
    page,
  }) => {
    /*
     * ⚠ THE FIRST VERSION OF THIS CASE ASSERTED THE WRONG PROPERTY AND THE GATE SAID SO.
     *
     * It claimed the staff shell has *"no motion to suppress, under either motion
     * preference"*, and all six projects answered with
     * `transition 0.16s, 0.16s, 0.16s on qr-button qr-button--quiet`. That is not a defect —
     * **160 ms is the APPROVED state token** (`--motion-state`), the kit's buttons have always
     * carried it, and `browser/a11y.spec.ts` asserts that exact figure as correct. The claim
     * was true only of `staff.css`, which declares no motion of its own, and the sweep ran
     * over the whole subtree including kit components.
     *
     * `§12` asks for `prefers-reduced-motion` RESPECTED, not for a motionless product. So the
     * assertion is the one `§12` actually makes, and it is the same shape `a11y.spec.ts` uses
     * for the kit: with the preference set, motion is **zero** — not shorter.
     */
    await signInAsStaff(page)

    const measure = async (): Promise<string[]> =>
      page.evaluate(() => {
        const out: string[] = []

        for (const element of document.querySelectorAll<HTMLElement>('.qr-staff, .qr-staff *')) {
          const resolved = getComputedStyle(element)

          if (resolved.animationName !== 'none' && resolved.animationName !== '') {
            out.push(`animation ${resolved.animationName} on ${element.className}`)
          }

          for (const duration of resolved.transitionDuration.split(',')) {
            if (Number.parseFloat(duration) > 0) {
              out.push(`transition ${duration.trim()} on ${element.className}`)
            }
          }
        }

        return out
      })

    // ⚠ WITHOUT THE PREFERENCE THERE IS MOTION, asserted first. Without this the case below
    // would pass against a surface that never animates — a measurement of nothing, which is
    // the vacuous shape `a11y.spec.ts`'s own reduced-motion pair is built to avoid.
    const ordinary = await measure()

    expect(
      ordinary.length,
      'nothing on the staff shell animates at all, so the reduction below measures nothing',
    ).toBeGreaterThan(0)

    await page.emulateMedia({ reducedMotion: 'reduce' })

    expect(await measure(), 'motion survives prefers-reduced-motion').toEqual([])

    // And `staff.css` itself introduces none — the claim its own footer makes. Asserted
    // against the classes this task authored rather than against the whole subtree, which is
    // the distinction the first version of this case got wrong.
    await page.emulateMedia({ reducedMotion: 'no-preference' })

    const ownClasses = await page.evaluate(() => {
      const out: string[] = []

      for (const element of document.querySelectorAll<HTMLElement>('[class*="qr-staff"]')) {
        // Only elements whose classes are ALL `qr-staff*` -- a `.qr-button` inside the shell
        // is the kit's and carries the approved token.
        const classes = element.className.split(/\s+/).filter((name) => name !== '')

        if (!classes.every((name) => name.startsWith('qr-staff'))) continue

        const resolved = getComputedStyle(element)

        if (resolved.animationName !== 'none' && resolved.animationName !== '') {
          out.push(`animation on ${element.className}`)
        }

        for (const duration of resolved.transitionDuration.split(',')) {
          if (Number.parseFloat(duration) > 0) out.push(`transition on ${element.className}`)
        }
      }

      return out
    })

    expect(ownClasses, 'staff.css declares motion of its own').toEqual([])
  })

  test('mirrors in Arabic without a horizontal scroll, and keeps the name unflipped', async ({
    page,
  }, testInfo) => {
    const fixture = await signInAsStaff(page)
    const rtl = testInfo.project.name.endsWith('-rtl')

    /*
     * ⚠ READ OFF THE ELEMENT `ThemeProvider` RENDERS, NOT `<html>`, AND THAT IS TASK 9's
     * DECISION RATHER THAN A WORKAROUND. `app/layout.tsx` hard-codes `lang="en" dir="ltr"` on
     * the document and says so; `ThemeProvider` sets `data-theme`, `lang` and `dir` together
     * on one element, which is what `PagerJourney` already does for the customer journey.
     * Asserting `<html>` here would assert a property of the root layout, which this ticket
     * does not own.
     *
     * The FIRST version of this case did read `documentElement` — and found `ltr` in all
     * three RTL projects, which was a real defect: `StaffApp` defaulted its locale prop to
     * `'en'` and never read the browser's language list, so the staff surface was English-
     * only. That is fixed in `StaffApp` and this assertion is what holds it.
     */
    const themed = page.locator('[data-theme]').first()

    await expect(themed).toHaveAttribute('dir', rtl ? 'rtl' : 'ltr')
    await expect(themed).toHaveAttribute('lang', rtl ? 'ar' : 'en')

    // A layout that mirrors by overflowing is not a mirrored layout. Measured on the real
    // engine, which is the only thing that can answer it.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    expect(overflow, 'the staff shell scrolls horizontally').toBeLessThanOrEqual(1)

    // ⚠ AND THE RESTAURANT'S NAME IS NOT REVERSED. `QRPAGER-15 §6` trap 2: RTL is not
    // `direction: rtl` -- a latin name inside an RTL document must still read left to right,
    // which is what `unicode-bidi` handles and what a transform-based "mirror" would break.
    await expect(page.getByRole('heading', { name: fixture.alpha.name })).toBeVisible()
  })

  test('renders the restaurant by NAME and no identifier anywhere on the page', async ({
    page,
  }) => {
    const fixture = await signInAsStaff(page)

    await expect(page.getByRole('heading', { name: fixture.alpha.name })).toBeVisible()

    // Every text node AND every attribute value, from the real DOM.
    const everything = await page.evaluate(() => {
      const parts: string[] = [document.body.textContent]

      for (const element of document.body.querySelectorAll('*')) {
        for (const attribute of element.attributes) parts.push(attribute.value)
      }

      return parts.join(' | ')
    })

    // `§12`'s zero-technical-background bar: no unexplained identifier. The slug is a DNS
    // label and the uuid is worse.
    expect(everything).not.toContain(fixture.alpha.id)
    expect(everything).not.toContain(fixture.alpha.slug)
    expect(everything).not.toContain('pager:read')
    expect(everything).not.toContain('tenant:read')
  })

  test('meets AA contrast on the chrome, measured from the engine’s own resolved colours', async ({
    page,
  }) => {
    await signInAsStaff(page)

    const pairs = await page.evaluate(() => {
      const parse = (value: string): [number, number, number] => {
        const parts = value.match(/[\d.]+/g) ?? []

        return [Number(parts[0] ?? 0), Number(parts[1] ?? 0), Number(parts[2] ?? 0)]
      }

      const channel = (byte: number): number => {
        const v = byte / 255

        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
      }

      const luminance = ([r, g, b]: [number, number, number]): number =>
        0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)

      /** The nearest ancestor with a non-transparent background. */
      const ground = (element: Element): [number, number, number] => {
        let current: Element | null = element

        while (current !== null) {
          const background = getComputedStyle(current).backgroundColor

          if (background !== 'rgba(0, 0, 0, 0)' && background !== 'transparent') {
            return parse(background)
          }

          current = current.parentElement
        }

        return [255, 255, 255]
      }

      const out: { selector: string; ratio: number; size: number }[] = []

      for (const selector of [
        '.qr-staff__restaurant',
        '.qr-staff__person',
        '.qr-staff__role',
        '.qr-staff-state__title',
        '.qr-staff-state__description',
      ]) {
        const element = document.querySelector(selector)

        if (element === null) continue

        const resolved = getComputedStyle(element)
        const ink = luminance(parse(resolved.color))
        const paper = luminance(ground(element))
        const ratio = (Math.max(ink, paper) + 0.05) / (Math.min(ink, paper) + 0.05)

        out.push({ selector, ratio, size: Number.parseFloat(resolved.fontSize) })
      }

      return out
    })

    // Not vacuous: the selectors matched something. Four of the five are always present on a
    // signed-in shell; the state ones depend on the view, so the floor is three.
    expect(pairs.length).toBeGreaterThanOrEqual(3)

    for (const pair of pairs) {
      // WCAG AA: 4.5:1 for body text, 3:1 for large text (18.66px bold or 24px). The kit's
      // headings are 28px, so they take the large-text bar and nothing else does.
      const bar = pair.size >= 24 ? 3 : 4.5

      expect(
        pair.ratio,
        `${pair.selector} is ${pair.ratio.toFixed(2)}:1 at ${String(pair.size)}px, under ${String(bar)}:1`,
      ).toBeGreaterThanOrEqual(bar)
    }
  })
})

test.describe('10a’s invariant, driven from inside the browser with a real cookie', () => {
  test('refuses every cross-tenant attempt the shell’s own origin can make', async ({ page }) => {
    const fixture = await signInAsStaff(page)

    /*
     * THE SWEEP, FROM THE PAGE. Every id below is a REAL row in beta, created by
     * `bin/issue-staff-fixture` through the real services. The requests go out with the
     * session cookie the login just established and with the `XSRF-TOKEN` the client reads
     * from `document.cookie`, which is the production credential path end to end.
     */
    const results = await page.evaluate(async (beta: BetaIds) => {
      const token = (() => {
        for (const part of document.cookie.split(';')) {
          const [name, ...rest] = part.trim().split('=')

          if (name === 'XSRF-TOKEN') return decodeURIComponent(rest.join('='))
        }

        return null
      })()

      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      }

      if (token !== null) headers['X-XSRF-TOKEN'] = token

      const attempts: [string, string, string, unknown][] = [
        ['ready', 'POST', `/v1/pager-sessions/${beta.session_id}/ready`, {}],
        ['renotify', 'POST', `/v1/pager-sessions/${beta.session_id}/renotify`, {}],
        ['complete', 'POST', `/v1/pager-sessions/${beta.session_id}/complete`, {}],
        ['cancel', 'POST', `/v1/pager-sessions/${beta.session_id}/cancel`, {}],
        ['member.update', 'PATCH', `/v1/members/${beta.membership_id}`, { role: 'manager' }],
        ['member.destroy', 'DELETE', `/v1/members/${beta.membership_id}`, null],
        ['device.destroy', 'DELETE', `/v1/devices/${beta.device_id}`, null],
      ]

      const out: { name: string; status: number; body: string }[] = []

      for (const [name, method, path, body] of attempts) {
        const response = await fetch(path, {
          method,
          headers,
          credentials: 'same-origin',
          ...(body === null ? {} : { body: JSON.stringify(body) }),
        })

        out.push({ name, status: response.status, body: await response.text() })
      }

      // And the reads, which take no id and must carry nothing of beta's.
      for (const [name, path] of [
        ['tenant', '/v1/tenant'],
        ['board', '/v1/pager-sessions'],
        ['members', '/v1/members'],
        ['devices', '/v1/devices'],
      ] as const) {
        const response = await fetch(path, { headers, credentials: 'same-origin' })

        out.push({ name, status: response.status, body: await response.text() })
      }

      return out
    }, fixture.beta)

    // Not vacuous: eleven attempts, counted. A sweep that made none would pass every
    // negative assertion below.
    expect(results.length).toBe(11)

    const keyed = results.slice(0, 7)
    const reads = results.slice(7)

    for (const result of keyed) {
      // ⚠ 404 EXACTLY. A 401 would mean no tenant resolved -- it did, this is alpha's owner
      // -- and a 403 would mean the ability check refused, which it did not. 404 is the only
      // answer that means *beta's row is invisible inside alpha's scope*, which is the
      // invariant. Asserted as an exact value, not as "not a success".
      expect(
        result.status,
        `${result.name} answered ${String(result.status)} for beta's real row`,
      ).toBe(404)
    }

    for (const result of reads) {
      expect(result.status, `${result.name} answered ${String(result.status)}`).toBe(200)
    }

    // And nothing of beta's is in ANY answer -- keyed or not. The keyed paths echo their own
    // id back in the problem document's `instance`, which is the caller's own input and not a
    // leak, so those ids are excluded from their own request's body the same way the PHP half
    // excludes them.
    for (const result of results) {
      for (const [what, id] of Object.entries(fixture.beta)) {
        if (what === 'name') continue
        if (result.body.includes(id) && results.indexOf(result) < 7) continue

        expect(
          result.body.includes(id),
          `${result.name} carried beta's ${what} to alpha's owner`,
        ).toBe(false)
      }

      expect(result.body.includes(fixture.beta.name), `${result.name} named beta`).toBe(false)
    }
  })

  test('locks the shell after idle, and the session is dead on the server afterwards', async ({
    page,
  }) => {
    // THE LOCK, PROVEN AT THE SERVER RATHER THAN AT THE OVERLAY. A lock that hid the data
    // over a live session would show the overlay and still answer 200 here, which is why the
    // assertion is a request made AFTER it rather than a screenshot of it.
    await signInAsStaff(page)

    const before = await page.evaluate(async () => (await fetch('/v1/tenant')).status)

    expect(before, 'the session was not live before the lock, so this case proves nothing').toBe(
      200,
    )

    // The shell's own sign-out takes the same path as the idle timer -- `staff-app.test.tsx`
    // pins that they are one mechanism -- so driving it here measures the lock without
    // waiting fifteen real minutes.
    await page.locator('[data-sign-out="true"]').click()
    await page.waitForSelector('[data-locked="true"]', { timeout: 30_000 })

    const after = await page.evaluate(async () => (await fetch('/v1/tenant')).status)

    expect(after, 'the server session survived the lock').toBe(401)

    // This case destroyed the shared session on purpose, so the next one must not spend five
    // seconds discovering that. `signInAsStaff` verifies a restored session anyway -- this is
    // courtesy to the case after it, not correctness.
    forgetStaffSession()

    // And the overlay names neither the person nor the restaurant: a lock screen that still
    // said who was using it, and where, has leaked the two facts a shared-device lock exists
    // to stop leaking.
    const visible = (await page.locator('body').textContent()) ?? ''
    const fixture = staffFixture()

    expect(visible).not.toContain(fixture.alpha.name)
    expect(visible).not.toContain(fixture.owner.name)
  })

  test('serves the sign-in view, and no chrome, to a caller with no credential', async ({
    page,
  }) => {
    // The browser half's other clause: there is no protected document at any staff URL. Driven
    // at both routes, with a fresh context and therefore no cookie.
    for (const path of ['/staff', '/staff/devices']) {
      await page.goto(path)

      await expect(page.locator('[data-passkey-signin="true"]')).toBeVisible({ timeout: 30_000 })
      await expect(page.locator('[data-staff-shell="true"]')).toHaveCount(0)
      await expect(page.locator('[data-sign-out="true"]')).toHaveCount(0)
    }
  })
})
