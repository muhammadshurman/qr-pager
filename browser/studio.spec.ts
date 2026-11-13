import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import type { Page } from '@playwright/test'
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
 * The origin apps/site is served on, which is the PUBLIC page's own serving layer.
 *
 * ⚠ A DIFFERENT PROCESS FROM EVERY OTHER ORIGIN IN THIS SUITE, and that is the point. The
 * studio is a Filament panel on the API; the published page is static bytes in the object
 * store, read back by a Next.js process that knows nothing about the panel. `R-303` is about
 * the seam between them, so a case that asserted the panel's own preview -- as the case above
 * does, correctly, for what it measures -- could never have reached it.
 */
function siteOrigin(): string {
  const base = process.env.QRPAGER_E2E_SITE_BASE

  if (base === undefined || base === '') {
    throw new Error(
      'QRPAGER_E2E_SITE_BASE is unset. This case needs apps/site serving the object store -- ' +
        'run it through `tools/browsers/bin/e2e`, which builds and starts it. It does not ' +
        'skip: `R-303` is open precisely because nothing has ever exercised this seam.',
    )
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

/*
 * ================================================================================
 * THE STUDIO AT `QRPAGER-44` — DENSITY, AND A TAB ROW THAT IS NOT CUT
 * ================================================================================
 *
 * Both cases here are about geometry a server-rendered assertion cannot reach. The PHP
 * suite can prove `targets.css` says the right thing; only an engine can prove the page
 * then MEASURES the right thing, and the two have disagreed on this exact control before —
 * `QRPAGER-41` part B's first attempt named three classes Filament does not render, changed
 * nothing, and was green the whole time.
 */
test.describe('the studio — QRPAGER-44', () => {
  test('gives the arrangement card a 44px target and a focusable, unfloored input — item A', async ({
    page,
  }) => {
    /*
     * ================================================================================
     * ⚠⚠ THE SUBJECT MOVED IN `QRPAGER-47` AND THE CLAUSE DID NOT
     * ================================================================================
     *
     * This case measured `Radio::make('template')` on the page tab: a 44px target on
     * `.fi-fo-radio-label` with a 24px painted circle nested inside it, which was
     * `QRPAGER-44` item A's separation of the TOUCH TARGET from the PAINTED SIZE — Muhammad's
     * ruling of 2026-11-10: *"§12 requires a 44px TOUCH TARGET. It says nothing about the
     * painted size."*
     *
     * `QRPAGER-47` Part B item 1 replaced that control with three cards on the theme tab, and
     * **there is now no Filament radio anywhere in the studio** — the page language control is
     * buttons (`builder.blade.php`), and nothing else offers a choice. So this case pointed at
     * `.fi-fo-radio-label` and found nothing, which is how the e2e run reported the move.
     *
     * `targets.css`'s `.fi-fo-radio-label` floor is deliberately LEFT IN PLACE: it governs any
     * Filament radio a later ticket adds, and `PanelTargetTest` still holds it to the file.
     * What is asserted here is the same clause against the control that now exists.
     */
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const card = page.locator('.studio-arrangement').first()
    const input = page.locator('.studio-arrangement__input').first()

    await expect(card).toBeVisible()

    const cardBox = await card.boundingBox()
    const inputBox = await input.boundingBox()

    expect(cardBox, 'the card is not in the layout, so nothing below measures it').not.toBeNull()
    expect(
      inputBox,
      'the radio is not in the layout at all, so it cannot be focused',
    ).not.toBeNull()

    /*
     * ⚠ THE TARGET IS THE CARD, because the input is inside the `<label>`: a pointer anywhere
     * on the card selects that arrangement. Both axes, because `§12` says both.
     */
    expect(
      Math.round(cardBox!.height),
      'the arrangement card is under §12’s 44px minimum target',
    ).toBeGreaterThanOrEqual(44)
    expect(Math.round(cardBox!.width)).toBeGreaterThanOrEqual(44)

    /*
     * ⚠ AND THE INPUT IS **NOT** FLOORED, which is this clause's other half in its new form.
     * The radio is clipped to a pixel so the card can be the whole control; if somebody put
     * `targets.css`'s 44px floor on `.studio-arrangement__input`, that clipped box would
     * become a 44px invisible square sitting over the card's corner — a target that selects
     * the right option by accident and swallows clicks meant for the thumbnail. An UPPER
     * bound, deliberately, exactly as the 24px assertion it replaces was.
     */
    expect(
      Math.round(inputBox!.width),
      'the clipped radio is carrying the touch-target floor, so it covers part of the card',
    ).toBeLessThan(44)

    // ⚠ AND IT IS INSIDE THE CARD. Everything above is true of a 1px input sitting NEXT TO a
    // card, which would make the card a label for nothing and the radio unreachable by
    // pointer — the nesting is what makes one element the control and the other its target.
    expect(
      await card.locator('.studio-arrangement__input').count(),
      'the radio is not inside its card, so the card labels nothing',
    ).toBeGreaterThan(0)

    /*
     * ⚠ FOCUSABLE, AND THE RING IS ON THE CARD — `§12`'s keyboard clause, which `display:none`
     * would have broken silently. The input is clipped, so the indicator has to be drawn
     * somewhere the eye is: `:has(:focus-visible)` puts it on the card.
     */
    await input.focus()

    const focus = await page.evaluate(() => {
      const el = document.querySelector('.studio-arrangement')
      const active = document.activeElement

      if (el === null) return null

      const style = getComputedStyle(el)

      return {
        focused: active !== null && el.contains(active),
        outline: style.outlineStyle,
        width: parseFloat(style.outlineWidth),
      }
    })

    expect(focus, 'no card is in the layout').not.toBeNull()
    expect(focus!.focused, 'the radio did not take focus, so it is out of the tab order').toBe(true)
    expect(focus!.outline, 'the focused card draws no outline').not.toBe('none')
    expect(focus!.width, 'the focused card’s outline has no width').toBeGreaterThan(0)
  })

  /*
   * ⚠ THE CONTROL WHOSE ABSENCE LET A DEFECT THROUGH EVERY GATE — `§11` QA round, High 1
   * and Medium 4. Two thirds of item A's stated invariant had NO measuring control: nothing
   * asserted the heading set's computed size or weight, and nothing asserted the row height.
   * What slipped through was `.studio-fieldset > legend { font-weight: 600 }` beating the
   * heading rule on source order at equal specificity — so the legend rendered 600 while the
   * CSS comment, `PLAN.md §4a` and this suite all said 700. A rule can be correct, committed
   * and overridden, and only a measurement of the RENDERED element can tell the difference.
   */
  test('renders the whole heading set at one step and one weight — item A', async ({ page }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    await expect(page.locator('.studio-section-field .fi-fo-field-label')).toBeVisible()

    const measured = await page.evaluate(() => {
      const read = (sel: string) => {
        const el = document.querySelector(sel)

        if (el === null) return null

        const c = getComputedStyle(el)

        return {
          size: c.fontSize,
          weight: c.fontWeight,
          text: el.textContent.trim().slice(0, 24),
        }
      }

      return {
        h2: read('.studio-layout__form h2'),
        legend: read('.studio-layout__form legend'),
        blocks: read('.studio-section-field .fi-fo-field-label'),
        // the control's OWN name, which is deliberately NOT in the set
        fieldLabel: read('.fi-fo-field:not(.studio-section-field) .fi-fo-field-label'),
        row: read('.fi-fo-builder-item-header-label'),
      }
    })

    for (const key of ['h2', 'legend', 'blocks'] as const) {
      const m = measured[key]

      expect(m, `${key} is not in the layout, so nothing here measures it`).not.toBeNull()
      expect(m!.size, `${key} is off the heading step`).toBe('16px')
      // 700 and not 600: Tajawal ships 400 and 700 here, so anything between is synthesised.
      expect(m!.weight, `${key} is not at the heavy weight`).toBe('700')
    }

    /*
     * And the set is DISCRIMINATING: a control's own name stays regular, or "the heading set"
     * would just mean "every label on the page" and the assertions above would be empty.
     *
     * ⚠⚠ THE DISCRIMINATOR IS READ FROM THE **THEME** TAB SINCE `QRPAGER-47`, and it has to be.
     * The ordinary field label on the page tab was `Radio::make('template')`'s — the
     * arrangement control, which Part B item 1 moved — and with it gone this read returned
     * `null` and the case failed with *"no ordinary field label is rendered"*, which is the
     * e2e run reporting the move rather than a defect in the layout.
     *
     * The theme tab's font and length fields are ordinary labelled fields and the stylesheet
     * is panel-wide, so the clause is unchanged: these two reads together say *the heading set
     * is one step and one weight AND an ordinary field label is not in it*. The three new
     * fields on that tab carry no wrapper label at all by design (`getDefaultLabel()` returns
     * `''`, because each renders its own), which is why the selector takes the FIRST label it
     * finds rather than assuming which field it belongs to.
     */
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const ordinary = await page.evaluate(() => {
      const el = document.querySelector(
        '.fi-fo-field:not(.studio-section-field) .fi-fo-field-label',
      )

      if (el === null) return null

      const c = getComputedStyle(el)

      return { size: c.fontSize, weight: c.fontWeight, text: el.textContent.trim().slice(0, 24) }
    })

    expect(ordinary, 'no ordinary field label is rendered on either tab').not.toBeNull()
    expect(ordinary!.weight, 'an ordinary field label took the heading weight').toBe('400')
  })

  test('keeps the block row at the shrunk height with its 44px cluster intact — item A', async ({
    page,
  }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    /*
     * ⚠ THE PICKER IS RETRIED RATHER THAN SLEPT AT, AND THE FIRST VERSION OF THIS CASE DID
     * NEITHER. The add control is an Alpine dropdown; a click that lands before Alpine binds
     * it is SWALLOWED with no error and no console message, so the case sat for its whole
     * 90-second budget waiting for a menu that was never going to open — a timeout on line
     * 1003 whose cause was on line 1002. Playwright's auto-waiting cannot help: the button is
     * present, visible and enabled the entire time; it simply is not listening yet.
     *
     * A fixed `waitForTimeout` would work on this machine and encode its speed. Asking for
     * the menu and clicking again when it is absent is the same idea without the guess.
     */
    const add = page.getByRole('button', { name: 'أضف كتلة' }).first()
    await expect(add).toBeVisible()

    const picked = page.getByRole('menuitem').first()
    let opened = false

    for (let attempt = 0; attempt < 5 && !opened; attempt++) {
      await add.click()
      opened = await picked
        .waitFor({ state: 'visible', timeout: 5_000 })
        .then(() => true)
        .catch(() => false)
    }

    expect(opened, 'the block picker never opened, so no row was ever added').toBe(true)
    await picked.click()

    const header = page.locator('.fi-fo-builder-item-header').first()
    await expect(header).toBeVisible()

    const box = await header.boundingBox()

    expect(box, 'no block row is in the layout, so nothing here measures one').not.toBeNull()

    // 56 -> 48. An UPPER bound, because the defect this guards is the row growing back.
    expect(Math.round(box!.height), 'the block row is not at the shrunk height').toBe(48)

    /*
     * ⚠ AND THE CLUSTER IS STILL AT THE BAR, which is the half that makes 48 the FLOOR
     * rather than a free choice: `4 + 32 + 4` would be 40 and the 44px buttons inside would
     * overflow the row they sit between. If this ever fails while the height above passes,
     * the row was shrunk past what its own controls fit in.
     */
    const buttons = header.locator('button')
    const count = await buttons.count()

    expect(
      count,
      'the row renders no controls, so the cluster is not being measured',
    ).toBeGreaterThan(2)

    for (let i = 0; i < count; i++) {
      const b = await buttons.nth(i).boundingBox()

      if (b === null) continue

      expect(
        Math.round(b.height),
        `cluster button ${String(i)} is under §12's 44px bar`,
      ).toBeGreaterThanOrEqual(44)
    }
  })

  test('keeps the tab row clear of the sticky topbar while the form scrolls — item B', async ({
    page,
  }) => {
    await signInAsStaff(page)

    /*
     * ⚠ A SHORT VIEWPORT, AND IT IS THIS CASE'S PRECONDITION RATHER THAN A STYLE CHOICE.
     * The `studio-chromium` project runs at 1280x1100 and this fixture's tenant has no
     * blocks, so the document is SHORTER than that viewport and the page cannot scroll at
     * all -- measured, `window.scrollY` stayed 0 at every offset asked for. A case about a
     * row being covered WHILE SCROLLING that cannot scroll would pass without ever reaching
     * the state it is about, which is the dead-control shape this suite keeps finding. The
     * WIDTH stays 1280, which is what every measurement in this ticket was taken at, and is
     * above the 56rem breakpoint so the two columns are still side by side.
     */
    await page.setViewportSize({ width: 1280, height: 600 })

    /*
     * ⚠⚠ THE **THEME** TAB SINCE `QRPAGER-47`, AND THE REASON IS THE PRECONDITION ABOVE. This
     * read `/studio`, whose form lost its tallest control when Part B item 1 moved the
     * arrangement choice off it — three sentence-long radio options — and this fixture's
     * tenant has no blocks, so the page tab at 600px high stopped scrolling at all:
     * `window.scrollY` stayed 0 and the case failed on its own precondition, which is exactly
     * the dead-control shape the comment above is about.
     *
     * The theme tab now carries four sections and is the studio's longest form, so the state
     * this case is about is reachable there. The clause is the tab row against the sticky
     * topbar — the panel's own chrome, identical on every tab.
     */
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const tab = page.locator('.studio-tab[aria-current="page"]')
    await expect(tab).toBeVisible()

    /*
     * At rest the row sits at y=114 and `div.fi-topbar-ctn` spans 0..64. 150 is past the
     * offset where a row that does NOT stick has carried its own top edge out of the
     * viewport entirely -- so on the defect this scroll leaves nothing to hit, and on the
     * fix the row is parked against the bar.
     */
    await page.evaluate(() => {
      window.scrollTo(0, 150)
    })
    await page.waitForTimeout(300)

    const scrolled = await page.evaluate(() => Math.round(window.scrollY))
    expect(scrolled, 'the page did not scroll, so nothing here was ever at risk').toBeGreaterThan(
      120,
    )

    /*
     * ⚠ `elementFromPoint` AND NOT A `position` ASSERTION. `toHaveCSS('position', 'sticky')`
     * would pass on a sticky row parked at the WRONG offset — still cut, still green. This
     * asks the engine what is actually painted at the tab's own top edge, which is the pixel
     * the ticket says is lost.
     */
    const verdict = await page.evaluate(() => {
      const el = document.querySelector('.studio-tab[aria-current="page"]')

      if (el === null) {
        return { ok: false, why: 'the active tab is gone' }
      }

      const r = el.getBoundingClientRect()
      const hit = document.elementFromPoint(r.x + r.width / 2, r.y + 1)
      const bar = document.querySelector('.fi-topbar')?.getBoundingClientRect() ?? null
      const barBottom = bar === null ? 0 : bar.y + bar.height

      return {
        // Both halves: the row parked AT OR BELOW the bar's bottom edge, and the pixel at
        // its own top edge painted by the row itself rather than by the bar over it.
        ok: r.y >= barBottom - 1 && hit !== null && hit.closest('.studio-tabs') !== null,
        why:
          `top edge at y=${String(Math.round(r.y))}, topbar ends at ` +
          `y=${String(Math.round(barBottom))}, painted there: ` +
          (hit === null ? 'nothing' : `${hit.tagName.toLowerCase()}.${hit.className}`),
      }
    })

    expect(verdict.ok, `the active tab’s top edge is covered — ${verdict.why}`).toBe(true)

    /*
     * ⚠ THE OPAQUE GROUND IS THE OTHER HALF OF THE FIX, AND `elementFromPoint` CANNOT SEE IT
     * — `§11` QA round, Low 7. A sticky row with no background still returns itself from
     * that probe while the form scrolls visibly through it, so the assertion above passes on
     * a shelf nobody can read.
     */
    const ground = await page.evaluate(() => {
      const el = document.querySelector('.studio-tabs')

      return el === null ? null : getComputedStyle(el).backgroundColor
    })

    expect(ground, 'the tab row is not in the layout').not.toBeNull()
    expect(ground, 'the sticky tab row paints no ground, so the form scrolls through it').not.toBe(
      'rgba(0, 0, 0, 0)',
    )
  })

  /*
   * ⚠ THE REGRESSION TEST FOR `§11`'s HIGH 2, AND IT IS A SEPARATE CASE BECAUSE IT CHANGES
   * THE ROOT FONT SIZE. The shelf offset was `64px`, measured off a booted panel at the
   * default root font size — right number, wrong unit. Filament declares
   * `.fi-body { --topbar-height: 4rem }` and applies it as `min-height`, and nothing in this
   * repo pins `html`/`:root` font-size, so a reader who raises their browser's default to
   * 20px got an 80px bar over a shelf still parked at 64 and the active tab's top 16px went
   * back under it — item B's own defect, returning for exactly the readers `§12` exists for.
   *
   * `addStyleTag` reproduces the same condition the preference does: `rem` resolves against
   * the root font size either way. Full-page zoom scales both and was never the hazard.
   */
  test('keeps the tab row clear of the topbar at a non-default root font size — item B', async ({
    page,
  }) => {
    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 600 })
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    await page.addStyleTag({ content: 'html { font-size: 20px }' })
    await page.waitForTimeout(300)

    const grown = await page.evaluate(() => {
      const bar = document.querySelector('.fi-topbar')

      return bar === null ? 0 : Math.round(bar.getBoundingClientRect().height)
    })

    // The precondition, asserted: if the bar did NOT grow, this case is measuring the
    // default layout under a different name and proves nothing.
    expect(
      grown,
      'the topbar did not grow, so the root font size never took effect',
    ).toBeGreaterThan(64)

    await page.evaluate(() => {
      window.scrollTo(0, 150)
    })
    await page.waitForTimeout(300)

    const verdict = await page.evaluate(() => {
      const el = document.querySelector('.studio-tab[aria-current="page"]')
      const bar = document.querySelector('.fi-topbar')

      if (el === null || bar === null)
        return { ok: false, why: 'the tab row or the topbar is gone' }

      const r = el.getBoundingClientRect()
      const br = bar.getBoundingClientRect()
      const barBottom = br.y + br.height
      const hit = document.elementFromPoint(r.x + r.width / 2, r.y + 1)

      return {
        ok: r.y >= barBottom - 1 && hit !== null && hit.closest('.studio-tabs') !== null,
        why:
          `tab top y=${String(Math.round(r.y))}, topbar ends y=${String(Math.round(barBottom))}, ` +
          `painted there: ${hit === null ? 'nothing' : hit.tagName.toLowerCase()}`,
      }
    })

    expect(verdict.ok, `the shelf did not follow the topbar when it grew — ${verdict.why}`).toBe(
      true,
    )
  })
})

