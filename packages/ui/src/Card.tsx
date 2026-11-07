import type { ReactNode } from 'react'

/**
 * A surface.
 *
 * `--color-surface` for the ground and `--color-hairline` for the edge -- and the edge is
 * DECORATIVE here, which is why `hairline` is the right token. A card is not an interactive
 * control, so WCAG 1.4.11's 3:1 does not apply to its border; `border-strong` would be
 * wrong, not merely stronger. The one place that distinction is made is the token source,
 * and this is the component that depends on it being made correctly.
 *
 * One elevation in the whole system (`--elevation-card`). A second would be a decision
 * nobody approved.
 */
export interface CardProps {
  readonly children: ReactNode
  /** Rendered as a heading, and used as the card's accessible name. */
  readonly title?: string
  /** Recessed -- `--color-surface-2`, the well. */
  readonly recessed?: boolean
}

export function Card({ children, title, recessed = false }: CardProps) {
  return (
    <section className="qr-card" data-recessed={recessed ? 'true' : undefined}>
      {title !== undefined && <h2 className="qr-card__title">{title}</h2>}
      {children}
    </section>
  )
}
