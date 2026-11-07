/**
 * =============================================================================
 * 10c's NAMED INVARIANT, DRIVEN. `QRPAGER-27 §0`.
 * =============================================================================
 *
 * Driven by `tools/gate/bin/kds-resync-proof`, which carries the clause, the reason the
 * browser runs natively rather than in the container, and the vacuity guards. This file is
 * only the mechanism.
 *
 * The clause, verbatim:
 *
 *   *"A KDS that loses the network mid-shift and reconnects re-sends NO notification and shows
 *   NO duplicate row. Asserted by dropping the network in a real browser, counting dispatches
 *   on the server before and after the reconnect, and asserting the two counts are equal."*
 *
 * =============================================================================
 * THE SEQUENCE, AND WHY THE STAFF ACTION HAPPENS *INSIDE* THE OUTAGE
 * =============================================================================
 *
 *   1. a pager is waiting in alpha, and the kitchen screen is showing it;
 *   2. the kitchen screen's context GOES OFFLINE — `context.setOffline(true)`, which is
 *      Playwright's own network interception and not a flag the page can disagree with;
 *   3. **while it is offline, a staff member marks that order ready.** One dispatch is written.
 *      This is what *mid-shift* means: the world moved while the screen could not see it;
 *   4. the dispatch count is taken — this is `before`, and it is taken AFTER the staff action
 *      and IMMEDIATELY BEFORE the reconnect, which is what makes the comparison the clause's
 *      own. A count taken before step 3 would differ from `after` by the one dispatch a staff
 *      member legitimately caused, and the assertion would have to subtract it — an oracle
 *      with an allowance in it is not an oracle;
 *   5. the context comes back online. `KdsApp`'s `online` handler polls immediately;
 *   6. the screen is waited on until it shows that order as READY, so the resync has provably
 *      completed before anything is counted — not a sleep, which would make the figure a
 *      measure of this script's patience;
 *   7. the dispatch count is taken again. `after === before`.
 *
 * Step 3 is what makes the proof discriminating rather than vacuous. With nothing happening
 * during the outage, a screen that re-rendered its cache would pass: there would be no change
 * for it to miss and no row for it to duplicate. With a transition inside the window, a
 * reconnect that replayed anything shows up at the outbox, and a reconnect that MERGED shows
 * up as two rows for one reference — the two halves of the clause, each with its own failure.
 *
 * =============================================================================
 * THE VACUITY GUARDS, BECAUSE EVERY ASSERTION BELOW IS AN EQUALITY OR AN ABSENCE
 * =============================================================================
 *
 * `0 === 0` passes against a stack where nothing works at all, and `1 row` passes against a
 * screen that rendered one stale row and never resynced. So the proof also requires:
 *
 *   - `before >= 1` — a dispatch really was written during the outage, so the counter is
 *     reading a live table rather than answering zero to everything;
 *   - the screen reached READY after the reconnect — so the resync demonstrably happened, and
 *     "no duplicate row" is a statement about a screen that updated rather than one that froze;
 *   - the screen was OFFLINE in a way it noticed — its own offline marker is waited for before
 *     the staff action, so a `setOffline` that silently did nothing cannot pass.
 *
 * Each of those FAILS the proof rather than warning, because each one is the difference
 * between a measurement and a green tick.
 *
 * =============================================================================
 * ⚠ IT WAS REVERT-PROVED, AND THE REVERT ONLY REACHES ONE OF THE TWO HALVES — SAID
 *   OUT LOUD, BECAUSE A REVERT THAT DECLARES NO REQUIRED RED PROVES NOTHING
 * =============================================================================
 *
 * Measured on 2026-11-02 at `41af5a3`, with `reconcile` reverted to the first merge shape its
 * own header names, `[...cached, ...answer]`:
 *
 *   REDDENED, the DUPLICATE-ROW half. The screen rendered two `KDS-RESYNC` rows and the proof
 *   failed, exit 1. (The first attempt failed as an uncaught Playwright strict-mode error
 *   rather than as a reported clause failure; that is fixed below, at the `.first()`, and the
 *   count is now the assertion.)
 *
 *   NOT REACHED, the DISPATCH-COUNT half. A client-side merge changes what is RENDERED and
 *   writes nothing, so `before === after` held throughout that revert — correctly. The two
 *   halves of the clause have two different failure modes and one revert cannot exercise both.
 *
 * What stands behind the dispatch half instead, named rather than implied:
 *
 *   - the `before >= 1` guard above, which fails if the counter is answering zero to
 *     everything — the way this half would go vacuous;
 *   - `apps/api/tests/Feature/Staff/KdsEndpointsTest.php`'s *"dispatches NOTHING when a
 *     kitchen screen attempts every transition"*, which counts outbox rows before and after
 *     five refused writes and is the hermetic form of the same comparison;
 *   - and the structural half: `apps/web/src/kds/api.ts` has no write in it, asserted against
 *     the recorded `fetch` calls by `apps/web/test/kds-api.test.ts`, and the device token
 *     cannot notify whatever a client asks (`Ability::issuableToDevice()`, a closed list of
 *     one, driven over HTTP by `RoleMatrixTest`).
 */