/*
 * ================================================================================
 * THE PREVIEW FOLLOWS THE FORM — `QRPAGER-45`'s stated invariant
 * ================================================================================
 *
 * *"changing the arrangement, or editing a block field, is reflected in the preview with no
 * Save click and no page refresh — proven by a browser test asserting the preview CONTENT
 * changed, not that a reload was requested."*
 *
 * ⚠ BUILT SO IT CANNOT PASS ON A RELOAD THAT CHANGED NOTHING — Muhammad's instruction of
 * 2026-11-11. "Content changed" is satisfiable by an iframe that merely re-fetched the same
 * stored draft with a different nonce. So each case asserts the marker is ABSENT first,
 * changes the form without saving, asserts it is PRESENT, and then asserts the STORED DRAFT
 * still holds the old value — the database disagreeing with the screen is the whole claim.
 *
 * ⚠⚠ AND READING "THE STORED DRAFT" IS NOT WHAT IT LOOKS LIKE. The first version of these
 * cases fetched `/studio/preview` in a second tab to get it — and that route now PREFERS
 * the unsaved key, in the same session, which is the entire feature. It could never have
 * returned the stored draft and the assertion was unsatisfiable by construction. The
 * database is read HERE through the panel's own mount instead: `LandingBuilderPage::mount()`
 * fills the form from `LandingPages::draft()`, and the collapsed block row shows that
 * value as its summary.
 */
