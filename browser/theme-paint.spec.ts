import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, test, type Page } from '@playwright/test'

/*
 * ================================================================================
 * DOES THE THEME REACH THE PIXEL? — QRPAGER-49A's collector
 * ================================================================================
 *
 * `scan-css` and `QRPAGER-46`'s emitted/referenced closure both read SOURCE. They prove that
 * no literal colour is written and that no token is referenced with nothing behind it. What
 * neither can prove is that the value a restaurant chose is the value a browser PAINTS: a
 * rule can reference the right token and be overridden, scoped out, or sit on an element with
 * no box. `QRPAGER-49` imports three externally designed templates and every literal colour
 * in them is a candidate to survive translation, so the claim has to exist before they land.
 *
 * ⚠ **THIS FILE ONLY COLLECTS. IT JUDGES NOTHING**, and that split is deliberate: the
 * partition into sentinel / declared-non-themed / everything-else is `tools/landing/
 * paint-report.mjs`'s, where it can be read, diffed and argued with, rather than hidden inside
 * an `expect` that reports one line when it fails. What this file asserts is only that the
 * measurement really happened — a page that loaded, images that decoded, elements that exist.
 *
 * ⚠ **IT MEASURES THE PUBLISHED PATH, NOT A RENDER OF ITS OWN.** The page under the browser
 * was written by `LandingPages::publish()` into the object store and is served by `apps/site`
 * from a `Host` header, which is the production chain minus the CDN. `browser/
 * landing-templates.spec.ts` reads `file://` pages that an instrument rendered, which is the
 * right subject for that ticket's question (what a document looks like under each
 * arrangement) and the wrong one for this: a published page's assets live at root-relative
 * `/assets/…` paths that only a server can answer, so a `file://` measurement would be taken
 * on a page with no photographs in it.
 *
 * ⚠ **AND THE TEMPLATE IS NOT CHOSEN HERE.** One tenant has one live page, so the three
 * arrangements are measured one publish at a time: `tools/gate/bin/theme-paint-proof` publishes
 * a template, runs this file against it, and moves on — with the list of templates read from
 * `LandingTemplate::values()` rather than from a literal anywhere. This file measures whatever
 * is live and names it from the manifest the publisher wrote.
 */

/** One colour-bearing computed value, on one element, with what it would take to paint it. */
interface Sample {
  path: string
  shape: string
  pseudo: string | null
  property: string
  /** Whether the property is one the ticket names, or one this file adds. See `PROPERTIES`. */
  named: boolean
  /** The whole computed value, as the engine reports it. */
  value: string
  /** The one colour inside it, normalised: `#rrggbb`, `transparent`, or an `rgba()` form. */
  token: string
  paints: boolean
  /** Why it does or does not paint, in the engine's own numbers. */
  reason: string
  box: { w: number; h: number }
  text: string
}

interface Collected {
  url: string
  template: string
  width: number
  height: number
  documentHeight: number
  rootStyle: string
  customProperties: Record<string, string>
  elements: number
  samples: Sample[]
  styleAttributes: { path: string; style: string }[]
  images: { src: string; complete: boolean; naturalWidth: number }[]
}

interface Manifest {
  template: string
  hostname: string
  checksum: string
  theme: Record<string, string>
}

/**
 * The two widths, and they are the ticket's rather than a sample of a range.
 *
 * 390 is `§3.1` row 9's phone and the width `one-handed.spec.ts` already measures every
 * customer screen at; 1280 is the width the studio's own preview pane is measured at. A third
 * width would widen the claim without widening what it covers — and the honest boundary is
 * that anything behind a media query that is not one of these two is unmeasured, which the PR
 * body states rather than this file implying otherwise by taking three.
 */
const WIDTHS = [
  { label: '390', width: 390, height: 844 },
  { label: '1280', width: 1280, height: 900 },
] as const

