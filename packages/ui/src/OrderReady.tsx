import type { ReactNode } from 'react'

import { LiveRegion } from './a11y.tsx'

/**
 * ================================================================================
 * THE ORDER-READY SCREEN. OUTSIDE THEMING, AND THAT IS A MECHANISM.
 * ================================================================================
 *
 * Decision D4: *"The order-ready screen is EXCLUDED from theming. Its job is to be read
 * across a room. Theme flexibility there is a failure surface, not a feature. A contrast
 * clamp is code that must be proven against hostile values and can still produce something
 * ugly. Fixed colour pair, the restaurant's logo only."*
 *
 * ⚠ HOW THAT IS ENFORCED, AND IT IS NOT A COMMENT. This component's colours come from
 * `--ready-field`, `--ready-ink` and `--ready-signal`. Those three are:
 *
 *   1. declared EXACTLY ONCE in the generated stylesheet, outside both theme blocks -- so
 *      there is no second declaration that could differ between light and dark;
 *   2. in the `--ready-*` namespace, which no tenant theme token can produce. Task 7's
 *      `ThemeTokens::property()` prefixes every one of its twelve allow-listed names with
 *      `--color-`, `--font-`, `--radius-`, `--space-` or `--size-`. The intersection with
 *      `--ready-*` is EMPTY, and a test asserts it over the generated artefacts.
 *
 * So a hostile tenant theme value does not reach this screen, get clamped and come out
 * safe. It never reaches it. There is NO clamp and NO contrast-adjustment code here or
 * anywhere in this package; if any appears, the design has drifted from the decision
 * (QRPAGER-15 §6, trap 5).
 *
 * The ratios, computed from the token source rather than measured in a browser -- which is
 * only possible because D4 fixes the pair: `--ready-ink` on `--ready-field` 15.55:1,
 * `--ready-signal` on `--ready-field` 9.73:1, `--ready-field` on `--ready-signal` 9.73:1.
 * All three clear AAA, which §12 requires on this screen and nowhere else.
 *
 * THE ONLY TENANT-SUPPLIED THING ON THIS SCREEN IS THE LOGO SLOT. It is an image, it has no
 * influence on any colour, and it is rendered at a bounded size.
 */
export interface OrderReadyProps {
  /** The order reference, shown large. The customer is matching it against their receipt. */
  readonly reference: string
  /** Copy. §12 forbids a component inventing its own. */
  readonly heading: string
  readonly instruction: string
  readonly acknowledgeLabel: string
  readonly onAcknowledge: () => void
  /** Whether the customer has acknowledged -- §4.1's `fresh` / `acknowledged` states. */
  readonly acknowledged?: boolean
  /**
   * The restaurant's logo. The ONE tenant-supplied element, and it is a slot: this component
   * never reads a tenant colour, so nothing a tenant provides can change the pair above.
   */
  readonly logo?: ReactNode
}

export function OrderReady({
  reference,
  heading,
  instruction,
  acknowledgeLabel,
  onAcknowledge,
  acknowledged = false,
  logo,
}: OrderReadyProps) {
  return (
    <section
      className="qr-ready"
      data-state={acknowledged ? 'acknowledged' : 'fresh'}
      // NO data-theme here, and its absence is deliberate: this screen does not
      // participate in theming, so it must not declare a theme either. Setting one would
      // make it look theme-aware to any future reader.
      aria-labelledby="qr-ready-heading"
    >
      {logo !== undefined && <div className="qr-ready__logo">{logo}</div>}

      <h1 className="qr-ready__heading" id="qr-ready-heading">
        {heading}
      </h1>

      {/* The reference is the thing read across the room. dir="ltr" for the same reason as
          ElapsedTimer: an order reference is a code, and codes do not mirror. */}
      <p className="qr-ready__reference" dir="ltr">
        {reference}
      </p>

      <p className="qr-ready__instruction">{instruction}</p>

      <button type="button" className="qr-ready__acknowledge" onClick={onAcknowledge}>
        {acknowledgeLabel}
      </button>

      {/* ASSERTIVE, and this is the one place in the product that earns it: the customer
          may not be looking at the screen, which is the entire premise of a pager. */}
      <LiveRegion message={`${heading} ${reference}`} politeness="assertive" />
    </section>
  )
}
