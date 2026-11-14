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
 * ⚠ **THIS FILE ONLY COLLECTS. IT JUDGES NOTHING.** It records what the engine returned, as the
 * engine returned it, plus the raw facts a judgement needs (a border's width and style, whether
 * an element owns a text node). Which values are sentinels, which are declared non-themed, which
 * are findings and which can paint is decided in `tools/landing/paint-classify.mjs` — a pure
 * module with unit tests, because a decision made inside a serialised `page.evaluate` closure
 * cannot be tested at all. Phase 1 had the verdict in here, and Muhammad's review made it a
 * blocker: *"it is the artefact that DECIDES what counts as a finding."*
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
 * ⚠ **AND THE ENVIRONMENT IS READ, NOT ASSUMED.** `requestedWidth` is what this file asked the
 * viewport for; `innerWidth` is what the page's own `window` reports; the browser's build string
 * comes from the browser. Phase 1 recorded the first and called it the second, and left the
 * build to be inferred from the image tag: *"derived provenance is not captured provenance."*
 *
 * ⚠ **THE TEMPLATE IS NOT CHOSEN HERE.** One tenant has one live page, so the arrangements are
 * measured one publish at a time: `tools/gate/bin/theme-paint-proof` publishes a template, runs
 * this file against it, and moves on — with the list of templates read from
 * `LandingTemplate::values()` rather than from a literal anywhere. This file measures whatever
 * is live and names it from the manifest the publisher wrote.
 */

/** The raw facts a paint verdict needs, and nothing the collector has decided. */
interface Facts {
  width?: number
  style?: string
  line?: string
  hasText?: boolean
}

/**
 * Measured render state — numbers and keywords as the engine reports them, judged nowhere here.
 * `opacity` is the EFFECTIVE value: the product up the ancestor chain, because a child of an
 * `opacity:0` parent computes `opacity:1` and is just as invisible.
 */
interface Render {
  visibility: string
  opacity: number
  fontSize: number
  clipPath: string
  clip: string
}

/** One colour-bearing computed value, on one element, as the engine returned it. */
interface Sample {
  /** The element's index in the walk, which is what a derived property collapses on. */
  el: number
  path: string
  shape: string
  pseudo: string | null
  property: string
  /** Whether the property is one the ticket names, or one this file adds. */
  named: boolean
  /** The whole computed value, as the engine reports it. */
  value: string
  /** The ONE colour token cut out of `value`, exactly as the engine wrote it. */
  colour: string
  box: { w: number; h: number }
  text: string
  facts: Facts
  /** What the element's own render state says about whether anything can be seen. */
  render: Render
}

