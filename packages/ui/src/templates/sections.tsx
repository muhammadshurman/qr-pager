import type { ReactNode } from 'react'

import { Icon } from '../Icon.tsx'
import type { LandingBlock, MediaResolver } from './blocks.ts'

/**
 * ================================================================================
 * ONE RENDERER PER BLOCK TYPE. THE THREE TEMPLATES ARRANGE; THEY DO NOT RE-RENDER.
 * ================================================================================
 *
 * Three templates x seven block types is twenty-one renderings if each template writes its
 * own. That is twenty-one places a required field can be forgotten, and the test could only
 * ever check the ones it knew about.
 *
 * So the block CONTENT is rendered once here and the templates differ in ARRANGEMENT --
 * order, grouping, scale, which blocks are emphasised. That is also what makes "fillable
 * from the block schema" provable: the test asserts this dispatcher handles every type in
 * the committed JSON and renders every required field, and the templates inherit that.
 *
 * Every value is a token. No hex, no raw spacing, no raw font size -- `pnpm lint` scans
 * these files and the stylesheet beside them.
 */

function MaybeImage({
  id,
  resolve,
  alt,
  className,
}: {
  readonly id: string | null | undefined
  readonly resolve: MediaResolver
  readonly alt: string
  readonly className: string
}) {
  if (id === null || id === undefined) return null
  const src = resolve(id)
  // A media id that resolves to nothing renders NOTHING rather than a broken image. Task 7
  // already refuses a publish carrying a foreign id, so this is the second half of the same
  // property rather than a guess about what the id might mean.
  if (src === undefined) return null
  return <img className={className} src={src} alt={alt} loading="lazy" decoding="async" />
}

/**
 * ================================================================================
 * `mailto:` — THE STORED ADDRESS IS PERCENT-ENCODED, PER RFC 6068
 * ================================================================================
 *
 * ⚠ THIS INTERPOLATED THE ADDRESS RAW, and the stored address is tenant-supplied.
 *
 * Task 7 validates the field with `filter_var($value, FILTER_VALIDATE_EMAIL)`, which accepts
 * a QUOTED LOCAL PART. Measured on this repository's PHP 8.4:
 * `"?subject=hi&body=click"@c.d` passes the validator and is stored.
 *
 * Interpolated raw into `mailto:`, the first unencoded `?` ENDS THE ADDRESS and opens the
 * URI's header section, so the rest is read as `subject=hi&body=click`. A mail client then
 * composes a message with a tenant-chosen subject and body, which is a tenant writing part
 * of a message a CUSTOMER is about to send.
 *
 * Not XSS -- the attribute is HTML-escaped by React, and it is the tenant's own page. It is
 * a URI-parsing confusion, and the fix is to encode rather than to re-validate: a sanitiser
 * at the renderer would be a second opinion about what a valid address is, and `§6.2` is
 * exactly about not having two.
 *
 * THE ENCODING IS RFC 6068's, NOT `encodeURIComponent`'s. RFC 6068 builds the `to` component
 * from `qchar = unreserved / pct-encoded / some-delims`, and `some-delims` is
 * `! $ ' ( ) * + , ; : @`. So `@` and `+` stay LITERAL -- `encodeURIComponent` would encode
 * `@` and break every address -- while `?`, `&`, `#`, `=`, `/`, `%`, `"` and whitespace are
 * encoded. `,` is encoded too, deliberately: RFC 6068 uses it to separate addresses in a
 * `to` list and this renders exactly one address.
 *
 * The `tel:` sibling eight lines below has reasoned about this class since Task 7 -- its
 * validator refuses `,` and `;` because a `tel:` URI reads them as a dial pause and a
 * parameter separator. The email field got none of that reasoning, which is the asymmetry
 * this closes.
 */