import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { chromium } from 'playwright'

const REPO = join(import.meta.dirname, '..', '..', '..')
const API = join(REPO, 'apps', 'api')

const base = process.env.QRPAGER_E2E_BASE
const fixture = JSON.parse(readFileSync(join(REPO, process.env.QRPAGER_E2E_STAFF_FIXTURE), 'utf8'))
const kds = JSON.parse(readFileSync(join(REPO, process.env.QRPAGER_E2E_KDS_TOKEN), 'utf8'))
const pager = JSON.parse(readFileSync(join(REPO, process.env.QRPAGER_E2E_KDS_PAGER), 'utf8'))

const TENANT = fixture.alpha.id

/** A long timeout for everything: this proof deliberately waits out a poll backoff. */
const WAIT = 60_000

/**
 * The server-side dispatch count for one session.
 *
 * ⚠ SHELLED OUT TO PHP, ON THE HOST, AND THAT IS THE WHOLE REASON THIS PROOF DOES NOT RUN
 * THROUGH `tools/browsers/bin/browsers`. `QRPAGER-27 §5` requires a *server-side* count on
 * both sides of the reconnect; the only server-side reader available is `apps/api`, which
 * requires PHP >= 8.4.1, and the browsers image carries 8.2 — `ready-latency.mjs` records
 * what that cost when it was discovered at run time. The wrapper script explains the
 * trade this makes (one engine, natively) and why it is the right one here.
 *
 * `execFileSync` with an ARGUMENT ARRAY and no shell, so the two uuids cannot be
 * reinterpreted as anything else.
 */
function dispatches(sessionId) {
  const out = execFileSync('php', ['bin/count-dispatches', TENANT, sessionId], {
    cwd: API,
    encoding: 'utf8',
  })

  const parsed = JSON.parse(out.trim())

  if (typeof parsed.dispatches !== 'number') {
    throw new Error(`count-dispatches answered no number: ${out.trim()}`)
  }

  return parsed.dispatches
}

const failures = []

function require_(condition, message) {
  if (!condition) failures.push(message)
}

const browser = await chromium.launch({ ignoreHTTPSErrors: true })

