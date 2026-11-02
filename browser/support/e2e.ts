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
  await navigate(page, `${origin()}/s#${token}`)
}

/**
 * Navigate to a page this application's SERVICE WORKER may claim mid-flight.
 *
 * ⚠ ONE RETRY, FOR ONE NAMED ERROR, WITH A MEASURED CAUSE — and it is deliberately not the
 * blanket `retries` that `playwright.config.ts` refuses.
 *
 * Measured on 2026-11-02 across ten `pnpm run gate:e2e` runs: **three** failed with
 *
 *     page.goto: Operation was cancelled; maybe frame was detached?
 *
 * every one of them in `journey-webkit`, in three different places (`sw.spec.ts:417`,
 * `pager.spec.ts:148`, and run 3 of `final-evidence`'s `frontend-three-green-runs`), while the
 * other 390 cases passed. It is not the application misbehaving and it is not slowness:
 *
 *   * `apps/web/public/sw.js` calls `self.skipWaiting()` on install (line 46) and
 *     `self.clients.claim()` on activate (line 203) — correct for a PWA, and what Task 9's
 *     own rotation clause requires;
 *   * `sw.spec.ts` installs, rotates and replaces workers, so a later navigation in the same
 *     project can begin while a NEW worker is activating;
 *   * a worker that claims a client mid-navigation supersedes that navigation, and WebKit
 *     reports the superseded one as cancelled.
 *
 * So the navigation was interrupted by a documented, deliberate part of the application, and
 * re-issuing it is the honest response: the page is reached, every assertion after it is
 * untouched, and nothing about the result is retried. The guard is narrow on purpose — this
 * error text only, one attempt only — because what it must not become is a way for a REAL
 * navigation failure to pass on the second try.
 *
 * ⚠ AND IT IS A WORKAROUND, NOT A FIX: the underlying gap is `§5` `R-209` — the TypeScript
 * half has no attribution path, so a flake here cannot be classified the way `bin/classify-reds`
 * classifies `R-191` by exit code. Three strikes tonight cost three ~7-minute re-runs and one
 * `final-evidence` round to tell apart from a regression.
 */
