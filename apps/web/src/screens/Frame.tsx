import type { ReactNode } from 'react'

import type { Translate } from '../copy/index.ts'

/**
 * The shell every screen shares: a full-height column, the body, and the actions pinned to
 * the bottom of it.
 *
 * `§4.1`: *"zero chrome"*. There is no header, no nav and no back button -- all six screens
 * are states of one route, so there is nowhere to go and nothing to navigate. The reasons are
 * in `docs/tickets/QRPAGER-16.md §3.0`.
 *
 * `data-screen` and `data-state` are what every browser assertion locates by. They are
 * derived from the journey's own `ScreenState` string rather than written per screen, so a
 * state that exists cannot be unmeasurable.
 */
export interface BrandSlot {
  /** `SR-4`: no endpoint on this surface carries these. The slot exists; the value does not. */
  readonly name?: string
  readonly logo?: ReactNode
  readonly homeHref?: string
}

export interface FrameProps {
  readonly screen: string
  readonly state: string
  readonly t: Translate
  readonly brand?: BrandSlot | undefined
  readonly children: ReactNode
  readonly actions?: ReactNode
}

export function Frame({ screen, state, t, brand, children, actions }: FrameProps) {
  return (
    <main className="pg-screen" data-screen={screen} data-state={state}>
      <ProductMark t={t} brand={brand} />
      <div className="pg-screen__body">{children}</div>
      {actions !== undefined && <div className="pg-actions">{actions}</div>}
    </main>
  )
}

/**
 * The restaurant's mark when there is one, the product's when there is not.
 *
 * ⚠ IT RENDERS NO GUESSED VALUE. `SR-4` records that `§4.1` asks three screens for
 * restaurant branding and that neither `POST /v1/pager/session` nor `POST /v1/pager/status`
 * carries a name, a logo or a landing URL -- deliberately, because `PagerStatusView` treats
 * everything it returns as readable by whoever holds the token. Inventing one from the
 * hostname, or linking to a guessed URL, would be a dead end wearing the costume of a path.
 */
function ProductMark({
  t,
  brand,
}: {
  readonly t: Translate
  readonly brand?: BrandSlot | undefined
}) {
  return (
    <div className="pg-mark">
      {brand?.logo !== undefined && <span className="pg-mark__logo">{brand.logo}</span>}
      <span>{brand?.name ?? t('a11y.productMark')}</span>
    </div>
  )
}
