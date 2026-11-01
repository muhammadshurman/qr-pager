import { Button } from '@qrpager/ui'

import type { Translate } from '../copy/index.ts'
import type { LandingState } from '../pager/journey.ts'
import { Frame, type BrandSlot } from './Frame.tsx'

/**
 * `§3.1` of the ticket. Purpose: turn a camera scan into one decision.
 *
 * `§4.1`: *"Restaurant mark, order reference, a single primary action, zero chrome. Static
 * shell from CDN."*
 *
 * ⚠ TWO of `§4.1`'s FOUR SCAN STATES ARE NOT RENDERED HERE, and that is the mapping
 * `journey.ts SCAN_OUTCOMES` makes explicit and tests by driving the real answer: an expired
 * scan IS the session-ended screen and a scan that is already ready IS the order-ready
 * screen. Rendering them here as well would be two states that look identical to two others.
 */
export interface ScanLandingProps {
  readonly state: LandingState
  readonly t: Translate
  readonly reference: string | null
  readonly onEnableAlerts: () => void
  readonly onSkip: () => void
  readonly brand?: BrandSlot | undefined
}

export function ScanLanding({
  state,
  t,
  reference,
  onEnableAlerts,
  onSkip,
  brand,
}: ScanLandingProps) {
  if (state === 'invalid') {
    return (
      <Frame screen="landing" state="invalid" t={t} brand={brand}>
        <h1 className="pg-title">{t('landing.invalid.title')}</h1>
        {/* No retry button, and no dead end either: the way forward is physical -- re-scan,
            or ask the counter -- and the copy names both. A "try again" here would re-read a
            fragment that has already been stripped and fail identically every time. */}
        <p className="pg-note">{t('landing.invalid.body')}</p>
      </Frame>
    )
  }

  return (
    <Frame
      screen="landing"
      state="valid"
      t={t}
      brand={brand}
      actions={
        <>
          <Button variant="primary" onClick={onEnableAlerts}>
            {t('landing.valid.cta')}
          </Button>
          {/* `§4.1` asks for ONE primary action and this is not a second one: enabling
              alerts may not be compulsory, so a customer who declines still reaches the
              waiting screen. Quiet variant, below the primary, same thumb zone. */}
          <Button variant="quiet" onClick={onSkip}>
            {t('landing.valid.skip')}
          </Button>
        </>
      }
    >
      <p className="pg-eyebrow">{t('landing.valid.eyebrow')}</p>
      {reference !== null && (
        <p className="pg-reference" dir="ltr" aria-label={`${t('landing.ref.label')} ${reference}`}>
          {reference}
        </p>
      )}
      <p className="pg-lede">{t('landing.valid.lede')}</p>
    </Frame>
  )
}