/**
 * The repository, resolved FROM THIS FILE — never from a path the caller composed.
 *
 * ⚠ MEASURED AS A RED RUN, AND IT IS `browser/landing-templates.spec.ts`'s OWN DEFECT ONE
 * TICKET LATER. The browsers may run in a container that mounts the repository at `/repo`, so
 * an absolute path from the host (`/home/…/storage/theme-paint`) does not exist inside it: the
 * first run of this spec died at collection with `ENOENT … /home/pixels/…/manifest-rail.json`.
 * The two path-bearing variables are therefore REPO-RELATIVE and are joined here, which is the
 * one form that is correct on both machines the gate runs on.
 */
const REPO = join(dirname(fileURLToPath(import.meta.url)), '..')

function env(name: string): string {
  const value = process.env[name]

  /*
   * ⚠ A FAILURE AND NEVER A SKIP. `tools/browsers/bin/e2e`'s clause — *a reasoned skip is not
   * a measurement* — holds here with particular force: this file's whole output is evidence,
   * and a skipped run produces a green suite with no report behind it.
   */
  if (value === undefined || value === '') {
    throw new Error(
      `${name} is unset. This spec is driven by \`tools/gate/bin/theme-paint-proof\`, which ` +
        'publishes the sentinel theme, serves it through `apps/site` and sets the three ' +
        'variables this file reads. Running it by hand is not supported, because a page ' +
        'nobody published is not the published path.',
    )
  }

  return value
}

