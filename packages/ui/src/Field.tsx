import { useId, type InputHTMLAttributes, type ReactNode } from 'react'

/**
 * A labelled text input with a hint and an error.
 *
 * THE BORDER IS `--color-border-strong`, NOT `--color-hairline`, and that is the whole
 * reason `border-strong` exists. WCAG 1.4.11: a border that is an interactive control's only
 * visual boundary needs 3:1 against its adjacent colour. Measured, `hairline` is 1.29:1 in
 * light and 2.08:1 in dark -- it qualifies in neither, so it is decorative separation only
 * and this input would have been inaccessible with it. Found at the dark theme's approval
 * (2026-10-31, condition 6) and it bit the light theme too.
 *
 * An error is announced AND associated: `aria-describedby` so it is read when the control is
 * reached, and `aria-invalid` so the state is exposed rather than implied by colour. Colour
 * alone would fail `§12` for anyone who cannot see it.
 */
export interface FieldProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'className' | 'id'
> {
  readonly label: string
  readonly hint?: string
  readonly error?: string
  readonly children?: ReactNode
}

export function Field({ label, hint, error, children, ...rest }: FieldProps) {
  const base = useId()
  const inputId = `${base}-input`
  const hintId = `${base}-hint`
  const errorId = `${base}-error`

  const describedBy = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter((v): v is string => v !== null)
    .join(' ')

  return (
    <div className="qr-field" data-invalid={error === undefined ? undefined : 'true'}>
      <label className="qr-field__label" htmlFor={inputId}>
        {label}
      </label>
      {hint !== undefined && (
        <p className="qr-field__hint" id={hintId}>
          {hint}
        </p>
      )}
      <input
        {...rest}
        id={inputId}
        className="qr-field__input"
        aria-invalid={error === undefined ? undefined : true}
        aria-describedby={describedBy === '' ? undefined : describedBy}
      />
      {error !== undefined && (
        <p className="qr-field__error" id={errorId} role="alert">
          {error}
        </p>
      )}
      {children}
    </div>
  )
}
