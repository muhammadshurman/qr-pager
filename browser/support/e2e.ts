import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import type { BrowserContext, Page } from '@playwright/test'

/**
 * ================================================================================
 * WHAT THE JOURNEY SPECS SHARE. Task 9.
 * ================================================================================
 *
 * The real sessions come from `tools/browsers/bin/e2e`, which issues them through
 * `apps/api/bin/issue-pager-session` -- the REAL domain service, so the outbox row, the audit
 * row and the Redis projection are written exactly as in production.
 *
 * ⚠ IT THROWS RATHER THAN SKIPPING when the fixture is absent. *A reasoned skip is not a
 * measurement*: a journey spec that quietly skipped because `QRPAGER_E2E_SESSIONS` was unset
 * would make a green gate mean nothing, and the whole point of these files is that they cross
 * HTTP to a real server.
 */

export interface IssuedSession {
  readonly token: string
  readonly order_reference: string
  readonly session_id: string
  readonly tenant_id: string
  readonly status: string
}

export type SessionStatus = 'waiting' | 'ready' | 'completed' | 'cancelled' | 'expired'

let cached: Record<SessionStatus, IssuedSession> | null = null

/**
 * ⚠ LAZY AND MEMOISED, AND THE LAZINESS IS NOT A STYLE CHOICE. Called at module scope, the
 * throw below happens during COLLECTION, and Playwright reports it as *"No tests found"* --
 * the error text is printed but the headline says the opposite of what happened, which is the
 * failure shape this project calls most expensive. Called from inside a test, the same throw
 * fails that test by name and says what to run.
 */
export function sessions(): Record<SessionStatus, IssuedSession> {
  if (cached !== null) return cached

  const path = process.env.QRPAGER_E2E_SESSIONS

  if (path === undefined || path === '') {
    throw new Error(
      'QRPAGER_E2E_SESSIONS is unset. These specs drive the REAL API and need real issued ' +
        'tokens -- run them through `tools/browsers/bin/e2e`, which starts the stack and ' +
        'issues them. They do not skip: a green gate whose journey suite did not run is ' +
        'worse than a red one.',
    )
  }

  const repo = join(import.meta.dirname, '..', '..')
  cached = JSON.parse(readFileSync(join(repo, path), 'utf8')) as Record<
    SessionStatus,
    IssuedSession
  >

  return cached
}

/** The one origin the suite is served from, through QRPAGER-24's opt-in `/v1` rewrite. */
export function origin(): string {
  const base = process.env.QRPAGER_E2E_BASE

  if (base === undefined || base === '') {
    throw new Error('QRPAGER_E2E_BASE is unset. Run through `tools/browsers/bin/e2e`.')
  }

  return base
}

/**
 * Open the scan landing with a token in the FRAGMENT, exactly as a camera scan does.
 *
 * `PagerToken::qrPayload()` emits `{landing_url}#{token}`, so this is the real shape -- and
 * `§3` row 3 as amended is why it is not a path segment.
 */
export async function scan(page: Page, token: string): Promise<void> {
  await page.goto(`${origin()}/s#${token}`)
}

/** Every request the page made, so clause (b) can be asserted at RUNTIME and not only on the build. */
export function recordRequests(page: Page): string[] {
  const urls: string[] = []

  page.on('request', (request) => {
    urls.push(request.url())
  })

  return urls
}

/**
 * Make `Notification.requestPermission()` answer a chosen value, before any page script runs.
 *
 * ⚠ THE ONLY AVAILABLE MECHANISM FOR A DENIAL, and it is stated rather than hidden. Playwright
 * can GRANT `notifications` through `context.grantPermissions`, which is a real browser
 * permission grant -- but there is no API that denies one, in any engine. So the denial path
 * overrides the single function the browser dialog would have resolved, and nothing else: the
 * `permission` getter and every other capability stay the engine's own. What is being tested
 * is the client's response to a denial, which is the half this project owns.
 */
export async function answerPermissionWith(
  page: Page,
  answer: 'denied' | 'default',
): Promise<void> {
  await page.addInitScript((value: string) => {
    const notification = (globalThis as unknown as Record<string, unknown>).Notification

    if (typeof notification !== 'function') return

    Object.defineProperty(notification, 'requestPermission', {
      configurable: true,
      value: () => Promise.resolve(value),
    })
  }, answer)
}

/* ================================================================================
 * TASK 10a's STAFF FIXTURE. Two restaurants, and a staff account in the first.
 * ================================================================================
 *
 * `tools/browsers/bin/e2e` creates it with `apps/api/bin/issue-staff-fixture`, through the
 * real services -- so beta's session, membership and device-token ids are the ones the
 * application's own comparisons are written against.
 *
 * ⚠ BETA'S IDS ARE THE POINT. `QRPAGER-25 §0`'s invariant is proven by aiming at rows that
 * EXIST: a 404 for a row that was never there proves absence, not isolation, and
 * `RoleMatrixTest`'s own comment records that defect in the PHP suite.
 *
 * It THROWS rather than skipping, for the reason this file's header already gives.
 */

export interface StaffFixture {
  readonly alpha: {
    readonly id: string
    readonly name: string
    readonly slug: string
    readonly membership_id: string
  }
  readonly owner: { readonly email: string; readonly password: string; readonly name: string }
  readonly beta: {
    readonly id: string
    readonly name: string
    readonly session_id: string
    readonly membership_id: string
    readonly device_id: string
  }
}