test.describe('the studio preview follows the form — QRPAGER-45', () => {
  /** What `/studio/preview` is rendering right now, as text. */
  async function previewText(page: Page): Promise<string> {
    return (await page.frameLocator('[data-studio-preview]').locator('body').innerText()).trim()
  }

  /**
   * The preview's THEME, which is not in the body.
   *
   * ⚠ `ThemeStylesheet` puts the tenant's tokens in ONE ATTRIBUTE of the rendered page's
   * root element and nowhere else — `ThemeTokenTest` asserts exactly that. So `previewText`
   * above, which reads `body`, cannot see a colour at all: the first version of the theme case
   * polled body markup for twenty seconds against something that could never contain it.
   * Third oracle in this suite to name a property and read a proxy.
   */
  async function previewTheme(page: Page): Promise<string> {
    return (
      (await page.frameLocator('[data-studio-preview]').locator('html').getAttribute('style')) ?? ''
    )
  }

  /**
   * The WHOLE previewed document, `<head>` included — `QRPAGER-47`.
   *
   * ⚠ IT REPLACED A `previewHtml()` THAT READ `body.innerHTML`, and the replacement is the
   * reason the old one is gone rather than kept beside it: nothing in this suite reads body
   * markup any more, and eslint refuses an unused helper — which is the right refusal, because
   * a second oracle nobody drives is a second oracle nobody notices is wrong.
   *
   * ⚠ `previewHtml()` CANNOT SEE AN ARRANGEMENT'S RULES. Every arrangement's CSS is one
   * `@elseif` branch inside `page.blade.php`'s single `<style>` element, which is in the
   * head; `body.innerHTML` stops short of it. So a case that wanted to prove *"these bytes
   * were rendered through the chosen arrangement's branch"* against the body alone could only
   * ever assert a class name, which the markup carries whether or not the rules came with it
   * — the exact hole `TemplateArrangementTest`'s own marker sweep was written to close.
   */
  async function previewDocument(page: Page): Promise<string> {
    return page.frameLocator('[data-studio-preview]').locator('html').innerHTML()
  }

  /**
   * Add one block through the real picker, and give it the heading it requires.
   *
   * The retry is `QRPAGER-44`'s finding, kept: the control is an Alpine dropdown and a click
   * landing before Alpine binds it is swallowed with no error and no console message.
   *
   * ⚠ AND THE HEADING IS FILLED HERE RATHER THAN BY THE CALLER, because a block with an
   * empty required field makes SAVE fail — which the second case discovered the slow way,
   * as a preview that rendered an empty page and an assertion that said so.
   */
  async function addBlock(page: Page, nth: number, heading: string): Promise<void> {
    const rows = page.locator('.fi-fo-builder-item')
    const before = await rows.count()

    const add = page.getByRole('button', { name: 'أضف كتلة' }).first()
    await expect(add).toBeVisible()

    const item = page.getByRole('menuitem').nth(nth)
    let opened = false

    for (let attempt = 0; attempt < 5 && !opened; attempt++) {
      await add.click()
      opened = await item
        .waitFor({ state: 'visible', timeout: 5_000 })
        .then(() => true)
        .catch(() => false)
    }

    expect(opened, `the block picker never opened for item ${String(nth)}`).toBe(true)
    await item.click()

    /*
     * ⚠ WAIT FOR THE ROW TO EXIST BEFORE REACHING FOR IT, AND THIS IS NOT A FORMALITY.
     * Without it `.last()` resolves against the DOM as it stands the instant the click
     * returns — which is still the PREVIOUS row — so both calls filled the SAME field and
     * the second block went to the server with an empty required heading. The save was then
     * refused, the preview stayed on an empty page, and the case failed three times on a
     * precondition while the feature under test was never reached. Diagnosed by dumping the
     * rows: `itemCount: 2`, `headings: ["HOW …", ""]`.
     */
    await expect(rows).toHaveCount(before + 1, { timeout: 15_000 })

    /*
     * ⚠ `.last()` AND NOT `.nth(nth)`. The picker index is not the row index: this fixture
     * tenant is shared across the whole e2e run, so by the time this case runs the page may
     * already hold blocks another case saved — and those render COLLAPSED, so their inputs
     * are hidden. Indexing by the picker position found one of those and waited five seconds
     * for a field nobody can see. A newly added block is appended and expanded, so it is the
     * last one, whatever was there before.
     */
    const field = rows.last().locator('input[type="text"]').first()
    await expect(field).toBeVisible()
    await field.fill(heading)

    /*
     * ⚠ THE FILL IS VERIFIED WHERE IT HAPPENS. Every precondition failure in this describe
     * has been a value that did not land where it was aimed, reported three steps later as
     * an empty preview. Asserting here turns "the preview is empty" into "this field is not
     * the one you think it is".
     */
    await expect(field, `the heading for picker item ${String(nth)} did not take`).toHaveValue(
      heading,
    )

    /*
     * ⚠ AND EVERY OTHER REQUIRED FIELD THE NEW ROW CAME WITH, WHICH IS NOT NONE.
     * `StudioForm` builds an item list as a Filament `Repeater` and never calls
     * `defaultItems(0)`, so Filament seeds it with ONE EMPTY ITEM — and for `how_it_works`
     * that item's `title` and `body` are required. A freshly added block is therefore
     * INVALID the moment it appears, `getState()` throws `ValidationException`, and
     * `previewChanged()` silently returns per the ruling. The preview then correctly shows
     * nothing new, and this case spent six runs reading that as a broken feature.
     *
     * Filling them is what an owner would do, and it is what makes this case about
     * arrangements rather than about an empty step.
     */
    const seeded = await rows.last().evaluate((root, text) => {
      const fields = Array.from(
        root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>(
          'input[required], textarea[required]',
        ),
      )
      let touched = 0

      for (const el of fields) {
        if (el.value !== '') continue

        el.value = `${text} ${String(touched)}`
        el.dispatchEvent(new Event('input', { bubbles: true }))
        el.dispatchEvent(new Event('change', { bubbles: true }))
        touched++
      }

      return touched
    }, heading)

    /*
     * ⚠ THROUGH THE DOM AND NOT `fill()`, BECAUSE THE FIELDS ARE COLLAPSED. The repeater is
     * `->collapsed()`, so the seeded item's inputs are present, required and HIDDEN —
     * `fill()` refuses a hidden field and a visibility check skips it, which is what the
     * previous version did and why this case failed a seventh time with the same empty
     * preview. Setting the value and dispatching `input` is what Livewire's binding
     * listens for, and this is SETUP: every assertion in this case still runs against the
     * rendered page.
     */
    expect(
      seeded,
      `the new row exposed no empty required field to seed for picker item ${String(nth)}`,
    ).toBeGreaterThanOrEqual(0)
  }

  /**
   * Click Save on the page that is open, and wait for THE SAVE'S OWN ROUND TRIP.
   *
   * ⚠ CLICKING IS NOT SAVING, AND TWO EARLIER ORACLES FOR "IT SAVED" BOTH PASSED BEFORE THE
   * WRITE — each measured on a real e2e run of 2026-11-11, each reported as *"the saved
   * colour is not in the draft"* by the remount assertion in the theme case, which is the
   * only one of the three that reads Postgres:
   *
   *   1. THE PREVIEW. `click()` returns when the click is dispatched, so polling the pane
   *      for the new colour passed while the save's POST was still in flight — the unsaved
   *      key already held that colour, which is the whole feature. `page.goto()` then
   *      cancelled the request and the draft kept `#828382`, the tenant's previous value.
   *      The preview is the one oracle that cannot tell a landed save from an abandoned one.
   *
   *   2. THE SUCCESS TOAST. Filament's notification lingers for several seconds, so the
   *      NEXT save found the PREVIOUS save's toast already on screen and returned
   *      immediately — the draft kept `#123456`, the baseline this case saves first. A
   *      durable marker is not an acknowledgement of the request that is in flight now.
   *
   * So the wait is on the response to the POST that carries `save` in its own Livewire
   * payload, started before the click and awaited after it. Livewire answers it when
   * `save()` has returned, which is after `saveDraft()` has committed.
   */
  async function saveAndWait(page: Page): Promise<void> {
    const landed = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        (response.request().postData() ?? '').includes('"save"'),
      { timeout: 20_000 },
    )

    await page.getByRole('button', { name: 'احفظ' }).first().click()
    await landed

    /*
     * ⚠ AND A REFUSED SAVE IS NAMED HERE RATHER THAN INFERRED LATER. It answers 200 with an
     * error bag, so the response above cannot tell the two apart — and from the preview a
     * refusal looks exactly like a save that has not landed yet, which cost this file four
     * runs once already.
     */
    const refusals = await page.locator('.fi-fo-field-error-message').allInnerTexts()

    expect(
      refusals.join(' | '),
      'the save was refused, so nothing below is about a stored document',
    ).toBe('')
  }

  /** What the DATABASE holds, read back through the panel's own mount. */
  async function storedHeading(page: Page): Promise<string> {
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    // The block this case added is the last one — see `addBlock()` for why not the first.
    const summary = page.locator('.studio-block-summary').last()
    await expect(summary).toBeVisible()

    return (await summary.innerText()).trim()
  }

  test('reflects a block field edit with no Save and no refresh', async ({ page }) => {
    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 1100 })
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    const saved = `SAVED ${String(Date.now())}`

    await addBlock(page, 0, saved)

    /*
     * ⚠ THE SAVE IS AWAITED, AND THE COMMENT HERE USED TO READ *"the save has landed when the
     * PREVIEW shows it"* — which is not true of this feature: `addBlock()`'s own debounce has
     * already written that heading into the unsaved key, so the poll below passes whether the
     * save landed or not. Step 4 reads the DATABASE through a navigation, and a navigation
     * that arrives while the save's POST is in flight cancels it — the latent flake the theme
     * case hit twice for real on 2026-11-11.
     */
    await saveAndWait(page)

    // And the pane has caught up, which is what step 1 below asserts the absence against.
    await expect.poll(async () => previewText(page), { timeout: 20_000 }).toContain(saved)

    const typed = `UNSAVED ${String(Date.now())}`

    // 1. absent to begin with — otherwise step 3 proves nothing
    expect(await previewText(page), 'the marker is already on screen').not.toContain(typed)

    // 2. type it, and do NOT save
    const heading = page.locator('.fi-fo-builder-item').last().locator('input[type="text"]').first()
    await heading.fill(typed)

    // 3. the preview catches up on its own, with no Save and no page refresh
    await expect.poll(async () => previewText(page), { timeout: 20_000 }).toContain(typed)

    // 4. ⚠ AND THE DATABASE STILL DISAGREES, which is what "without Save" means.
    const stored = await storedHeading(page)

    expect(stored, 'the stored draft moved, so this was an autosave and not a preview').toBe(saved)
    expect(stored, 'the stored draft already holds the typed value — nothing was unsaved').not.toBe(
      typed,
    )
  })

  test('reflects a THEME colour with no Save, and agrees with the draft after one', async ({
    page,
  }) => {
    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 1100 })

    // A page with something to colour, saved from the builder first.
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    const host = `THEME HOST ${String(Date.now())}`

    await addBlock(page, 0, host)
    await saveAndWait(page)

    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const swatch = page.locator('.studio-colour-input').first()
    await expect(swatch).toBeVisible()

    /*
     * ⚠ THE REPORTED BUG, END TO END, ON THE TAB IT WAS REPORTED ON. QRPAGER-45 shipped the
     * preview contract on the builder page alone: a colour changed here moved nothing, and
     * after Save the stale key from the builder tab kept winning — so the pane showed
     * something that contradicted the database and only the arrangement control cleared it.
     *
     * The PHP cases assert the contract; only an engine runs the debounce, the Livewire
     * round trip and the frame reload, which is what the owner actually experienced.
     */
    const colour = '#7d2b8f'

    /*
     * ⚠ A BASELINE COLOUR IS SAVED FIRST, SO THE PRECONDITION BELOW CAN BE REAL — the `§11`
     * QA round's L-13. `ThemeStylesheet::declarations()` emits *"only tokens the tenant
     * actually set"*, so on a tenant with no theme the rendered page carries no custom
     * property at all; `previewTheme()` ends in `?? ''` and returns an empty string, which
     * contains nothing — so `not.toContain(colour)` passed by measuring the absence of the
     * WHOLE attribute, which is also exactly what a frame that failed to load looks like.
     * One save makes "this colour is not there" and "nothing is there" tell apart.
     */
    const baseline = '#123456'

    await swatch.fill(baseline)
    await saveAndWait(page)

    await expect.poll(async () => previewTheme(page), { timeout: 20_000 }).toContain(baseline)

    const before = await previewTheme(page)

    /*
     * The pane is painting a theme, and it is not already painting the one this case is
     * about to type.
     */
    expect(
      before,
      'the preview frame paints no custom property at all, so this case cannot tell a ' +
        'colour that arrived from an attribute that never existed',
    ).toContain('--')

    expect(
      before,
      'the preview already shows the colour, so nothing below is proven',
    ).not.toContain(colour)

    await swatch.fill(colour)

    // 1. No Save: the preview catches up on its own.
    await expect.poll(async () => previewTheme(page), { timeout: 20_000 }).toContain(colour)

    /*
     * 2. After a Save on THIS page, the preview and the stored draft agree.
     *
     * 2a. THE REPORTED BUG, END TO END: the colour reaches the DATABASE, not just the key.
     */
    await saveAndWait(page)

    await expect.poll(async () => previewTheme(page), { timeout: 20_000 }).toContain(colour)

    // Re-mounted from Postgres. `ThemePage::mount()` fills this field from
    // `LandingPages::draft()` and reads no key at all, so the swatch's value is the only
    // oracle here for "the colour was STORED" rather than "the colour is on screen".
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const remounted = (
      await page.locator('.studio-colour-input').first().inputValue()
    ).toLowerCase()

    expect(remounted, 'the saved colour is not in the draft, so Save did not store it').toContain(
      colour,
    )

    await expect.poll(async () => previewTheme(page), { timeout: 20_000 }).toContain(colour)

    /*
     * 2b. AND THE SAVE DROPS THE UNSAVED KEY, WHICH IS WHAT THE REJECTION WAS ABOUT.
     *
     * ⚠ NOTHING ABOVE CAN FALSIFY THAT, AND THE FIRST VERSION OF THIS CASE CLAIMED TO —
     * the `§11` QA round's M-5. By the time it polled, the page's own debounce had already
     * written the SAME colour into the unsaved key; `PreviewController` prefers that key and
     * `get()` pushes its TTL out on every render. So with `forget()` deleted from
     * `ThemePage::save()` every assertion above still passed: the key agreed with the draft
     * by accident, which is precisely the condition the bug hides behind.
     *
     * The second attempt typed a second colour and raced the 400ms window, which is worse:
     * if the debounce wins the key holds the new colour and the case passes vacuously again,
     * so a green run would have meant nothing either way.
     *
     * So the stale key is written from the OTHER TAB, which is where the owner's came from:
     * *"the key written while the owner was on the builder tab kept winning over the freshly
     * saved draft"*. `StudioForm::merged()` builds the builder's document from the STORED
     * draft, so that key holds a block this tab's save will not write — and the save has to
     * clear it for the pane to agree with the database. Nothing is typed on the theme tab
     * afterwards, so there is no debounce in flight and no race to lose.
     */
    const stale = `STALE KEY ${String(Date.now())}`

    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    await addBlock(page, 0, stale)

    // The key holds it — asserted on the pane, because an unverified precondition here
    // would turn the whole of 2b into a test of an empty Redis key.
    await expect.poll(async () => previewText(page), { timeout: 20_000 }).toContain(stale)

    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    // Still the key after a full navigation: this pane is showing something the database
    // does not have.
    await expect.poll(async () => previewText(page), { timeout: 20_000 }).toContain(stale)

    await saveAndWait(page)

    /*
     * Both halves of `forgetUnsavedPreview()` are required to get here: without the `del`
     * the reloaded frame re-reads the same key, and without the dispatch nothing asks the
     * frame to reload at all — so deleting the call reddens this twice over.
     */
    await expect.poll(async () => previewText(page), { timeout: 20_000 }).not.toContain(stale)

    // ⚠ AND NOT BECAUSE THE PANE WENT BLANK, which `not.toContain` cannot tell apart. The
    // stored draft's own block is what should be there instead.
    expect(
      await previewText(page),
      'the pane holds neither the stale block nor the stored one, so it rendered nothing and ' +
        'the assertion above is vacuous',
    ).toContain(host)

    await expect.poll(async () => previewTheme(page), { timeout: 20_000 }).toContain(colour)
  })

  test('reflects an arrangement change with no Save and no refresh', async ({ page }) => {
    /*
     * ================================================================================
     * `QRPAGER-47`'s STATED INVARIANT, SECOND CLAUSE — AND `R-303`'s BROWSER HALF
     * ================================================================================
     *
     * *"Choosing a theme in the picker is reflected in the preview with no Save click and no
     * page refresh."* The server half — what `previewChanged()` writes and what the stored
     * draft still says — is `ArrangementPickerTest`. What only an engine can add is that a
     * real click on a real card produces the chosen arrangement's own RULES in the pane, and
     * that the database does not move while it happens.
     *
     * `R-303` is the row `QRPAGER-46` filed and recorded against this ticket *so it is
     * inherited rather than rediscovered*: the journey fixture named no template, so every
     * preview this suite had rendered was the default arrangement and `rail`, `ember` and
     * `tile` had never been in a browser against a real stack. A picker is what makes them
     * reachable.
     *
     * ⚠ THE ORACLE IS A RULE HEAD FROM `page.blade.php`'s `@elseif` CHAIN, not a class name in
     * the markup. A page can be arranged and unstyled — that is the `§11` QA round's M-3 on
     * the previous ticket — so only the CSS proves the bytes went through the chosen branch.
     * `previewDocument()` exists because those rules are in the `<head>`.
     *
     * ⚠⚠ AND IT NO LONGER ADDS TWO BLOCKS THROUGH THE UI, WHICH IS WHY IT USED TO TIME OUT.
     * The earlier shape was written for `classic` vs `editorial`, where only the ORDER of the
     * blocks differed — so it needed two blocks before a comparison meant anything, and the
     * whole preamble plus two 20-second preview polls exceeded the 90-second budget once the
     * case grew a published-bytes assertion. Each of the three arrangements now has a root
     * class and rules of its own, so ONE block is enough for the page to be non-empty and the
     * oracle is the CSS rather than a diff of two renders.
     */
    const noise: string[] = []

    page.on('pageerror', (e) => noise.push(`pageerror: ${e.message.slice(0, 200)}`))
    page.on('console', (m) => {
      if (m.type() === 'error') noise.push(`console: ${m.text().slice(0, 200)}`)
    })

    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 1100 })

    // One block, saved, so the previewed page is a page rather than §12.1's empty state.
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    await addBlock(page, 0, `HERO ${String(Date.now())}`)
    await saveAndWait(page)

    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    /*
     * ⚠⚠ THE **CARD** IS CLICKED AND THE INPUT IS ONLY READ, WHICH IS WHAT MADE THIS CASE
     * FINISH. The first version called `check()` on the radio — and that radio is clipped to
     * one pixel behind the card, so Playwright's actionability wait (visible, stable, RECEIVES
     * EVENTS) could never pass: the hit test lands on the label every time. The case spent its
     * whole 90-second budget inside one `check()` and reported a timeout with no assertion
     * error, twice.
     *
     * Clicking the card is also the real user action: the `<label>` IS the control's target,
     * which is the property the `QRPAGER-44` density case now measures. The input is still the
     * oracle for *which* arrangement is selected, read with `isChecked()` — a state read needs
     * no actionability.
     */
    const cards = page.locator('.studio-arrangement')
    const inputs = page.locator('.studio-arrangement__input')

    await expect(cards).toHaveCount(3)
    await expect(inputs).toHaveCount(3)
    await expect(page.locator('.studio-arrangements__note')).toBeVisible()

    const stored = await inputs.first().getAttribute('value')

    expect(await inputs.first().isChecked(), 'the tab did not open on the stored arrangement').toBe(
      true,
    )

    // The badge marks what is STORED. It must not move when another card is chosen, because
    // nothing has been saved — it is the only thing on the tab that distinguishes looking
    // from having changed.
    const badge = page.locator('.studio-arrangement__badge')
    const badgeCard = page.locator('.studio-arrangement', { has: badge })

    await expect(badge).toHaveCount(1)
    await expect(badgeCard.locator('.studio-arrangement__input')).toHaveAttribute(
      'value',
      stored ?? '',
    )

    const markers: Record<string, string> = {
      rail: '.rail-find{',
      ember: '.ember-hero{',
      tile: '.tile-grid{',
    }

    const chosen = (await inputs.nth(1).getAttribute('value')) ?? ''

    expect(
      Object.keys(markers),
      'an arrangement was added and this case does not know it',
    ).toContain(chosen)

    await cards.nth(1).click()

    expect(await inputs.nth(1).isChecked(), 'the arrangement did not actually change').toBe(true)

    // ⚠ WITH NO SAVE: the pane carries the chosen arrangement's own rules, written by the
    // 400ms debounce and the preview key behind it.
    await expect
      .poll(async () => previewDocument(page), { timeout: 20_000 })
      .toContain(markers[chosen])

    // And none of the others', because the chain is meant to be exclusive: two arrangements
    // served at once is a defect no screenshot would show.
    const document_ = await previewDocument(page)

    for (const [name, marker] of Object.entries(markers)) {
      if (name !== chosen) {
        expect(
          document_,
          `the preview carries ${name}'s rules as well as ${chosen}'s`,
        ).not.toContain(marker)
      }
    }

    await expect(badge).toHaveCount(1)
    await expect(badgeCard.locator('.studio-arrangement__input')).toHaveAttribute(
      'value',
      stored ?? '',
    )

    /*
     * ...and the DATABASE does not move. `template` is a member of the landing document, so
     * an autosave would show up on a fresh navigation as the SECOND card being the checked
     * one — and the checked state is server-rendered, so this read is of the stored value and
     * not of Alpine's memory.
     */
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const afterReload = page.locator('.studio-arrangement__input')

    await expect(afterReload).toHaveCount(3)

    expect(
      await afterReload.first().isChecked(),
      'the stored draft changed, so the arrangement was saved rather than previewed',
    ).toBe(true)

    expect(noise, 'the arrangement change logged an error').toEqual([])
  })

  /**
   * ⚠⚠ `R-303`, AND IT IS A SIBLING RATHER THAN A GROWTH OF THE CASE ABOVE — DELIBERATELY.
   *
   * That case's whole subject is *with no Save*: it asserts the stored draft does NOT move,
   * and a publish is a save followed by a render. Adding one would have falsified its own
   * premise. `§4a` also records that an earlier shape of it *"grew a published-bytes
   * assertion"* and then exceeded the 90-second budget, which is the second reason to put the
   * published half in a case with its own.
   *
   * ⚠ **WHAT THIS REACHES THAT NOTHING ELSE DOES.** `TemplateArrangementTest` drives
   * `LandingRenderer::render()` directly — the renderer's output, not a served page.
   * `landing-templates.spec.ts` renders from the FILESYSTEM. The case above asserts the panel's
   * PREVIEW pane. All three stop short of the one thing a customer actually gets: bytes out of
   * the object store, served by apps/site, addressed by `Host`. `R-303` has been open across
   * two tickets because that seam had no subject at all.
   */
  test('serves the arrangement the picker chose, and no other, on the PUBLISHED page', async ({
    page,
    request,
  }) => {
    const markers: Record<string, string> = {
      rail: '.rail-find{',
      ember: '.ember-hero{',
      tile: '.tile-grid{',
    }

    await signInAsStaff(page)

    /*
     * ⚠⚠ THIS CASE MAKES ITS OWN PAGE PUBLISHABLE, AND THE FIRST VERSION DID NOT — WHICH IS
     * THE DEFECT THE REDDEN PROOF FOUND RATHER THAN THE ONE IT WENT LOOKING FOR.
     *
     * Run in isolation (`-g`), it timed out on the publish response: the status plate read
     * `studio.page.empty` -- *“لا توجد كتل في صفحتك بعد”* -- so Publish was DISABLED and the click
     * did nothing. It had been passing in the full suite only because earlier cases had left
     * blocks behind. An ordering dependency is exactly what `R-303`'s proof must not have: the
     * whole point of the row is that the published page has no subject of its own.
     */
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })
    await addBlock(page, 0, `R-303 ${String(Date.now())}`)
    await saveAndWait(page)

    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const cards = page.locator('.studio-arrangement')
    const inputs = page.locator('.studio-arrangement__input')

    await expect(cards).toHaveCount(3)

    const values = await inputs.evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLInputElement).value),
    )

    expect(
      [...values].sort((a, b) => a.localeCompare(b)),
      'an arrangement was added or renamed and this case does not know it',
    ).toEqual(Object.keys(markers).sort((a, b) => a.localeCompare(b)))

    /*
     * ⚠ PICK THE ONE THAT IS **NOT** STORED. Publishing the arrangement the tenant already had
     * would assert the marker of a page that was already being served, and would pass against
     * a picker that wrote nothing at all.
     */
    const stored = await inputs.evaluateAll((nodes) =>
      nodes.findIndex((node) => (node as HTMLInputElement).checked),
    )

    /*
     * ⚠ `-1` MEANS THE DOCUMENT NAMES NO ARRANGEMENT, AND THAT IS NOT THE SAME AS "NONE IS IN
     * EFFECT". A document with no `template` member renders as `LandingTemplate::DEFAULT`,
     * which `QRPAGER-47` Part A moved to `rail` -- `RetiredTemplateTest` asserts exactly that,
     * and the migration's `create_missing => false` depends on it. So "the one that is not
     * stored" has to mean "not the one in EFFECT", or on a tenant that has never chosen, this
     * case could publish `rail` over a page already rendering as `rail` and prove nothing.
     */
    const effective = stored === -1 ? values.indexOf('rail') : stored

    expect(
      effective,
      'neither a stored arrangement nor the default could be located',
    ).toBeGreaterThan(-1)

    const target = (effective + 1) % values.length
    // `?? ''` for the index check only: the equality above already pins the three values, and
    // an empty string would fail the `toContain` on the next line rather than slip through.
    const chosen = values[target] ?? ''

    expect(Object.keys(markers), 'the picker offered an arrangement with no marker').toContain(
      chosen,
    )
    expect(chosen, 'the arrangement to publish is the one already in effect').not.toBe(
      values[effective],
    )

    // ⚠ THE CARD, NOT THE RADIO: the input is clipped to one pixel behind it, so Playwright's
    // actionability wait can never pass on the radio. Measured — see the case above.
    await cards.nth(target).click()
    expect(await inputs.nth(target).isChecked(), 'the arrangement did not change').toBe(true)

    await saveAndWait(page)

    /*
     * ⚠ THIS COMMENT SAID THE OPPOSITE UNTIL `QRPAGER-48` (`§11` QA L-4). It read *"the
     * publish action lives on the BUILDER page, not the theme tab: `ThemePage` has `save()`
     * and no `publish()`"*, which was true when it was written and stopped being true in
     * this ticket: the chrome puts a publish action in the theme tab's header
     * (`PAGE_HEADER_ACTIONS_BEFORE`), and `ThemePage::getHeaderActions()` publishes.
     *
     * The NAVIGATION below stays, because what this case needs from `/studio` is the
     * hostname off the builder's status plate, which is the next paragraph's subject —
     * not the publish control. A stale reason attached to a line that is still correct is
     * the harder kind to notice, which is why it is corrected rather than deleted.
     */
    await page.goto(`${apiOrigin()}/studio`, { waitUntil: 'domcontentloaded' })

    /*
     * ⚠ THE HOSTNAME COMES OFF THE STATUS PLATE, NOT OUT OF A FIXTURE. `builder.blade.php`
     * renders `$state['hostname']` -- `hostnameInScope()`, which prefers an active custom
     * domain over the platform one -- inside `studio.partials.emphasised`, which emits the
     * value in a `<strong>` because `{!! !!}` is refused in this application. So the one
     * `<strong>` in the status plate IS the host this page will be served on, stated by the
     * product rather than derived here.
     *
     * The first version of this case read it from a file the harness published to get it, and
     * that publish overwrote the restaurant's draft and reddened three sibling cases.
     */
    /*
     * ⚠ SELECTED BY ITS OWN MARKER, AND IT WAS `[role="status"]` UNTIL `§11` QA M-2. That
     * finding is about this page having FOUR live regions for one fact, so the builder's
     * plate stopped being one -- the chrome's plate is the single `role="status"` now, and
     * it renders on every tab. This case then timed out at 90s on an `innerText` for a
     * locator that matched nothing, which is the e2e gate catching a regression the whole
     * PHP suite was happy with.
     *
     * `data-page-status-plate` exists FOR this: the plate is still the thing that states the
     * hostname, and a case that needs it should not be asking which ARIA role it happens to
     * carry. Reinstating the role to keep this selector working would have undone M-2 to
     * satisfy a test, which is the wrong direction.
     */
    const hostname = (
      await page.locator('[data-page-status-plate="true"] p.text-sm strong').first().innerText()
    ).trim()

    expect(hostname, 'the studio states no hostname, so nothing could be fetched').not.toBe('')

    const landed = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        (response.request().postData() ?? '').includes('"publish"'),
      { timeout: 30_000 },
    )

    await page.getByRole('button', { name: 'انشر' }).first().click()
    await landed

    /*
     * ⚠ POLLED, BECAUSE A PUBLISH IS NOT ONE WRITE. `LandingPages::publish()` copies the
     * assets, writes `{checksum}.html`, moves the pointer and then writes `manifest.json` —
     * the pointer is what this reads, and asserting once on the first fetch would be racing
     * the publisher rather than measuring it.
     */
    const servedHtml = async (): Promise<string> => {
      const response = await request.get(`${siteOrigin()}/`, { headers: { host: hostname } })

      return response.status() === 200
        ? await response.text()
        : `status ${String(response.status())}`
    }

    await expect.poll(servedHtml, { timeout: 30_000 }).toContain(markers[chosen])

    // And none of the others', because `page.blade.php`'s `@elseif` chain is meant to be
    // exclusive: two arrangements served at once is a defect no screenshot would show.
    const document_ = await servedHtml()

    for (const [name, marker] of Object.entries(markers)) {
      if (name !== chosen) {
        expect(
          document_,
          `the published page carries ${name}'s rules as well as ${chosen}'s`,
        ).not.toContain(marker)
      }
    }
  })

  test('advises on a colour nobody can read on, and stops when it is put back', async ({
    page,
  }) => {
    /*
     * ================================================================================
     * `QRPAGER-47` PART B ITEM 3 — THE LIVE CONTRAST FEEDBACK, IN A REAL ENGINE
     * ================================================================================
     *
     * The ticket's words: *"Live contrast feedback: when a chosen colour is hard to read, the
     * message says what to do, not what failed."*
     *
     * ⚠ ONLY A BROWSER CAN DRIVE THIS. The advice is computed in Alpine from the EFFECTIVE
     * colours of two sibling rows — each read from its own `data-token` input, whose value is
     * the owner's override or the default the picker opened on. No PHP test can observe it,
     * and `ThemeTokenPairTest` deliberately covers the half that is server-side: which colour
     * each row is paired with.
     */
    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 1100 })
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const accent = page.locator('[data-token="color.accent"]')
    const row = page.locator('.studio-colour', { has: accent })
    const advice = row.locator('.studio-colour__advice')

    await expect(accent).toHaveCount(1)

    /*
     * The pair is `accent` / `accent_text`, which is Muhammad's ruling: a button's label is
     * read ON the button. The default pair clears AA, so the row opens quiet — and that is
     * asserted first, because an advice element that was ALWAYS visible would satisfy the
     * next assertion without judging anything.
     */
    await expect(advice).toBeHidden()

    /*
     * ⚠ A MID-TONE, CHOSEN SO IT FAILS AGAINST **BOTH** SIDES OF THE PAIR. The artboard's own
     * failing row is a yellow against white; this is the same idea in the one direction that
     * cannot be satisfied by luck — against `accent_text` (paper, near-white) a light accent
     * fails, and the advice is about exactly that combination.
     */
    await accent.fill('#c9c48f')

    await expect(advice).toBeVisible()
    await expect(advice).toHaveText(/\S/)

    // ⚠ WHAT IT SAYS, NOT MERELY THAT IT SPEAKS: the sentence tells the owner what to do and
    // names no ratio, no token and no WCAG level. The copy is the language file's, read here
    // so a reworded line cannot make this case red while a silent one cannot make it green.
    const sentence = await advice.innerText()

    expect(sentence).not.toMatch(/4\.5|WCAG|ratio|contrast/i)
    expect(sentence.length).toBeGreaterThan(20)

    /*
     * And the way back is the row's own: `رجّع الافتراضي` clears the override, the swatch
     * returns to the token's default, and the advice goes with it. That is `§12`'s "no state
     * without a next action" — the control that caused the warning carries the remedy.
     */
    await row.locator('.studio-colour__clear').click()

    await expect(advice).toBeHidden()
    await expect(row.locator('.studio-colour__default')).toBeVisible()
  })

  /**
   * ================================================================================
   * WHICH WAY TO MOVE IT — BOTH ARMS, `§11` QA H-1 ON `0e72316`
   * ================================================================================
   *
   * ⚠⚠ **THE ARM THE `§11` ROUND ADDED WAS REACHED BY NOTHING.** The round replaced one
   * fixed *"darken it"* with a computed choice between two sentences, and the only case that
   * touched the advice drove `#c9c48f` against a near-white partner — `mine < theirs`, so the
   * **darken** arm, every time. Measured by the scoped pass and confirmed by search: no file
   * under `apps/api/tests`, `browser/` or `packages/` named `adviceLighten` or
   * `hard_to_read_lighten`. **Inverting the ternary at `StudioPanelProvider.php:498` left every
   * suite green**, which is the state the round was run to prevent.
   *
   * ⚠ **AND THE CASE ASSERTS THE ADVICE, NOT THAT THE ARM WAS ENTERED.** A case that reaches a
   * branch and asserts nothing about what it produced is the vacuous assertion this repository
   * has now caught four times (`R-290` is the row). What an owner reads is the sentence, so the
   * sentence is what is pinned.
   *
   * ⚠ **THE TWO SENTENCES ARE READ OFF THE COMPONENT'S OWN CONFIG, NEVER HARD-CODED.**
   * `ColourField::toEmbeddedHtml()` emits them into `x-data="studioColour({…})"`, so this case
   * compares against the exact strings the panel would show. A reworded line therefore cannot
   * redden it, and a SWAPPED line cannot green it — which is the only property that matters
   * here, because the defect being closed is two correct sentences on the wrong branches.
   */
  test('tells the owner which WAY to move a colour, on both arms', async ({ page }) => {
    await signInAsStaff(page)
    await page.setViewportSize({ width: 1280, height: 1100 })
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const ground = page.locator('[data-token="color.background"]')
    const row = page.locator('.studio-colour', { has: ground })
    const advice = row.locator('.studio-colour__advice')

    await expect(ground).toHaveCount(1)

    /*
     * The row's own config: the pair it is judged against, the floor it must clear, and the
     * two sentences. Parsed out of `studioColour({…})` rather than reconstructed.
     */
    const xData = (await row.getAttribute('x-data')) ?? ''
    const config = JSON.parse(xData.slice(xData.indexOf('(') + 1, xData.lastIndexOf(')'))) as {
      pairedWith: string
      floor: number
      adviceLighten: string
      adviceDarken: string
    }

    // ⚠ THE CONTROL THAT MAKES THE TWO ASSERTIONS BELOW MEAN ANYTHING. If the panel ever
    // shipped one sentence under both keys, every assertion here would pass while the owner
    // was told the same thing in both directions.
    expect(
      config.adviceLighten,
      'both arms carry the same sentence, so no case could tell them apart',
    ).not.toBe(config.adviceDarken)
    expect(config.adviceLighten.length).toBeGreaterThan(20)
    expect(config.adviceDarken.length).toBeGreaterThan(20)
    expect(config.pairedWith).toBe('color.text')

    /** The two luminances the component itself compares, read the way it reads them. */
    const judged = async (): Promise<{ mine: number; theirs: number }> =>
      page.evaluate((pairedWith: string) => {
        const luminance = (
          window as unknown as { studioLuminance?: (hex: string) => number | null }
        ).studioLuminance

        const me = document.querySelector<HTMLInputElement>('[data-token="color.background"]')
        const partner = document.querySelector<HTMLInputElement>(`[data-token="${pairedWith}"]`)

        return {
          mine:
            luminance === undefined || me === null
              ? Number.NaN
              : (luminance(me.value) ?? Number.NaN),
          theirs:
            luminance === undefined || partner === null
              ? Number.NaN
              : (luminance(partner.value) ?? Number.NaN),
        }
      }, config.pairedWith)

    /*
     * ⚠⚠ BOTH HALVES OF THE PAIR GO BACK TO THEIR DEFAULTS FIRST, AND THAT IS THIS CASE'S
     * OWN DOING RATHER THAN AN INHERITED PREMISE.
     *
     * Measured: alone this case passed in 2.4 s, and in the full suite it failed on its very
     * FIRST assertion — the advice was already **visible** on load — because a sibling case
     * saves a theme colour and the draft it leaves behind makes this pair fail the floor
     * before this case has touched anything. An opening assertion that depends on test
     * ordering is exactly the defect `R-303`'s own case was repaired for one round earlier
     * in this ticket, and it is the same fix: own the premise.
     *
     * `.studio-colour__clear` carries `x-show="chosen"`, so it exists only while the row is
     * overridden — which makes its visibility the honest test of whether there is anything
     * to put back.
     */
    for (const token of ['color.background', config.pairedWith]) {
      const other = page.locator('.studio-colour', {
        has: page.locator(`[data-token="${token}"]`),
      })
      const clear = other.locator('.studio-colour__clear')

      if (await clear.isVisible()) {
        await clear.click()
        await expect(other.locator('.studio-colour__default')).toBeVisible()
      }
    }

    // NOW the default pair clears the floor, so the row is quiet — asserted before anything
    // else, because an advice element that was always visible would satisfy everything below
    // without judging a thing.
    await expect(advice).toBeHidden()

    /*
     * ─── THE LIGHTEN ARM: A DARK GROUND UNDER THE DEFAULT DARK TEXT ───
     *
     * Dark text on a dark ground, which is the mistake an owner actually makes. The ground is
     * set just LIGHTER than the ink it is read against, so `mine >= theirs` and the honest
     * advice is to move it further away by lightening it.
     */
    await ground.fill('#2b2b2b')

    const lighten = await judged()

    // ⚠ THE PREMISE, ASSERTED RATHER THAN ASSUMED. If the default ink ever moved past this
    // value the case would silently start measuring the other arm twice.
    expect(
      lighten.mine,
      'the ground is not the lighter of the pair, so this is not the lighten arm',
    ).toBeGreaterThanOrEqual(lighten.theirs)

    await expect(advice).toBeVisible()
    await expect(advice).toHaveText(config.adviceLighten)

    /*
     * ─── THE DARKEN ARM: THE SAME ROW, THE SAME PAIR, THE OTHER SIDE ───
     *
     * Now the ground goes BELOW the ink, so `mine < theirs` and moving away means darker. One
     * row and one pair for both arms, so nothing but the direction differs between them.
     */
    await ground.fill('#000000')

    const darken = await judged()

    expect(
      darken.mine,
      'the ground is not the darker of the pair, so this is not the darken arm',
    ).toBeLessThan(darken.theirs)

    await expect(advice).toBeVisible()
    await expect(advice).toHaveText(config.adviceDarken)

    // And the way back, so this case leaves the row as it found it.
    await row.locator('.studio-colour__clear').click()

    await expect(advice).toBeHidden()
  })

  test('computes the ratios the generated table publishes, to its own convention', async ({
    page,
  }) => {
    /*
     * ⚠ THE DUPLICATION CONTROL, AND IT IS WHY THIS CASE EXISTS AT ALL. The product's
     * contrast figures are computed at BUILD time by `packages/tokens/src/source.ts`; the
     * advice above is computed at RUN time in the panel, because a colour the owner is
     * choosing right now is in no generated table. Two implementations of one formula is a
     * duplication, and the honest answer to a duplication that cannot be removed is a test
     * that holds the two together.
     *
     * `contrast.json`'s own convention is stated in the file: *"TRUNCATED at two decimals
     * (floored), never rounded up: every threshold is a MINIMUM"*. So the comparison floors
     * the live figure the same way, and a drift of one hundredth in either direction fails.
     */
    const table = JSON.parse(
      readFileSync(
        join(import.meta.dirname, '..', 'packages/tokens/generated/contrast.json'),
        'utf8',
      ),
    ) as {
      orderReady: { pairs: { fgHex: string; bgHex: string; ratio: number }[] }
      pairs?: { fgHex: string; bgHex: string; ratio: number }[]
    }

    const published = [...table.orderReady.pairs, ...(table.pairs ?? [])].slice(0, 8)

    expect(
      published.length,
      'the generated table published no pairs to check against',
    ).toBeGreaterThan(2)

    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    // The function is registered on `alpine:init`, which has run by the time the first
    // `x-data` on the page is evaluated — so a swatch being present means it is there.
    await expect(page.locator('[data-token="color.text"]')).toHaveCount(1)

    for (const pair of published) {
      const live = await page.evaluate(
        ({ fg, bg }: { fg: string; bg: string }) => {
          const compute = (
            window as unknown as { studioContrast?: (a: string, b: string) => number }
          ).studioContrast

          return compute === undefined ? null : compute(fg, bg)
        },
        { fg: pair.fgHex, bg: pair.bgHex },
      )

      expect(
        live,
        'the panel defines no contrast function, so the advice cannot be computed',
      ).not.toBeNull()
      expect(
        Math.floor(live! * 100) / 100,
        `the panel computes a different ratio for ${pair.fgHex} on ${pair.bgHex} than the generated table publishes`,
      ).toBe(pair.ratio)
    }
  })
})

