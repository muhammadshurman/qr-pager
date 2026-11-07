/**
 * ================================================================================
 * THE iOS HOME-SCREEN INSTALL PATH. A required product path, not a hint.
 * ================================================================================
 *
 * `§4 › Realtime`: *"iOS: Web Push only works from a Home Screen install. The install
 * guidance is a required product path, not a nice-to-have."* `§4.1` puts it on the
 * enable-alerts screen as *"a first-class flow"*.
 *
 * MEASURED, not assumed. MDN `browser-compat-data`, `api/PushManager.json`: `safari_ios`
 * `16.4`, with Apple's own compat note *"Notifications are supported in web apps saved to
 * the home screen."* And the WebKit announcement: *"Now with iOS and iPadOS 16.4, we are
 * adding support for Web Push to Home Screen web apps."*
 *
 * ⚠ THE GUIDANCE IS GATED ON THE DISPLAY MODE, NOT ON A USER-AGENT STRING ALONE. The display
 * mode is the thing that actually decides whether a push can arrive; the platform only
 * decides whether the install is the REASON it cannot. An iPhone already installed must not
 * be told to install, and a desktop Safari must not be told about a Share sheet it does not
 * have.
 */

export type DisplayMode = 'standalone' | 'browser'

export interface InstallEnvironment {
  readonly matchMedia: (query: string) => Pick<MediaQueryList, 'matches'>
  /** Safari's own, and what older iOS reports. Absent elsewhere. */
  readonly navigatorStandalone: boolean | undefined
  readonly userAgent: string
  /** iPadOS 13+ reports a Mac UA; `maxTouchPoints` is how it is told from a real Mac. */
  readonly maxTouchPoints: number
}

/**
 * Standalone or a tab.
 *
 * TWO PROBES, because they answer on different engines: `(display-mode: standalone)` is the
 * standard one and iOS has honoured the manifest's `display` since 16.4;
 * `navigator.standalone` is Safari's own and is what older iOS reports. QRPAGER-24's harness
 * established this pair and this inherits it rather than re-deriving it.
 */
export function displayMode(env: InstallEnvironment): DisplayMode {
  const standalone =
    env.matchMedia('(display-mode: standalone)').matches || env.navigatorStandalone === true

  return standalone ? 'standalone' : 'browser'
}

/**
 * Is this the iOS family?
 *
 * ⚠ `maxTouchPoints` IS NOT OPTIONAL HERE. iPadOS 13 and later send a desktop Mac
 * user-agent by default -- `Macintosh; Intel Mac OS X` -- so a UA test alone reports an iPad
 * as a Mac and the install guidance never appears on the device class that most needs it.
 * A Mac reports `maxTouchPoints === 0`; an iPad reports 5. That is the standard
 * discrimination and it is the only one available without a UA-Client-Hints API Safari does
 * not implement.
 */
export function isIosFamily(env: InstallEnvironment): boolean {
  const ua = env.userAgent

  if (/\b(iPhone|iPad|iPod)\b/.test(ua)) return true

  return /\bMacintosh\b/.test(ua) && env.maxTouchPoints > 1
}

export type InstallGuidance =
  /** Show the three-step flow: this engine needs the install and does not have it. */
  | { readonly kind: 'required' }
  /** Already installed. Nothing to show. */
  | { readonly kind: 'installed' }
  /** Not an engine where the install is the blocker. Nothing to show. */
  | { readonly kind: 'not-applicable' }

export function installGuidance(env: InstallEnvironment): InstallGuidance {
  if (displayMode(env) === 'standalone') return { kind: 'installed' }

  return isIosFamily(env) ? { kind: 'required' } : { kind: 'not-applicable' }
}

/**
 * Whether a push can reach this context AT ALL, which is a different question from whether
 * the API is present.
 *
 * ⚠ THIS IS THE SELF-LABELLING QRPAGER-24 ESTABLISHED AND THE TICKET REQUIRES. Its §3 says
 * *"a push received in Safari WITHOUT a Home Screen install does not satisfy it"*, and the
 * harness stamps every log line with the display mode so a transcript cannot be misread. The
 * screen's equivalent is `alerts.install.tabNotice`: on an iOS tab the customer is told, in
 * copy they can read, that alerts cannot reach them yet -- rather than being allowed to grant
 * a permission that will never deliver anything.
 */
export function pushCanReach(env: InstallEnvironment): boolean {
  return !(isIosFamily(env) && displayMode(env) === 'browser')
}
