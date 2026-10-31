import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { ThemeProvider } from '../src/index.ts'
import {
  BLOCK_TYPES,
  TEMPLATES,
  TEMPLATE_NAMES,
  type BlockType,
  type LandingBlock,
  type LandingPage,
  type TemplateName,
} from '../src/templates/index.tsx'

afterEach(cleanup)

const REPO = join(import.meta.dirname, '..', '..', '..')

/**
 * ================================================================================
 * "A TEMPLATE THE BUILDER CANNOT PRODUCE IS DECORATION"
 * ================================================================================
 *
 * `QRPAGER-15 §6`, trap 4, verbatim: *"A template is not a screenshot. Each one must be
 * FILLABLE FROM TASK 7's BLOCK SCHEMA -- a template the builder cannot produce is
 * decoration."* `§5`'s gate clause: *"Three landing templates ship, each rendering from
 * tokens only, each fillable from Task 7's block schema, each passing the contrast and RTL
 * bars."*
 *
 * So the schema is READ, at test time, from the committed contract -- not restated here.
 * `docs/contracts/landing-page.schema.json` is generated from `App\Domain\Landing\BlockSchema`
 * and `composer landing:check` already fails when it drifts from the PHP, so this file
 * inherits that authority rather than claiming its own.
 *
 * WHAT THIS CATCHES THAT A SCREENSHOT CANNOT: a block type added at Task 11 that no
 * template renders, a required field the renderer drops, and a template that silently
 * discards a block it did not expect.
 */

interface SchemaBlock {
  readonly type: string
  readonly fields: readonly string[]
  readonly required: readonly string[]
}

/** Read the committed contract and project out what a template must handle. */
function schemaBlocks(): SchemaBlock[] {
  const raw = readFileSync(join(REPO, 'docs/contracts/landing-page.schema.json'), 'utf8')
  const schema = JSON.parse(raw) as {
    properties: {
      blocks: { items: { oneOf: readonly Record<string, never>[] } }
    }
  }
  return schema.properties.blocks.items.oneOf.map((variant) => {
    const v = variant as unknown as {
      properties: {
        type: { const: string }
        fields: { properties: Record<string, unknown>; required?: readonly string[] }
      }
    }
    return {
      type: v.properties.type.const,
      fields: Object.keys(v.properties.fields.properties),
      required: v.properties.fields.required ?? [],
    }
  })
}

/**
 * One fully-populated block per schema type, with EVERY field set.
 *
 * Built from the schema's own field list below, so a field added to the PHP appears here as
 * a missing key rather than being quietly untested.
 */
const SAMPLE: Readonly<Record<BlockType, LandingBlock>> = {
  hero: {
    type: 'hero',
    fields: {
      heading: 'Qahwa & Co',
      subheading: 'Specialty coffee on Rainbow Street',
      image: '11111111-1111-4111-8111-111111111111',
      cta_label: 'See the menu',
      cta_url: 'https://example.test/menu',
    },
  },
  how_it_works: {
    type: 'how_it_works',
    fields: {
      heading: 'How the pager works',
      steps: [
        { title: 'Scan', body: 'Point your camera at the QR code on your table.' },
        { title: 'Sit down', body: 'Keep the page open or add it to your Home Screen.' },
        { title: 'Get paged', body: 'We will buzz your phone the moment it is ready.' },
      ],
    },
  },
  menu_highlights: {
    type: 'menu_highlights',
    fields: {
      heading: 'Today',
      items: [
        {
          name: 'Flat white',
          description: 'Double shot, whole milk',
          price: '3.50',
          image: '22222222-2222-4222-8222-222222222222',
        },
        { name: 'Cardamom latte', description: null, price: '4.00', image: null },
      ],
    },
  },
  gallery: {
    type: 'gallery',
    fields: {
      heading: 'The room',
      images: [
        { image: '33333333-3333-4333-8333-333333333333', caption: 'The counter at opening' },
      ],
    },
  },
  hours_location: {
    type: 'hours_location',
    fields: {
      heading: 'Find us',
      address: '12 Rainbow Street, Amman',
      hours: [
        { day: 'Monday', hours: '08:00 - 22:00' },
        { day: 'Friday', hours: '10:00 - 23:00' },
      ],
      latitude: 31.95,
      longitude: 35.93,
      zoom: 15,
    },
  },
  contact: {
    type: 'contact',
    fields: {
      heading: 'Contact',
      email: 'hello@example.test',
      phone: '+962790000000',
      links: [{ label: 'Instagram', url: 'https://example.test/ig' }],
    },
  },
  footer: {
    type: 'footer',
    fields: {
      text: 'Qahwa & Co',
      links: [{ label: 'Privacy', url: 'https://example.test/privacy' }],
    },
  },
}

/** Every media id in SAMPLE resolves; a template must not render an unresolvable one. */
const resolve = (id: string) => `https://cdn.example.test/${id}.webp`

