import { expect, test } from '@playwright/test'

import { signInAsStaff, staffFixture } from './support/e2e.ts'

/**
 * ================================================================================
 * THE STUDIO, IN A REAL BROWSER — `QRPAGER-41` part B
 * ================================================================================
 *
 * Three of part B's items cannot be settled anywhere else, and one of them was RULED to
 * belong here rather than in the PHP suite.
 *
 * **Item 4, upload on choose.** Muhammad: *"`Livewire::test()->set('upload', …)` makes the
 * temporary file in process and never issues a request. You already know what that cost: a
 * validated upload surface sat behind a globally-refused POST for a whole task with a green
 * suite. **'Uploads when chosen' is a browser case.**"* `§4a` records that defect in full --
 * the guard refused `POST <prefix>/upload-file` with a 404 problem document, a test asserted
 * that 404 as correct, and both were green because what they agreed on was wrong. So this
 * file drives a real `<input type="file">` over a real network.
 *
 * **Item 2, the sticky preview.** Whether an element stays in view while the page scrolls is
 * a question about layout and a scroll position; no server-rendered assertion can reach it.
 *
 * **Item 6, the dark palette.** A resolved colour is a computed style.
 *
 * ================================================================================
 * ⚠ THE STUDIO IS ON THE API ORIGIN, AND THE SESSION REACHES IT BECAUSE OF THE PORT
 * ================================================================================
 *
 * `signInAsStaff()` authenticates through the web origin, which proxies `/v1` to the API
 * (`QRPAGER-24`'s opt-in rewrite), so the session cookie comes back on `TLS_ORIGIN`. The
 * studio is a Filament panel served by the API itself on `TLS_API_ORIGIN`. Those are the
 * SAME HOST on DIFFERENT PORTS, and cookies are not scoped by port (RFC 6265 §8.5), so the
 * session the staff shell established is sent here too.
 *
 * That is a property of the harness rather than of the product, and it is written down
 * because a future change to either origin would break this file in a way whose cause is
 * nowhere near its symptom.
 */

function apiOrigin(): string {
  const base = process.env.QRPAGER_E2E_API_BASE

  if (base === undefined || base === '') {
    throw new Error('QRPAGER_E2E_API_BASE is unset. Run through `tools/browsers/bin/e2e`.')
  }

  return base.replace(/\/$/, '')
}

/**
 * A one-pixel PNG, as bytes.
 *
 * ⚠ IT HAS TO BE A REAL IMAGE, not a renamed text file. `InspectedImage` reads the CONTENTS
 * with libmagic and then decodes raster types a second time with `getimagesizefromstring`;
 * a file that fails either is refused, which is the whole point of that boundary. So a case
 * about "choosing a file uploads it" has to hand it something that genuinely is one, or it
 * would be measuring the refusal path while claiming to measure the success path.
 */
function onePixelPng(): Buffer {
  return Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
  )
}

