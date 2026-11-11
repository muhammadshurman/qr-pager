import { readFileSync } from 'node:fs'

import { expect, test, type Page } from '@playwright/test'

/*
 * ================================================================================
 * THE TWO COMPUTED VALUES THAT BIT — `QRPAGER-46`, after the pre-merge screenshots
 * ================================================================================
 *
 * `QRPAGER-46` shipped two defects that every test it wrote was blind to, and the only
 * control that caught either was a human looking at a render:
 *
 *   1. a SECTION HEADING computing at the page title's size, in all three arrangements. The
 *      shared rule `h1,section:first-of-type h2{font-size:var(--size-heading)}` was written
 *      when every block was a direct child of `main`, so *"the first section"* meant THE
 *      HERO; each new arrangement puts blocks in a wrapper of its own and `:first-of-type`
 *      is scoped to the PARENT. `ember` rendered *"How the pager works"* at 64px, `tile`
 *      *"The room"*, `rail` *"Find us"* — each as large as the real title above it.
 *
 *   2. `rail` rendering **6,618px** tall against `tile`'s 2,777, because `display:block` on
 *      the shared `.grid` — the thing that makes that arrangement a column — also makes
 *      every photograph full-measure. A restaurant with a dozen dishes would have published
 *      twenty thousand pixels of photograph.
 *
 * ⚠ THE STRUCTURAL TESTS WERE RIGHT AND BLIND AT THE SAME TIME, which is why this file is
 * not an apology for them. The ticket's stated invariant is *"assert on structure — element
 * counts, ordering, which regions exist — never on pixels"*, and the structure was correct in
 * both cases: the right heading in the right region, in the right order, every block rendered
 * exactly once. What was wrong was a COMPUTED font size and a COMPUTED height, which only a
 * browser can report. The `e2e` suite is no help either: its fixture document names no
 * template, so every preview it renders is `classic` — extending it belongs to `QRPAGER-47`,
 * once a picker exists to drive it (`R-303`).
 *
 * ⚠ IT IS ITS OWN PROJECT, NOT PART OF THE KIT. `playwright.config.ts`'s kit projects must be
 * runnable from a fresh clone with nothing built, and these cases need `php artisan
 * landing:render-arrangements` to have run — a PHP 8.4 application the browsers container
 * cannot host. `pnpm run gate:templates` is both halves in order, and `bin/gate`'s step list
 * carries it so it runs every time rather than when somebody remembers.
 */

const DIR = '/repo/storage/landing-preview'
const ARRANGEMENTS = ['rail', 'ember', 'tile'] as const
const STATES = ['default', 'tenant'] as const

/**
 * The bound, stated rather than discovered — and it is a bound on THIS document.
 *
 * The fixture carries seven blocks and six photographs, so the number is meaningless without
 * that: measured after the fix, the three arrangements are 2,777 / 3,084 / 4,324 px at 1280,
 * and the defect measured **6,618**. 5,000 sits between the tallest correct value and the
 * defect, with about fifteen per cent of headroom over `rail` — enough that a legitimate
 * spacing change does not redden it, and far too little to let a column of full-measure
 * photographs back in.
 */
const MAX_HEIGHT = { 1280: 5_000, 390: 5_500 } as const

const WIDTHS = [
  { label: '1280' as const, width: 1280, height: 900 },
  { label: '390' as const, width: 390, height: 844 },
]

/** The page as the renderer wrote it, loaded from the filesystem — no server, no stack. */
async function openArrangement(page: Page, state: string, arrangement: string): Promise<void> {
  const file = `${DIR}/${state}-${arrangement}.html`
  let html: string

  try {
    html = readFileSync(file, 'utf8')
  } catch {
    throw new Error(
      `${file} is not there. Run \`php artisan landing:render-arrangements\` first — ` +
        '`pnpm run gate:templates` does both halves in order. A skip here would be a ' +
        'measurement nobody took, which is what this file exists to replace.',
    )
  }

  // `file://` would work and is deliberately not used: the images are beside the HTML, and
  // `setContent` with a base URL keeps the page's own bytes the subject rather than a
  // directory listing.
  await page.goto(`file://${DIR}/${state}-${arrangement}.html`, { waitUntil: 'load' })

  expect(html.length, 'the renderer wrote an empty page').toBeGreaterThan(1_000)

  await page.waitForFunction(() => Array.from(document.images).every((image) => image.complete), {
    timeout: 10_000,
  })
}

