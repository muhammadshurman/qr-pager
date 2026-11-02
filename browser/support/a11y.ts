import type { Page } from '@playwright/test'

/**
 * ================================================================================
 * THE `§12` MEASUREMENTS THAT NEED A REAL ENGINE, AS ONE IMPLEMENTATION
 * ================================================================================
 *
 * `§12` names four clauses jsdom cannot answer — a visible focus ring, a 44px minimum touch
 * target, WCAG AA contrast, and `prefers-reduced-motion` — because all four are COMPUTED
 * values: a focused element's outline, a rendered box size, a resolved colour pair, a media
 * query. `apps/web/test/setup.ts`'s own header says so and says asserting any of them in
 * jsdom *"would pass and mean nothing"*.
 *
 * ⚠ **EXTRACTED AT 10e's ACCESSIBILITY PASS, AND THE REASON IS THE SECOND CONSUMER.** These
 * functions lived inside `browser/staff.spec.ts` and were exactly right there while there was
 * one staff surface. Task 10 added four more screens and a whole second surface (`/kds`,
 * `Principal::Device`), and a second copy of a contrast evaluator is a second place for the
 * luminance formula to be subtly wrong — which is the class of defect that reads as a pass.
 *
 * Nothing here is new behaviour: the bodies are the ones `staff.spec.ts` debugged over four
 * e2e rounds, and the comments that record WHY each is shaped as it is travelled with them.
 */

/** One measured interactive box. */
export interface Target {
  readonly name: string
  readonly width: number
  readonly height: number
}

/**
 * Every interactive box on the page, with its rendered size and its accessible name.
 *
 * ⚠ ZERO-SIZED ELEMENTS ARE SKIPPED, which is not leniency: a collapsed disclosure's contents
 * are not rendered, and measuring them would fail the clause for boxes nobody can tap.
 */
export async function targets(page: Page): Promise<Target[]> {
  return page.evaluate(() => {
    const found: { name: string; width: number; height: number }[] = []

    for (const element of document.querySelectorAll('button, a[href], input, [role="button"]')) {
      const box = element.getBoundingClientRect()

      if (box.width === 0 && box.height === 0) continue

      /*
       * Composed rather than chained with `??` or `||`: `textContent` is non-null on an
       * element, so a `??` on it is provably dead and `||` is refused for being the looser
       * operator. The fallback is for a control whose name is an attribute — an icon-only
       * button, which these surfaces have none of and a later one might.
       *
       * ⚠ AND A CHECKBOX INSIDE A LABEL TAKES THE LABEL'S BOX, which 10d's filter toggles
       * need: a native checkbox renders at ~13px in every engine and cannot be styled to 44,
       * so `staff.css` makes the WRAPPING LABEL the target. Measuring the input alone would
       * fail a screen that is correct, so the nearest `label` ancestor's box is used when the
       * element is inside one.
       */
      const text = element.textContent.trim()
      const label = element.getAttribute('aria-label') ?? ''
      const wrapper = element.closest('label')
      const measured =
        wrapper === null || wrapper === element ? box : wrapper.getBoundingClientRect()

      found.push({
        name: (text === '' ? label : text).slice(0, 40),
        width: Math.round(measured.width),
        height: Math.round(measured.height),
      })
    }

    return found
  })
}

/** One measured text/background pair. */
export interface ContrastPair {
  readonly selector: string
  readonly ratio: number
  readonly size: number
}

/**
 * The contrast ratio of each selector's text against its nearest opaque background, measured
 * from the ENGINE's own resolved colours rather than from the token source.
 *
 * `R-118`'s own lesson: a pair that is correct in the token file can still resolve wrong on a
 * page, and the only honest reading is `getComputedStyle`'s.
 */
export async function contrastPairs(
  page: Page,
  selectors: readonly string[],
): Promise<ContrastPair[]> {
  return page.evaluate((list: readonly string[]) => {
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

    for (const selector of list) {
      const element = document.querySelector(selector)

      if (element === null) continue

      const resolved = getComputedStyle(element)
      const ink = luminance(parse(resolved.color))
      const paper = luminance(ground(element))
      const ratio = (Math.max(ink, paper) + 0.05) / (Math.min(ink, paper) + 0.05)

      out.push({ selector, ratio, size: Number.parseFloat(resolved.fontSize) })
    }

    return out
  }, selectors)
}