function page(blocks: readonly LandingBlock[] = BLOCK_TYPES.map((t) => SAMPLE[t])): LandingPage {
  return {
    blocks,
    // The tenant's allow-listed overrides. No template reads this: the publish pipeline
    // turns it into a style attribute of custom properties and the CSS reads var(--…).
    theme: { 'color.accent': '#123456', 'font.body': 'sans' },
    seo: { title: 'Qahwa & Co', description: 'Specialty coffee', og_image: null },
  }
}

// ======================================================= the schema and the templates agree

describe('the block types this kit renders and the committed schema agree', () => {
  const schema = schemaBlocks()

  it('reads a schema with block types in it, so the test cannot pass vacuously', () => {
    // A JSON read that silently produced [] would make every assertion below trivially
    // true. Exact count, not a lower bound.
    expect(schema).toHaveLength(7)
  })

  it('renders EVERY block type the schema declares -- none unrendered', () => {
    const schemaTypes = schema.map((b) => b.type).sort()
    expect([...BLOCK_TYPES].sort()).toEqual(schemaTypes)
  })

  it('declares no block type the schema does not have -- none invented', () => {
    // Both directions. One alone would let the kit carry a type Task 7 never produces.
    for (const type of BLOCK_TYPES) {
      expect(schema.map((b) => b.type)).toContain(type)
    }
  })

  it('has a sample covering every field of every block, so none is untested', () => {
    for (const block of schema) {
      const sample = SAMPLE[block.type as BlockType]
      const sampleFields = Object.keys(sample.fields)
      for (const field of block.fields) {
        expect(sampleFields, `${block.type}.${field} is not covered by the sample`).toContain(field)
      }
    }
  })
})

// ================================================================= each template renders

describe.each(TEMPLATE_NAMES)('the %s template', (name: TemplateName) => {
  const Template = TEMPLATES[name]

  function renderTemplate(p: LandingPage = page(), locale: 'en' | 'ar' = 'en') {
    return render(
      <ThemeProvider locale={locale}>
        <Template page={p} resolve={resolve} />
      </ThemeProvider>,
    )
  }

  it('renders every block type, each marked so it can be found', () => {
    const { container } = renderTemplate()
    for (const type of BLOCK_TYPES) {
      expect(
        container.querySelector(`[data-block='${type}']`),
        `${name} dropped the ${type} block`,
      ).not.toBeNull()
    }
  })

  it('renders every REQUIRED field of every block as visible text', () => {
    // The clause that makes "fillable" mean something. A required field the renderer drops
    // produces a page the builder cannot actually produce.
    renderTemplate()
    const required: [BlockType, string][] = [
      ['hero', 'Qahwa & Co'],
      ['how_it_works', 'How the pager works'],
      ['menu_highlights', 'Today'],
      ['hours_location', 'Find us'],
      ['hours_location', '12 Rainbow Street, Amman'],
      ['contact', 'Contact'],
    ]
    for (const [, text] of required) {
      expect(screen.getAllByText(text).length, `missing: ${text}`).toBeGreaterThan(0)
    }
    // hours is required and is a list: both rows must be present.
    expect(screen.getByText('Monday')).toBeTruthy()
    expect(screen.getByText('08:00 - 22:00')).toBeTruthy()
    // gallery's caption is required on each image and doubles as the alt text.
    expect(screen.getByAltText('The counter at opening')).toBeTruthy()
    // the footer's text is required.
    expect(screen.getAllByText('Qahwa & Co').length).toBeGreaterThan(0)
  })

  it('renders a page with ONLY the minimum required fields, every optional omitted', () => {
    // The other end of the schema. A template that needs an optional field to render is not
    // fillable from the builder, because the builder lets a tenant leave it blank.
    const minimal: LandingBlock[] = [
      { type: 'hero', fields: { heading: 'Minimal' } },
      { type: 'how_it_works', fields: { heading: 'Steps' } },
      { type: 'menu_highlights', fields: { heading: 'Menu' } },
      { type: 'gallery', fields: {} },
      {
        type: 'hours_location',
        fields: { heading: 'Hours', address: 'Somewhere', hours: [{ day: 'Mon', hours: '9-5' }] },
      },
      { type: 'contact', fields: { heading: 'Contact' } },
      { type: 'footer', fields: { text: 'Minimal footer' } },
    ]
    const { container } = renderTemplate(page(minimal))
    expect(screen.getByText('Minimal')).toBeTruthy()
    expect(screen.getByText('Minimal footer')).toBeTruthy()
    expect(screen.getByText('Somewhere')).toBeTruthy()
    // Still every block present, just emptier.
    for (const type of BLOCK_TYPES) {
      expect(container.querySelector(`[data-block='${type}']`)).not.toBeNull()
    }
  })

  it('renders an EMPTY page without throwing -- a new draft has no blocks', () => {
    const { container } = renderTemplate(page([]))
    expect(container.querySelector('.qr-lp')).not.toBeNull()
  })

  it('renders nothing for a media id that resolves to nothing', () => {
    // Task 7 refuses a publish carrying a foreign id; this is the second half of the same
    // property. A broken <img> is worse than an absent one.
    const { container } = render(
      <ThemeProvider>
        <Template page={page()} resolve={() => undefined} />
      </ThemeProvider>,
    )
    expect(container.querySelectorAll('img')).toHaveLength(0)
  })

  it('keeps a price and a phone number LTR inside an RTL page', () => {
    // Numbers do not mirror (§6 trap 2). A price reordered by the surrounding direction is
    // a different price.
    const { container } = renderTemplate(page(), 'ar')
    expect(container.querySelector('.qr-lp__menu-price')!.getAttribute('dir')).toBe('ltr')
    expect(container.querySelector("a[href^='tel:']")!.getAttribute('dir')).toBe('ltr')
  })

  it('uses logical properties only -- no left/right in any inline style', () => {
    // What makes RTL a direction attribute rather than a second stylesheet.
    const { container } = renderTemplate(page(), 'ar')
    const html = container.innerHTML
    expect(html).not.toMatch(/style="[^"]*\b(?:margin|padding)-(?:left|right)\b/)
  })

  it('exposes steps as an ORDERED list, because the order is the content', () => {
    const { container } = renderTemplate()
    expect(container.querySelector('.qr-lp__steps-list')!.tagName).toBe('OL')
  })

  it('exposes opening hours as a description list, which reads as name/value', () => {
    const { container } = renderTemplate()
    const dl = container.querySelector('.qr-lp__hours-list')!
    expect(dl.tagName).toBe('DL')
    expect(dl.querySelectorAll('dt')).toHaveLength(2)
    expect(dl.querySelectorAll('dd')).toHaveLength(2)
  })

  it('marks every external link noopener and nofollow -- all of it is tenant-authored', () => {
    const { container } = renderTemplate()
    const external = container.querySelectorAll<HTMLAnchorElement>("a[target='_blank']")
    expect(external.length).toBeGreaterThan(0)
    for (const link of external) {
      expect(link.rel).toContain('noopener')
      expect(link.rel).toContain('noreferrer')
      expect(link.rel).toContain('nofollow')
    }
  })

  it('loads no third-party script and embeds no map tile', () => {
    // Task 9's gate: the landing origin loads NO third-party script. A map embed would be
    // one, plus a request to someone else's origin on a page held to a cold-3G bar. The
    // coordinates are rendered as data instead, for Task 11 to decide about.
    const { container } = renderTemplate()
    expect(container.querySelectorAll('script')).toHaveLength(0)
    expect(container.querySelectorAll('iframe')).toHaveLength(0)
    expect(container.querySelector('.qr-lp__coords')!.getAttribute('data-latitude')).toBe('31.95')
  })

  it('carries exactly one h1 -- the hero heading', () => {
    // More than one h1 breaks the document outline for anyone navigating by heading.
    const { container } = renderTemplate()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(container.querySelector('h1')!.textContent).toBe('Qahwa & Co')
  })

  it('reads no tenant theme value directly', () => {
    // A theme overrides TOKENS, never components. The template's rules read var(--…); the
    // tenant's twelve values arrive as a style attribute from the publish pipeline.
    const { container } = renderTemplate()
    expect(container.innerHTML).not.toContain('#123456')
  })
})