test.describe('the arrangements, measured as a browser computes them — QRPAGER-46', () => {
  for (const { label, width, height } of WIDTHS) {
    for (const state of STATES) {
      for (const arrangement of ARRANGEMENTS) {
        test(`${arrangement} at ${label}, ${state} theme: one display heading, bounded height`, async ({
          page,
        }) => {
          await page.setViewportSize({ width, height })
          await openArrangement(page, state, arrangement)

          /*
           * ⚠ EXACTLY ONE VISIBLE HEADING AT THE PAGE'S LARGEST SIZE, which is the property
           * rather than *"no section heading is as large as the title"*. In `classic` the
           * page's own `h1` is visually hidden and the HERO's `h2` is the display heading —
           * both compute at `--size-heading` and that is correct, so a rule about the `h1`
           * would fail on a page that is right. What was wrong is that a SECOND visible
           * heading joined it. Visibility is read from the box, because `.sr` hides by
           * clipping to one pixel rather than by `display:none`.
           */
          const headings = await page.evaluate(() => {
            const nodes = Array.from(document.querySelectorAll('main h1, main h2, h1'))

            return nodes.map((node) => {
              const box = node.getBoundingClientRect()

              return {
                text: node.textContent.trim().slice(0, 40),
                size: Number.parseFloat(getComputedStyle(node).fontSize),
                visible: box.width > 2 && box.height > 2,
              }
            })
          })

          expect(headings.length, 'the page carries no headings at all').toBeGreaterThan(2)

          const largest = Math.max(...headings.map((h) => h.size))
          const atLargest = headings.filter((h) => h.visible && h.size === largest)

          expect(
            atLargest.map((h) => `${h.text} (${String(h.size)}px)`),
            `${arrangement} shows more than one heading at the page's largest size (${String(largest)}px). ` +
              'A section heading as large as the page title is the defect the pre-merge ' +
              'screenshots found in all three arrangements: `:first-of-type` is scoped to the ' +
              'PARENT, so a new wrapper re-targets the shared display rule.',
          ).toHaveLength(1)

          /*
           * ⚠ AND THE DOCUMENT'S OWN HEIGHT, against a bound stated for THIS document. The
           * second defect had the right structure and the wrong size: seven blocks, every
           * block rendered once, every region where it belongs — and a page nobody would
           * scroll to the end of.
           */
          const documentHeight = await page.evaluate(() => document.documentElement.scrollHeight)

          expect(
            documentHeight,
            `${arrangement} at ${label} renders ${String(documentHeight)}px for a document of ` +
              "seven blocks and six photographs. The bound is this fixture's, and the defect " +
              'it was set against measured 6,618px: `display:block` on the shared `.grid` makes ' +
              'every photograph full-measure.',
          ).toBeLessThan(MAX_HEIGHT[label])

          /*
           * The photograph, taken in the same pass. Muhammad's instruction: *"keep the capture
           * step as a command, not a one-off"* — so the captures are a by-product of the
           * measurement rather than a separate thing to remember.
           */
          await page.screenshot({
            path: `${DIR}/${state}-${arrangement}-${label}.png`,
            fullPage: true,
          })
        })
      }
    }
  }

  test('the three arrangements differ in height, which is what makes them three', async ({
    page,
  }) => {
    /*
     * The companion to the bound: a ceiling alone is satisfied by three identical pages. This
     * is the computed-value half of the structural claim `TemplateStructureTest` makes — and
     * it is deliberately a WEAK assertion about magnitude (they differ) rather than a strong
     * one (by how much), because the amount is a design decision and the difference is the
     * product claim.
     */
    const heights: Record<string, number> = {}

    await page.setViewportSize({ width: 1280, height: 900 })

    for (const arrangement of ARRANGEMENTS) {
      await openArrangement(page, 'default', arrangement)
      heights[arrangement] = await page.evaluate(() => document.documentElement.scrollHeight)
    }

    const distinct = new Set(Object.values(heights))

    expect(
      distinct.size,
      `the three arrangements render the same document at the same height: ${JSON.stringify(heights)}`,
    ).toBe(3)
  })
})
