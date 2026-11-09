import { expect, test } from '@playwright/test'

import { signInAsStaff } from './support/e2e.ts'

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
     */
    await expect(page.locator('form button[type="submit"]')).toHaveCount(0)

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

    const viewport = page.viewportSize()
    expect(viewport).not.toBeNull()

    /*
     * ⚠ THE SCROLL HAS TO BE REAL, AND IT HAS TO BE LONGER THAN THE PANE. A page that cannot
     * scroll would pass this case trivially -- the pane never moves because nothing moves --
     * which is the vacuity shape this repository names everywhere else. So the scroll height
     * is asserted first, and only then is the position measured.
     */
    const scrollable = await page.evaluate(
      () => document.documentElement.scrollHeight - window.innerHeight,
    )

    test.skip(scrollable < 200, 'the page does not scroll far enough to measure stickiness')

    await page.evaluate(() => {
      window.scrollTo(0, document.documentElement.scrollHeight)
    })

    await page.waitForTimeout(300)

    const box = await pane.boundingBox()
    expect(box).not.toBeNull()

    // Still on screen after scrolling to the bottom: its top edge is inside the viewport and
    // its bottom edge is below the top of it. A pane that scrolled away fails both.
    expect(box!.y).toBeLessThan(viewport!.height)
    expect(box!.y + box!.height).toBeGreaterThan(0)
  })

  test('paints every surface from a token, in both modes — item 6', async ({ page }) => {
    await signInAsStaff(page)

    for (const mode of ['light', 'dark'] as const) {
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
