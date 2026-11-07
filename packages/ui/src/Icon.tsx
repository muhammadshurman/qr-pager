import { useTheme } from './theme.tsx'

/**
 * ================================================================================
 * ICONS, AND THE RTL RULE THAT IS NOT `direction: rtl`
 * ================================================================================
 *
 * QRPAGER-15 §6, trap 2, verbatim: *"RTL is not `direction: rtl`. Icons, progress, timers
 * and anything with directional meaning mirror; logos and numbers do not."*
 *
 * So each icon declares whether it is DIRECTIONAL. An arrow means "onward", and onward is
 * leftward in Arabic, so it mirrors. A clock does not: a mirrored clock is a broken clock,
 * not a localised one. A logo never mirrors. That is a property of each glyph, decided here
 * once, rather than a CSS rule applied to everything inside an `[dir='rtl']` subtree --
 * which is the mistake that produces backwards clocks and reversed logos.
 *
 * Every path uses `currentColor`. No icon carries a colour of its own, which is both a §12
 * requirement and what lets one glyph sit on paper and on ready-field unchanged.
 */

export type IconName =
  'arrow-next' | 'arrow-back' | 'check' | 'clock' | 'bell' | 'alert' | 'wifi-off'

/**
 * Which glyphs carry directional meaning and therefore mirror.
 *
 * The list is the mechanism. A glyph added without an entry defaults to NOT mirroring,
 * which is the safe direction: a logo rendered unmirrored is correct, while a logo
 * mirrored by accident is a trademark problem.
 */
const DIRECTIONAL: Readonly<Record<IconName, boolean>> = {
  'arrow-next': true,
  'arrow-back': true,
  // A clock face is not directional in the mirroring sense: time does not run backwards in
  // Arabic. §6 trap 2 names "timers" as mirroring, and what mirrors in a timer is its
  // PROGRESS TRACK (see ProgressTrack), never the numerals or the dial.
  clock: false,
  check: false,
  bell: false,
  alert: false,
  'wifi-off': false,
}

const PATHS: Readonly<Record<IconName, string>> = {
  'arrow-next': 'M5 12h14M13 6l6 6-6 6',
  'arrow-back': 'M19 12H5M11 18l-6-6 6-6',
  check: 'M4 12.5l5 5L20 6.5',
  clock: 'M12 7v5l3.5 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
  bell: 'M18 16V11a6 6 0 1 0-12 0v5l-2 3h16l-2-3M10 22h4',
  alert:
    'M12 8v5M12 17h.01M10.3 3.9 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  'wifi-off':
    'M2 2l20 20M8.5 16.4a5 5 0 0 1 7 0M5 12.9a10 10 0 0 1 3-2M19 12.9a10 10 0 0 0-7-2.9M12 20h.01',
}

export interface IconProps {
  readonly name: IconName
  /**
   * An accessible name. OMIT IT for a decorative icon beside text that already says the
   * same thing -- a duplicated label is noise, not access. When omitted the icon is
   * `aria-hidden`, which is the correct answer and not a shortcut.
   */
  readonly label?: string
  readonly size?: 16 | 20 | 24
}

export function Icon({ name, label, size = 24 }: IconProps) {
  const { mirrors } = useTheme()
  const flip = mirrors && DIRECTIONAL[name]

  return (
    <svg
      className="qr-icon"
      data-icon={name}
      data-mirrored={flip ? 'true' : undefined}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
      role={label === undefined ? undefined : 'img'}
      aria-hidden={label === undefined ? true : undefined}
      aria-label={label}
      // The transform is inline because it is DATA about this glyph in this direction,
      // not styling: it comes from the DIRECTIONAL table above, and a CSS rule could not
      // tell an arrow from a clock. No colour, length or font value appears here.
      style={flip ? { transform: 'scaleX(-1)' } : undefined}
    >
      <path d={PATHS[name]} />
    </svg>
  )
}

/** Exported so a test can assert the table rather than re-deriving it. */
export const DIRECTIONAL_ICONS = DIRECTIONAL
