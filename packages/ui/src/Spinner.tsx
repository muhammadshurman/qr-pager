/**
 * A loading indicator.
 *
 * `aria-hidden`, deliberately: the accessible name belongs on the REGION that is busy, not
 * on the decoration inside it, and a spinner that announces itself says "image" to a screen
 * reader. `StateView` supplies the name.
 *
 * The motion is a CSS animation whose duration is a token, so `prefers-reduced-motion`
 * resolves it to 0ms -- no motion, not less motion (QRPAGER-15 §6, trap 3). At 0ms the
 * element is still present and still marks the region busy; only the spin stops.
 */
export function Spinner({ label }: { readonly label?: string } = {}) {
  return (
    <span
      className="qr-spinner"
      aria-hidden={label === undefined ? true : undefined}
      role={label === undefined ? undefined : 'img'}
      aria-label={label}
    >
      <svg viewBox="0 0 24 24" width="24" height="24" focusable="false">
        {/* No fill or stroke colour here: both come from currentColor, so the spinner
            inherits whatever token the surrounding text uses and carries no colour of
            its own. A hex here would be the §12 violation this whole package prevents. */}
        <circle
          cx="12"
          cy="12"
          r="9"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeOpacity="0.25"
        />
        <path
          d="M21 12a9 9 0 0 0-9-9"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
        />
      </svg>
    </span>
  )
}
