import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import designTokens from '@qrpager/eslint-plugin-design-tokens'

/**
 * One flat config for the workspace.
 *
 * Prettier owns whitespace and ESLint owns semantics, with no overlap -- no formatting rule
 * is enabled here, which is why there is no eslint-config-prettier to switch any off.
 */
export default tseslint.config(
  {
    ignores: [
      '**/node_modules/**',
      '**/.next/**',
      '**/coverage/**',
      '**/dist/**',
      'apps/api/**',
      // Generated. The gate byte-diffs each against its generator's output, so a lint fix
      // applied here would fail that diff with a change nobody authored.
      'packages/tokens/generated/**',
      'packages/contracts/src/generated/**',
      // Deliberately wrong, and read by the test that proves the rule rather than by a
      // lint pass. Linting them in the ordinary pass would make `pnpm lint` permanently red.
      'tools/design-tokens/fixtures/**',
    ],
  },

  js.configs.recommended,
  ...tseslint.configs.strictTypeChecked,
  ...tseslint.configs.stylisticTypeChecked,

  {
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      // A promise nobody awaited is the bug class that actually ships in this kind of code.
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      // `any` is how a strict config stops being strict.
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unsafe-assignment': 'error',
      'no-console': ['error', { allow: ['warn', 'error'] }],
      eqeqeq: ['error', 'always'],
    },
  },

  // The §12 rule. Scoped to the surfaces §12 is binding on -- the component kit, the PWA and
  // staff app, and the public renderer -- plus the token package's own tests. It deliberately
  // does NOT apply to packages/tokens/src, which is the file that DEFINES the values: a rule
  // forbidding literals in the single source would forbid the single source.
  {
    files: ['packages/ui/**/*.{ts,tsx}', 'apps/web/**/*.{ts,tsx}', 'apps/site/**/*.{ts,tsx}'],
    plugins: { '@qrpager/design-tokens': designTokens },
    rules: {
      '@qrpager/design-tokens/no-raw-color': 'error',
      '@qrpager/design-tokens/no-raw-spacing': 'error',
      '@qrpager/design-tokens/no-raw-font-size': 'error',
    },
  },

  // Plain JavaScript: the ESLint plugin, the CSS scanner and the token generator.
  //
  // TYPE-AWARE RULES ARE OFF HERE, not suppressed per error. They are .mjs on purpose --
  // an ESLint plugin is loaded by ESLint itself, and the token generator is run by `node
  // --experimental-strip-types` -- so they belong to no tsconfig project and the type-aware
  // rules have nothing to read. Leaving them on produced a parse error per file, which is
  // the shape of a rule that is not actually running.
  {
    files: ['**/*.mjs', '**/*.js'],
    extends: [tseslint.configs.disableTypeChecked],
    languageOptions: { parserOptions: { projectService: false } },
    rules: {
      'no-console': 'off',
      'no-undef': 'off',
    },
  },

  // Config files and the generator: Node, not browser code, and not components.
  {
    files: [
      '*.config.ts',
      'playwright.config.ts',
      'vitest.config.ts',
      '**/scripts/**/*.{ts,mts}',
      'browser/**/*.ts',
    ],
    rules: {
      'no-console': 'off',
      '@typescript-eslint/no-unsafe-assignment': 'off',
      // A browser test reads resolved values out of an engine; the engine's own types are
      // `string` and the assertions narrow them at run time.
      '@typescript-eslint/no-non-null-assertion': 'off',
    },
  },

  // Tests assert on literal values by nature -- a contrast ratio, a hex, a pixel count. The
  // §12 rule is about COMPONENTS carrying values, and a test that may not name the value it
  // is checking cannot check it.
  {
    files: ['**/*.test.{ts,tsx}', '**/test/**/*.{ts,tsx}'],
    rules: {
      '@qrpager/design-tokens/no-raw-color': 'off',
      '@qrpager/design-tokens/no-raw-spacing': 'off',
      '@qrpager/design-tokens/no-raw-font-size': 'off',
      '@typescript-eslint/no-non-null-assertion': 'off',
    },
  },
)
