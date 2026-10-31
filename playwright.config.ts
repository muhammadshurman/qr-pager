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
export default defineConfig({
  testDir: './browser',
  // Fail rather than silently pass if a test has no assertion path -- a browser test that
  // finds no element must not look like a pass.
  forbidOnly: true,
  retries: 0,
  reporter: [['list']],
  use: {
    ...devices['Desktop Chrome'],
    // No baseURL: every fixture is loaded from the filesystem with setContent, so nothing
    // here depends on a dev server being up -- which is what makes it runnable from a
    // fresh clone with no app built.
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    {
      // The same checks with the user's motion preference set. A separate project rather
      // than a test flag, because `prefers-reduced-motion` is a context-level setting in
      // Playwright and setting it per test would not affect CSS already parsed.
      name: 'chromium-reduced-motion',
      use: { ...devices['Desktop Chrome'], reducedMotion: 'reduce' },
    },
  ],
})
