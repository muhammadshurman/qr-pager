import { expect, test, type Page } from '@playwright/test'

import { forgetStaffSession, signInAsStaff, staffFixture } from './support/e2e.ts'
import { echoCount, leaksIn } from './support/isolation.ts'

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

/**
 * Open the board's detail panel, so the `§12` sweeps measure S7's controls too.
 *
 * ⚠ **ADDED AT 10b's FIX ROUND — `§11` QA M-15.** All three sweeps ran immediately after
 * `signInAsStaff()`, with no row opened, so the panel's seven controls — *Tell them it is
 * ready*, *Buzz them again*, *They collected it*, *Cancel this order*, *Close* and the
 * confirm pair — and the board's own two new text classes were measured by NOTHING, on the
 * surface whose ticket calls contrast and target size *functional* requirements rather than
 * aspirations. The panel's buttons are the shared `Button` primitive measured elsewhere, which
 * is mitigation and not a measurement of this screen.
 *
 * It returns whether a panel opened, so a sweep can say so rather than silently covering the
 * chrome alone: a board with no live row is a legitimate state of the fixture, and a sweep
 * that quietly measured less would be the vacuous half of this very finding.
 */
async function openDetailPanel(page: Page): Promise<boolean> {
  const opener = page.locator('[data-board="true"] [data-action="open"]').first()

  if ((await opener.count()) === 0) return false

  await opener.click()
  await page.waitForSelector('[data-detail="true"]', { timeout: 30_000 })

  return true
}

