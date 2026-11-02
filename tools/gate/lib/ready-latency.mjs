/**
 * =============================================================================
 * THE *under 2 s* MEASUREMENT. Five runs, staff click → customer rendered ready.
 * =============================================================================
 *
 * Driven by `tools/gate/bin/ready-latency-proof`, which has the method, the clause each part
 * of it comes from, and the arithmetic reason the median is reported rather than enforced.
 * This file is only the mechanism.
 *
 * ================================================================================
 * TWO PAGES, ONE CONTEXT EACH, AND THE REASON THEY CANNOT SHARE ONE
 * ================================================================================
 *
 * The staff client authenticates by a first-party session cookie; the customer client holds a
 * QR token in a URL fragment and no account at all — `§4 › Tenancy`'s *two authentication
 * surfaces, never confused*. In ONE browser context the staff cookie would ride along on the
 * customer page's own requests, which is not what a customer's phone does, and the figure
 * would describe a stack no real pair of devices produces. Two contexts is what two devices
 * are.
 *
 * ================================================================================
 * WHERE THE CLOCK STARTS AND STOPS, which is the whole of clause (a)
 * ================================================================================
 *
 *   t0  immediately before the staff `click()` resolves — the last instant at which nothing
 *       has been sent. Taken in THIS process rather than in the page, because a page's own
 *       `performance.now()` has a different origin per context and the two cannot be
 *       compared.
 *   t1  when the CUSTOMER page's ready state is visible — `[data-screen="ready"]`, which is
 *       Task 9's own marker for that screen, found by waiting rather than polled by us.
 *
 * So the figure includes the staff request, the transition's transaction, the Redis
 * projection, the customer's next poll landing, and React rendering the ready screen. It is a
 * SUPERSET of the product's own work, which is conservative in the right direction — the same
 * reasoning `§3.1` row 4's p99 method states for the polling endpoint.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { chromium } from 'playwright'

const REPO = join(import.meta.dirname, '..', '..', '..')
const RUNS = 5

/** The bar `§3` row 10 states, in milliseconds. Reported against, never thrown on. */
const BAR_MS = 2000

const base = process.env.QRPAGER_E2E_BASE
const fixture = JSON.parse(readFileSync(join(REPO, process.env.QRPAGER_E2E_STAFF_FIXTURE), 'utf8'))

/**
 * The five pagers, issued on the HOST by `tools/browsers/bin/e2e` before this ran.
 *
 * ⚠ **IT USED TO SHELL OUT TO `php bin/issue-board-pager` FROM HERE AND THAT CANNOT WORK:
 * this file runs inside the BROWSERS CONTAINER, whose image carries PHP 8.2 while `apps/api`
 * requires >= 8.4.1.** The first real run died on `platform_check.php` — *"Your Composer
 * dependencies require a PHP version >= 8.4.1. You are running 8.2.35."* — which is a
 * measurement that could never have been taken rather than a figure that came out wrong.
 *
 * `lighthouse-proof` already had the right shape: anything needing PHP happens on the host
 * and is passed in. That also removes the child process `§11` SECURITY M-5 was about — there
 * is no `APP_ENV` for a caller to get wrong, because there is no caller.
 *
 * One JSON object per line, five lines, and the harness refuses to continue without exactly
 * five.
 */
const pagers = readFileSync(join(REPO, process.env.QRPAGER_E2E_LATENCY_PAGERS), 'utf8')
  .split('\n')
  .filter((line) => line.trim() !== '')
  .map((line) => JSON.parse(line))

if (pagers.length !== RUNS) {
  console.error(
    `FATAL: expected ${String(RUNS)} issued pagers and found ${String(pagers.length)} — the method says five runs`,
  )
  process.exit(1)
}

const browser = await chromium.launch({
  // The suite's own flag, for the suite's own reason: the TLS proxy in front of this stack
  // carries a self-signed certificate, and `playwright.config.ts` passes Chromium the same.
  ignoreHTTPSErrors: true,
})

const failures = []
const figures = []

