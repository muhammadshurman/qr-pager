import { PAGER_STATUS, type PagerStatus } from '@qrpager/contracts'

/**
 * A pager status, as text.
 *
 * TWO PROPERTIES WORTH NAMING:
 *
 * 1. The status union comes from `@qrpager/contracts`, which is GENERATED from the PHP
 *    enum. There is no second list of statuses in this package, so a status added in PHP
 *    makes this file fail to compile until the mapping is extended -- which is the point of
 *    the generated mirror rather than a side effect of it.
 *
 * 2. THE STATE IS TEXT, NOT COLOUR ALONE. `§12`'s accessibility bar and
 *    `PlatformPanelAccessibilityTest`'s existing assertion for the admin panel both require
 *    it: a badge whose only signal is its background is unreadable to anyone who cannot
 *    distinguish the hues, and unreadable in a monochrome print of a shift report.
 */
export interface StatusBadgeProps {
  readonly status: PagerStatus
  /** The display copy per status. Supplied by the caller -- §12 owns the copy, not this. */
  readonly labels: Readonly<Record<PagerStatus, string>>
}

/**
 * Which visual tone each status takes.
 *
 * `Record<PagerStatus, …>` rather than a partial map, so the compiler requires an entry for
 * every status the generated mirror declares. A status added in PHP breaks the build here
 * instead of rendering with no tone.
 */
const TONE: Readonly<Record<PagerStatus, 'waiting' | 'ready' | 'done' | 'stopped'>> = {
  waiting: 'waiting',
  ready: 'ready',
  completed: 'done',
  cancelled: 'stopped',
  expired: 'stopped',
}

export function StatusBadge({ status, labels }: StatusBadgeProps) {
  return (
    <span className="qr-badge" data-status={status} data-tone={TONE[status]}>
      {labels[status]}
    </span>
  )
}

/** Exported so a test can iterate every status the contract declares. */
export const STATUS_TONES = TONE
export const ALL_PAGER_STATUSES = PAGER_STATUS