/**
 * ================================================================================
 * A BROWSER CASE PER CHROME REGION — `QRPAGER-48` item 2, `§11` QA H-1
 * ================================================================================
 *
 * The ticket's invariant ends *"with no region missing, proven by a browser case per
 * region"*, and what the branch shipped with was five Pest `assertSee('data-…')` cases —
 * four markers in the per-tab sweep, and **`data-status-plate` and `data-footer-bar` in no
 * test anywhere**. The review measured it rather than read it: with both render hooks
 * replaced by `return '';` — the status plate and the footer deleted outright —
 * `php artisan test tests/Feature/Landing` stayed green at 337 passed.
 *
 * ⚠ A MARKER IN THE HTML IS NOT A REGION ON THE SCREEN, which is why these are browser cases
 * and not more `assertSee`. `§11` QA H-2 is the proof: every one of those Pest assertions
 * passed while the chrome rendered with zero padding, square corners and 0×0 state dots,
 * because the tokens its CSS named did not exist. `assertSee` cannot see a box. These assert
 * `toBeVisible()` and, where the region's job is to say something, the words it says.
 *
 * The table is a LIST OF REGIONS the invariant names, so adding a sixth region without a row
 * here is a visible omission rather than an invisible one.
 */
const CHROME_REGIONS = [
  {
    region: 'the restaurant’s name in the topbar',
    marker: '[data-topbar-restaurant="true"]',
    // The tenant the harness signs in as, whatever it is called, must be NAMED -- an empty
    // span would satisfy a marker check and is the §12 defect ("no unexplained identifier")
    // wearing the right attribute.
    saysSomething: true,
  },
  {
    region: 'the studio chip beside it',
    marker: '[data-topbar-chip="true"]',
    saysSomething: true,
  },
  {
    region: 'the signed-in person’s first name beside the avatar',
    marker: '[data-topbar-person="true"]',
    saysSomething: true,
  },
  {
    region: 'the status plate',
    marker: '[data-status-plate="true"]',
    saysSomething: true,
  },
  {
    region: 'the footer bar’s save state',
    marker: '[data-footer-bar="true"]',
    saysSomething: true,
  },
  {
    region: 'the way back to the orders board',
    marker: '[data-back-to-staff="true"]',
    saysSomething: true,
  },
  {
    region: 'the studio language control',
    marker: '[data-panel-language="true"]',
    saysSomething: false,
  },
] as const

