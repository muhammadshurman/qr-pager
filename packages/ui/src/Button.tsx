import type { ButtonHTMLAttributes, ReactNode } from 'react'

import { Spinner } from './Spinner.tsx'
import { VisuallyHidden } from './a11y.tsx'

/**
 * The one button.
 *
 * `§12`'s "no library default look" and the 44x44 minimum target are both properties of
 * this file: the minimum is `--target-min` in the kit stylesheet, and Playwright measures
 * the RENDERED box rather than trusting the declaration (R-118's share for this task).
 */
export type ButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger'

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> {
  readonly variant?: ButtonVariant
  readonly loading?: boolean
  readonly loadingLabel?: string
  readonly children: ReactNode
}

export function Button({
  variant = 'primary',
  loading = false,
  loadingLabel = 'Working',
  disabled,
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      type="button"
      {...rest}
      className={`qr-button qr-button--${variant}`}
      // A loading button is disabled so it cannot be submitted twice, and `aria-busy`
      // says WHY -- `disabled` alone is indistinguishable from "not allowed".
      disabled={disabled === true || loading}
      aria-busy={loading || undefined}
      data-variant={variant}
      data-loading={loading ? 'true' : undefined}
    >
      {loading && (
        <>
          <Spinner />
          <VisuallyHidden>{loadingLabel}</VisuallyHidden>
        </>
      )}
      <span className="qr-button__label">{children}</span>
    </button>
  )
}
