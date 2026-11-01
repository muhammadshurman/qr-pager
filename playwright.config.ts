import { defineConfig, devices } from '@playwright/test'

/**
 * ================================================================================
 * THE CHECKS THAT NEED A REAL BROWSER -- 8a's SHARE OF R-118
 * ================================================================================
 *
 * `R-118` carries four clauses of `§12`'s accessibility bar as an accepted risk, because
 * measuring them needs a browser the machine did not have: **a visible focus ring, a 44px
 * minimum touch target, WCAG AA contrast, and `prefers-reduced-motion`.** All four are
 * COMPUTED STYLES -- a focused element's outline, a rendered box size, a resolved colour
 * pair, a media query -- and jsdom computes none of them.
 *
 * The approval comment of 2026-10-31 ruled where they close: *"Playwright is already the
 * locked E2E runner (§1), so `playwright install chromium` belongs inside this task's
 * workspace setup step, and 8a's share of R-118 closes here instead of becoming a third row
 * in PLAN.md §5."*
 *
 * ⚠ 8a's SHARE IS THE KIT. The admin panel's share is 8b's (QRPAGER-23), which is the task
 * that restyles `/platform` and `/studio` from these tokens -- and R-118's own resolution
 * requires the figures be taken against the TOKEN-STYLED panel, not the default one, so it
 * cannot close here. That split is stated rather than left for a reviewer to notice.
 *
 * AAA on the order-ready screen is deliberately NOT in this file. D4 fixes that pair in the
 * token source, so it is arithmetic over two values and is asserted in Vitest with no
 * browser -- which the approval comment says in so many words.
 *
 * Chromium only, and pinned. `playwright` is an exact 1.64.1 because its browser build is
 * tied to the package version: a range re-downloads a different Chromium and the suite then
 * tests against a browser nobody verified. Firefox and WebKit are Task 9's gate
 * ("E2E green on Chromium, Firefox and WebKit"), not this task's -- these four clauses are
 * about our own CSS resolving, not about engine differences.
 */
/**
 * ================================================================================
 * TASK 9 ADDS THE JOURNEY AND THE ONE-HANDED MEASUREMENT, ON THREE ENGINES
 * ================================================================================
 *
 * The two `chromium*` projects above are 8a's share of `R-118` and are UNCHANGED in name and
 * in subject -- the evidence in `PLAN §4a` refers to them by name. What Task 9 adds:
 *
 *   journey-{chromium,firefox,webkit}     `§3` row 9: *"Playwright E2E green on Chromium,
 *                                         Firefox AND WebKit"*. WebKit is where the iOS
 *                                         behaviour lives, and iOS is where Web Push is most
 *                                         constrained -- testing Chromium alone tests the
 *                                         easy case, which is the comment `QRPAGER-16`'s
 *                                         own 2026-10-25 amendment makes.
 *   one-handed-{engine}-{ltr,rtl}         `§3.1` row 9 (2): 390x844 at DPR 3, every screen in
 *                                         `§4.1`, in BOTH directions. The direction is a
 *                                         CONTEXT option (`locale`), so it has to be a
 *                                         project rather than a per-test flag -- the same
 *                                         reason `chromium-reduced-motion` is one.
 *
 * ⚠ THE JOURNEY PROJECTS NEED A RUNNING STACK AND THE KIT PROJECTS MUST NOT. The kit specs
 * load every fixture from the filesystem with `setContent`, which is what makes them runnable
 * from a fresh clone with nothing built. So the two sets are separated by `testMatch` /
 * `testIgnore` rather than by a skip -- *a reasoned skip is not a measurement*, and a journey
 * spec that quietly skipped when `QRPAGER_E2E_BASE` was unset would make a green gate mean
 * nothing. `tools/browsers/bin/e2e` is what stands the stack up.
 */

/** 390x844 at DPR 3 -- `§3.1` row 9's viewport, named once so the two clauses share it. */
const PHONE = { width: 390, height: 844 } as const
const PHONE_DPR = 3

/** The journey and the service worker: the specs that need the real stack. */
const STACK_SPECS = ['**/pager.spec.ts', '**/sw.spec.ts'] as const

/**
 * The engine measurement. It starts its own `node:http` server IN PROCESS, so it needs no
 * stack and no host networking -- which is what lets `R-194`'s answer be a gate-checked fact
 * rather than a transcript. It runs in all three engines and in `pnpm gate`.
 */
const ENGINE_SPECS = ['**/engines.spec.ts'] as const