async function collect(page: Page, template: string, width: number): Promise<Collected> {
  return page.evaluate(
    ({ template: name, width: w }) => {
      /*
       * ⚠ THE PROPERTY LIST, AND THE SPLIT IN IT IS THE TICKET'S OWN BOUNDARY. `named` are the
       * properties `QRPAGER-49A` enumerates; the rest are colour-bearing properties this file
       * adds because the stated invariant says *every* colour-bearing computed style, and a
       * list that stopped at the ones somebody thought of is the shape of a guard whose scope
       * excludes where the damage lands. They are tagged rather than merged so a finding can
       * be read against the ticket that asked for it.
       */
      const NAMED = [
        'color',
        'background-color',
        'border-top-color',
        'border-right-color',
        'border-bottom-color',
        'border-left-color',
        'outline-color',
        'text-decoration-color',
        'box-shadow',
        'background-image',
        'fill',
        'stroke',
      ]
      const ALSO = [
        'caret-color',
        'column-rule-color',
        'text-emphasis-color',
        '-webkit-text-fill-color',
      ]

      /*
       * ⚠ `fill` AND `stroke` ARE COLLECTED ON SVG ELEMENTS ONLY, AND THAT IS A SCOPE
       * DECISION RATHER THAN AN EXEMPTION. Both are inherited properties that COMPUTE on every
       * HTML element — `getComputedStyle(document.body).fill` is `rgb(0, 0, 0)` on a page with
       * no SVG in it — and no pixel can ever take that value, because there is nothing to fill.
       * Collecting it would put one unpaintable black on every element in the report and bury
       * the findings under it. This page carries no SVG at all today, so the scope is vacuous
       * here; it stops being vacuous the first time a template ships an icon.
       */
      const SVG_ONLY = ['fill', 'stroke']

      const COLOUR =
        /(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^()]*(?:\([^()]*\)[^()]*)*\)|#[0-9a-f]{3,8}\b|\b(?:transparent|currentcolor|canvas|canvastext|linktext|visitedtext|activetext|buttonface|buttontext|buttonborder|field|fieldtext|highlight|highlighttext|selecteditem|selecteditemtext|graytext|accentcolor|accentcolortext)\b/gi

      /** `rgb(255, 0, 228)` -> `#ff00e4`; a zero alpha is `transparent` whatever the channels. */
      const normalise = (raw: string): string => {
        const match = /^rgba?\(([^)]+)\)$/i.exec(raw.trim())

        if (match === null) return raw.trim().toLowerCase()

        const parts = (match[1] ?? '')
          .split(/[\s,/]+/)
          .filter((piece) => piece !== '')
          .map((piece) => Number.parseFloat(piece))
        const alpha = parts.length > 3 ? parts[3] : 1

        if (!Number.isFinite(alpha) || alpha === 0) return 'transparent'

        const hex =
          '#' +
          parts
            .slice(0, 3)
            .map((channel) => Math.round(channel).toString(16).padStart(2, '0'))
            .join('')

        return alpha === 1 ? hex : `rgba(${parts.slice(0, 3).join(', ')}, ${String(alpha)})`
      }

      const pathOf = (element: Element): string => {
        const parts: string[] = []
        let node: Element | null = element

        while (node !== null && parts.length < 8) {
          let part = node.tagName.toLowerCase()

          if (node.classList.length > 0) part += '.' + Array.from(node.classList).join('.')

          const parent: Element | null = node.parentElement

          if (parent !== null) {
            const siblings = Array.from(parent.children).filter((c) => c.tagName === node?.tagName)

            if (siblings.length > 1) {
              part += `:nth-of-type(${String(siblings.indexOf(node) + 1)})`
            }
          }

          parts.unshift(part)
          node = node.parentElement
        }

        return parts.join('>')
      }

      const shapeOf = (element: Element, pseudo: string | null): string => {
        const classes = Array.from(element.classList)

        return (
          element.tagName.toLowerCase() +
          (classes.length > 0 ? '.' + classes.join('.') : '') +
          (pseudo ?? '')
        )
      }

      const ownText = (element: Element): string => {
        const text = Array.from(element.childNodes)
          .filter((node) => node.nodeType === 3)
          .map((node) => (node.textContent ?? '').trim())
          .join(' ')
          .trim()

        return text.slice(0, 40)
      }

      /**
       * Whether a value of this property, on this element, can reach a pixel.
       *
       * ⚠ IT IS RECORDED RATHER THAN FILTERED ON. A computed colour that cannot paint is still
       * reported — the report says so and the reader decides — because *"it computes but never
       * paints"* is itself the answer to the dead-control question this ticket asks, and a
       * collector that dropped those samples would be answering it by not asking.
       */
      const paintsFor = (
        style: CSSStyleDeclaration,
        property: string,
        token: string,
        visible: boolean,
        hasText: boolean,
      ): { paints: boolean; reason: string } => {
        if (!visible) return { paints: false, reason: 'the element has no box larger than 2px' }
        if (token === 'transparent') return { paints: false, reason: 'the value is transparent' }

        if (property === 'color' || property === '-webkit-text-fill-color') {
          return hasText
            ? { paints: true, reason: 'the element carries its own text' }
            : { paints: false, reason: 'the element carries no text node of its own' }
        }

        if (property === 'background-color' || property === 'background-image') {
          return { paints: true, reason: 'the element has a box to fill' }
        }

        if (property.startsWith('border-')) {
          const side = property.split('-')[1] ?? ''
          const borderWidth = Number.parseFloat(style.getPropertyValue(`border-${side}-width`))
          const borderStyle = style.getPropertyValue(`border-${side}-style`)

          return borderWidth > 0 && borderStyle !== 'none' && borderStyle !== 'hidden'
            ? { paints: true, reason: `border-${side}: ${String(borderWidth)}px ${borderStyle}` }
            : {
                paints: false,
                reason: `border-${side} is ${String(borderWidth)}px ${borderStyle}`,
              }
        }

        if (property === 'outline-color') {
          const outlineWidth = Number.parseFloat(style.outlineWidth)

          return outlineWidth > 0 && style.outlineStyle !== 'none'
            ? { paints: true, reason: `outline: ${String(outlineWidth)}px ${style.outlineStyle}` }
            : {
                paints: false,
                reason: `outline is ${String(outlineWidth)}px ${style.outlineStyle} in this state`,
              }
        }

        if (property === 'text-decoration-color') {
          return style.textDecorationLine !== 'none'
            ? { paints: true, reason: `text-decoration-line: ${style.textDecorationLine}` }
            : { paints: false, reason: 'text-decoration-line is none' }
        }

        if (property === 'column-rule-color') {
          const ruleWidth = Number.parseFloat(style.columnRuleWidth)

          return ruleWidth > 0 && style.columnRuleStyle !== 'none'
            ? { paints: true, reason: `column-rule: ${String(ruleWidth)}px` }
            : { paints: false, reason: 'there is no column rule' }
        }

        if (property === 'box-shadow') return { paints: true, reason: 'a shadow is drawn' }
        if (property === 'caret-color') {
          return { paints: false, reason: 'a caret needs an editable element in focus' }
        }
        if (property === 'text-emphasis-color') {
          return style.getPropertyValue('text-emphasis-style') !== 'none'
            ? { paints: true, reason: 'text-emphasis-style is set' }
            : { paints: false, reason: 'text-emphasis-style is none' }
        }
        if (property === 'fill' || property === 'stroke') {
          return { paints: true, reason: 'an SVG shape' }
        }

        return { paints: true, reason: 'assumed to paint' }
      }

      const SKIP = new Set(['SCRIPT', 'STYLE', 'TITLE', 'META', 'LINK', 'TEMPLATE', 'NOSCRIPT'])
      const samples: Sample[] = []
      const styleAttributes: { path: string; style: string }[] = []

      const elements: Element[] = [
        document.documentElement,
        document.body,
        ...Array.from(document.body.querySelectorAll('*')),
      ].filter((element) => !SKIP.has(element.tagName))

      for (const element of elements) {
        const attribute = element.getAttribute('style')

        if (attribute !== null && attribute !== '') {
          styleAttributes.push({ path: pathOf(element), style: attribute })
        }

        const own = getComputedStyle(element)
        const box = element.getBoundingClientRect()
        const visible = box.width > 2 && box.height > 2
        const text = ownText(element)
        const isSvg = element.namespaceURI === 'http://www.w3.org/2000/svg'

        /*
         * ⚠ THE GENERATED BOXES TOO, because a `::before` is a painted pixel with no element
         * behind it — and a template that drew a rule, a bullet or a band that way would be
         * invisible to a collector that only walked the DOM. They are included only when they
         * really generate a box: `content: none` means there is nothing to paint.
         */
        const pseudos: (string | null)[] = [null]
        for (const pseudo of ['::before', '::after']) {
          const content = getComputedStyle(element, pseudo).content

          if (content !== 'none' && content !== 'normal') pseudos.push(pseudo)
        }
        if (own.display === 'list-item' && own.listStyleType !== 'none') pseudos.push('::marker')

        for (const pseudo of pseudos) {
          const style = pseudo === null ? own : getComputedStyle(element, pseudo)

          for (const property of [...NAMED, ...ALSO]) {
            if (SVG_ONLY.includes(property) && !isSvg) continue

            const value = style.getPropertyValue(property)

            if (value === '' || value === 'none' || value === 'auto') continue

            const tokens = value.match(COLOUR)

            if (tokens === null) continue

            for (const raw of tokens) {
              const token = normalise(raw)
              const { paints, reason } = paintsFor(style, property, token, visible, text !== '')

              samples.push({
                path: pathOf(element),
                shape: shapeOf(element, pseudo),
                pseudo,
                property,
                named: NAMED.includes(property),
                value,
                token,
                paints,
                reason,
                box: { w: Math.round(box.width), h: Math.round(box.height) },
                text,
              })
            }
          }
        }
      }

      /*
       * The twelve custom properties as the ROOT resolves them, which is the first link in the
       * chain: a sentinel that is not here never left the publisher, and one that is here but
       * nowhere below was emitted and never used.
       */
      const root = getComputedStyle(document.documentElement)
      const customProperties: Record<string, string> = {}

      for (const property of [
        '--color-background',
        '--color-surface',
        '--color-text',
        '--color-muted',
        '--color-accent',
        '--color-accent-text',
        '--font-heading',
        '--font-body',
        '--radius-small',
        '--radius-large',
        '--space-section',
        '--size-heading',
      ]) {
        customProperties[property] = root.getPropertyValue(property).trim()
      }

      return {
        url: location.href,
        template: name,
        width: w,
        height: window.innerHeight,
        documentHeight: document.documentElement.scrollHeight,
        rootStyle: document.documentElement.getAttribute('style') ?? '',
        customProperties,
        elements: elements.length,
        samples,
        styleAttributes,
        images: Array.from(document.images).map((image) => ({
          src: image.currentSrc.split('/').slice(-1)[0] ?? '',
          complete: image.complete,
          naturalWidth: image.naturalWidth,
        })),
      }
    },
    { template, width },
  )
}