interface Collected {
  url: string
  template: string
  /** What this file asked the viewport for. */
  requestedWidth: number
  /** What the page's own `window` reports — the figure the 390/1280 clause rests on. */
  innerWidth: number
  innerHeight: number
  clientWidth: number
  htmlWidth: number
  devicePixelRatio: number
  /** A direct witness that the page's own breakpoint evaluated differently at the two widths. */
  minWidth48rem: boolean
  documentHeight: number
  rootStyle: string
  customProperties: Record<string, string>
  elements: number
  /** The list of properties read per element: this IS the scope of the claim. */
  properties: string[]
  samples: Sample[]
  styleAttributes: { path: string; style: string }[]
  images: { src: string; complete: boolean; naturalWidth: number }[]
  browser: { name: string; version: string; userAgent: string }
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

async function collect(
  page: Page,
  template: string,
  width: number,
): Promise<Omit<Collected, 'browser'> & { userAgent: string }> {
  return page.evaluate(
    ({ template: name, width: requestedWidth }) => {
      /*
       * ⚠ THE PROPERTY LIST, AND THE SPLIT IN IT IS THE TICKET'S OWN BOUNDARY. `NAMED` are the
       * properties `QRPAGER-49A` enumerates; `ALSO` are colour-bearing properties this file adds
       * because the stated invariant says *every* colour-bearing computed style, and a list that
       * stopped at the ones somebody thought of is the shape of a guard whose scope excludes
       * where the damage lands.
       *
       * ⚠ AND IT IS A LIST, NOT "EVERY PROPERTY", ON PURPOSE. Iterating the whole computed style
       * would sweep in user-agent internals — `-webkit-tap-highlight-color` computes to
       * `rgba(0, 0, 0, 0.18)` on every element — and each one would be a finding nobody can fix
       * and only Muhammad may rule on. The list is recorded in the artefact and printed in the
       * transcript, so what is NOT in it is visible as an absence rather than hidden in a loop.
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
        'text-shadow',
        /*
         * ⚠ ADDED AFTER THE `§11` ROUND, WHICH FOUND THE LIST TOO NARROW AGAINST THE STATED
         * INVARIANT (*"every colour-bearing COMPUTED style"*) — QA H1, Security 1. Each is a
         * colour a template author can write and each would have left the gate green:
         * `accent-color:#f0f`, `scrollbar-color`, `-webkit-text-stroke`, a gradient in
         * `border-image-source` or `mask-image`, a `drop-shadow()` in `filter`, a
         * `list-style-image`. None has a coloured UA default — they compute to `none`, `auto` or
         * (the stroke) `currentcolor` — so adding them cannot create findings nobody can fix.
         * `-webkit-text-stroke-color` is in the classifier's currentcolor-derived set.
         */
        '-webkit-text-stroke-color',
        'accent-color',
        'scrollbar-color',
        'border-image-source',
        'mask-image',
        'list-style-image',
        'filter',
        'backdrop-filter',
        // SVG-only, below
        'stop-color',
        'flood-color',
        'lighting-color',
      ]

      /*
       * ⚠ `fill` AND `stroke` ARE COLLECTED ON SVG ELEMENTS ONLY, AND THAT IS A SCOPE DECISION
       * RATHER THAN AN EXEMPTION. Both are inherited properties that COMPUTE on every HTML
       * element — `getComputedStyle(document.body).fill` is `rgb(0, 0, 0)` on a page with no SVG
       * in it — and no pixel can ever take that value, because there is nothing to fill.
       * Collecting it would put one unpaintable black on every element in the report and bury
       * the findings under it. This page carries no SVG at all today, so the scope is vacuous
       * here; it stops being vacuous the first time a template ships an icon.
       */
      const SVG_ONLY = ['fill', 'stroke', 'stop-color', 'flood-color', 'lighting-color']

      const COLOUR =
        /(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^()]*(?:\([^()]*\)[^()]*)*\)|#[0-9a-f]{3,8}\b|\b(?:transparent|currentcolor|canvas|canvastext|linktext|visitedtext|activetext|buttonface|buttontext|buttonborder|field|fieldtext|highlight|highlighttext|selecteditem|selecteditemtext|graytext|accentcolor|accentcolortext)\b/gi

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
       * The raw facts a paint verdict will need for this property — numbers and keywords as the
       * engine reports them, with no judgement applied. What a fact MEANS is the classifier's.
       */
      const factsFor = (style: CSSStyleDeclaration, property: string, hasText: boolean): Facts => {
        if (property.startsWith('border-') && property.endsWith('-color')) {
          const side = property.split('-')[1] ?? ''

          return {
            width: Number.parseFloat(style.getPropertyValue(`border-${side}-width`)),
            style: style.getPropertyValue(`border-${side}-style`),
          }
        }

        switch (property) {
          case 'outline-color':
            return { width: Number.parseFloat(style.outlineWidth), style: style.outlineStyle }
          case 'text-decoration-color':
            return { line: style.textDecorationLine }
          case 'column-rule-color':
            return {
              width: Number.parseFloat(style.columnRuleWidth),
              style: style.columnRuleStyle,
            }
          case 'text-emphasis-color':
            return { style: style.getPropertyValue('text-emphasis-style') }
          case '-webkit-text-stroke-color':
            return { width: Number.parseFloat(style.webkitTextStrokeWidth), hasText }
          case 'color':
          case '-webkit-text-fill-color':
          case 'text-shadow':
            return { hasText }
          default:
            return {}
        }
      }

      /**
       * The element's own render state. Effective opacity is walked up the ancestors because it
       * compounds and does not inherit; `visibility` does inherit, so the element's own computed
       * value is already the effective one.
       */
      const renderOf = (element: Element, style: CSSStyleDeclaration): Render => {
        let opacity = 1

        for (let node: Element | null = element; node !== null; node = node.parentElement) {
          opacity *= Number.parseFloat(getComputedStyle(node).opacity)
        }

        return {
          visibility: style.visibility,
          opacity,
          fontSize: Number.parseFloat(style.fontSize),
          clipPath: style.clipPath,
          // by name: the `.clip` accessor is deprecated, the property is still what an old
          // visually-hidden pattern uses
          clip: style.getPropertyValue('clip'),
        }
      }

      const SKIP = new Set(['SCRIPT', 'STYLE', 'TITLE', 'META', 'LINK', 'TEMPLATE', 'NOSCRIPT'])
      const samples: Sample[] = []
      const styleAttributes: { path: string; style: string }[] = []

      const elements: Element[] = [
        document.documentElement,
        document.body,
        ...Array.from(document.body.querySelectorAll('*')),
      ].filter((element) => !SKIP.has(element.tagName))

      elements.forEach((element, index) => {
        const attribute = element.getAttribute('style')

        if (attribute !== null && attribute !== '') {
          styleAttributes.push({ path: pathOf(element), style: attribute })
        }

        const own = getComputedStyle(element)
        const rect = element.getBoundingClientRect()
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

            /*
             * ⚠ `url(…)` IS REMOVED BEFORE EXTRACTION, because the keyword half of the colour
             * pattern matches a WORD, and a background URL whose file name contains `field`,
             * `canvas` or `highlight` would be read as a system colour (QA L7). It would have
             * been a loud false finding, not a hidden one — and a likely one, with QRPAGER-49's
             * asset names arriving.
             */
            for (const colour of value.replace(/url\([^)]*\)/gi, '').match(COLOUR) ?? []) {
              samples.push({
                el: index,
                path: pathOf(element),
                shape: shapeOf(element, pseudo),
                pseudo,
                property,
                named: NAMED.includes(property),
                value,
                colour,
                box: { w: Math.round(rect.width), h: Math.round(rect.height) },
                text,
                facts: factsFor(style, property, text !== ''),
                render: renderOf(element, style),
              })
            }
          }
        }
      })

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
        requestedWidth,
        innerWidth: window.innerWidth,
        innerHeight: window.innerHeight,
        clientWidth: document.documentElement.clientWidth,
        htmlWidth: Math.round(document.documentElement.getBoundingClientRect().width),
        devicePixelRatio: window.devicePixelRatio,
        minWidth48rem: window.matchMedia('(min-width: 48rem)').matches,
        documentHeight: document.documentElement.scrollHeight,
        rootStyle: document.documentElement.getAttribute('style') ?? '',
        customProperties,
        elements: elements.length,
        properties: [...NAMED, ...ALSO],
        samples,
        styleAttributes,
        images: Array.from(document.images).map((image) => ({
          src: image.currentSrc.split('/').slice(-1)[0] ?? '',
          complete: image.complete,
          naturalWidth: image.naturalWidth,
        })),
        userAgent: navigator.userAgent,
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

      const { userAgent, ...collected } = await collect(page, MANIFEST.template, width)

      /*
       * ⚠ THE WIDTH IS READ FROM THE PAGE'S OWN WINDOW AND ASSERTED HERE AS WELL AS IN THE
       * REPORT. A viewport that did not take — a context option overriding it, a device
       * emulation that resized — would measure a different page under the same label, and the
       * failure should name the width rather than surface later as a strange finding.
       */
      expect(
        collected.innerWidth,
        `the page reports window.innerWidth ${String(collected.innerWidth)}, not the ${label} this case asked for`,
      ).toBe(width)

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

      /*
       * ⚠ THE BROWSER'S OWN ACCOUNT OF ITSELF, captured rather than inferred from an image tag.
       * `Browser.version()` is the engine build; the user agent is what the page was told. A
       * figure whose engine build is not beside it has no provenance, which is the argument
       * `§3.1` row 9's method makes about the Lighthouse score and applies here equally.
       */
      const engine = page.context().browser()
      const browser = {
        name: engine?.browserType().name() ?? 'unknown',
        version: engine?.version() ?? '',
        userAgent,
      }

      mkdirSync(OUT, { recursive: true })
      writeFileSync(
        join(OUT, `paint-${MANIFEST.template}-${label}.json`),
        JSON.stringify({ ...collected, browser }, null, 2),
      )
      await page.screenshot({
        path: join(OUT, `paint-${MANIFEST.template}-${label}.png`),
        fullPage: true,
      })
    })
  }
})