let staffCached: StaffFixture | null = null

export function staffFixture(): StaffFixture {
  if (staffCached !== null) return staffCached

  const path = process.env.QRPAGER_E2E_STAFF_FIXTURE

  if (path === undefined || path === '') {
    throw new Error(
      'QRPAGER_E2E_STAFF_FIXTURE is unset. This spec needs two restaurants and a staff ' +
        'account in the first -- run it through `tools/browsers/bin/e2e`, which creates them ' +
        'with `apps/api/bin/issue-staff-fixture`. It does not skip: 10a ships on an invariant ' +
        'with no other evidence behind it.',
    )
  }

  const repo = join(import.meta.dirname, '..', '..')
  /*
   * ⚠ PARSED AS A LOOSE RECORD AND NARROWED, NOT CAST TO THE INTERFACE AND TRUSTED. A cast
   * tells the compiler every field is present, which is the one thing a file read from disk
   * cannot promise -- and it would make the check below provably dead code, which
   * `@typescript-eslint/no-unnecessary-condition` says in so many words.
   */
  const raw = JSON.parse(readFileSync(join(repo, path), 'utf8')) as Record<
    string,
    Record<string, string> | undefined
  >

  // ⚠ READ BACK, so a fixture that wrote a partial document cannot make a cross-tenant case
  // vacuous -- every assertion in that case is a NEGATIVE, and an empty id matches nothing.
  for (const [section, key] of [
    ['alpha', 'id'],
    ['owner', 'email'],
    ['beta', 'id'],
    ['beta', 'session_id'],
    ['beta', 'membership_id'],
    ['beta', 'device_id'],
  ] as const) {
    const value = raw[section]?.[key]

    if (typeof value !== 'string' || value === '') {
      throw new Error(
        `the staff fixture carries no ${section}.${key}, so a cross-tenant case would be vacuous`,
      )
    }
  }

  staffCached = raw as unknown as StaffFixture

  return staffCached
}

/** The cookies of the last successful staff login, reused across cases. See below. */
let staffCookies: Awaited<ReturnType<BrowserContext['cookies']>> | null = null

/**
 * Sign in as the staff fixture's owner, through the real login form on 10a's own screen.
 *
 * The PASSWORD path, deliberately: this helper is used by the `§12` and isolation cases,
 * which are about the shell rather than about the ceremony, and a WebAuthn ceremony needs CDP
 * and therefore one engine. `browser/staff-passkey.spec.ts` drives the passkey path.
 *
 * ================================================================================
 * ⚠ ONE LOGIN PER SESSION, REUSED — AND THE RATE LIMITER IS WHY
 * ================================================================================
 *
 * The first version logged in from scratch in every case: six projects times seven cases is
 * **forty-two logins from one IP in ten minutes**, against `config/ratelimit.php`'s
 * `auth-login` bucket. Measured consequence in the first full `gate:e2e` run: Chromium's
 * cases passed, then **five cases across firefox and webkit timed out at 30 s** waiting for a
 * shell that never arrived — because the eleventh login onward was a 429.
 *
 * **The limiter was right and the spec was wrong.** `R-063`'s own clause is that a limit is
 * proven by being driven, and `RateLimitTest` drives this bucket to a real 429 on purpose; a
 * browser suite that trips it is a suite modelling something no restaurant does. One person
 * signs in once and works a shift.
 *
 * So the cookies of the first successful login are kept for the process and re-added to each
 * new context. **It is verified rather than assumed**: after restoring, the shell has to
 * appear, and if it does not — which is exactly what happens after a case that locks the
 * screen and destroys the session server-side — the helper falls back to a real login and
 * refreshes the cache. A cache that silently served a dead session would turn every later
 * case into a sign-in-screen assertion failure with no hint of the cause.
 */
export async function signInAsStaff(page: Page): Promise<StaffFixture> {
  const fixture = staffFixture()
  const context = page.context()

  if (staffCookies !== null) {
    await context.addCookies(staffCookies)
    await page.goto('/staff')

    // A short wait, not the full 30 s: a restored session either works immediately or is
    // dead, and spending the whole budget here would make the fallback below useless.
    const restored = await page
      .waitForSelector('[data-staff-shell="true"]', { timeout: 5_000 })
      .then(() => true)
      .catch(() => false)

    if (restored) return fixture

    staffCookies = null
  }

  await page.goto('/staff')
  await page.getByRole('button', { name: /use a password instead|استخدم كلمة المرور/i }).click()
  await page.locator('input[name="email"]').fill(fixture.owner.email)
  await page.locator('input[name="password"]').fill(fixture.owner.password)
  await page.locator('[data-password-signin="true"]').click()
  await page.waitForSelector('[data-staff-shell="true"]', { timeout: 30_000 })

  staffCookies = await context.cookies()

  return fixture
}

/**
 * Forget the cached session.
 *
 * Called by a case that deliberately destroys the server session, so the next case does not
 * spend five seconds discovering it. Not required for correctness — the verification above
 * covers it — but it keeps a locking case from taxing the one after it.
 */
export function forgetStaffSession(): void {
  staffCookies = null
}
