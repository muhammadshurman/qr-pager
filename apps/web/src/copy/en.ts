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

  /* ================================================================================
   * TASK 10a — THE STAFF SURFACE. A different reader, and `§12`'s zero-technical bar.
   * ================================================================================
   *
   * Specified in `docs/tickets/QRPAGER-25.md` and in `QRPAGER-25`'s pre-coding comment
   * before any of these screens was coded. Three rules shaped every string below and they
   * are the zero-technical-background clause rather than style:
   *
   *   - NO UNEXPLAINED IDENTIFIER. Not a uuid, not a slug, not an ability string, not a
   *     `tenant_role` value. "Kitchen screen", not `pager:read`.
   *   - NO EMPTY STATE WITHOUT A NEXT ACTION. Every `empty` string below has an action
   *     string beside it, and `StaffState`'s type will not compile without one.
   *   - EVERY ERROR SAYS WHAT TO DO, not what failed. No status code, no "API", no
   *     "token", no "session".
   */

  // ----------------------------------------------------------------- staff sign-in
  'staff.signin.title': 'Sign in',
  'staff.signin.passkey': 'Use your passkey',
  'staff.signin.password': 'Use a password instead',
  'staff.signin.working': 'Signing you in…',
  // ONE message for every cause, deliberately. Distinguishing "no such account" from
  // "wrong password" lets a form probe whether an address has an account here.
  'staff.signin.failed': 'That did not work. Try your passkey again, or use your password.',
  'staff.signin.offline': 'No connection. Check the network and try again.',
  'staff.signin.unsupported': 'This browser cannot use passkeys. Use your password instead.',
  'staff.signin.email': 'Email',
  'staff.signin.passwordLabel': 'Password',
  'staff.signin.submit': 'Sign in',
  'staff.signin.twoFactor': 'Enter the 6-digit code from your authenticator app.',
  'staff.signin.code': 'Code',
  'staff.signin.choose': 'Which restaurant?',

  // ---------------------------------------------------------------------- the shell
  'staff.shell.signedIn': 'Signed in as {name}',
  'staff.shell.signOut': 'Sign out',
  'staff.shell.loading': 'Loading',
  'staff.shell.error.title': 'Something went wrong on our side.',
  'staff.shell.error.body': 'Nothing you did caused it.',
  'staff.shell.error.retry': 'Try again',
  'staff.shell.offline.title': 'No connection.',
  'staff.shell.offline.body': 'We will keep trying.',
  // The role as a WORD. `tenant_role` carries `owner`/`manager`/`staff`; these are what a
  // person reads, and the mapping lives at one call site so an enum case can never reach a
  // screen.
  'staff.role.owner': 'Owner',
  'staff.role.manager': 'Manager',
  'staff.role.staff': 'Staff',
  'staff.nav.today': 'Today',
  'staff.nav.devices': 'Kitchen screens',
  'staff.home.title': 'You are signed in.',
  'staff.home.body':
    'The order board arrives next. For now you can set up the kitchen screens that will show it.',
  'staff.home.action': 'Kitchen screens',
  'staff.passkey.add': 'Add a passkey to this device',
  'staff.passkey.added': 'This device can sign you in with a passkey now.',
  'staff.passkey.failed': 'That did not work. Try again, or sign in with your password next time.',

  // ----------------------------------------------------------------------- the lock
  'staff.lock.title': 'Screen locked',
  'staff.lock.body': 'Nobody is signed in on this screen. Sign in with your passkey to carry on.',
  'staff.lock.signIn': 'Sign in',
  'staff.signedout.title': 'Signed out',
  'staff.signedout.body':
    'This screen is no longer signed in. Sign in with your passkey when you are ready.',

  // -------------------------------------------------------------------- kitchen screens
  'staff.devices.title': 'Kitchen screens',
  'staff.devices.lede': 'A kitchen screen shows the orders without anybody signing in to it.',
  'staff.devices.empty.title': 'No kitchen screen is set up yet.',
  'staff.devices.empty.body': 'Set one up and it will show this restaurant\u2019s orders.',
  'staff.devices.empty.action': 'Set up a kitchen screen',
  'staff.devices.add': 'Set up a kitchen screen',
  'staff.devices.expires': 'Stops working on {date}',
  'staff.devices.lastUsed': 'Last used {when}',
  'staff.devices.neverUsed': 'Not used yet',
  'staff.devices.name.label': 'What is this screen called?',
  'staff.devices.name.hint': 'Something you will recognise, like the pass counter or the grill.',
  'staff.devices.create': 'Create it',
  'staff.devices.creating': 'Setting up the screen…',
  'staff.devices.cancel': 'Cancel',
  'staff.devices.issued.title': 'Set up the screen with this',
  'staff.devices.issued.warning':
    'Copy this now. It is shown once and cannot be shown again. If you lose it, remove this screen and set up a new one.',
  'staff.devices.issued.done': 'I have copied it',
  'staff.devices.remove': 'Remove',
  'staff.devices.remove.title': 'Remove this screen?',
  'staff.devices.remove.body':
    'The screen will stop working straight away. You can set up a new one afterwards.',
  'staff.devices.remove.confirm': 'Remove',
  'staff.devices.remove.keep': 'Keep it',
  'staff.devices.denied.title': 'You cannot change the kitchen screens.',
  'staff.devices.denied.body':
    'Only the owner or a manager can set up kitchen screens. Ask one of them.',
  'staff.devices.error.title': 'We could not load the kitchen screens.',
  'staff.devices.error.body': 'Nothing has changed. Try again.',
  'staff.devices.offline.title': 'No connection.',
  'staff.devices.offline.body':
    'The screens are unchanged. Try again once you are back on a network.',
} as const

/** Every copy id. Derived, never listed -- see the header. */
export type CopyKey = keyof typeof EN