test.describe('§12 on the staff shell', () => {
  test('gives every interactive box at least 44px on both axes', async ({ page }) => {
    await signInAsStaff(page)

    // S7's controls, in the same sweep as the chrome's — `§11` QA M-15.
    const panel = await openDetailPanel(page)

    expect(panel, 'no board row to open, so the panel\u2019s controls went unmeasured').toBe(true)

    const boxes = await targets(page)

    /*
     * Not vacuous: the shell HAS controls, and the sweep found ALL of them.
     *
     * ⚠ AN EQUALITY WITH THE PAGE'S OWN COUNT, AND IT WAS `toBeGreaterThanOrEqual(2)` —
     * `§11` QA L-4. The absolute number cannot be pinned here and that is not laziness:
     * `StaffHome` renders the add-a-passkey button only where `passkeysAvailable()` is true,
     * which is Chromium-with-a-virtual-authenticator and not WebKit, so the count genuinely
     * differs per project. What IS exact is that the sweep saw every control the page has —
     * asked a second way, through a locator rather than through `targets()`'s own selector,
     * so a sweep that silently covered a subset fails.
     */
    const declared = await page.locator('button:visible, a[href]:visible, input:visible').count()

    expect(declared, 'the shell rendered no controls at all').toBeGreaterThanOrEqual(2)
    expect(boxes.length, 'the 44px sweep did not see every control on the page').toBe(declared)

    for (const box of boxes) {
      expect(box.width, `"${box.name}" is ${String(box.width)}px wide`).toBeGreaterThanOrEqual(44)
      expect(box.height, `"${box.name}" is ${String(box.height)}px tall`).toBeGreaterThanOrEqual(44)
    }
  })

  test('draws a visible focus ring on every control reachable by keyboard', async ({ page }) => {
    await signInAsStaff(page)

    // S7's controls too — `§11` QA M-15. Tab reaches them only once the panel is open.
    expect(
      await openDetailPanel(page),
      'the panel did not open, so its focus rings went unmeasured',
    ).toBe(true)

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

    /*
     * Not vacuous: the shell HAS controls, and this count bounds the loop below.
     *
     * ⚠ THIS ONE STAYS A BOUND, WITH THE REASON STATED — `§11` QA L-4 asked for exact values
     * and here there is not one to state: the add-a-passkey button exists only where
     * `passkeysAvailable()` is true, so the control count differs between the six projects by
     * design. The exact claim this case makes is the EQUALITY below — every control Tab
     * reached — which is stronger than any number would be and holds in all six.
     */
    expect(controls, 'the shell rendered no controls at all').toBeGreaterThanOrEqual(2)

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

    /*
     * ⚠ EVERY CONTROL WAS ACTUALLY REACHED, AS AN EQUALITY — `§11` QA L-4, and the floor it
     * replaces was the weaker half of this case. A Tab order that escaped the document after
     * one step left `seen` with one entry and passed `>= 2` as soon as there were two
     * controls; `=== controls` says what the sentence above it always claimed.
     */
    expect(seen.length, `Tab reached ${String(seen.length)} of ${String(controls)} controls`).toBe(
      controls,
    )

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

    /*
     * A BOUND, AND `§11` QA L-4's answer for it is the reason rather than a number: which
     * elements animate depends on which controls the project rendered (the add-a-passkey
     * button is Chromium-only here), so the exact count is not one fact. What matters is that
     * the set is non-empty BEFORE the preference and empty after, and the `toEqual([])` below
     * is the exact half.
     */
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

    // S7's controls and the board's own two text classes — `§11` QA M-15.
    expect(
      await openDetailPanel(page),
      'the panel did not open, so its contrast went unmeasured',
    ).toBe(true)

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
        /*
         * ⚠ 10b's OWN TEXT, ADDED AT THE FIX ROUND — `§11` QA M-15. This list was 10a's five
         * chrome selectors, so the board's row title and its quiet notices were never
         * measured. `--color-ink-mid` is an approved AA token (`QRPAGER-15-contrast-table.md`)
         * — which is a reason to expect a pass, not a measurement of one.
         */
        '.qr-staff-list__name',
        '.qr-staff-list__waiting',
        '.qr-staff-board__stale',
        '.qr-staff-warning',
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

    /*
     * Not vacuous: the selectors matched, and the three that are ALWAYS on a signed-in shell
     * are named rather than counted.
     *
     * ⚠ NAMED, NOT `toBeGreaterThanOrEqual(3)` — `§11` QA L-4. A count of three passed if the
     * three that matched were the two state selectors plus one of the chrome ones, which is a
     * different measurement wearing the same number. The two `qr-staff-state__*` selectors
     * stay unasserted because whether a state is on screen depends on the view, and that is a
     * property of the page rather than of this check.
     */
    const matched = pairs.map((pair) => pair.selector)

    for (const always of ['.qr-staff__restaurant', '.qr-staff__person', '.qr-staff__role']) {
      expect(matched, `the shell did not render ${always}`).toContain(always)
    }

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

      /*
       * ⚠ EACH ATTEMPT CARRIES THE ID IT PUT IN ITS OWN PATH, which the first version of this
       * sweep did not record — and not recording it is what made the leak check vacuous for
       * seven of eleven answers (`§11` QA M-3 / SECURITY L-3). `classifyAppearance` needs it
       * to tell *"the server echoed back the id I just sent"* from *"the server told me about
       * another one of beta's rows"*, and those two differ only by which id it is.
       */
      const attempts: [string, string, string, unknown, string | null][] = [
        ['ready', 'POST', `/v1/pager-sessions/${beta.session_id}/ready`, {}, beta.session_id],
        ['renotify', 'POST', `/v1/pager-sessions/${beta.session_id}/renotify`, {}, beta.session_id],
        ['complete', 'POST', `/v1/pager-sessions/${beta.session_id}/complete`, {}, beta.session_id],
        ['cancel', 'POST', `/v1/pager-sessions/${beta.session_id}/cancel`, {}, beta.session_id],
        [
          'member.update',
          'PATCH',
          `/v1/members/${beta.membership_id}`,
          { role: 'manager' },
          beta.membership_id,
        ],
        ['member.destroy', 'DELETE', `/v1/members/${beta.membership_id}`, null, beta.membership_id],
        ['device.destroy', 'DELETE', `/v1/devices/${beta.device_id}`, null, beta.device_id],
      ]

      const out: { name: string; status: number; body: string; ownId: string | null }[] = []

      for (const [name, method, path, body, ownId] of attempts) {
        const response = await fetch(path, {
          method,
          headers,
          credentials: 'same-origin',
          ...(body === null ? {} : { body: JSON.stringify(body) }),
        })

        out.push({ name, status: response.status, body: await response.text(), ownId })
      }

      // And the reads, which take no id and must carry nothing of beta's -- `ownId: null`, so
      // for these the echo exemption cannot apply at all.
      for (const [name, path] of [
        ['tenant', '/v1/tenant'],
        ['board', '/v1/pager-sessions'],
        ['members', '/v1/members'],
        ['devices', '/v1/devices'],
      ] as const) {
        const response = await fetch(path, { headers, credentials: 'same-origin' })

        out.push({ name, status: response.status, body: await response.text(), ownId: null })
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

    /*
     * AND NOTHING OF BETA'S IS IN ANY ANSWER — keyed or not.
     *
     * ⚠ THE RULE IS `support/isolation.ts` NOW, AND IT USED TO BE THIS LINE:
     *
     *     if (result.body.includes(id) && results.indexOf(result) < 7) continue
     *
     * — which reads as *"the keyed paths echo their own id in `instance`, and that is the
     * caller's own input"* and MEANS *"for the first seven answers, skip the leak check
     * exactly when it would fail"*. `§11` QA M-3 / SECURITY L-3. A 404 for beta's session
     * that also carried beta's `membership_id` or `device_id` passed this sweep silently,
     * and those are the disclosures the invariant is about.
     *
     * The tolerated appearance is now the narrow one the reasoning justifies: the id THIS
     * request named, in a problem document's `instance`, and nowhere else in it.
     * `support/test/isolation.test.ts` drives seven leak shapes past that rule — which is the
     * half this round cannot prove, since a real disclosure would have to exist first.
     */
    expect(leaksIn(results, fixture.beta), 'beta was disclosed to alpha').toEqual([])

    /*
     * And the echo is COUNTED, exactly. Seven keyed attempts, each echoing its own path id in
     * `instance` once. Pinned rather than bounded so that an eighth echo — a read starting to
     * quote an id, a new field carrying one — is a failure a person reads, not a number that
     * drifts.
     */
    expect(echoCount(results, fixture.beta), 'the echo count moved').toBe(7)
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

    /*
     * ⚠ THE CLIENT HALF IS INSTANTANEOUS AND IS ASSERTED FIRST. The overlay is up and the
     * restaurant, the person and the role are out of the document ALREADY -- before the server
     * has answered anything. That is the property `§11` SECURITY M-1 bought and it is the half
     * that protects an unattended screen: whoever walks up sees nothing.
     */
    const covered = (await page.locator('body').textContent()) ?? ''

    expect(covered).not.toContain(staffFixture().alpha.name)

    /*
     * ⚠ AND THE SERVER HALF IS AWAITED RATHER THAN SAMPLED ONCE — AND THE SINGLE SAMPLE WAS A
     * RACE I INTRODUCED, measured in `gate:e2e` round 3: **3 of 6 staff projects red** with
     * *"the server session survived the lock"* (`staff-firefox-ltr`, `staff-webkit-ltr`,
     * `staff-webkit-rtl`; chromium passed, which is how a race presents).
     *
     * `§11` SECURITY M-1 said `lock()` must not `await signOut()`: against a
     * reachable-but-dead route a bare `fetch` has no `AbortController`, so the promise can be
     * pending for the browser's whole connect timeout — tens of seconds with the restaurant's
     * name, the person's name and the device list still rendered on an unattended screen. The
     * fix drops the data and raises the overlay FIRST and lets the logout fly. The cost, which
     * is this: `[data-locked="true"]` no longer implies *the server session is already gone* —
     * it implies *the request has been sent*.
     *
     * So the assertion follows the behaviour instead of the behaviour following the assertion,
     * and it is written out rather than relaxed quietly: the session must die, and it must die
     * without anything else happening — no reload, no second sign-out, no further interaction.
     * A lock that never destroyed the session fails here after the full timeout, which is the
     * defect this case has always existed to catch. The residual window between the overlay and
     * the 401 is milliseconds on a working network; on a dead one the cookie survives until the
     * server's own 120-minute expiry, which is `§5` `R-220` and is recorded there.
     */
    /*
     * ⚠ A FETCH THAT CANNOT BE MADE IS "NOT YET", NOT A FAILURE — measured inside
     * `final-evidence`'s `frontend-three-green-runs`, run 2 of 3 at `cd8a4ba`:
     * `page.evaluate: NetworkError when attempting to fetch resource.` escaped the polled
     * callback and failed the case under the message *"the server session survived the lock"*,
     * which is the one thing that had NOT been established. One transient refusal from the TLS
     * front, on the second of three consecutive stack bring-ups, read as a security regression.
     *
     * The claim is *the session is dead*, and a network error is evidence of neither side of
     * it. Returning a sentinel keeps the poll asking for its whole budget and keeps the
     * failure message true: if the session really does survive, this still fails after 15 s
     * with that exact sentence.
     */
    await expect
      .poll(
        async () =>
          page.evaluate(async () => {
            try {
              return (await fetch('/v1/tenant')).status
            } catch {
              return -1
            }
          }),
        {
          message: 'the server session survived the lock',
          timeout: 15_000,
        },
      )
      .toBe(401)

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

test.describe('10b’s board, in a real browser', () => {
  /**
   * The board's rows, newest first.
   *
   * ⚠ MEASURED FROM THE PAGE'S OWN MARKUP RATHER THAN FROM THE API. The point of a browser
   * case is the rendered result: a server that ordered correctly and a client that re-sorted
   * would both pass an assertion made against the response.
   */
  async function boardReferences(page: Page): Promise<string[]> {
    await page.waitForSelector('[data-board="true"] .qr-staff-list__item', { timeout: 60_000 })

    return page.locator('[data-board="true"] .qr-staff-list__name').allInnerTexts()
  }

  test('renders the restaurant’s live pagers, newest first, and no identifier', async ({
    page,
  }) => {
    const fixture = await signInAsStaff(page)

    const references = await boardReferences(page)

    /*
     * ⚠ NEWEST FIRST, AS AN ORDERED ASSERTION OVER THE FIXTURE'S OWN TWO. The fixture
     * creates A-101 then A-102, so A-102 is the newer and must be the FIRST row. A
     * `toContain` pair would pass against a board that rendered them backwards, which in a
     * kitchen means reading the oldest order as the one that just came in.
     */
    expect(references[0]).toContain(fixture.alpha.second_order_reference)
    expect(references[1]).toContain(fixture.alpha.order_reference)

    // AND NOT ONE IDENTIFIER, over the whole rendered document rather than over the rows:
    // the session id reaches the client because every action needs it in a path, and must
    // reach nothing a person or a screenshot can see.
    const visible = await page.locator('body').innerText()

    expect(visible).not.toContain(fixture.alpha.session_id)
    expect(visible).not.toContain(fixture.alpha.second_session_id)
    expect(visible).not.toContain(fixture.alpha.id)
  })

  /**
   * One row, by its order reference, with the status attribute ON the row.
   *
   * ⚠ **THE FIRST VERSION OF THIS LOOKED FOR `[data-pager-status]` AS A DESCENDANT, AND IT
   * COULD NEVER MATCH — 12 e2e failures across all six staff projects, found by this
   * ticket's own gate rather than by a reviewer.** `StaffBoard` writes the attribute on the
   * `<li>` itself, so `row.locator('[data-pager-status="ready"]')` searched inside the row
   * for an element that is the row, waited the full 60 s and reported `0 elements`. An
   * assertion that cannot pass is the mirror of a vacuous one: it fails honestly, but it
   * tests nothing until it is fixed, and it cost a whole e2e round to learn.
   *
   * Composed as ONE selector so the attribute is matched where it lives.
   */
  function rowFor(page: Page, reference: string, status?: string) {
    const selector =
      status === undefined
        ? '[data-board="true"] .qr-staff-list__item'
        : `[data-board="true"] .qr-staff-list__item[data-pager-status="${status}"]`

    return page.locator(selector).filter({ hasText: reference })
  }

  /*
   * ⚠ THE TWO ACTING CASES RUN IN **ONE** PROJECT, AND THAT IS A SCOPE RATHER THAN A SKIP.
   *
   * The six staff projects share one fixture and one database. The first project to mark
   * A-101 ready leaves it `ready` for the other five — at which point the panel correctly
   * offers *They collected it* and *Buzz them again* rather than *Tell them it is ready*, so
   * the case would be asserting a precondition the previous project consumed. Making each
   * project issue its own pager means a spec reaching for PHP, which is the harness boundary
   * this suite does not cross.
   *
   * What is engine-dependent on this screen is `§12` — the rendered box, the resolved colour
   * pair, the focus ring, the mirroring — and every one of those is measured in all six
   * projects by the sweeps above, which now open the panel too. A POST and a re-render are
   * not engine-dependent, and `browser/staff-passkey.spec.ts` is the standing precedent for
   * a Chromium-only case with a stated reason.
   */
  const ACTING_PROJECT = 'staff-chromium-ltr'

  test('marks an order ready through the real stack, and the board converges on it', async ({
    page,
  }, testInfo) => {
    test.skip(
      testInfo.project.name !== ACTING_PROJECT,
      `the acting cases run in ${ACTING_PROJECT} only — see the comment above for why, and the §12 sweeps cover all six`,
    )

    const fixture = await signInAsStaff(page)

    await boardReferences(page)

    const row = rowFor(page, fixture.alpha.order_reference)

    await row.locator('[data-action="open"]').click()
    await row.locator('[data-action="ready"]').click()

    /*
     * ⚠ THE ASSERTION IS THE ROW'S OWN STATUS ATTRIBUTE, NOT THE BUTTON DISAPPEARING.
     *
     * `data-pager-status` is written from the status the SERVER last sent, so this waits for
     * a board that has been re-read and re-rendered — which is the convergence clause of
     * 10b's invariant, driven rather than reasoned. A disappearing button would also be
     * satisfied by a panel that merely closed.
     */
    await expect(rowFor(page, fixture.alpha.order_reference, 'ready')).toHaveCount(1, {
      timeout: 60_000,
    })

    // And the OTHER pager is untouched, which is what makes the transition keyed rather than
    // broad -- the same property `PagerIdorTest` asserts on the server, seen from the screen.
    await expect(rowFor(page, fixture.alpha.second_order_reference, 'waiting')).toHaveCount(1)
  })

  test('confirms a cancellation before it sends anything', async ({ page }, testInfo) => {
    test.skip(
      testInfo.project.name !== ACTING_PROJECT,
      `the acting cases run in ${ACTING_PROJECT} only — see the comment above for why`,
    )

    const fixture = await signInAsStaff(page)

    await boardReferences(page)

    const row = rowFor(page, fixture.alpha.second_order_reference)

    await row.locator('[data-action="open"]').click()
    await row.locator('[data-action="cancel"]').click()

    // The confirmation is on screen and the row is STILL waiting -- a destructive action
    // whose first tap already fired it has a dialog and no confirmation.
    await expect(row.locator('[data-action="cancel"]')).toHaveCount(0)
    await expect(rowFor(page, fixture.alpha.second_order_reference, 'waiting')).toHaveCount(1)

    /*
     * ⚠ AND THE REFUSAL IS LEFT UNCONFIRMED ON PURPOSE. This case asserts the confirmation
     * EXISTS; it does not press through, because cancelling consumes the fixture's second
     * pager and the case above needs it waiting. The confirmed path is driven in
     * `apps/web/test/staff-flows.test.tsx` against a scripted stack, where consuming a row
     * costs nothing — the split is deliberate rather than a gap.
     */
  })
})
