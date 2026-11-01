/**
 * ================================================================================
 * THE ENGLISH COPY. THE KEY SET IS DEFINED HERE AND NOWHERE ELSE.
 * ================================================================================
 *
 * `§12`: *"Every screen is specified before it is coded: purpose, all states, content
 * inventory, and the exact copy."* Every string below is the one written into
 * `docs/tickets/QRPAGER-16.md §3` before any screen was coded, and no component in this app
 * contains a customer-visible literal -- `apps/web/test/copy.test.ts` scans the screens'
 * own source for bare text and fails on one.
 *
 * ⚠ THIS OBJECT IS THE TYPE. `CopyKey` is `keyof typeof EN`, and `ar.ts` is declared as
 * `Record<CopyKey, string>` -- so a key added here and not translated is a TYPE ERROR at
 * `pnpm typecheck`, not a screen that silently falls back to English in Arabic. `§12` names
 * Arabic a launch language; a fallback is how a launch language becomes a second-class one.
 *
 * The companion runtime assertion covers what the type cannot: an EXTRA key in `ar.ts`
 * (which `Record` admits, because excess-property checking does not apply to a
 * non-literal-typed import), and a value that is empty or still English.
 *
 * `{name}` placeholders are substituted by `t()`. They are written in the copy rather than
 * concatenated at the call site so a translator can move them -- Arabic word order is not
 * English word order, and a sentence assembled from fragments cannot be reordered.
 */
export const EN = {
  // ------------------------------------------------------------------ scan landing
  'landing.valid.eyebrow': 'Your order',
  'landing.valid.lede': "We'll buzz your phone the moment it's ready. You can put it away.",
  'landing.valid.cta': 'Enable alerts',
  'landing.valid.skip': 'Just show me the status',
  'landing.invalid.title': "That isn't a valid pager link",
  'landing.invalid.body': 'Scan the code on your receipt again, or ask the counter for a new one.',
  'landing.ref.label': 'Order',

  // ----------------------------------------------------------------- enable alerts
  'alerts.lede': 'One buzz when your order is ready. Nothing else, ever.',
  'alerts.cta': 'Allow notifications',
  'alerts.why.summary': 'Why we ask',
  'alerts.why.body':
    'So you can put your phone away and still know. We send one notification for this order and we keep no account, no phone number and no name.',
  'alerts.notNow': 'Not now',
  'alerts.granted': 'Alerts are on. You can put your phone away.',
  'alerts.denied.title': 'Notifications are blocked',
  'alerts.denied.body':
    "Your browser won't ask again, so we can't turn them on from here. Keep this page open and the status below stays live.",
  'alerts.unsupported.title': "This browser can't send alerts",
  'alerts.unsupported.body':
    'Keep this page open instead — the status below stays live while you wait.',
  'alerts.continue': 'Continue',
  'alerts.install.title': 'Add this to your Home Screen first',
  'alerts.install.body':
    'On iPhone and iPad, notifications only work from a Home Screen app. It takes three taps.',
  'alerts.install.step1': 'Tap the Share button in the browser bar.',
  'alerts.install.step2': 'Choose "Add to Home Screen".',
  'alerts.install.step3': 'Open it from the new icon, then allow notifications.',
  'alerts.install.tabNotice': "You're in a browser tab, so alerts can't reach you yet.",

  // ----------------------------------------------------------------------- waiting
  'waiting.title': "We're on it",
  'waiting.status.waiting': 'Being prepared',
  'waiting.elapsed.label': 'Time since you scanned',
  'waiting.next.summary': 'What happens next',
  'waiting.next.body':
    'When the kitchen marks your order ready, your phone buzzes and this screen turns green. If you miss it, the screen still shows it.',
  'waiting.awake.label': 'Keep the screen on',
  'waiting.awake.on': 'On',
  'waiting.awake.off': 'Off',
  'waiting.awake.unavailable': 'Your screen may dim while you wait. The alert still arrives.',
  'waiting.degraded.title': 'Live updates are running behind',
  'waiting.degraded.body':
    'The counter below may be a little old. Your alert is not affected and will still arrive.',
  'waiting.degraded.asOf': 'Last confirmed {time}',
  'waiting.offline.title': "You're offline",
  'waiting.offline.body':
    "We can't check the status without a connection. Your order is still being prepared.",
  'waiting.retry': 'Try again',

  // ------------------------------------------------------------------- order ready
  'ready.heading': 'Your order is ready',
  'ready.instruction': 'Show this number at the counter.',
  'ready.ack': 'Got it',
  'ready.acked': 'On your way',
  'ready.announce': 'Your order is ready. Order {reference}.',

  // ----------------------------------------------------------------- session ended
  'ended.completed.title': 'Enjoy your order',
  'ended.completed.body': 'This pager is finished. Thanks for waiting.',
  'ended.cancelled.title': 'This order was cancelled',
  'ended.cancelled.body':
    "The restaurant cancelled it. Please ask at the counter if you weren't expecting this.",
  'ended.expired.title': 'This code has expired',
  'ended.expired.body': 'Pager codes last two hours. Ask the counter for a new one.',
  'ended.rescan': 'Scan a new code',
  'ended.home': 'Back to {restaurant}',

  // ---------------------------------------------------------------- offline / error
  'error.offline.title': "You're offline",
  'error.offline.body': "We'll try again as soon as you're back on a network.",
  'error.server.title': "We can't reach the kitchen right now",
  'error.server.body': 'This is on us, not on you. Your order is unaffected.',
  'error.unknown.title': 'Something went wrong',
  'error.unknown.body':
    "We're not sure what. Try again, and ask at the counter if it keeps happening.",
  'error.retry': 'Try again',

  // --------------------------------------------------------------------- non-visual
  // Accessible names and announcements. Still copy, still translated: a screen reader
  // user in Arabic hearing an English label is the same defect as seeing one.
  'a11y.loading': 'Checking your order',
  'a11y.productMark': 'QR Pager',
  'a11y.statusRegion': 'Order status',
} as const

/** Every copy id. Derived, never listed -- see the header. */
export type CopyKey = keyof typeof EN
