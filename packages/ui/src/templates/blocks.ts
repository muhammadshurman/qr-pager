/**
 * ================================================================================
 * THE BLOCK TYPES, MIRRORING TASK 7's COMMITTED SCHEMA
 * ================================================================================
 *
 * `docs/contracts/landing-page.schema.json` is the contract. It is GENERATED from
 * `App\Domain\Landing\BlockSchema` and `composer landing:check` already fails when it drifts
 * from the PHP, so the JSON is authoritative on both sides.
 *
 * These types were read FROM that file, not inferred -- QRPAGER-15 §6, trap 4: *"a template
 * is not a screenshot. Each one must be FILLABLE from Task 7's block schema -- a template
 * the builder cannot produce is decoration."*
 *
 * ⚠ AND THE MIRRORING IS ASSERTED RATHER THAN TRUSTED. `templates.test.tsx` reads the
 * committed JSON at test time and fails if: a block type exists in the schema that no
 * template renders, a type here does not exist there, or a REQUIRED field in the schema is
 * not rendered. So a block added at Task 11 cannot quietly go unrendered.
 *
 * Why these are hand-written TypeScript rather than a fourth generated artefact: the JSON
 * Schema describes VALIDATION (maxLength, pattern, uri format), and the useful TypeScript
 * projection of it is narrower than a mechanical translation would give -- a generated
 * `string & {maxLength: 80}` is not a thing TypeScript has. The test closes the gap that
 * leaves, and it closes it on the axis that matters: which types and which required fields.
 */

/** A media asset id. A foreign id resolves to nothing and the publish is refused (Task 7). */
export type MediaId = string

export interface HeroBlock {
  readonly type: 'hero'
  readonly fields: {
    readonly heading: string
    readonly subheading?: string | null
    readonly image?: MediaId | null
    readonly cta_label?: string | null
    readonly cta_url?: string | null
  }
}

export interface HowItWorksBlock {
  readonly type: 'how_it_works'
  readonly fields: {
    readonly heading: string
    readonly steps?: readonly { readonly title: string; readonly body: string }[] | null
  }
}

export interface MenuHighlightsBlock {
  readonly type: 'menu_highlights'
  readonly fields: {
    readonly heading: string
    readonly items?:
      | readonly {
          readonly name: string
          readonly description?: string | null
          readonly price?: string | null
          readonly image?: MediaId | null
        }[]
      | null
  }
}

export interface GalleryBlock {
  readonly type: 'gallery'
  readonly fields: {
    readonly heading?: string | null
    readonly images?: readonly { readonly image: MediaId; readonly caption: string }[] | null
  }
}

export interface HoursLocationBlock {
  readonly type: 'hours_location'
  readonly fields: {
    readonly heading: string
    readonly address: string
    readonly hours: readonly { readonly day: string; readonly hours: string }[]
    readonly latitude?: number | null
    readonly longitude?: number | null
    readonly zoom?: number | null
  }
}

export interface ContactBlock {
  readonly type: 'contact'
  readonly fields: {
    readonly heading: string
    readonly email?: string | null
    readonly phone?: string | null
    readonly links?: readonly { readonly label: string; readonly url: string }[] | null
  }
}

export interface FooterBlock {
  readonly type: 'footer'
  readonly fields: {
    readonly text: string
    readonly links?: readonly { readonly label: string; readonly url: string }[] | null
  }
}

export type LandingBlock =
  | HeroBlock
  | HowItWorksBlock
  | MenuHighlightsBlock
  | GalleryBlock
  | HoursLocationBlock
  | ContactBlock
  | FooterBlock

export type BlockType = LandingBlock['type']

/**
 * Every block type a template must handle.
 *
 * The test compares this list against the schema's own `oneOf`, in both directions, so it
 * cannot be a stale subset.
 */
export const BLOCK_TYPES: readonly BlockType[] = [
  'hero',
  'how_it_works',
  'menu_highlights',
  'gallery',
  'hours_location',
  'contact',
  'footer',
]

export interface LandingSeo {
  readonly title: string | null
  readonly description: string | null
  readonly og_image: MediaId | null
}

/**
 * A whole page, as the builder produces it.
 *
 * `theme` is the tenant's allow-listed token overrides -- twelve names, each validated by
 * Task 7's `ThemeTokens::accepts()`. A template never reads it directly: the publish
 * pipeline turns it into a `style` attribute of CSS custom properties, and the template's
 * own rules read `var(--color-…)`. That indirection is what makes "a theme overrides tokens,
 * never components" true of the renderer rather than merely stated.
 */
export interface LandingPage {
  readonly blocks: readonly LandingBlock[]
  readonly theme: Readonly<Record<string, string>>
  readonly seo: LandingSeo
}

/** Resolve a media id to a URL. Supplied by the renderer (Task 11), never guessed here. */
export type MediaResolver = (id: MediaId) => string | undefined
