import { OrderReady } from '@qrpager/ui'

import type { Translate } from '../copy/index.ts'
import type { BrandSlot } from './Frame.tsx'

/**
 * `§3.4` of the ticket. The screen the product exists for.
 *
 * ⚠ THIS TASK ADDS NO COLOUR, NO CLAMP AND NO CONTRAST CODE TO IT. The screen is
 * `@qrpager/ui`'s `OrderReady`, whose three colours are `--ready-field`, `--ready-ink` and
 * `--ready-signal`: declared exactly once in the generated stylesheet, outside both theme
 * blocks, in a namespace no tenant theme token can produce (`ThemeTokens::property()`
 * prefixes every one of its twelve allow-listed names with `--color-`, `--font-`,
 * `--radius-`, `--space-` or `--size-`, and the intersection with `--ready-*` is empty).
 *
 * D4: *"Fixed colour pair, the restaurant's logo only."* There is no clamp here and none
 * anywhere in this task; if one ever appears, the design has drifted from the decision
 * (`QRPAGER-15 §6`, trap 5).
 *
 * The AAA ratios are arithmetic over the token source rather than a browser measurement,
 * which is only possible BECAUSE D4 fixes the pair: `--ready-ink` on `--ready-field`
 * **15.55:1**, `--ready-signal` on `--ready-field` **9.73:1**, `--ready-field` on
 * `--ready-signal` **9.73:1**.
 *
 * WHAT THIS FILE IS, THEN: the copy and the two states. It passes no colour, reads no theme
 * and computes nothing.
 */
export interface OrderReadyScreenProps {
  readonly state: 'fresh' | 'acknowledged'
  readonly t: Translate
  readonly reference: string
  readonly onAcknowledge: () => void
  readonly brand?: BrandSlot | undefined
}

export function OrderReadyScreen({
  state,
  t,
  reference,
  onAcknowledge,
  brand,
}: OrderReadyScreenProps) {
  const acknowledged = state === 'acknowledged'

  return (
    // The wrapper carries `data-screen`/`data-state` so the browser assertions locate this
    // screen the same way they locate the other five. The kit's own `data-state` on
    // `.qr-ready` is its own and is left alone.
    <div data-screen="ready" data-state={state}>
      <OrderReady
        reference={reference}
        heading={t('ready.heading')}
        instruction={t('ready.instruction')}
        // ⚠ THE LABEL CHANGES AND THE SCREEN DOES NOT. `§4.1`'s `acknowledged` state keeps
        // the customer here: the order reference is still the thing they are holding up at
        // the counter, so sending them anywhere would take away the only thing they need.
        acknowledgeLabel={acknowledged ? t('ready.acked') : t('ready.ack')}
        onAcknowledge={onAcknowledge}
        acknowledged={acknowledged}
        {...(brand?.logo === undefined ? {} : { logo: brand.logo })}
      />
    </div>
  )
}