/**
 * WCAG AA's bar for one rendered size: 4.5:1 for body text, 3:1 for large text.
 *
 * Large is 18.66px bold or 24px regular. The kit's headings are 28px, so they take the large
 * bar and nothing else does — stated as a function so the two call sites cannot disagree about
 * where the boundary is.
 */
export function aaBar(fontSizePx: number): number {
  return fontSizePx >= 24 ? 3 : 4.5
}

/**
 * Tag every visible control with a `data-sweep` id and return the ids.
 *
 * ⚠ **THE SWEEP ASSERTS THE SET IT REACHED, NOT A COUNT OF PRESSES, AND THE COUNT-BASED
 * VERSION COST TWO e2e ROUNDS.** Chromium and WebKit make a SCROLLABLE CONTAINER
 * keyboard-focusable, so a press can land on something that is not a control and a budget of
 * exactly `controls` presses reported *"Tab reached 6 of 7"* on a screen where all seven are
 * reachable. Identifying each control makes the assertion set equality — strictly stronger,
 * since a count could be satisfied by reaching one control seven times — and immune to
 * whatever focusable non-control an engine invents next.
 */
export async function tagControls(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const found: string[] = []

    document.querySelectorAll('button, a[href], input').forEach((element, index) => {
      const box = element.getBoundingClientRect()

      if (box.width === 0 && box.height === 0) return

      const id = `sweep-${String(index)}`

      element.setAttribute('data-sweep', id)
      found.push(id)
    })

    return found
  })
}

/**
 * Return the engine's sequential-navigation starting point to the top of the document.
 *
 * ⚠ **CLICKING A NON-INTERACTIVE ELEMENT, AND `blur()` ALONE IS NOT ENOUGH — MEASURED.**
 * Firefox keeps its starting point where a click left it, so with focus merely cleared it
 * tabbed on from mid-document, ran out into the browser chrome rather than cycling, and
 * reported the controls BEFORE that point as unreachable. A click on plain text moves the
 * point without focusing anything; a programmatic `focus()` would move it too and would also
 * make the first control's ring a programmatic focus, which does not match `:focus-visible` in
 * any engine.
 *
 * @param anchor a selector for a NON-interactive element at the top of the page.
 */
export async function resetTabOrder(page: Page, anchor: string): Promise<void> {
  await page.locator(anchor).first().click()

  await page.evaluate(() => {
    const active = document.activeElement

    if (active instanceof HTMLElement) active.blur()
  })
}

/**
 * Tab through the page and return the `data-sweep` ids that received a VISIBLE focus ring.
 *
 * ⚠ **REAL `Tab` PRESSES, AND `element.focus()` DOES NOT WORK — MEASURED, NOT REASONED.**
 * `:focus-visible` is a HEURISTIC about how focus arrived, and on a page with no prior
 * keyboard interaction a programmatic focus on a `<button>` does not match it: the gate
 * reported *"outline-style none"* from all six projects against a kit whose ring is correct.
 * The keyboard is also what the clause is about — *keyboard reachable, visible focus ring* —
 * and tabbing proves both at once.
 */
export async function focusRings(page: Page, budget: number): Promise<Set<string>> {
  const seen = new Set<string>()

  for (let press = 0; press < budget; press++) {
    await page.keyboard.press('Tab')

    const ring = await page.evaluate(() => {
      const active = document.activeElement

      if (!(active instanceof HTMLElement)) return null

      const id = active.getAttribute('data-sweep')

      if (id === null) return null

      const style = getComputedStyle(active)

      return {
        id,
        outline: style.outlineStyle,
        width: Number.parseFloat(style.outlineWidth),
      }
    })

    if (ring === null) continue
    if (ring.outline !== 'none' && ring.width > 0) seen.add(ring.id)
  }

  return seen
}
