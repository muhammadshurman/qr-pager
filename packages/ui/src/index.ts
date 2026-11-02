/** The public surface of @qrpager/ui. */
export { ThemeProvider, useTheme, directionFor, LOCALES, AVAILABLE_THEMES } from './theme.tsx'
export type { Direction, Locale, ThemeContextValue, ThemeProviderProps } from './theme.tsx'

export {
  VisuallyHidden,
  LiveRegion,
  useFocusTrap,
  useRovingTabIndex,
  focusableWithin,
  nextId,
  resetIdCounter,
} from './a11y.tsx'
export type { Politeness } from './a11y.tsx'

export { Button } from './Button.tsx'
export type { ButtonProps, ButtonVariant } from './Button.tsx'
export { Card } from './Card.tsx'
export type { CardProps } from './Card.tsx'
export { ElapsedTimer, formatElapsed } from './ElapsedTimer.tsx'
export type { ElapsedTimerProps } from './ElapsedTimer.tsx'
export { Field } from './Field.tsx'
export type { FieldProps } from './Field.tsx'
export { Icon, DIRECTIONAL_ICONS } from './Icon.tsx'
export type { IconName, IconProps } from './Icon.tsx'
export { OrderReady } from './OrderReady.tsx'
export type { OrderReadyProps } from './OrderReady.tsx'
export { ProgressTrack } from './ProgressTrack.tsx'
export type { ProgressTrackProps } from './ProgressTrack.tsx'
export { Spinner } from './Spinner.tsx'
export { StateView, VIEW_STATES } from './StateView.tsx'
export type { StateViewProps, ViewState } from './StateView.tsx'
export { StatusBadge, STATUS_TONES, ALL_PAGER_STATUSES } from './StatusBadge.tsx'
export type { StatusBadgeProps } from './StatusBadge.tsx'

/* ------------------------------------------------- the staff layer, added at Task 10a */
export { StaffState, STAFF_STATES, StaffShell, LockScreen } from './staff/index.ts'
export type {
  StaffAction,
  StaffStateProps,
  StaffStateName,
  StaffShellProps,
  LockScreenProps,
} from './staff/index.ts'