const MAILTO_LITERAL = /^[A-Za-z0-9\-._~!$'()*+;:@]$/

export function mailtoHref(address: string): string {
  let encoded = ''
  // Iterated by CODE POINT, not by UTF-16 unit, so an internationalised address with an
  // astral character is encoded as one character rather than as two broken halves.
  for (const character of address) {
    encoded += MAILTO_LITERAL.test(character) ? character : encodeURIComponent(character)
  }
  return `mailto:${encoded}`
}

/** An external link. `rel` is set because every one of these is tenant-authored. */
function ExternalLink({ href, children }: { readonly href: string; readonly children: ReactNode }) {
  return (
    <a className="qr-lp__link" href={href} rel="noopener noreferrer nofollow" target="_blank">
      {children}
      {/* The icon is decorative: the link text already says where it goes. */}
      <Icon name="arrow-next" size={16} />
    </a>
  )
}

export interface BlockProps {
  readonly block: LandingBlock
  readonly resolve: MediaResolver
  /** Heading level, so a template controls the document outline rather than each block. */
  readonly level?: 2 | 3
}

/**
 * Render one block.
 *
 * The switch is EXHAUSTIVE over `LandingBlock`, and the `never` default is what makes that
 * mechanical: a block type added to the union without a case here fails to compile. A
 * `default: return null` would have compiled and rendered nothing.
 */
export function Block({ block, resolve, level = 2 }: BlockProps) {
  const Heading = level === 2 ? 'h2' : 'h3'

  switch (block.type) {
    case 'hero': {
      const f = block.fields
      return (
        <section className="qr-lp__hero" data-block="hero">
          <MaybeImage id={f.image} resolve={resolve} alt="" className="qr-lp__hero-image" />
          <div className="qr-lp__hero-body">
            <h1 className="qr-lp__hero-heading">{f.heading}</h1>
            {f.subheading !== null && f.subheading !== undefined && (
              <p className="qr-lp__hero-sub">{f.subheading}</p>
            )}
            {f.cta_label !== null &&
              f.cta_label !== undefined &&
              f.cta_url !== null &&
              f.cta_url !== undefined && (
                <a className="qr-lp__cta" href={f.cta_url} rel="noopener noreferrer">
                  {f.cta_label}
                </a>
              )}
          </div>
        </section>
      )
    }

    case 'how_it_works': {
      const f = block.fields
      return (
        <section className="qr-lp__steps" data-block="how_it_works">
          <Heading className="qr-lp__heading">{f.heading}</Heading>
          {f.steps !== null && f.steps !== undefined && f.steps.length > 0 && (
            // An ORDERED list: these are steps, and the order is the content. A <ul> would
            // lose that for anyone navigating by landmark.
            <ol className="qr-lp__steps-list">
              {f.steps.map((step, i) => (
                <li className="qr-lp__step" key={`${step.title}-${String(i)}`}>
                  <h3 className="qr-lp__step-title">{step.title}</h3>
                  <p className="qr-lp__step-body">{step.body}</p>
                </li>
              ))}
            </ol>
          )}
        </section>
      )
    }

    case 'menu_highlights': {
      const f = block.fields
      return (
        <section className="qr-lp__menu" data-block="menu_highlights">
          <Heading className="qr-lp__heading">{f.heading}</Heading>
          {f.items !== null && f.items !== undefined && f.items.length > 0 && (
            <ul className="qr-lp__menu-list">
              {f.items.map((item, i) => (
                <li className="qr-lp__menu-item" key={`${item.name}-${String(i)}`}>
                  <MaybeImage
                    id={item.image}
                    resolve={resolve}
                    alt={item.name}
                    className="qr-lp__menu-image"
                  />
                  <div className="qr-lp__menu-text">
                    <p className="qr-lp__menu-name">{item.name}</p>
                    {item.description !== null && item.description !== undefined && (
                      <p className="qr-lp__menu-description">{item.description}</p>
                    )}
                  </div>
                  {item.price !== null && item.price !== undefined && (
                    // A price is a number-ish code: dir="ltr" so it is not reordered inside
                    // an RTL page. The same rule as ElapsedTimer's readout.
                    <p className="qr-lp__menu-price" dir="ltr">
                      {item.price}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )
    }

    case 'gallery': {
      const f = block.fields
      return (
        <section className="qr-lp__gallery" data-block="gallery">
          {f.heading !== null && f.heading !== undefined && (
            <Heading className="qr-lp__heading">{f.heading}</Heading>
          )}
          {f.images !== null && f.images !== undefined && f.images.length > 0 && (
            <ul className="qr-lp__gallery-list">
              {f.images.map((entry, i) => (
                <li className="qr-lp__gallery-item" key={`${entry.image}-${String(i)}`}>
                  <figure className="qr-lp__figure">
                    {/* The caption IS the alt text. The schema makes caption required on a
                        gallery image, which is what lets this be accessible by construction
                        rather than by the tenant remembering. */}
                    <MaybeImage
                      id={entry.image}
                      resolve={resolve}
                      alt={entry.caption}
                      className="qr-lp__gallery-image"
                    />
                    <figcaption className="qr-lp__caption">{entry.caption}</figcaption>
                  </figure>
                </li>
              ))}
            </ul>
          )}
        </section>
      )
    }

    case 'hours_location': {
      const f = block.fields
      return (
        <section className="qr-lp__hours" data-block="hours_location">
          <Heading className="qr-lp__heading">{f.heading}</Heading>
          <address className="qr-lp__address">{f.address}</address>
          {/* A description list, not a table: day -> hours is a name/value pair, and a <dl>
              is what a screen reader reads as one. The same choice
              PlatformPanelAccessibilityTest already asserts for the panel's figures. */}
          <dl className="qr-lp__hours-list">
            {f.hours.map((entry, i) => (
              <div className="qr-lp__hours-row" key={`${entry.day}-${String(i)}`}>
                <dt className="qr-lp__hours-day">{entry.day}</dt>
                <dd className="qr-lp__hours-value" dir="ltr">
                  {entry.hours}
                </dd>
              </div>
            ))}
          </dl>
          {f.latitude !== null &&
            f.latitude !== undefined &&
            f.longitude !== null &&
            f.longitude !== undefined && (
              // COORDINATES ONLY, AND NO MAP TILE. A third-party map embed would be a
              // third-party script and a request to someone else's origin on a page §12
              // holds to a cold-3G bar -- and Task 9's gate forbids third-party script on
              // the landing origin outright. The renderer (Task 11) decides what to do with
              // these; the template states them as data.
              <p className="qr-lp__coords" data-latitude={f.latitude} data-longitude={f.longitude}>
                <Icon name="arrow-next" size={16} />
                <span className="qr-lp__coords-value" dir="ltr">
                  {f.latitude.toFixed(5)}, {f.longitude.toFixed(5)}
                </span>
              </p>
            )}
        </section>
      )
    }

    case 'contact': {
      const f = block.fields
      return (
        <section className="qr-lp__contact" data-block="contact">
          <Heading className="qr-lp__heading">{f.heading}</Heading>
          <ul className="qr-lp__contact-list">
            {f.email !== null && f.email !== undefined && (
              <li>
                <a className="qr-lp__link" href={mailtoHref(f.email)}>
                  {f.email}
                </a>
              </li>
            )}
            {f.phone !== null && f.phone !== undefined && (
              <li>
                {/* tel: and dir="ltr": a phone number is digits and must not be reordered. */}
                <a className="qr-lp__link" href={`tel:${f.phone}`} dir="ltr">
                  {f.phone}
                </a>
              </li>
            )}
            {f.links?.map((link, i) => (
              <li key={`${link.url}-${String(i)}`}>
                <ExternalLink href={link.url}>{link.label}</ExternalLink>
              </li>
            ))}
          </ul>
        </section>
      )
    }

    case 'footer': {
      const f = block.fields
      return (
        <footer className="qr-lp__footer" data-block="footer">
          <p className="qr-lp__footer-text">{f.text}</p>
          {f.links !== null && f.links !== undefined && f.links.length > 0 && (
            <ul className="qr-lp__footer-links">
              {f.links.map((link, i) => (
                <li key={`${link.url}-${String(i)}`}>
                  <ExternalLink href={link.url}>{link.label}</ExternalLink>
                </li>
              ))}
            </ul>
          )}
        </footer>
      )
    }

    default: {
      // Exhaustiveness, mechanically. A block type added to the union without a case above
      // makes this assignment fail to compile -- which is the whole reason the default is a
      // `never` binding and not `return null`.
      const exhaustive: never = block
      return exhaustive
    }
  }
}