export async function navigate(page: Page, url: string): Promise<void> {
  try {
    await page.goto(url)

    return
  } catch (error) {
    const cancelled = /Operation was cancelled|frame was detached/i.test(String(error))

    if (!cancelled) throw error
  }

  // The service worker claimed the page mid-navigation. Ask once more, now that it has.
  await page.goto(url)
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
    /** 10b: alpha's own live pagers, so the board has rows to render and act on. */
    readonly session_id: string
    readonly order_reference: string
    readonly second_session_id: string
    readonly second_order_reference: string
    /**
     * The RAW token of the first pager, for the *under 2 s* measurement only.
     *
     * That figure is staff click → the CUSTOMER's rendered ready state, so the harness has
     * to open the customer page for a session the staff board can act on. It exists once, at
     * generation, which is why the fixture carries it and nothing reads it back from the
     * database — `§4 › Data` makes that impossible by design.
     */
    readonly token: string
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
    /*
     * ⚠ 10b's FOUR, AND THEY ARE HELD TO THE SAME READ-BACK FOR THE SAME REASON ONE LAYER
     * OVER. The cross-tenant cases above are negatives, and an empty id makes them pass for
     * free. The BOARD cases are positives — a row rendered, a target measured, a transition
     * driven — and an empty id makes those fail in a way that looks like the screen is
     * broken rather than like the fixture is. Both directions want the id to exist.
     */
    ['alpha', 'session_id'],
    ['alpha', 'order_reference'],
    ['alpha', 'second_session_id'],
    ['alpha', 'token'],
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
/**
 * Which screen `/staff` settled on, decided by whichever marker appears FIRST.
 *
 * ⚠ A RACE AND NOT A FIXED WAIT, AND THE FIXED WAIT COST A GATE RUN. The first version gave
 * the restored session five seconds to produce a shell and logged in from scratch if it did
 * not. On WebKit under load the identity round trip took longer than that, so the probe
 * reported "not restored" for a session that was perfectly good — and by the time the helper
 * looked for *"Use a password instead"*, the page had finished loading the SHELL and there was
 * no sign-in control to click. The case spent its whole 90 s budget waiting for a button that
 * could not appear, and reported a timeout on a line that was not the problem.
 *
 * Racing the markers removes the timing judgement entirely: the page is in exactly one of
 * these states and the first selector to match says which. Each side swallows its own
 * rejection, or the loser's timeout surfaces as an unhandled rejection after the test has
 * moved on.
 *
 * ⚠⚠ IT RACES **FOUR** STATES AND IT USED TO RACE TWO, WHICH MADE ITS OWN FAILURE MESSAGE
 * UNREADABLE — MEASURED TWICE ON 2026-11-02, once in CI (`staff-webkit-ltr`, *"renders the
 * restaurant by NAME"*, 390 passed / 1 failed) and once locally at run 2 of 3 of
 * `tools/gate/bin/three-green-runs`. Both reported the same thing:
 *
 *     /staff settled on neither the shell nor sign-in within 60s (shell=never, signin=never)
 *
 * **`StaffApp` has five phases and only two of them render either marker.** `ready` renders
 * the shell, `signed-out` renders the sign-in surface — and `loading`, `offline` and `error`
 * render a `StaffState`, which carries neither. So ONE transient 5xx or one unreachable
 * identity call parks the page in a state this helper cannot see, and it then waits the full
 * sixty seconds for a marker that can never appear and reports a TIMEOUT. The message blames
 * the clock for what is actually a failed API call, which is the most expensive shape of red
 * this project knows: it names the wrong thing, so it gets diagnosed in the wrong place.
 *
 * `data-state` is on the primitive (`error`, `offline`, `loading`, …), so the unresolved
 * phases are observable and are now raced alongside the two good ones. A page in `error`
 * fails this helper in however long it takes to render — two seconds, typically — and the
 * message says which state it reached. `loading` is deliberately NOT a loser: it is the
 * transient every page passes through on its way to one of the other four.
 *
 * ⚠ AND THE BUDGET IS NOT RAISED. 60 s was not the problem and raising it would have hidden
 * the real one for longer — the opposite of `§11` round two's QA M-9 ruling, which raised a
 * timeout for a case measured at 27.8 s against a 30 s bound. Nothing here was two seconds
 * short of rendering; it was in a state that renders nothing.
 */
async function settledOn(page: Page): Promise<'shell' | 'signin'> {
  const race = (selector: string, label: string) =>
    page
      .waitForSelector(selector, { timeout: 60_000 })
      .then(() => label)
      .catch(() => 'never')

  const shell = race('[data-staff-shell="true"]', 'shell')
  const signin = race('[data-passkey-signin="true"]', 'signin')
  const errored = race('[data-state="error"]', 'error')
  const offline = race('[data-state="offline"]', 'offline')

  const first = await Promise.race([shell, signin, errored, offline])

  if (first === 'shell' || first === 'signin') return first

  /*
   * An unresolved phase, or nothing at all. Either way the message carries what the page
   * actually showed and, when it is an unresolved phase, the text the person would have read
   * -- which for `error` and `offline` is the shell's own copy and is usually the whole
   * diagnosis.
   */
  if (first === 'error' || first === 'offline') {
    const said = (await page.locator(`[data-state="${first}"]`).textContent()) ?? ''

    /*
     * ⚠ AND IT ASKS THE API WHAT HAPPENED, FROM THE PAGE'S OWN ORIGIN AND WITH THE PAGE'S OWN
     * COOKIES. Knowing the app reached `error` narrows it to *"`/v1/auth/me` or `/v1/tenant`
     * answered something `fetchIdentity`/`fetchTenant` calls `server`"* — which is a 5xx, a
     * 404, or a 200 whose shape the parser refuses — and those have completely different
     * causes. Measured on 2026-11-02: the API log held no 5xx at the moment of the failure, so
     * the answer is one of the other two and a message that cannot tell them apart costs
     * another whole run to find out.
     *
     * The probe is diagnostic only: it runs after the failure is already decided, it cannot
     * turn a red green, and its own errors are swallowed so a dead server reports the state
     * rather than a second exception on top of it.
     */
    const probe = await page
      .evaluate(async () => {
        const look = async (path: string) => {
          try {
            const response = await fetch(path, { headers: { Accept: 'application/json' } })
            const body = (await response.text()).slice(0, 200)

            return `${path} -> ${String(response.status)} ${body}`
          } catch (error) {
            return `${path} -> unreachable (${String(error)})`
          }
        }

        return [await look('/v1/auth/me'), await look('/v1/tenant')].join(' | ')
      })
      .catch(() => 'the probe itself could not run')

    throw new Error(
      `/staff reached the ${first} state rather than the shell or sign-in: "${said.trim().slice(0, 160)}" ` +
        `— the identity or tenant call failed, so this is an API failure and not a timeout. Probe: ${probe}`,
    )
  }

  // All four timed out: the page reached no known state at all, which IS a load failure.
  const [a, b, c, d] = await Promise.all([shell, signin, errored, offline])

  throw new Error(
    `/staff reached no known state within 60s (shell=${a}, signin=${b}, error=${c}, offline=${d})`,
  )
}

export async function signInAsStaff(page: Page): Promise<StaffFixture> {
  const fixture = staffFixture()
  const context = page.context()

  if (staffCookies !== null) {
    await context.addCookies(staffCookies)
  }

  await page.goto('/staff')

  if ((await settledOn(page)) === 'shell') {
    // Either the restored session worked, or this context was already authenticated. Both are
    // the state the caller asked for, so there is nothing to do.
    return fixture
  }

  staffCookies = null

  await page.getByRole('button', { name: /use a password instead|استخدم كلمة المرور/i }).click()
  await page.locator('input[name="email"]').fill(fixture.owner.email)
  await page.locator('input[name="password"]').fill(fixture.owner.password)
  await page.locator('[data-password-signin="true"]').click()
  await page.waitForSelector('[data-staff-shell="true"]', { timeout: 60_000 })

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
