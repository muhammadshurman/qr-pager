import { useTheme } from './theme.tsx'

/**
 * A determinate progress track.
 *
 * ⚠ THIS MIRRORS, AND IT IS THE REASON QRPAGER-15 §6 TRAP 2 NAMES "progress". Progress runs
 * from the reading origin: left to right in English, **right to left in Arabic**. A track
 * that always fills from the left is not merely cosmetically wrong in Arabic -- it reads as
 * emptying.
 *
 * It is implemented as a transform origin rather than as `left`/`right` offsets, so there is
 * one rule and the direction decides its anchor. `aria-valuenow` is the same number in both
 * directions, because the VALUE does not mirror -- only its depiction does. That is the same
 * distinction as the numerals in ElapsedTimer.
 */
export interface ProgressTrackProps {
  /** 0..1. Clamped, because a caller computing elapsed/total can exceed 1 at the boundary. */
  readonly value: number
  readonly label: string
}

export function ProgressTrack({ value, label }: ProgressTrackProps) {
  const clamped = Math.min(1, Math.max(0, value))
  const { mirrors } = useTheme()
  const percent = Math.round(clamped * 100)

  return (
    <div
      className="qr-progress"
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={percent}
      data-mirrored={mirrors ? 'true' : undefined}
    >
      <span
        className="qr-progress__fill"
        // scaleX with a direction-dependent origin: one rule, and the anchor is the only
        // thing that changes. A width percentage would need a left/right switch, which is
        // two rules that can disagree.
        style={{
          transform: `scaleX(${String(clamped)})`,
          transformOrigin: mirrors ? 'right' : 'left',
        }}
      />
    </div>
  )
}
