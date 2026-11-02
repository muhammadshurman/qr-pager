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
  // R-224, and this is deliberately NOT the board's sentence. Muhammad's ruling: "on the
  // board nothing is required of the person and it resumes itself; on sign-in they must wait
  // before trying again." One sentence covering both would be wrong somewhere, which is how
  // R-224 came to exist -- a single mapping serving two situations that differ in exactly the
  // way that matters.
  'staff.signin.throttled': 'Too many tries. Wait a minute, then sign in again.',
  // Its own second sentence — `§11` QA M-11 found the passkey BUTTON'S LABEL rendered here,
  // which told a rate-limited person to do the rate-limited thing.
  'staff.signin.throttled.body': 'Signing in again straight away will not work yet.',

  // ---------------------------------------------------------------------- the shell
  'staff.shell.signedIn': 'Signed in as {name}',
  'staff.shell.signOut': 'Sign out',
  'staff.shell.loading': 'Loading',
  'staff.shell.error.title': 'Something went wrong on our side.',
  'staff.shell.error.body': 'Nothing you did caused it.',
  'staff.shell.error.retry': 'Try again',
  // `R-224`'s third surface — `§11` QA H-3. The shell retries ITSELF on the same jittered
  // backoff the board uses, which is what makes "you do not need to do anything" true here
  // rather than a sentence over a screen that has stopped.
  'staff.shell.throttled.title': 'Catching up.',
  'staff.shell.throttled.body': 'This is taking a moment. You do not need to do anything.',
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
  /*
   * ⚠ `staff.home.*`'s THREE KEYS WERE HERE AND ARE GONE — `§11` QA L-19. They belonged to
   * `StaffHome`, which 10b deleted, and one of them had become false: *"The order board
   * arrives next."* on a surface whose board had arrived. Nothing read them, and because the
   * key count is pinned the check was counting dead copy. Removed rather than kept "in case",
   * which is how a catalogue grows a shadow.
   */
  'staff.passkey.add': 'Add a passkey to this device',
  'staff.passkey.added': 'This device can sign you in with a passkey now.',
  'staff.passkey.failed': 'That did not work. Try again, or sign in with your password next time.',

  // ------------------------------------------------------------------- the board (10b)
  //
  // `§12` requires the exact copy before the screen is coded, and every string below is the
  // one written into `docs/tickets/QRPAGER-26-precoding.md §12` S6/S7 before any of it
  // existed. Nothing here is system vocabulary: no "session", no "pager session", no
  // "status", no "renotify" -- a restaurant manager reads no documentation.
  'staff.board.title': 'Orders',
  'staff.board.order': 'Order {reference}',
  // ⚠ `staff.board.waiting` WAS `'Waiting {duration}'` AND THE PLACEHOLDER NEVER RENDERED —
  // `§11` QA M-13. The duration is a live readout from `ElapsedTimer`, not a string this
  // catalogue can interpolate, so the key is now just the WORD and the component puts it
  // beside the clock. `§12` S6 is amended to match, dated and attributed.
  'staff.board.waitingLabel': 'Waiting',
  'staff.board.open': 'Open order {reference}',
  // The status as a WORD, one per status the generated contract declares. `StatusBadge`
  // takes a `Record<PagerStatus, string>`, so a status added in PHP fails the TypeScript
  // build here rather than rendering its enum value on a kitchen screen.
  'staff.board.status.waiting': 'Preparing',
  'staff.board.status.ready': 'Ready',
  'staff.board.status.completed': 'Collected',
  'staff.board.status.cancelled': 'Cancelled',
  'staff.board.status.expired': 'Expired',
  'staff.board.empty.title': 'No orders are waiting.',
  // The next action is NOT "start a pager": creating one is 10d's screen and does not exist
  // yet, and an empty state whose button goes nowhere is worse than one with no button.
  // Checking now is a real action -- it polls immediately instead of waiting for the tick.
  'staff.board.empty.body': 'An order appears here as soon as a pager is started for it.',
  'staff.board.empty.action': 'Check for new orders',
  'staff.board.error.title': 'We could not load the orders.',
  'staff.board.error.body': 'Nothing has changed. Try again.',
  'staff.board.offline.title': 'No connection.',
  'staff.board.offline.body': 'These are the orders from the last time we reached the network.',
  'staff.board.retry': 'Try again',
  // R-224, and the ruling's own words: on the board nothing is required of the person and
  // it resumes itself. So there is no action copy here -- `StaffState`'s `throttled` case
  // has no `action` member, so one could not be rendered.
  'staff.board.throttled.title': 'The board is catching up.',
  'staff.board.throttled.body':
    'It will refresh itself in a moment. You do not need to do anything.',
  'staff.board.stale': 'Last updated {time}',

  // ------------------------------------------------------------------ the pager detail
  'staff.detail.ready': 'Tell them it is ready',
  'staff.detail.renotify': 'Buzz them again',
  'staff.detail.complete': 'They collected it',
  'staff.detail.cancel': 'Cancel this order',
  'staff.detail.close': 'Close',
  'staff.detail.working': 'Sending…',
  // Destructive in effect, so `§12` wants it confirmed and marked not reversible. The body
  // says what happens to the CUSTOMER, which is the consequence the person is deciding about.
  'staff.detail.cancel.title': 'Cancel this order?',
  'staff.detail.cancel.body': 'The customer is told it is cancelled. You cannot undo this.',
  'staff.detail.cancel.confirm': 'Cancel the order',
  'staff.detail.cancel.keep': 'Keep it',
  'staff.detail.limit': 'You have buzzed them as many times as we allow. Go and find them instead.',
  'staff.detail.expired': 'This pager has run out. Give the customer a new code.',
  'staff.detail.gone':
    'That order is not on the board any more. Somebody else may have finished it.',
  'staff.detail.stale': 'This order has moved on. The board has been refreshed.',
  'staff.detail.failed': 'That did not go through. Nothing has changed — try again.',

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

  /* ================================================================================
   * TASK 10c — THE KITCHEN DISPLAY (`/kds`). A THIRD READER, AND THE HARDEST ONE.
   * ================================================================================
   *
   * `QRPAGER-27 §6`: *"A kitchen screen is greasy, bright and viewed from two metres —
   * contrast and target size are **functional** requirements here."* Two rules on top of the
   * staff surface's three, and both are about the reader rather than the layout:
   *
   *   - **NOBODY IS SIGNED IN**, so no string here addresses a known person and none says
   *     *you*. The staff board can say *"You have buzzed them as many times as we allow"*;
   *     this screen does not know who is standing in front of it.
   *   - **THE WORD *CODE*, NEVER *TOKEN*.** What gets pasted at setup is a bearer credential;
   *     the person pasting it is whoever is mounting the tablet. `staff.devices.issued.*`
   *     already calls it a code on the screen that produces it, and these two screens are the
   *     two ends of one errand — so they use one word for the thing being carried.
   */

  // ------------------------------------------------------------------ setting one up
  'kds.setup.title': 'Set up this kitchen screen',
  'kds.setup.body':
    'Paste the code from the restaurant\u2019s Kitchen screens page. This screen will then show the orders on its own, with nobody signing in to it.',
  'kds.setup.code.label': 'The code',
  'kds.setup.code.hint': 'It was shown once, when the screen was created.',
  'kds.setup.submit': 'Show the orders',
  'kds.setup.working': 'Checking the code\u2026',
  // `§12`: every error says what to DO. The remedy is a NEW code, because a code that has
  // been refused cannot be repaired from this screen -- and it deliberately does not
  // speculate about which of the three causes it was (wrong, expired, removed): all three
  // have the same remedy and naming them would be three sentences for one action.
  'kds.setup.refused':
    'That code does not work any more. Create a new kitchen screen on the Kitchen screens page and paste the new code here.',

  // ------------------------------------------------------------------- the orders
  'kds.title': 'Orders',
  'kds.order': 'Order {reference}',
  // The WORD beside the live clock, never interpolated into it -- `§11` QA M-13's lesson on
  // the staff board: a duration is a live readout and a catalogue cannot interpolate one, so
  // a `'Waiting {duration}'` key renders a bare `mm:ss` that reads as a time of day.
  'kds.waitingLabel': 'Waiting',
  'kds.status.waiting': 'Preparing',
  'kds.status.ready': 'Ready',
  // Unreachable on this screen -- `PagerSessions::board()` selects only the two live statuses
  // -- and present because `StatusBadge` takes a `Record<PagerStatus, string>` and the
  // generated mirror is the source. A status added in PHP must fail this build rather than
  // render its enum value in a kitchen.
  'kds.status.completed': 'Collected',
  'kds.status.cancelled': 'Cancelled',
  'kds.status.expired': 'Expired',
  'kds.empty.title': 'Nothing is waiting.',
  'kds.empty.body': 'An order appears here as soon as a pager is started for it.',
  'kds.empty.action': 'Check now',
  'kds.error.title': 'The orders could not be loaded.',
  'kds.error.body': 'Nothing has changed. Try again.',
  'kds.offline.title': 'No connection.',
  // ⚠ TWO JOBS, AND THE SECOND IS THE ONE THAT MATTERS ON A WALL. It is the body of the
  // full-screen state when there is nothing to show, AND the banner above the orders when
  // there are -- because the orders are KEPT through an outage (`kds/resync.ts`) and a
  // kitchen reading a kept list needs to be told it is a kept list.
  'kds.offline.body': 'These are the last orders we could reach. They may have moved on.',
  'kds.retry': 'Try again',
  // R-224, and the board's wording rather than sign-in's: nothing is required of the person
  // and the screen resumes itself. So there is no action copy, and `StaffState`'s `throttled`
  // case has no `action` member for one to be rendered into.
  'kds.throttled.title': 'Catching up.',
  'kds.throttled.body': 'The orders will refresh themselves in a moment.',
  'kds.stale': 'Last updated {time}',

  // -------------------------------------------------------------- taking it off the wall
  'kds.forget': 'Remove this screen\u2019s code',
  // Destructive in effect -- the screen goes dark until somebody with a staff account returns
  // with a new code -- so `§12` wants it confirmed and marked not reversible. The body says
  // what happens to the SCREEN, which is the consequence being decided about.
  'kds.forget.title': 'Remove this screen\u2019s code?',
  'kds.forget.body':
    'This screen will stop showing orders straight away, and the code cannot be used again. Somebody will have to create a new kitchen screen to bring it back.',
  'kds.forget.confirm': 'Remove the code',
  'kds.forget.keep': 'Keep showing orders',

  /* ================================================================================
   * TASK 10d — HISTORY WITH FILTERS, AND ISSUING A PAGER SHEET
   * ================================================================================
   *
   * `QRPAGER-28 §3` makes `§12`'s zero-technical-background bar the HARD half of this ticket
   * rather than the accessibility half, and states it as three mechanical clauses:
   *
   *   NO UNEXPLAINED IDENTIFIER anywhere on either screen.
   *   NO EMPTY STATE WITHOUT A NEXT ACTION.
   *   EVERY ERROR SAYS WHAT TO DO rather than what failed.
   *
   * *A restaurant owner reads no documentation.* So: no *session*, no *token*, no *filter*,
   * no *query*, no *status*. An order is an order, a code is a code, and a date range is
   * *"from"* and *"to"*.
   */

  // ------------------------------------------------------------------------- history
  'staff.nav.history': 'Past orders',
  'staff.history.title': 'Past orders',
  'staff.history.lede': 'Every order from the last three months. Newest first.',
  // The filter controls. `staff.history.filter.status` labels a group of toggles, and the
  // status words themselves are `staff.board.status.*` -- one set of words for one set of
  // states, across the board, the kitchen screen and this screen.
  'staff.history.filter.status': 'Show only these',
  'staff.history.filter.from': 'From',
  'staff.history.filter.to': 'To',
  'staff.history.filter.reference': 'Order number',
  'staff.history.filter.reference.hint': 'Type any part of it.',
  'staff.history.filter.apply': 'Show these orders',
  'staff.history.filter.clear': 'Show everything',
  'staff.history.started': 'Started {time}',
  'staff.history.ready': 'Ready {time}',
  'staff.history.collected': 'Collected {time}',
  // The empty state has TWO forms, because the next action differs and `§12` requires one
  // that works. With filters on, the action is to clear them; with none on, the restaurant
  // genuinely has no past orders and the action is to start one.
  'staff.history.empty.title': 'No orders match what you asked for.',
  'staff.history.empty.body': 'Try a wider date range, or clear what you have chosen.',
  'staff.history.empty.action': 'Show everything',
  'staff.history.none.title': 'No orders yet.',
  'staff.history.none.body': 'Orders appear here once you have started a pager for one.',
  'staff.history.none.action': 'Start a pager',
  'staff.history.error.title': 'We could not load the past orders.',
  'staff.history.error.body': 'Nothing has changed. Try again.',
  'staff.history.offline.title': 'No connection.',
  'staff.history.offline.body': 'Try again once you are back on a network.',
  'staff.history.retry': 'Try again',
  // The range refusal, in a person's words rather than the server's. The server's own message
  // names a number of days; this one names what to do about it.
  'staff.history.range.tooWide': 'That is a long time to ask for. Choose three months or less.',
  'staff.history.range.backwards': 'The second date is before the first one.',

  // ------------------------------------------------------- issuing a sheet (10d)
  'staff.nav.issue': 'Start a pager',
  'staff.issue.title': 'Start a pager',
  'staff.issue.lede': 'Give the order a number, then print the sheet and hand it over.',
  'staff.issue.reference.label': 'What is the order number?',
  'staff.issue.reference.hint': 'Whatever you call it on the ticket, like A-14 or 102.',
  'staff.issue.create': 'Make the sheet',
  'staff.issue.creating': 'Making the sheet\u2026',
  // The sheet itself. The code is shown ONCE and the copy says so plainly -- the same
  // sentence `staff.devices.issued.warning` makes about a kitchen screen's code, because it
  // is the same fact about the same kind of value.
  'staff.issue.sheet.title': 'Print this and give it to the customer',
  'staff.issue.sheet.order': 'Order {reference}',
  'staff.issue.sheet.instruction': 'They scan the code, and we buzz their phone when it is ready.',
  'staff.issue.sheet.print': 'Print it',
  'staff.issue.sheet.done': 'Done',
  'staff.issue.sheet.once':
    'This code is shown once. If you lose the sheet, open the order from Past orders and make a new one \u2014 the old sheet then stops working.',
  'staff.issue.error.title': 'We could not start the pager.',
  'staff.issue.error.body': 'Nothing has changed. Try again.',
  'staff.issue.offline.title': 'No connection.',
  'staff.issue.offline.body': 'Nothing has been started. Try again once you are back on a network.',
  'staff.issue.reference.invalid':
    'An order number can use letters, digits, spaces and - _ # . / only.',

  // ----------------------------------------------------- re-issuing a sheet (10d)
  //
  // ⚠ MUHAMMAD's RULING, `QRPAGER-28 §2`: *a sheet is RE-ISSUED, never reprinted* -- and
  // `§2`'s first bullet says the screen must say this *"to the staff member in plain words,
  // not system vocabulary: printing a new sheet makes the old code stop working."* That
  // sentence is `staff.issue.reissue.body` almost verbatim, because it was already the right
  // one. Destructive in effect, so `§12` wants it confirmed and marked not reversible.
  'staff.issue.reissue': 'Make a new sheet for this order',
  'staff.issue.reissue.title': 'Make a new sheet?',
  'staff.issue.reissue.body':
    'Printing a new sheet makes the old code stop working. If the customer still has the old sheet, it will not buzz them any more. You cannot undo this.',
  'staff.issue.reissue.confirm': 'Make a new sheet',
  'staff.issue.reissue.keep': 'Keep the old one',
  'staff.issue.reissue.working': 'Making the new sheet\u2026',
  'staff.issue.reissue.finished':
    'That order is already finished, so a new sheet would buzz nobody. Start a new pager instead.',
  'staff.issue.reissue.expired': 'That order has run out. Start a new pager instead.',
  'staff.issue.reissue.gone': 'That order is not here any more. Check Past orders.',

  /* ================================================================================
   * TASK 10e — STAFF MANAGEMENT
   * ================================================================================
   *
   * `QRPAGER-29 §6`: *"The hard half here is the zero-technical-background bar on a REFUSAL:
   * 'you cannot give someone a role above your own' has to read as a sentence a restaurant
   * manager understands, in both launch languages, and a removal is destructive in effect so
   * `§12` requires it confirmed."*
   *
   * So the two refusals below are the copy that mattered most on this screen, and neither
   * uses the word *role*, *privilege*, *permission* or *senior*.
   */
  'staff.nav.team': 'Staff',
  'staff.team.title': 'Staff',
  'staff.team.lede': 'Everybody who can sign in to this restaurant.',
  'staff.team.you': 'This is you',
  'staff.team.empty.title': 'Nobody else works here yet.',
  'staff.team.empty.body': 'Add somebody who already has an account and they can sign in.',
  'staff.team.empty.action': 'Add somebody',
  'staff.team.add': 'Add somebody',
  'staff.team.add.title': 'Add somebody to this restaurant',
  'staff.team.add.email': 'Their email address',
  'staff.team.add.email.hint': 'They need an account already. We will not email them.',
  'staff.team.add.role': 'What can they do?',
  'staff.team.add.submit': 'Add them',
  'staff.team.add.working': 'Adding them\u2026',
  'staff.team.add.cancel': 'Cancel',
  // ⚠ ONE MESSAGE FOR AN UNKNOWN ADDRESS, AND IT MUST STAY ONE. `MemberController::store()`
  // answers 404 for an address that has no account AND for one this caller may not see,
  // deliberately, so the platform's `users` table is not an account-existence oracle. Two
  // sentences here would undo on the screen what the server is careful about.
  'staff.team.add.unknown':
    'We could not add that address. They need an account here first \u2014 ask them to sign up, then add them.',
  'staff.team.add.invalid': 'That does not look like an email address.',
  'staff.team.role.change': 'Change what {name} can do',
  'staff.team.role.working': 'Saving\u2026',
  'staff.team.remove': 'Remove {name}',
  'staff.team.remove.title': 'Remove {name}?',
  'staff.team.remove.body':
    'They will not be able to sign in to this restaurant any more. Their account itself is not deleted, and you can add them back.',
  'staff.team.remove.confirm': 'Remove them',
  'staff.team.remove.keep': 'Keep them',
  // ⚠ THE TWO REFUSALS `QRPAGER-29 §6` CALLS THE HARD HALF. Neither says *role*, *senior*,
  // *privilege* or *permission*, and each says what to DO.
  'staff.team.denied.seniority':
    'You cannot give somebody more than you have yourself. Ask an owner to do it.',
  'staff.team.denied.ownRole':
    'You cannot change what you yourself can do. Ask somebody else here to change it for you.',
  'staff.team.denied.lastOwner':
    'Every restaurant needs at least one owner. Make somebody else an owner first, then change this.',
  'staff.team.denied.title': 'You cannot change the staff.',
  'staff.team.denied.body': 'Only the owner or a manager can. Ask one of them.',
  'staff.team.error.title': 'We could not load the staff.',
  'staff.team.error.body': 'Nothing has changed. Try again.',
  'staff.team.offline.title': 'No connection.',
  'staff.team.offline.body': 'The list is unchanged. Try again once you are back on a network.',
  'staff.team.retry': 'Try again',
  'staff.team.failed': 'That did not go through. Nothing has changed \u2014 try again.',
} as const

/** Every copy id. Derived, never listed -- see the header. */
export type CopyKey = keyof typeof EN