try {
  /*
   * ONE STAFF CONTEXT FOR ALL FIVE RUNS. Signing in per run would put a login inside the
   * window being measured five times and would tell us about Fortify rather than about the
   * board.
   */
  const staffContext = await browser.newContext({ ignoreHTTPSErrors: true })
  const staff = await staffContext.newPage()

  await staff.goto(`${base}/staff`, { waitUntil: 'domcontentloaded' })
  await staff.getByRole('button', { name: /use a password instead|استخدم كلمة المرور/i }).click()
  await staff.locator('input[name="email"]').fill(fixture.owner.email)
  await staff.locator('input[name="password"]').fill(fixture.owner.password)
  await staff.locator('[data-password-signin="true"]').click()
  await staff.waitForSelector('[data-staff-shell="true"]', { timeout: 60_000 })

  for (let run = 1; run <= RUNS; run += 1) {
    const pager = pagers[run - 1]

    // The customer, on its own context — a different device, with no staff cookie.
    const customerContext = await browser.newContext({ ignoreHTTPSErrors: true })
    const customer = await customerContext.newPage()

    await customer.goto(`${base}/s#${pager.token}`, { waitUntil: 'domcontentloaded' })

    /*
     * ⚠ THE CUSTOMER IS PARKED ON THE *WAITING* SCREEN BEFORE THE CLOCK STARTS, and this is
     * the one ordering mistake that would make every figure meaningless: a page still
     * resolving its token has not begun polling, so a click sent before it settles measures
     * page load and not notification. Task 9's own marker is waited for.
     */
    await customer.waitForSelector('[data-screen="waiting"]', { timeout: 60_000 })

    // The board row for THIS pager, and the panel opened — both outside the window, because
    // neither is part of the round trip the clause is about.
    const row = staff
      .locator('[data-board="true"] .qr-staff-list__item')
      .filter({ hasText: pager.order_reference })

    await row.locator('[data-action="open"]').waitFor({ state: 'visible', timeout: 60_000 })
    await row.locator('[data-action="open"]').click()
    await row.locator('[data-action="ready"]').waitFor({ state: 'visible', timeout: 60_000 })

    const t0 = Date.now()

    await row.locator('[data-action="ready"]').click()

    let reached = true

    try {
      await customer.waitForSelector('[data-screen="ready"]', { timeout: 60_000 })
    } catch {
      reached = false
    }

    const elapsed = Date.now() - t0

    if (reached) {
      figures.push(elapsed)
      console.log(`  run ${run}: ${String(elapsed)} ms`)
    } else {
      // THE LOAD-INVARIANT HALF, AND IT IS THE FATAL ONE. A customer page that never
      // reaches `ready` over polling is the product claim failing; the clock is only how
      // fast it happens to be today.
      failures.push(`run ${run}: the customer page never reached its ready state over polling`)
      console.log(`  run ${run}: NEVER REACHED READY`)
    }

    await customerContext.close()

    // The board is re-read before the next run's row is looked for, which the poll does on
    // its own; waiting for the row's own marker above is what actually synchronises them.
  }

  await staffContext.close()
} finally {
  await browser.close()
}

console.log()

if (figures.length > 0) {
  const sorted = [...figures].sort((a, b) => a - b)
  const median = sorted[Math.floor(sorted.length / 2)]

  /*
   * ⚠ THE LABEL COUNTS WHAT IT PRINTS — `§11` QA L-21. This said *"all five"* over however
   * many runs produced a figure, so a 3-of-5 run printed three numbers under a five-run
   * heading and a median over three. The script does exit 1 in that case, so the figure
   * would never have been used — but a transcript that reads as a five-run median is the
   * kind of artefact `§4a` keeps catching, and it would outlive the exit code.
   */
  console.log(
    `  all ${String(figures.length)} of ${String(RUNS)}      ${figures.map(String).join(', ')} ms`,
  )
  console.log(`  MEDIAN              ${String(median)} ms`)
  console.log(`  §3 row 10's bar     ${String(BAR_MS)} ms`)
  console.log(
    `  verdict             ${median <= BAR_MS ? 'MET' : 'NOT MET'} — reported, not fatal; see the script's header for why`,
  )
}

console.log(
  `  load-invariant      ${String(RUNS - failures.length)}/${String(RUNS)} runs reached the customer's ready state over POLLING, with no push`,
)

for (const failure of failures) console.error(`FATAL: ${failure}`)

process.exit(failures.length === 0 ? 0 : 1)
