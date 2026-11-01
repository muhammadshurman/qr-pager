import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import type { Page } from '@playwright/test'

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