describe('the three templates are three, and they differ', () => {
  it('ships exactly three (D2)', () => {
    expect(TEMPLATE_NAMES).toEqual(['classic', 'editorial', 'compact'])
    expect(Object.keys(TEMPLATES)).toHaveLength(3)
  })

  it('produces a DIFFERENT arrangement for each, not three copies', () => {
    // Three templates that render identically are one template with three names.
    const html = TEMPLATE_NAMES.map((name) => {
      cleanup()
      const Template = TEMPLATES[name]
      const { container } = render(
        <ThemeProvider>
          <Template page={page()} resolve={resolve} />
        </ThemeProvider>,
      )
      return container.innerHTML
    })
    expect(new Set(html).size).toBe(3)
  })

  it('puts the practical blocks FIRST in compact and not in classic', () => {
    // The arrangement each template exists for, asserted rather than described.
    const order = (name: TemplateName) => {
      cleanup()
      const Template = TEMPLATES[name]
      const { container } = render(
        <ThemeProvider>
          <Template page={page()} resolve={resolve} />
        </ThemeProvider>,
      )
      return [...container.querySelectorAll('[data-block]')].map((e) =>
        e.getAttribute('data-block'),
      )
    }

    const classic = order('classic')
    const compact = order('compact')

    // classic follows the builder's own order.
    expect(classic).toEqual([...BLOCK_TYPES])
    // compact promotes hours and contact to directly after the hero.
    expect(compact.slice(0, 3)).toEqual(['hero', 'hours_location', 'contact'])
  })

  it('puts how_it_works directly after the hero in editorial', () => {
    cleanup()
    const Template = TEMPLATES.editorial
    const { container } = render(
      <ThemeProvider>
        <Template page={page()} resolve={resolve} />
      </ThemeProvider>,
    )
    const order = [...container.querySelectorAll('[data-block]')].map((e) =>
      e.getAttribute('data-block'),
    )
    expect(order.slice(0, 2)).toEqual(['hero', 'how_it_works'])
    // And the footer is last in every arrangement.
    expect(order[order.length - 1]).toBe('footer')
  })
})