test.describe('the studio chrome — QRPAGER-48 item 2', () => {
  for (const { region, marker, saysSomething } of CHROME_REGIONS) {
    test(`renders ${region}`, async ({ page }) => {
      await signInAsStaff(page)
      await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

      const element = page.locator(marker)

      await expect(element, `${region} is not on the page`).toHaveCount(1)
      await expect(element, `${region} is on the page but not visible`).toBeVisible()

      // A region with no height is a region that is missing, whatever the DOM says -- which
      // is exactly the state QA H-2's undefined tokens produced for the two state dots.
      const box = await element.boundingBox()

      expect(box, `${region} has no box at all`).not.toBeNull()
      expect(
        box?.height ?? 0,
        `${region} rendered ${String(box?.height ?? 0)}px tall`,
      ).toBeGreaterThan(0)

      if (saysSomething) {
        expect(((await element.textContent()) ?? '').trim(), `${region} is empty`).not.toBe('')
      }
    })
  }

  /**
   * The publish action, which is a region of the artboard and also a CONTROL -- so this
   * asserts it is enabled rather than merely present. A disabled publish button is a correct
   * state for an empty page and would make a presence-only assertion pass on a studio nobody
   * can publish from.
   */
  test('renders the publish action in the page header', async ({ page }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const publish = page.locator('.fi-header button', { hasText: /.+/ })

    await expect(publish.first()).toBeVisible()
  })

  /**
   * The user menu's identity block, which needs OPENING -- and that is the point of putting
   * it in a browser rather than in `assertSee`. Filament renders the dropdown's contents in
   * the DOM behind an Alpine `x-show`, so a server-side assertion passes on a block no one
   * can ever see.
   */
  test('shows the full name and the email once the user menu is opened', async ({ page }) => {
    await signInAsStaff(page)
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    const identity = page.locator('[data-usermenu-identity="true"]')

    await expect(identity).toHaveCount(1)

    await page.locator('.fi-user-menu-trigger, [data-usermenu-identity="true"]').first().click()

    await expect(identity).toBeVisible()

    const text = ((await identity.textContent()) ?? '').trim()

    // Both lines, and the email is the one that cannot be confused with anything else.
    expect(text).not.toBe('')
    expect(text, 'the user menu shows no email address').toContain('@')
  })

  /**
   * ================================================================================
   * `§12`'s TOUCH FLOOR, OVER THE CHROME'S OWN CONTROLS — `§11` QA H-3
   * ================================================================================
   *
   * The staff side of this very ticket shipped a 24px studio link and the gate caught it,
   * because `browser/staff.spec.ts` sweeps every interactive box. The studio had no such
   * sweep: this file's 44px cases are aimed at the arrangement card and the block-row
   * cluster, and `browser/panel.spec.ts` measures a STATIC fixture with no server and no
   * session. So the chrome's two new controls -- the way back and the AR/EN choices --
   * arrived at 20px with nothing able to see them.
   *
   * At 390px, which is `§12`'s one-handed width and the width at which the topbar is most
   * crowded.
   */
  test('gives every chrome control at least 44px on both axes at 390px', async ({ page }) => {
    await signInAsStaff(page)
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })

    await page.waitForSelector('[data-topbar-restaurant="true"]', { timeout: 30_000 })

    const boxes = await page.evaluate(() => {
      const selectors = [
        '[data-back-to-staff="true"]',
        '[data-panel-language="true"] button',
        '[data-status-plate="true"] button',
        '[data-status-plate="true"] a[href]',
        '[data-footer-bar="true"] button',
        '[data-footer-bar="true"] a[href]',
      ]

      const found: { name: string; width: number; height: number }[] = []

      for (const element of document.querySelectorAll(selectors.join(', '))) {
        const box = element.getBoundingClientRect()

        if (box.width === 0 && box.height === 0) continue

        // `textContent` is non-null on an element, so no `??` here -- the shared helper in
        // `support/a11y.ts` records the same reasoning for the same expression.
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

    /*
     * Not vacuous. The chrome HAS controls at this width: the way back and two language
     * choices is three, and a sweep that found fewer has stopped seeing the thing it exists
     * to measure -- which is how this defect survived in the first place.
     */
    expect(boxes.length, 'the chrome sweep found no controls to measure').toBeGreaterThanOrEqual(3)

    for (const box of boxes) {
      expect(box.width, `"${box.name}" is ${String(box.width)}px wide`).toBeGreaterThanOrEqual(44)
      expect(box.height, `"${box.name}" is ${String(box.height)}px tall`).toBeGreaterThanOrEqual(44)
    }
  })
})

/**
 * ================================================================================
 * THE LANGUAGE TOGGLE SWITCHES THE LANGUAGE AND OPENS NOTHING — PR #22 walkthrough
 * ================================================================================
 *
 * Muhammad, reviewing PR #22: *"clicking AR/EN in the topbar opens the restaurant's logo
 * chooser instead of switching the panel language."*
 *
 * It did not open a chooser. It navigated the owner FULL-PAGE ONTO A RAW JPEG, which is what
 * an image filling the window looks like. Measured: the POST was correct and the response was
 * `302 Location: /studio/preview/asset/01a1d2b2-…`. This form sends no `Referer`, so
 * `url()->previous()` fell back to the session's previous URL; Laravel sets that from the last
 * non-AJAX GET; and the theme tab's preview iframe fetches `/studio/preview/asset/{id}` for
 * every image on the page. The redirect guard accepted it because an image under `/studio/` is
 * still under `/studio/`.
 *
 * ⚠⚠ THIS ASSERTS THE CONSEQUENCE, NOT THE REQUEST, WHICH IS MUHAMMAD'S OWN INSTRUCTION:
 * *"A case asserting only that the request fires would pass on the broken build."* And it
 * would have — the POST was never the broken part. So this asserts the three things an owner
 * would have noticed: the language CHANGED, they are still on a studio PAGE with its chrome,
 * and nothing opened.
 *
 * Both directions, because a toggle that can only move one way is a toggle that is stuck.
 */
test.describe('the studio language toggle — PR #22', () => {
  test('switches the panel language, stays on the page, and opens nothing', async ({ page }) => {
    let fileChooserOpened = false

    page.on('filechooser', () => {
      fileChooserOpened = true
    })

    await signInAsStaff(page)

    for (const [glyph, expected] of [
      ['EN', 'en'],
      ['ع', 'ar'],
    ] as const) {
      await page.goto(`${apiOrigin()}/studio/theme`, { waitUntil: 'domcontentloaded' })
      await page.waitForSelector('[data-panel-language="true"]', { timeout: 60_000 })

      await page.locator('.studio-lang__choice', { hasText: glyph }).click()
      await page.waitForLoadState('domcontentloaded')

      // 1. THE LANGUAGE CHANGED. The marker is rendered on whichever choice is current, so
      //    this reads the panel's own answer rather than our memory of what we clicked.
      await expect(page.locator(`[data-panel-language-current="${expected}"]`)).toHaveCount(1)

      // 2. STILL ON A STUDIO PAGE. The chrome only renders inside the panel, so a raw image —
      //    which is where this used to land — has none of it. This is the assertion the
      //    defect would have failed.
      await expect(page).toHaveURL(/\/studio\/[a-z-]+$/)
      await expect(page.locator('[data-topbar-restaurant="true"]')).toHaveCount(1)
      await expect(page.locator('[data-status-plate="true"]')).toHaveCount(1)

      // 3. AND NOTHING OPENED — no file chooser, no modal.
      expect(fileChooserOpened, 'clicking the language toggle opened a file chooser').toBe(false)

      const dialogs = await page.locator('[role="dialog"]:visible, .fi-modal:visible').count()

      expect(dialogs, 'clicking the language toggle opened a dialog').toBe(0)
    }
  })
})
