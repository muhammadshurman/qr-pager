/**
 * An elapsed-time readout.
 *
 * ⚠ THE NUMERALS DO NOT MIRROR. QRPAGER-15 §6, trap 2: *"logos and numbers do not."* `04:12`
 * is `04:12` in Arabic -- the digits are read left to right even inside right-to-left text,
 * which is what the Unicode bidi algorithm already does. So this component sets
 * `dir="ltr"` on the readout ITSELF, inside an RTL page, rather than letting the surrounding
 * direction reorder the clock into `12:04`.
 *
 * That is the opposite of what the ProgressTrack beside it does, and both are correct. It is
 * exactly why "RTL is not direction: rtl" is written down.
 *
 * `role="timer"` with `aria-live="off"`: a timer that announces every second makes a screen
 * reader unusable. The ORDER BEING READY is announced (assertive, in OrderReady); the
 * seconds ticking are not.
 */
export interface ElapsedTimerProps {
  /** Whole seconds elapsed. */
  readonly seconds: number
  /** The accessible name -- the digits alone do not say what is being timed. */
  readonly label: string
}

/** mm:ss, and past an hour h:mm:ss. Zero-padded so the readout does not change width. */
export function formatElapsed(totalSeconds: number): string {
  const safe = Math.max(0, Math.floor(totalSeconds))
  const hours = Math.floor(safe / 3600)
  const minutes = Math.floor((safe % 3600) / 60)
  const seconds = safe % 60
  const pad = (n: number) => String(n).padStart(2, '0')

  return hours > 0
    ? `${String(hours)}:${pad(minutes)}:${pad(seconds)}`
    : `${pad(minutes)}:${pad(seconds)}`
}

export function ElapsedTimer({ seconds, label }: ElapsedTimerProps) {
  const formatted = formatElapsed(seconds)

  return (
    <p className="qr-timer" role="timer" aria-live="off" aria-label={`${label}: ${formatted}`}>
      {/* dir="ltr" on the readout itself. Inside an RTL page the surrounding direction
          would otherwise reorder the colon-separated parts, turning 04:12 into 12:04. */}
      <span className="qr-timer__value" dir="ltr">
        {formatted}
      </span>
    </p>
  )
}
