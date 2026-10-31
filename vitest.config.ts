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
          // Both tool packages: the design-token rule and the gate's own checks. One
          // project rather than two, because they share an environment and neither has a
          // config of its own -- and a `tools/*/test` glob means a third tool package's
          // tests are picked up without editing this file.
          name: 'tools',
          root: './tools',
          environment: 'node',
          include: ['*/test/**/*.test.mjs'],
        },
      },
    ],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json-summary'],
      reportsDirectory: './coverage',
      /**
       * What this task ADDS. `apps/*` are skeletons with no logic yet (their screens are
       * Tasks 9, 10 and 11), and a generated file's coverage measures its generator.
       *
       * ⚠ THE DENOMINATOR WAS WRONG, AND THE NUMBER WAS THEREFORE NOT ABOUT THIS TASK.
       * `96.73%` was measured over `packages/tokens/src`, `packages/ui/src` and
       * `tools/design-tokens/*.mjs` -- and it omitted about 990 lines of this task's own
       * code:
       *
       *   packages/tokens/scripts/generate.mjs   620 lines  the generator for five artefacts
       *   packages/tokens/scripts/fetch-fonts.mjs 241 lines
       *   tools/design-tokens/bin/scan-css.mjs   129 lines  the CSS half of the lint rule
       *
       * `§6` asks for coverage "on code this task added", so a figure measured over a
       * subset of it is not that figure, whatever its value. The three are included now and
       * the number below is re-measured over the whole set.
       *
       * `tools/gate/bin/*` stays OUT, with the reason stated rather than left implicit:
       * `gate.mjs` is a process runner that exports no unit, and covering it would mean
       * running the gate inside its own coverage pass. Its step list is asserted by
       * `tools/gate/test/ci-parity.test.mjs` instead -- in both directions, against both
       * halves of CI -- which is a stronger claim about it than a line count.
       * `check-shell-evidence` and the three proof scripts are shell, which v8 cannot
       * instrument at all; `rule.test.mjs` drives them as processes.
       */
      include: [
        'packages/tokens/src/**/*.ts',
        'packages/tokens/scripts/*.mjs',
        'packages/ui/src/**/*.{ts,tsx}',
        'tools/design-tokens/*.mjs',
        'tools/design-tokens/bin/*.mjs',
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