const MANIFEST = JSON.parse(
  readFileSync(join(REPO, env('QRPAGER_PAINT_MANIFEST')), 'utf8'),
) as Manifest
const ORIGIN = env('QRPAGER_PAINT_ORIGIN')
const OUT = join(REPO, env('QRPAGER_PAINT_OUT'))

test.describe(`the published page's colours, as a browser resolves them — ${MANIFEST.template}`, () => {
  for (const { label, width, height } of WIDTHS) {
    test(`${MANIFEST.template} at ${label}: every colour-bearing computed value, collected`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height })

      const response = await page.goto(ORIGIN, { waitUntil: 'load' })

      /*
       * ⚠ THE STATUS IS ASSERTED, because `apps/site` answers 404 for a host with nothing
       * published — and a 404 page has colours too. Collecting one would produce a full report
       * about the wrong document.
       */
      expect(response?.status(), `${ORIGIN} did not answer 200`).toBe(200)

      await page.waitForFunction(
        () => Array.from(document.images).every((image) => image.complete),
        { timeout: 20_000 },
      )

      const collected = await collect(page, MANIFEST.template, width)

      /*
       * ⚠ **THE BYTES UNDER THE BROWSER ARE THE BYTES THE PUBLISHER WROTE**, by the
       * publisher's own identity for them: `LandingPages::publish()` names each snapshot by
       * the sha-256 of its own HTML, and the manifest carries that checksum. Without this, a
       * stale pointer or a cached response would be measured in full and reported as a clean
       * result — the reassuring direction, which is the one that costs the most.
       *
       * ⚠ AND THE SENTINELS ALONE WOULD NOT CATCH IT, which is why this is a checksum and not
       * a string search. All three arrangements publish the SAME six colours — that is the
       * point of the measurement — so a page left over from the previous arrangement carries
       * every sentinel this one does and would pass a theme check in full while being the
       * wrong document. The checksum is the only value in the manifest that distinguishes
       * them.
       */
      const served = createHash('sha256')
        .update(await (response?.body() ?? Promise.resolve(Buffer.alloc(0))))
        .digest('hex')

      expect(
        served,
        `the served page is not the one this run published: its sha-256 is ${served} and the ` +
          `publisher recorded ${MANIFEST.checksum} for ${MANIFEST.template}. A stale pointer, ` +
          'a cached response or a publish that did not take would all look like this.',
      ).toBe(MANIFEST.checksum)

      // And the theme really did reach the document — the first link, stated separately from
      // the bytes so a failure says which of the two broke.
      for (const [name, value] of Object.entries(MANIFEST.theme)) {
        expect(
          collected.rootStyle,
          `the served page does not carry the published ${name}`,
        ).toContain(value)
      }

      expect(collected.elements, 'the page has almost no elements in it').toBeGreaterThan(40)
      expect(
        collected.images.filter((image) => image.naturalWidth > 0).length,
        'no photograph decoded, so every image-bearing element was measured empty',
      ).toBeGreaterThan(0)

      mkdirSync(OUT, { recursive: true })
      writeFileSync(
        join(OUT, `paint-${MANIFEST.template}-${label}.json`),
        JSON.stringify(collected, null, 2),
      )
      await page.screenshot({
        path: join(OUT, `paint-${MANIFEST.template}-${label}.png`),
        fullPage: true,
      })
    })
  }
})
