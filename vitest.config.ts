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
        /**
         * ⚠ JSX IS CONFIGURED EXPLICITLY HERE AND IT HAS TO BE. `apps/web/tsconfig.json`
         * sets `"jsx": "preserve"`, which Next requires because Next's own compiler does the
         * transform -- so Vite reads that setting, leaves the JSX alone, and the parse fails
         * with *"Unexpected JSX expression"* on the first `<Component />` in a test. The
         * `packages/ui` project needs nothing because its tsconfig inherits
         * `"jsx": "react-jsx"` from the base.
         *
         * This is a TEST-RUNNER setting and changes nothing about the app's build.
         */
        oxc: { jsx: { runtime: 'automatic', importSource: 'react' } },
        test: {
          /**
           * Task 9. The customer PWA's own modules.
           *
           * jsdom, for the same reason `ui` uses it and with the same boundary: the journey
           * reducer, the cadence arithmetic, the token handling and the copy catalogue are
           * logic, and the screens' DOM output is structure. What jsdom CANNOT answer --
           * a computed outline, a rendered bounding box, a resolved colour pair, whether
           * `prefers-reduced-motion` actually suppressed anything, and whether a real engine
           * sends `Sec-Fetch-Dest` -- is Playwright's, in `browser/`.
           *
           * ⚠ AND `api.test.ts` IS DELIBERATELY NOT MOCKED. It drives a real `node:http`
           * server over a real socket, because *a test that crosses HTTP is not one that does
           * not*: the four answers the polling endpoint gives are distinguished by status
           * code AND problem type, and a stubbed `fetch` would assert the client's own
           * assumptions about them rather than its behaviour against a wire.
           */
          name: 'web',
          root: './apps/web',
          environment: 'jsdom',
          include: ['test/**/*.test.{ts,tsx}'],
          setupFiles: ['./test/setup.ts'],
        },
      },
      {
        /*
         * The browser suite's own pure helpers.
         *
         * ⚠ A VITEST PROJECT FOR FILES UNDER `browser/`, which looks odd until you ask where
         * else the cross-tenant sweep's leak RULE could be proven. Playwright selects its
         * specs by explicit `testMatch` lists, so nothing here is picked up by a browser run;
         * and `§11` QA M-3's finding was that the RULE was wrong, which a browser round cannot
         * show without introducing a real cross-tenant disclosure for it to detect.
         */
        test: {
          name: 'browser-support',
          root: './browser',
          environment: 'node',
          include: ['support/test/**/*.test.ts'],
        },
      },
      {
        /*
         * `apps/site` -- the local stand-in for the CDN's `Host` -> prefix mapping (ADR-005).
         *
         * `environment: 'node'` because this app has no DOM in it at all: it renders nothing,
         * ships no stylesheet and has one route handler. What it has is a MAPPING, and the
         * mapping is where `§3` row 11's invariant lives, so it is tested where the invariant
         * can be driven over every hostile `Host` a header can carry.
         */
        test: {
          name: 'site',
          root: './apps/site',
          environment: 'node',
          include: ['test/**/*.test.ts'],
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
        /**
         * `QRPAGER-49A`'s classifier and reporter -- the code that DECIDES what counts as a
         * finding. Muhammad's blocker 2: 355 lines, no tests and not in this list, *"and it is
         * the artefact that DECIDES"*, so a mis-partition would have made every number in the
         * report wrong with nothing able to notice. `tools/landing/test/` drives both.
         */
        'tools/landing/*.mjs',
        /**
         * Task 9's own code. `§6` asks for coverage "on code this task added", and the
         * previous comment's own lesson is that a figure measured over a SUBSET of it is not
         * that figure -- 96.73% once omitted ~990 lines of 8a's code.
         *
         * `apps/web/src/**` is every module of the customer journey AND its six screens.
         *
         * ⚠ WHAT IS DELIBERATELY NOT HERE, each with its reason rather than as an omission:
         *
         *   apps/web/app/**        three files of route plumbing: a `metadata` object, a
         *                          `viewport` object and a component that forwards two env
         *                          vars. There is no unit in them, and `build-surface.test.ts`
         *                          asserts what they actually produce -- the built HTML's meta
         *                          tags -- which is a stronger claim than a line count.
         *   apps/web/public/sw.js  a service worker runs in a worker global this coverage
         *                          provider cannot instrument. It is driven for real in
         *                          `browser/sw.spec.ts`, in all three engines, including the
         *                          rotation path with the handler removed.
         */
        'apps/web/src/**/*.{ts,tsx}',
        /*
         * `browser/support/isolation.ts` and nothing else under `browser/`: it is ordinary
         * task code with ordinary unit tests (`§11` QA M-3), so it belongs in the
         * denominator. The specs and `support/e2e.ts` do not -- they run under Playwright
         * against a real stack, and including them would measure a Vitest run's coverage of
         * code Vitest never executes.
         */
        'browser/support/isolation.ts',
        /*
         * Task 11's own code: `apps/site/src/**`, the mapping and the one signed GET.
         *
         * ⚠ `apps/site/app/**` IS NOT HERE, and the reason is the same one `apps/web/app/**`
         * carries two comments up: it is route plumbing -- a `dynamic` declaration and six
         * lines that read the environment and forward a request. There is no unit in it, and
         * what it actually produces is asserted by `site-surface.test.ts` (the route is
         * server-rendered on demand, never prerendered) and by `tools/gate/bin/site-isolation-proof`,
         * which drives the built app over real HTTP with real `Host` headers. Both are
         * stronger claims about that file than a line count.
         */
        'apps/site/src/**/*.ts',
      ],
      // ⚠ NO BLANKET GLOB OVER EVERY `index.ts`, AND THERE USED TO BE ONE. `§11` round one,
      // QA L-1: it excluded `apps/web/src/copy/index.ts` -- 41 lines holding `interpolate()`
      // and `translatorFor()`, which is not a barrel. The clause passed either way, so this is
      // the denominator-honesty shape 8a's review already filed rather than a threshold
      // failure: a coverage figure that excludes the code it claims to measure is not a
      // measurement of that code. Only real barrels are named.
      //
      // (Written as line comments on purpose: the glob this replaced contains the two
      // characters that end a block comment, which is how the first version of this note
      // broke the config file outright.)
      exclude: [
        '**/generated/**',
        '**/*.d.ts',
        'packages/*/src/index.ts',
        'packages/tokens/src/fonts/**',
      ],
      thresholds: {
        lines: 90,
        functions: 90,
        branches: 90,
        statements: 90,
      },
    },
  },
})