/**
 * `R-100`'s passkey ceremony. It needs the API over https and an account to sign in as, so it
 * belongs to the stack set -- but it runs in ONE engine, because
 * `WebAuthn.addVirtualAuthenticator` is a CDP command and Firefox and WebKit expose no
 * equivalent in any runner. The spec skips itself on the other two, naming that reason.
 */
const PASSKEY_SPECS = ['**/passkey.spec.ts'] as const

/** The geometry measurement, which reaches its states through the real app. */
const ONE_HANDED = ['**/one-handed.spec.ts'] as const

const ENGINES = [
  { name: 'chromium', device: devices['Desktop Chrome'] },
  { name: 'firefox', device: devices['Desktop Firefox'] },
  { name: 'webkit', device: devices['Desktop Safari'] },
] as const

export default defineConfig({
  testDir: './browser',
  // Fail rather than silently pass if a test has no assertion path -- a browser test that
  // finds no element must not look like a pass.
  forbidOnly: true,
  retries: 0,
  reporter: [['list']],
  // ⚠ ONE WORKER. The journey specs drive ONE real API against ONE composed Postgres and
  // Redis, and `R-049` records what two concurrent runners against one database cost this
  // project once. The kit specs are pure and would parallelise fine; one worker for all of
  // them is the cheap, honest choice rather than a per-project setting nobody maintains.
  workers: 1,
  use: {
    ...devices['Desktop Chrome'],
    // No baseURL by default: every kit fixture is loaded from the filesystem with
    // setContent, so nothing there depends on a dev server being up.
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: [...STACK_SPECS, ...ONE_HANDED, ...ENGINE_SPECS, ...PASSKEY_SPECS],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // The same checks with the user's motion preference set. A separate project rather
      // than a test flag, because `prefers-reduced-motion` is a context-level setting in
      // Playwright and setting it per test would not affect CSS already parsed.
      name: 'chromium-reduced-motion',
      testIgnore: [...STACK_SPECS, ...ONE_HANDED, ...ENGINE_SPECS, ...PASSKEY_SPECS],
      use: { ...devices['Desktop Chrome'], reducedMotion: 'reduce' },
    },

    ...ENGINES.map((engine) => ({
      name: `engines-${engine.name}`,
      testMatch: [...ENGINE_SPECS],
      use: { ...engine.device },
    })),

    {
      // `R-100`, in the one engine that can present a virtual authenticator.
      name: 'passkey-chromium',
      testMatch: [...PASSKEY_SPECS],
      use: { ...devices['Desktop Chrome'], ignoreHTTPSErrors: true },
    },

    ...ENGINES.map((engine) => ({
      name: `journey-${engine.name}`,
      testMatch: [...STACK_SPECS],
      use: {
        ...engine.device,
        baseURL: process.env.QRPAGER_E2E_BASE,
        viewport: PHONE,
        deviceScaleFactor: PHONE_DPR,
        // A phone, because every screen in this journey is phone-first (`§4.1`) and a
        // desktop viewport would measure a layout no customer sees.
        isMobile: engine.name === 'chromium',
        hasTouch: true,
        // ⚠ THE CERTIFICATE IS SELF-SIGNED ON PURPOSE AND THE ORIGIN MUST STILL BE SECURE.
        // `navigator.serviceWorker` is undefined outside a secure context and the browsers
        // reach the host as `host.docker.internal`, which is on no engine's secure-origin
        // allow-list -- so the harness serves https through
        // `tools/browsers/bin/tls-proxy.mjs`. Accepting its certificate is what makes the
        // origin secure; it does NOT make the page insecure, and nothing about the bytes
        // changes.
        ignoreHTTPSErrors: true,
      },
    })),

    ...ENGINES.flatMap((engine) =>
      (
        [
          ['ltr', 'en-GB'],
          ['rtl', 'ar-JO'],
        ] as const
      ).map(([direction, locale]) => ({
        name: `one-handed-${engine.name}-${direction}`,
        testMatch: [...ONE_HANDED],
        use: {
          ...engine.device,
          baseURL: process.env.QRPAGER_E2E_BASE,
          viewport: PHONE,
          deviceScaleFactor: PHONE_DPR,
          isMobile: engine.name === 'chromium',
          hasTouch: true,
          ignoreHTTPSErrors: true,
          // ⚠ `ar-JO`, NOT `ar`. Real phones send a region subtag and `resolveLocale` matches
          // on the primary one for exactly that reason -- a project pinned to a bare `ar`
          // would pass while every Arabic phone in the launch region got English.
          locale,
        },
      })),
    ),
  ],
})
