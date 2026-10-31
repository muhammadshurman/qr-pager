import type { ReactNode } from 'react'

import { Block } from './sections.tsx'
import type { BlockType, LandingBlock, LandingPage, MediaResolver } from './blocks.ts'

export * from './blocks.ts'
export { Block, mailtoHref } from './sections.tsx'

/**
 * ================================================================================
 * THREE LANDING TEMPLATES (D2). THEY ARRANGE; THEY DO NOT RE-RENDER.
 * ================================================================================
 *
 * Every one renders from tokens only, is fillable from Task 7's block schema, and passes the
 * contrast and RTL bars. The CONTENT of a block is rendered once, in `sections.tsx`; a
 * template decides order, grouping and emphasis.
 *
 * That split is deliberate and it is what makes the gate clause provable. Three templates
 * each writing their own seven renderings would be twenty-one places a required field could
 * be forgotten, and the test could only check the ones it knew about. As it is, the test
 * reads the committed JSON schema and asserts the dispatcher handles every type -- and all
 * three templates inherit that.
 *
 * None of them reads `page.theme`. A tenant's twelve allow-listed values become a `style`
 * attribute of CSS custom properties in the publish pipeline, and these templates' rules
 * read `var(--color-…)`. That indirection is why "a theme overrides tokens, never
 * components" is true of the renderer rather than merely asserted about it.
 */

export const TEMPLATE_NAMES = ['classic', 'editorial', 'compact'] as const
export type TemplateName = (typeof TEMPLATE_NAMES)[number]

export interface TemplateProps {
  readonly page: LandingPage
  readonly resolve: MediaResolver
  /** Rendered into the header -- the restaurant's own mark. */
  readonly logo?: ReactNode
}

/** Blocks of one type, in the order the builder put them. */
function pick(blocks: readonly LandingBlock[], type: BlockType): LandingBlock[] {
  return blocks.filter((b) => b.type === type)
}

/** Everything not in `types`, so a template cannot silently drop a block it forgot. */
function rest(blocks: readonly LandingBlock[], types: readonly BlockType[]): LandingBlock[] {
  return blocks.filter((b) => !types.includes(b.type))
}

function renderAll(blocks: readonly LandingBlock[], resolve: MediaResolver, level: 2 | 3 = 2) {
  return blocks.map((block, i) => (
    <Block block={block} resolve={resolve} level={level} key={`${block.type}-${String(i)}`} />
  ))
}

/**
 * CLASSIC -- the builder's own order, one column, generous rhythm.
 *
 * The safe default: it renders exactly what the tenant arranged, so a block order that makes
 * sense in the builder makes the same sense on the page. Chosen as the default for that
 * reason rather than because it is the plainest.
 */
export function ClassicTemplate({ page, resolve, logo }: TemplateProps) {
  return (
    <div className="qr-lp qr-lp--classic">
      {logo !== undefined && <header className="qr-lp__mark">{logo}</header>}
      <main className="qr-lp__main">{renderAll(page.blocks, resolve)}</main>
    </div>
  )
}

/**
 * EDITORIAL -- the hero leads, then the story, with the gallery given the full measure.
 *
 * `how_it_works` is promoted to sit directly under the hero, because on a pager product the
 * question a first-time visitor has is "what happens when I scan this". The gallery goes
 * full-bleed and the menu follows. `rest()` catches anything this arrangement does not name,
 * so a block type added later still renders instead of vanishing.
 */
export function EditorialTemplate({ page, resolve, logo }: TemplateProps) {
  const ordered: BlockType[] = ['hero', 'how_it_works', 'gallery', 'menu_highlights']

  return (
    <div className="qr-lp qr-lp--editorial">
      {logo !== undefined && <header className="qr-lp__mark">{logo}</header>}
      <main className="qr-lp__main">
        {renderAll(pick(page.blocks, 'hero'), resolve)}
        <div className="qr-lp__band">{renderAll(pick(page.blocks, 'how_it_works'), resolve)}</div>
        <div className="qr-lp__wide">{renderAll(pick(page.blocks, 'gallery'), resolve)}</div>
        {renderAll(pick(page.blocks, 'menu_highlights'), resolve)}
        {renderAll(
          rest(page.blocks, ordered).filter((b) => b.type !== 'footer'),
          resolve,
        )}
        {renderAll(pick(page.blocks, 'footer'), resolve)}
      </main>
    </div>
  )
}

/**
 * COMPACT -- everything above the fold on a phone, practical information first.
 *
 * Inverts the usual order on purpose: a customer standing outside the restaurant wants the
 * hours, the address and the phone number, not the hero. The hero is kept but demoted, and
 * secondary blocks drop to heading level 3 so the outline stays shallow on a small screen.
 */
export function CompactTemplate({ page, resolve, logo }: TemplateProps) {
  const leading: BlockType[] = ['hours_location', 'contact']

  return (
    <div className="qr-lp qr-lp--compact">
      {logo !== undefined && <header className="qr-lp__mark">{logo}</header>}
      <main className="qr-lp__main">
        {renderAll(pick(page.blocks, 'hero'), resolve)}
        <div className="qr-lp__practical">
          {renderAll(pick(page.blocks, 'hours_location'), resolve)}
          {renderAll(pick(page.blocks, 'contact'), resolve)}
        </div>
        {renderAll(
          rest(page.blocks, [...leading, 'hero']).filter((b) => b.type !== 'footer'),
          resolve,
          3,
        )}
        {renderAll(pick(page.blocks, 'footer'), resolve, 3)}
      </main>
    </div>
  )
}

/** The three templates, by name, so a renderer can select one from a stored value. */
export const TEMPLATES: Readonly<Record<TemplateName, (props: TemplateProps) => ReactNode>> = {
  classic: ClassicTemplate,
  editorial: EditorialTemplate,
  compact: CompactTemplate,
}