test.describe('the studio — QRPAGER-41 part B', () => {
  test('uploads an image when it is chosen, with no second click — item 4', async ({ page }) => {
    await signInAsStaff(page)

    await page.goto(`${apiOrigin()}/studio/media`, { waitUntil: 'domcontentloaded' })

    const input = page.locator('#media-upload')
    await expect(input).toBeAttached()

    /*
     * ⚠ THE SUBMIT BUTTON IS GONE, AND THAT IS ASSERTED BEFORE THE UPLOAD RATHER THAN AFTER.
     * The ticket's words: *"The separate `Upload` button goes."* Checked first so a case that
     * somehow uploaded by pressing it could not pass -- the absence is part of the claim, not
     * a tidy-up after it.
     *
     * ⚠ SCOPED TO THE UPLOAD FORM, because a bare `form button[type="submit"]` is not that
     * claim: Filament's user menu renders a sign-out FORM with a submit button on every panel
     * page, so the first version of this line failed on the panel's own chrome and would have
     * kept failing however correct the upload became.
     */
    await expect(page.locator('form:has(#media-upload) button[type="submit"]')).toHaveCount(0)

    const name = `chosen-${String(Date.now())}.png`

    await input.setInputFiles({ name, mimeType: 'image/png', buffer: onePixelPng() })

    // No click anywhere between the line above and this one. The tile appearing IS the claim.
    await expect(page.locator(`[data-asset]`).filter({ hasText: name })).toBeVisible({
      timeout: 30_000,
    })
  })

  test('keeps the preview in view while the form scrolls — item 2', async ({ page }) => {
    await signInAsStaff(page)

    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    const pane = page.locator('.studio-pane')
    await expect(pane).toBeVisible()

    /*
     * ⚠ THE PREMISE FIRST, AND THIS CASE PASSED VACUOUSLY WITHOUT IT. Before the harness
     * served the panel its stylesheet, `position` resolved to `static` and the pane simply
     * happened to sit inside the viewport -- the two box assertions below were both true of
     * a page with no CSS at all, so the case reported a sticky pane that did not exist.
     *
     * Asserting the resolved `position` is what makes the rest a measurement: it fails loudly
     * when the rule is absent instead of passing on a coincidence of layout.
     */
    await expect(pane).toHaveCSS('position', 'sticky')

    /*
     * ⚠ THE SCROLL IS MADE REAL RATHER THAN HOPED FOR, AND IT IS NOT A SKIP. The first
     * version read the page's scroll height and skipped when it was short -- and it WAS
     * short, so the case skipped and reported nothing. `playwright.config.ts` states the rule
     * this broke in its own words: the two spec sets are separated by `testIgnore` *"rather
     * than by a skip -- a reasoned skip is not a measurement"*.
     *
     * A short viewport creates the condition instead. The WIDTH stays at 1280 because that is
     * what the sticky rule is conditioned on -- below `56rem` the columns stack and the pane
     * is deliberately not sticky -- and only the height changes, which is the axis the scroll
     * happens on. So this measures the real page at the real width, with a window short
     * enough that it has somewhere to scroll.
     */
    await page.setViewportSize({ width: 1280, height: 420 })
    await page.waitForTimeout(200)

    const scrollable = await page.evaluate(
      () => document.documentElement.scrollHeight - window.innerHeight,
    )

    expect(
      scrollable,
      'the page does not scroll, so "stays in view while scrolling" would be true of nothing',
    ).toBeGreaterThan(200)

    await page.evaluate(() => {
      window.scrollTo(0, document.documentElement.scrollHeight)
    })

    await page.waitForTimeout(300)

    const box = await pane.boundingBox()
    expect(box).not.toBeNull()

    // Still on screen after scrolling to the bottom: its top edge is inside the viewport and
    // its bottom edge is below the top of it. A pane that scrolled away fails both.
    expect(box!.y).toBeLessThan(420)
    expect(box!.y + box!.height).toBeGreaterThan(0)
  })

  test('paints every surface from a token, in both modes — item 6', async ({ page }) => {
    await signInAsStaff(page)

    /*
     * ⚠ ONE MODE SINCE `QRPAGER-42` A2, AND THE OTHER HALF IS REPLACED RATHER THAN DROPPED.
     * This case ran over `['light', 'dark']` when the studio had both. Muhammad's ruling of
     * 2026-11-10 removed the dark surface from both panels, so a dark assertion here would
     * assert a state that cannot occur. The two cases below are what took its place: the
     * ground paints the paper token under `prefers-color-scheme: dark`, and no element
     * computes to a dark-palette value. Narrowed, not deleted -- `R-250` is why that
     * distinction is written down.
     */
    for (const mode of ['light'] as const) {
      await page.addInitScript((m) => {
        try {
          localStorage.setItem('filament:theme:mode', m)
        } catch {
          /* a context with no storage is not a reason to fail the measurement */
        }
      }, mode)

      const offToken: string[] = []

      for (const path of ['/studio', '/studio/media', '/studio/theme', '/studio/domains']) {
        await page.goto(`${apiOrigin()}${path}`, { waitUntil: 'domcontentloaded' })
        await page.waitForTimeout(600)

        /*
         * ⚠ THE VACUITY GUARD, AND IT IS NOT DECORATION — it is the first thing this case
         * learned. An earlier run reported 131 off-token colours in light, every one of them
         * a browser DEFAULT (`#000000` text, `#0000ee` links, `#efefef` buttons): the panel's
         * compiled theme had not loaded at all, so the measurement was of an unstyled
         * document and said nothing whatever about the token layer.
         *
         * A colour measurement on a page with no stylesheet is not a measurement, and the
         * failure it produces points at the tokens rather than at the missing CSS. So the
         * premise is asserted first, by a value that only the panel theme defines.
         */
        const themed = await page.evaluate(() =>
          getComputedStyle(document.documentElement).getPropertyValue('--color-ink').trim(),
        )

        expect(
          themed,
          `${path}: the panel theme did not load, so any colour read here is a browser ` +
            'default rather than a token. Check that `composer assets` has run and that the ' +
            'built manifest is reachable from the browser.',
        ).not.toBe('')

        offToken.push(
          ...(await page.evaluate(() => {
            const root = getComputedStyle(document.documentElement)
            const NAMES = [
              'paper',
              'surface',
              'surface-2',
              'ink',
              'ink-mid',
              'hairline',
              'brand',
              'border-strong',
              'signal',
              'alert-fill',
              'alert-ink',
            ]

            /*
             * ⚠ THE SHORTHAND IS EXPANDED FIRST, AND SKIPPING THIS INVERTS THE RESULT.
             * `--color-surface` resolves to `#fff`; compared as a string against `#ffffff`
             * it looks like a different colour, and the first run of this measurement
             * reported every white surface in the panel as off-palette. Measured, corrected,
             * and written down here so the next reader does not re-derive it.
             */
            const expand = (h: string): string =>
              /^#[0-9a-f]{3}$/i.test(h) ? `#${h.slice(1).replace(/./g, (c) => `${c}${c}`)}` : h

            const tokens = new Set(
              NAMES.map((n) => expand(root.getPropertyValue(`--color-${n}`).trim().toLowerCase())),
            )

            const parse = (c: string): { hex: string; a: number } | null => {
              const m =
                /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,/\s]+([\d.]+))?\s*\)$/.exec(c)

              if (m === null) return null

              const hex = `#${[m[1], m[2], m[3]]
                .map((x) =>
                  Math.round(parseFloat(x ?? '0'))
                    .toString(16)
                    .padStart(2, '0'),
                )
                .join('')}`

              return { hex, a: m[4] === undefined ? 1 : parseFloat(m[4]) }
            }

            const found: string[] = []
            const props = [
              'backgroundColor',
              'color',
              'borderTopColor',
              'borderBottomColor',
              'borderInlineStartColor',
            ] as const

            for (const el of [
              document.documentElement,
              document.body,
              ...document.querySelectorAll('body *'),
            ]) {
              if (el.tagName === 'IFRAME') continue

              const box = el.getBoundingClientRect()
              if (box.width === 0 || box.height === 0) continue

              const c = getComputedStyle(el)

              for (const prop of props) {
                if (prop.startsWith('border')) {
                  const w = c[prop.replace('Color', 'Width') as 'borderTopWidth']
                  if (!w || parseFloat(w) === 0) continue
                }

                const p = parse(c[prop])
                if (p === null || p.a === 0) continue
                if (tokens.has(p.hex.toLowerCase())) continue

                found.push(
                  `${prop} ${p.hex} on ${el.tagName.toLowerCase()}.${el.className
                    .split(' ')
                    .filter(Boolean)
                    .slice(0, 2)
                    .join('.')}`,
                )
              }
            }

            return [...new Set(found)]
          })),
        )
      }

      expect(
        [...new Set(offToken)],
        `${mode}: these are painted by a studio screen and are not a token value, so two ` +
          'palettes are on screen at once',
      ).toEqual([])
    }
  })
})

