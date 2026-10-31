import { defineConfig } from 'vitest/config'

/**
 * One Vitest config for the workspace, with a project per package.
 *
 * `§6` requires >= 90% unit coverage on the code a task adds, and ADR-003 names Vitest as
 * the runner that produces that number for TypeScript. The thresholds below are the gate
 * clause, not a preference: a run under them exits non-zero.
 */
export default defineConfig({
  test: {
    projects: [
      {
        test: {
          name: 'tokens',
          root: './packages/tokens',
          environment: 'node',
          include: ['test/**/*.test.ts'],
        },
      },
      {
        test: {
          name: 'contracts',
          root: './packages/contracts',
          environment: 'node',
          include: ['test/**/*.test.ts'],
        },
      },
      {
        test: {
          name: 'ui',
          root: './packages/ui',
          // jsdom, not a real browser, and the distinction is load-bearing. The four
          // clauses that need a real browser -- a visible focus ring, a measured 44px
          // target, resolved contrast, and prefers-reduced-motion actually suppressing
          // motion -- are COMPUTED STYLES, which jsdom does not compute. Those are
          // Playwright's, in playwright.config.ts, and they are R-118's share for this task.
          environment: 'jsdom',
          include: ['test/**/*.test.{ts,tsx}'],
          setupFiles: ['./test/setup.ts'],
        },
      },
      {
        test: {
          name: 'design-tokens-rule',
          root: './tools/design-tokens',
          environment: 'node',
          include: ['test/**/*.test.mjs'],
        },
      },
    ],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json-summary'],
      reportsDirectory: './coverage',
      // What this task ADDS. apps/* are skeletons with no logic yet (their screens are
      // Tasks 9, 10 and 11), and a generated file's coverage measures its generator.
      include: [
        'packages/tokens/src/**/*.ts',
        'packages/ui/src/**/*.{ts,tsx}',
        'tools/design-tokens/*.mjs',
      ],
      exclude: ['**/generated/**', '**/*.d.ts', '**/index.ts', 'packages/tokens/src/fonts/**'],
      thresholds: {
        lines: 90,
        functions: 90,
        branches: 90,
        statements: 90,
      },
    },
  },
})