try {
  /*
   * TWO CONTEXTS, AND THEY ARE TWO DEVICES. `setOffline` is a per-CONTEXT setting, which is
   * exactly what this proof needs: the kitchen screen loses the network and the staff tablet
   * does not. One context could not express that at all — and it would also put the staff
   * session cookie on the kitchen screen's requests, which is the confusion
   * `apps/web/src/kds/api.ts` sends `credentials: 'omit'` to prevent.
   */
  const kdsContext = await browser.newContext({ ignoreHTTPSErrors: true })
  const screen = await kdsContext.newPage()

  const staffContext = await browser.newContext({ ignoreHTTPSErrors: true })
  const staff = await staffContext.newPage()

  /* ------------------------------------------------- 1. the kitchen screen, set up and live */

  await screen.goto(`${base}/kds`, { waitUntil: 'domcontentloaded' })
  await screen.waitForSelector('[data-kds-view="setup"]', { timeout: WAIT })

  // Pasted into the real field and submitted through the real form, which is how a kitchen
  // screen is set up -- `kds/storage.ts` has why the token is not in the URL.
  await screen.locator('[data-kds-code="true"]').fill(kds.token)
  await screen.getByRole('button', { name: /show the orders|اعرض الطلبات/i }).click()

  await screen.waitForSelector('[data-kds-view="ready"]', { timeout: WAIT })

  const row = screen.locator(`[data-kds-order="${pager.order_reference}"]`)

  await row.waitFor({ state: 'visible', timeout: WAIT })

  console.log(`  the screen is live and showing ${pager.order_reference}`)

  // The row is there EXACTLY ONCE before anything else happens, so "no duplicate row" after
  // the reconnect is a comparison against a known state rather than against an assumption.
  const rowsBefore = await screen.locator(`[data-kds-order="${pager.order_reference}"]`).count()

  require_(
    rowsBefore === 1,
    `before the outage the screen showed ${String(rowsBefore)} rows for ${pager.order_reference}, not 1`,
  )

  /* --------------------------------------------------------------- 2. the network goes away */

  await kdsContext.setOffline(true)

  /*
   * ⚠ WAITED FOR THE SCREEN'S OWN OFFLINE MARKER, NOT FOR A DURATION. A `setOffline` that
   * silently did nothing — a Playwright version change, a service worker serving from cache —
   * would leave the screen online and every assertion after this would be about a screen that
   * never lost the network. The marker is rendered only by a FAILED poll, so waiting for it
   * proves the interception reached the application.
   */
  await screen.waitForSelector('[data-kds-offline="true"]', { timeout: WAIT })

  console.log('  the screen has lost the network and says so')

  /* ---------------------------------------- 3. mid-shift: a staff member marks the order ready */

  await staff.goto(`${base}/staff`, { waitUntil: 'domcontentloaded' })
  await staff.getByRole('button', { name: /use a password instead|استخدم كلمة المرور/i }).click()
  await staff.locator('input[name="email"]').fill(fixture.owner.email)
  await staff.locator('input[name="password"]').fill(fixture.owner.password)
  await staff.locator('[data-password-signin="true"]').click()
  await staff.waitForSelector('[data-staff-shell="true"]', { timeout: WAIT })

  const staffRow = staff
    .locator('[data-board="true"] .qr-staff-list__item')
    .filter({ hasText: pager.order_reference })

  await staffRow.locator('[data-action="open"]').waitFor({ state: 'visible', timeout: WAIT })
  await staffRow.locator('[data-action="open"]').click()
  await staffRow.locator('[data-action="ready"]').waitFor({ state: 'visible', timeout: WAIT })
  await staffRow.locator('[data-action="ready"]').click()

  // Waited for the STAFF board to show it ready, so the transition has demonstrably committed
  // before the count is taken. Counting against an in-flight request would make `before`
  // race the very dispatch it is supposed to include.
  await staff
    .locator(`[data-pager-status="ready"]`)
    .filter({ hasText: pager.order_reference })
    .waitFor({ state: 'visible', timeout: WAIT })

  console.log('  a staff member marked it ready while the screen was offline')

  /* ------------------------------------------------ 4. the count, immediately before reconnect */

  const before = dispatches(pager.session_id)

  console.log(`  dispatches before the reconnect: ${String(before)}`)

  /*
   * ⚠ THE VACUITY GUARD THAT MATTERS MOST. `after === before` passes trivially when both are
   * zero, which is what a stack with a broken outbox, a wrong tenant or a mistyped session id
   * would produce — and it would read as a green proof of the clause.
   */
  require_(
    before >= 1,
    `no dispatch was written for ${pager.session_id} during the outage (count ${String(before)}), ` +
      'so comparing the two counts would prove nothing',
  )

  /* ------------------------------------------------------------- 5. the network comes back */

  await kdsContext.setOffline(false)

  /*
   * ⚠ THE `online` EVENT IS DISPATCHED EXPLICITLY AS WELL AS THE FLAG CLEARED.
   *
   * `context.setOffline(false)` restores the network, and whether Chromium then fires a DOM
   * `online` event at the page is an implementation detail of the harness rather than a
   * contract. The application's reconnect path is its `online` listener, so the proof makes
   * sure that path is exercised rather than depending on Playwright to trigger it — and if
   * the browser fires its own too, the handler runs twice, which is itself worth proving
   * harmless: two polls are two GETs, and the clause is about dispatches.
   */
  await screen.evaluate(() => {
    window.dispatchEvent(new Event('online'))
  })

  /* -------------------------------------- 6. the resync has provably completed before counting */

  /*
   * Waited on the ROW's own status, not on the absence of the offline marker: the screen could
   * clear that marker on a poll whose answer it then mishandled. The order showing READY is
   * the resync having actually landed, which is the state this proof needs to be in before it
   * asks the server anything.
   *
   * ⚠ `.first()`, AND THE REASON IS A MEASURED REVERT RATHER THAN CAUTION. With `reconcile`
   * reverted to the naive `[...cached, ...answer]` this proof DID redden — which is what a
   * revert is for — but it reddened as an UNCAUGHT Playwright error:
   *
   *     locator.waitFor: Error: strict mode violation:
   *     locator('[data-kds-order="KDS-RESYNC"]').filter(…) resolved to 2 elements
   *
   * That is the right detection arriving in the wrong shape. The process died here, so the
   * `failures` list was never printed, the dispatch comparison below never ran, and the
   * transcript reported a locator problem rather than *a resync duplicated a row* — the
   * clause this script exists to report on. A gate you cannot read afterwards is a gate you
   * have to re-run to learn anything from.
   *
   * So the WAIT is non-strict and the COUNT is the assertion, a few lines down, where it is
   * reported in the clause's own words. The duplicate is still caught — by the explicit
   * count, which is also the thing `QRPAGER-27 §4` actually asks for (*"the row identity is
   * asserted, not eyeballed"*) rather than by a side effect of Playwright's strictness.
   */
  await screen
    .locator(`[data-kds-order="${pager.order_reference}"]`)
    .filter({ hasText: /ready|جاهز/i })
    .first()
    .waitFor({ state: 'visible', timeout: WAIT })

  console.log('  the screen has resynced and shows the order as ready')

  /* ------------------------------------------------------------------ 7. the two assertions */

  const after = dispatches(pager.session_id)

  console.log(`  dispatches after the reconnect:  ${String(after)}`)

  require_(
    after === before,
    `THE CLAUSE FAILED: ${String(before)} dispatches before the reconnect and ${String(after)} after. ` +
      'A reconnect re-sent a notification.',
  )

  const rowsAfter = await screen.locator(`[data-kds-order="${pager.order_reference}"]`).count()

  require_(
    rowsAfter === 1,
    `THE CLAUSE FAILED: after the reconnect the screen shows ${String(rowsAfter)} rows for ` +
      `${pager.order_reference}, not 1. A resync duplicated a row.`,
  )

  /*
   * AND THE WHOLE BOARD CARRIES NO DUPLICATE OF ANY REFERENCE, not only of the one this proof
   * drove. `reconcile`'s dedupe is keyed by id; this counts by the REFERENCE a person can
   * actually see, which is the thing `QRPAGER-27 §4` means by *"the row identity is asserted,
   * not eyeballed"*.
   */
  const references = await screen
    .locator('[data-kds-order]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-kds-order')))
  const unique = new Set(references)

  require_(
    unique.size === references.length,
    `the screen shows ${String(references.length)} rows for ${String(unique.size)} distinct orders: ` +
      references.join(', '),
  )

  /*
   * ⚠ THE LABEL STATES WHAT WAS MEASURED, NOT WHAT WAS HOPED FOR — `§11` QA L-21's defect,
   * which `ready-latency.mjs` carries in its own words (*"the label counts what it prints"*).
   *
   * The first version printed *"all distinct"* unconditionally, after the checks rather than
   * instead of them, so the revert run's transcript read:
   *
   *     the screen shows 9 row(s), all distinct: KDS-RESYNC, A-102, A-101, KDS-RESYNC, …
   *
   * The proof still failed and still said why, two lines down — but the artefact carried a
   * sentence contradicted by the list beside it, and a transcript outlives the exit code it
   * was printed next to.
   */
  console.log(
    `  the screen shows ${String(references.length)} row(s) for ` +
      `${String(unique.size)} distinct order(s): ${references.join(', ')}`,
  )

  await kdsContext.close()
  await staffContext.close()
} finally {
  await browser.close()
}

console.log()

if (failures.length > 0) {
  console.log('FAILED:')
  for (const failure of failures) console.log(`  - ${failure}`)
  process.exit(1)
}

console.log(
  '  VERDICT             MET — equal dispatch counts across a real reconnect, one row per order',
)
process.exit(0)