/**
 * ================================================================================
 * `QRPAGER-42` — THE GROUND, THE ABSENT DARK PALETTE, AND A COLOUR VALUE'S ORDER
 * ================================================================================
 *
 * The ticket's stated invariant, in Muhammad's words: *"On a fresh clone, with the OS set to
 * prefer dark, loading the studio panel paints the ground as the paper token and paints no
 * value from the dark palette anywhere on the page; and a colour value rendered in that RTL
 * document reads `#RRGGBB` with the `#` first."*
 *
 * ⚠ THESE RUN WITH `colorScheme: 'dark'` AND NO STORED PREFERENCE — the condition the
 * invariant names. An ATTRIBUTE assertion is explicitly refused: *"An attribute assertion is
 * what missed this bug last time and does not count."* Every assertion below is a COMPUTED
 * value or a measured box.
 */
test.describe('the studio under an OS that prefers dark — QRPAGER-42', () => {
  test.use({ colorScheme: 'dark' })

  test('paints the ground as the paper token — A1', async ({ page }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(600)

    const seen = await page.evaluate(() => {
      const resolve = (value: string): string => {
        const probe = document.createElement('div')
        probe.style.backgroundColor = value
        document.body.appendChild(probe)
        const computed = getComputedStyle(probe).backgroundColor
        probe.remove()

        return computed
      }

      const root = getComputedStyle(document.documentElement)

      return {
        prefersDark: matchMedia('(prefers-color-scheme: dark)').matches,
        ground: getComputedStyle(document.body).backgroundColor,
        paper: resolve(root.getPropertyValue('--color-paper').trim()),
        // ⚠ THE RAW CUSTOM PROPERTY, KEPT SO THE PREMISE BELOW CAN BE ASSERTED.
        paperRaw: root.getPropertyValue('--color-paper').trim(),
      }
    })

    // The premise: without it the case would pass on a machine that prefers light.
    expect(seen.prefersDark, 'the context does not prefer dark, so this proves nothing').toBe(true)

    /*
     * ================================================================================
     * ⚠ AND THE SECOND PREMISE, WHICH THIS CASE SHIPPED WITHOUT — `§11` QA H-1, 2026-11-10
     * ================================================================================
     *
     * The assertion below compares the page against ITSELF, and that is vacuous on a page
     * with no stylesheet: `getPropertyValue` returns `''` for an undefined custom property,
     * `probe.style.backgroundColor = ''` leaves the probe unset, and a probe with no
     * background computes `rgba(0, 0, 0, 0)` — which is exactly what `body` computes with no
     * CSS. **MEASURED rather than reasoned:** these two probe functions, run verbatim against
     * `<html class="fi"><body class="fi-body">` with no stylesheet at all, returned
     * `ground`, `paper` and `forcedPaper` all `rgba(0, 0, 0, 0)`, and every colour assertion
     * in this block was GREEN.
     *
     * That is `R-264`'s own incident — *"looked green in every PHP test while no panel asset
     * loaded at all"* — and it is the reason `§4a` gives for running the e2e suite. The guard
     * already existed 150 lines above, in this same file, added when a run reported 131
     * off-token colours because the theme had not loaded; these cases did not use it.
     */
    expect(
      seen.paperRaw,
      'the panel theme did not load, so this is a browser default and not a token. Check ' +
        'that `composer assets` has run and that the built manifest is reachable.',
    ).not.toBe('')

    expect(seen.ground, 'the ground is transparent, which no token ever resolves to').not.toBe(
      'rgba(0, 0, 0, 0)',
    )

    /*
     * ⚠ COMPARED AS RESOLVED COLOURS, NOT AS STRINGS. `--color-paper` is authored as a hex
     * and `background-color` computes to `rgb(...)`; a string compare would fail on two
     * spellings of one colour, exactly as a shorthand compare did when this panel's palette
     * was first measured.
     */
    expect(seen.ground).toBe(seen.paper)
  })

  test('paints no value from the dark palette anywhere on the page — A2', async ({ page }) => {
    await signInAsStaff(page)

    /*
     * ⚠⚠ A STORED DARK PREFERENCE IS THE CONDITION, AND WITHOUT IT THIS CASE IS VACUOUS.
     * The first version set nothing and PASSED with `->darkMode(false)` reverted — because
     * reverting that only restores the ABILITY to choose dark, and `defaultThemeMode(Light)`
     * still rendered light for a user who had never chosen. The case asserted something that
     * was already true, which is the one failure mode this ticket has been about throughout.
     *
     * Muhammad's requirement names the real case: *"The real case is a user who toggled dark
     * once: if removing dark mode leaves a persisted 'dark' value that still resolves, that
     * user's panel renders dark forever."* Measured before the fix, with exactly this value
     * present: `html.class="fi dark"`, the body painted from Filament's dark grey, and the
     * token layer inverted. With `darkMode(false)`, Filament's own blade overwrites the key
     * with `light` on every load, so the paint below is what proves the user is recovered.
     */
    /*
     * ⚠ THE PREFERENCE IS STORED ONCE AND THE PAGE RELOADED — NOT VIA `addInitScript`, AND
     * THE DIFFERENCE IS THE BUG THIS CASE KEPT REPORTING AS A PRODUCT DEFECT.
     *
     * An init script runs before the scripts of EVERY document in the context, and the
     * studio embeds its preview as a SAME-ORIGIN iframe. The iframe's copy rewrote the
     * shared key back to `dark` after the parent's head had cleared it to `light`, and
     * before Filament's bundle read it on `alpine:init` — which then added `.dark`, which
     * the `QRPAGER-40` mirror faithfully copied onto `data-theme`. The symptom was a dark
     * panel; the cause was the test. It showed only on tabs carrying the preview, and only
     * sometimes, being a race between that iframe's load and Alpine's init.
     *
     * Storing it once and reloading is also the real user's situation: a preference left
     * from an earlier session, then an ordinary page load.
     */
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    await page.evaluate(() => {
      try {
        localStorage.setItem('filament:theme:mode', 'dark')
      } catch {
        /* a context with no storage cannot carry the preference this case is about */
      }
    })

    const found: string[] = []

    /*
     * ⚠ THE STUDIO TABS ONLY, AND `/platform` IS A CASE OF ITS OWN BELOW. The first version
     * put it in this loop and failed on it -- correctly, but for a reason with nothing to do
     * with dark mode: `signInAsStaff()` authenticates a TENANT OWNER, and
     * `User::canAccessPanel()` sends `/platform` to `is_platform_admin`, so what was measured
     * was a 403 document titled *"That is not permitted."*. That page is not a panel, carries
     * no `data-theme`, and therefore resolves the DARK token values under an OS that prefers
     * dark -- a true finding about an error page, reported as a finding about the ruling.
     */
    for (const path of ['/studio', '/studio/media', '/studio/theme', '/studio/domains']) {
      await page.goto(`${apiOrigin()}${path}`, { waitUntil: 'domcontentloaded' })

      /*
       * ⚠ WAIT FOR THE PAGE TO HAVE SETTLED, AND FAIL ON *THAT* IF IT HAS NOT. A fixed
       * `waitForTimeout` made this case intermittent: one run reported eleven dark values
       * and the next reported none, with no code between them. The cause is not dark mode --
       * it is a PARTIALLY LOADED page. `data-theme` is written by the panel's own head
       * script, and `tokens.css` resolves the DARK values under `:root:not([data-theme=
       * 'light'])`, so a document whose scripts have not run yet legitimately computes dark
       * colours under an OS that prefers dark.
       *
       * Measuring that and reporting it as a finding about the ruling is the lie this guard
       * removes: if the attribute never appears, the failure says the page did not settle.
       */
      await page
        .waitForFunction(() => document.documentElement.dataset.theme !== undefined, null, {
          timeout: 15_000,
        })
        .catch(() => {
          throw new Error(
            `${path} never set data-theme, so its scripts did not run — the colour measurement below would be of a half-loaded document`,
          )
        })

      found.push(
        ...(await page.evaluate((where: string) => {
          /*
           * The dark palette, by VALUE. `tokens.css` holds both sets, so the dark values are
           * knowable without the dark mode being reachable — which is the point: the claim
           * is that none of them is painted, not that a class is absent.
           */
          /*
           * ⚠ THE DARK-ONLY VALUES, AND THE TWO THAT ARE MISSING ARE MISSING ON PURPOSE.
           * The palette is a deliberate INVERSION: dark `paper` is `#191c22`, which is
           * light `ink`, and dark `ink` is `#fcfcfa`, which is light `paper`. Those two
           * values therefore prove nothing about which mode is rendering — a correct light
           * page paints `#191c22` as its text on every screen, and the first run of this
           * case reported 69 findings that were all exactly that.
           *
           * What remains is the four values the dark palette does NOT share with the light
           * one. A dark ground (`#2b2e33`), a dark raised surface (`#393b40`), dark muted
           * text (`#abadb2`) or a dark hairline (`#4f5256`) cannot be produced by a light
           * render at all, so any of them is the failure this case is for.
           */
          const DARK = ['#2b2e33', '#393b40', '#abadb2', '#4f5256']

          const expand = (h: string): string =>
            /^#[0-9a-f]{3}$/i.test(h) ? `#${h.slice(1).replace(/./g, (c) => `${c}${c}`)}` : h

          const parse = (c: string): string | null => {
            const m =
              /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,/\s]+([\d.]+))?\s*\)$/.exec(c)

            if (m === null) return null
            if (m[4] !== undefined && parseFloat(m[4]) === 0) return null

            return `#${[m[1], m[2], m[3]]
              .map((x) =>
                Math.round(parseFloat(x ?? '0'))
                  .toString(16)
                  .padStart(2, '0'),
              )
              .join('')}`
          }

          const dark = new Set(DARK.map((d) => expand(d.toLowerCase())))
          const hits: string[] = []

          for (const el of [
            document.documentElement,
            document.body,
            ...document.querySelectorAll('body *'),
          ]) {
            if (el.tagName === 'IFRAME') continue

            const box = el.getBoundingClientRect()
            if (box.width === 0 || box.height === 0) continue

            const c = getComputedStyle(el)

            for (const prop of ['backgroundColor', 'color'] as const) {
              const hex = parse(c[prop])

              if (hex !== null && dark.has(hex)) {
                hits.push(`${where} ${prop} ${hex} on ${el.tagName.toLowerCase()}`)
              }
            }
          }

          return [...new Set(hits)]
        }, path)),
      )
    }

    const stored = await page.evaluate(() => {
      try {
        return localStorage.getItem('filament:theme:mode')
      } catch {
        return null
      }
    })

    /*
     * ⚠ AND THE PREMISE IS ASSERTED, NOT ASSUMED. `darkMode(false)` makes Filament rewrite
     * the key to `light` on every load, so by the time this reads it the value is `light` --
     * which is itself the recovery working. What would make the case vacuous is the key
     * being ABSENT, meaning the init script never ran and no preference was ever stored.
     */
    expect(
      stored,
      'no theme preference is stored, so the condition under test never existed',
    ).not.toBeNull()

    expect(found, 'a dark-palette value is painted on a panel that has no dark mode').toEqual([])
  })

  test('paints the platform panel from the paper token, light, with a stored dark preference — A1 and A2', async ({
    page,
  }) => {
    /*
     * The other half of Muhammad's requirement: *"The stored-dark-preference test runs
     * against BOTH panels."* It is its own case because it needs its own principal -- the
     * seeded platform administrator, the only one `canAccessPanel()` admits here.
     *
     * The two panels also share ONE origin-wide `localStorage` key (`R-266`), which is part
     * of why the ruling covers both: had `darkMode(false)` landed on `/studio` alone, every
     * studio page load would have rewritten this admin's stored preference to light.
     */
    const fixture = staffFixture()

    /*
     * ⚠ THE CONTEXT IS ESTABLISHED THROUGH `signInAsStaff()` FIRST, AND THEN THE PRINCIPAL IS
     * SWITCHED. This case does not need the owner's session -- it replaces it a few lines
     * below -- but it is the only case in this file whose first navigation went straight to
     * the API origin in a fresh context, and that navigation came back
     * `ERR_CONNECTION_REFUSED` while every other case reached the same origin happily after
     * a web-origin navigation. Rather than assert a cause for that which I have not
     * isolated, this uses the path the rest of the suite already proves.
     *
     * The replacement is also what makes the case meaningful: the second login below is a
     * PLATFORM ADMIN, and `User::canAccessPanel()` admits only `is_platform_admin` here.
     */
    await signInAsStaff(page)

    /*
     * ⚠ THE PRINCIPAL IS SWITCHED FROM THE WEB ORIGIN, NOT FROM THE PANEL. Logging in with a
     * `fetch` issued by the API-origin page fails with `TypeError: Failed to fetch`: as a
     * tenant owner that page is a 403 PROBLEM DOCUMENT, and its policy does not permit the
     * connection. The web front proxies `/v1` (`QRPAGER-24`), so the exchange happens there,
     * exactly as the staff shell does it.
     *
     * The session then reaches the API origin because a cookie is scoped by host and not by
     * port — which is the same property `R-266` records for the two panels' shared storage.
     */
    const signedIn = await page.evaluate(
      async ({ email, password }: { email: string; password: string }) => {
        const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))
        const token = raw === undefined ? '' : decodeURIComponent(raw.slice('XSRF-TOKEN='.length))
        const response = await fetch('/v1/auth/login', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
          },
          credentials: 'same-origin',
          body: JSON.stringify({ email, password }),
          cache: 'no-store',
        })

        return response.status
      },
      { email: fixture.platform_admin.email, password: fixture.platform_admin.password },
    )

    expect(signedIn, 'the platform administrator could not sign in').toBeLessThan(400)

    await page.goto(`${apiOrigin()}/platform`, { waitUntil: 'domcontentloaded' })

    /*
     * The preference is stored on the API origin, which is where BOTH panels live — browser
     * storage is scoped by port where a cookie is not, so it has to be set here rather than
     * on the web front. Then an ordinary reload, which is the real user's situation.
     */
    await page.evaluate(() => {
      try {
        localStorage.setItem('filament:theme:mode', 'dark')
      } catch {
        /* a context with no storage cannot carry the preference this case is about */
      }
    })

    const stored = await page.evaluate(() => {
      try {
        return localStorage.getItem('filament:theme:mode')
      } catch {
        return null
      }
    })

    expect(stored, 'the dark preference was not stored, so the condition never existed').toBe(
      'dark',
    )

    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(600)

    const seen = await page.evaluate(() => {
      /*
       * ⚠ THE ORDER OF THESE THREE READS IS THE CASE, AND THE FIRST VERSION GOT IT WRONG --
       * IT WAS ALWAYS GREEN. It was an object literal whose `lightPaper` member set
       * `documentElement.dataset.theme = 'light'` and never put it back, and whose `paper`
       * member came AFTER it. Object members evaluate in source order, so `paper` was read
       * with the attribute the probe had just written: the two agreed whether or not the
       * panel pins it, which is the one thing this case exists to tell apart. Revert the
       * `HEAD_END` hook and that version still passed.
       *
       * So the page's OWN state is read first, every probe that forces the attribute restores
       * what was there, and `forcedPaper` is a separate reading taken afterwards.
       */
      const root = document.documentElement

      const resolve = (name: string): string => {
        const probe = document.createElement('div')
        probe.style.backgroundColor = getComputedStyle(root).getPropertyValue(name).trim()
        document.body.appendChild(probe)
        const computed = getComputedStyle(probe).backgroundColor
        probe.remove()

        return computed
      }

      const observed = {
        /*
         * ⚠ THE PREMISE FIRST. A 403 document would satisfy every colour assertion below
         * while proving nothing -- which is precisely how this measurement first went wrong
         * when it ran under the tenant owner's session.
         */
        isPanel: document.querySelector('.fi-body') !== null,
        ground: getComputedStyle(document.body).backgroundColor,
        paper: resolve('--color-paper'),
        // ⚠ AND THE SECOND PREMISE -- `§11` QA H-1. Without it every comparison below is
        // vacuous on a page whose stylesheet did not load; the studio case above carries the
        // measurement that proved it.
        paperRaw: getComputedStyle(root).getPropertyValue('--color-paper').trim(),
      }

      // And the same token with the attribute forced: equal to the reading above only when
      // the page is genuinely on the light palette already.
      const previous = root.getAttribute('data-theme')
      root.setAttribute('data-theme', 'light')
      const forcedPaper = resolve('--color-paper')

      if (previous === null) {
        root.removeAttribute('data-theme')
      } else {
        root.setAttribute('data-theme', previous)
      }

      return { ...observed, forcedPaper }
    })

    expect(
      seen.isPanel,
      'this is not the platform panel, so the measurement is of something else',
    ).toBe(true)

    expect(
      seen.paperRaw,
      'the platform theme did not load, so every colour below is a browser default',
    ).not.toBe('')

    expect(seen.ground, 'the ground is transparent, which no token ever resolves to').not.toBe(
      'rgba(0, 0, 0, 0)',
    )

    /*
     * A2 ON THIS PANEL: light paint with a stored dark preference. The measurement that
     * mattered is the TOKEN rather than the ground -- before the `data-theme` pin,
     * `--color-paper` resolved on `/platform` to the DARK paper under an OS that prefers
     * dark, because `darkMode(false)` does not reach the token layer's own media query. A
     * dark token value resolving on a panel with no dark mode is the failure, whatever the
     * ground happens to be.
     */
    expect(seen.paper, 'the token layer resolved a dark value on a panel with no dark mode').toBe(
      seen.forcedPaper,
    )

    /*
     * ⚠ AND A1 IS ASSERTED HERE, NOT REPORTED. An earlier version of this case recorded
     * `/platform`'s ground as a finding on the grounds that extending `A1` was not this
     * ticket's -- Muhammad, 2026-11-10: *"A1 is a token substitution, not a restyle …
     * leaving one panel on Filament's oklch recreates `R-268`."* So the rule exists in
     * `platform/theme.css` and this is what holds it there.
     *
     * Measured before that rule: `oklch(0.973 0.001 106.424)`, Filament's own, the same value
     * the studio carried before `A1`. The comparison is against the TOKEN as this page
     * resolves it, so the case fails both ways -- a ground from Filament, and a ground from
     * the right token on the wrong palette.
     */
    expect(seen.ground, 'the platform panel paints a ground that comes from no token').toBe(
      seen.paper,
    )

    /*
     * ================================================================================
     * ⚠ AND THE GROUND STANDS DOWN IF `.dark` EVER APPEARS — `§11` QA M-3, 2026-11-10
     * ================================================================================
     *
     * The rule above was written unconditional, and QA measured what that cost against the
     * compiled cascade: Filament emits `.fi-body:where(.dark,.dark *){background-color:
     * var(--gray-950); color: var(--color-white)}`, `:where()` adds no specificity and our
     * rule is later in source — so with `.dark` on the root the body took OUR light ground
     * and KEPT their white text. **Near-white on near-white, about 1.02:1.** The rule that
     * used to prevent it (`.dark .fi-panel-studio{--color-white: var(--color-ink)}`) was
     * deleted in this same delivery as unreachable, so the two changes together made the pair.
     *
     * `.dark` cannot appear while `->darkMode(false)` stands, which is why nothing was red.
     * Re-enabling dark is ONE LINE. So the selectors are scoped to `:root:not(.dark)` and
     * this is the case that holds them there: forced into the state the panel does not
     * currently have, the ground must NOT be the light paper.
     */
    const forcedDark = await page.evaluate(() => {
      const root = document.documentElement
      root.classList.add('dark')
      const seenDark = {
        ground: getComputedStyle(document.body).backgroundColor,
        text: getComputedStyle(document.body).color,
      }
      root.classList.remove('dark')

      return seenDark
    })

    expect(
      forcedDark.ground,
      `with .dark forced on, the ground is still the light paper (${forcedDark.ground}) while ` +
        `Filament paints its text ${forcedDark.text} -- the 1.02:1 pair QA M-3 measured`,
    ).not.toBe(seen.paper)
  })

  test('renders a colour value with the hash first — A3', async ({ page }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })
    await page.waitForTimeout(800)

    /*
     * ⚠ THE CASE CREATES ITS OWN CONDITION. The e2e tenant has no theme saved, so every
     * caption reads "الافتراضي" and there is no hex to measure — the first run failed on
     * exactly that, which is the vacuity guard working before the assertion it guards.
     * Driving the picker also exercises the live Alpine binding rather than a server render.
     */
    await page
      .locator('.studio-colour-input')
      .first()
      .evaluate((el: HTMLInputElement) => {
        el.value = '#eee8e8'
        el.dispatchEvent(new Event('input', { bubbles: true }))
      })

    await page.waitForTimeout(400)

    const caption = page.locator('.studio-colour__value').first()
    await expect(caption).toBeVisible()
    await expect(caption).toHaveText('#eee8e8')

    // The CSS contract: both halves. `isolate` alone was already true on the broken build.
    await expect(caption).toHaveCSS('direction', 'ltr')
    await expect(caption).toHaveCSS('unicode-bidi', 'isolate')

    const order = await page.evaluate(() => {
      const el = [...document.querySelectorAll('.studio-colour__value')].find((e) =>
        e.textContent.trim().startsWith('#'),
      )

      if (!el?.firstChild) return null

      const node = el.firstChild
      // `ChildNode.textContent` is nullable where `Element.textContent` is not — the
      // typechecker and the linter disagreed about this line until each was given its own.
      const text = node.textContent ?? ''
      const boxes: { ch: string; x: number }[] = []

      for (let i = 0; i < text.length; i++) {
        const range = document.createRange()
        range.setStart(node, i)
        range.setEnd(node, i + 1)
        boxes.push({ ch: text[i] ?? '', x: range.getBoundingClientRect().x })
      }

      return boxes
    })

    expect(order, 'no colour value is rendered, so this proves nothing').not.toBeNull()
    expect(order!.length).toBeGreaterThan(1)

    /*
     * ⚠ GEOMETRY, NOT `textContent`. Measured on the broken build the DOM string was already
     * `#eee8e8` in the correct order while the render read `eee8e8#` — so a text assertion
     * PASSES on the defect. That is the `R-264` class of lying test, from the same week, and
     * Muhammad named it when specifying this case.
     *
     * The document is RTL, so a larger x is further right. The hash must sit LEFT of the
     * first hex digit; on the broken build it sat 42px to its right.
     */
    const hash = order!.find((b) => b.ch === '#')
    const firstDigit = order!.find((b) => b.ch !== '#')

    expect(hash, 'the caption carries no hash').not.toBeUndefined()
    expect(firstDigit).not.toBeUndefined()
    expect(hash!.x, 'the hash renders after the digits, so the owner reads RRGGBB#').toBeLessThan(
      firstDigit!.x,
    )
  })
})
