/**
 * Declarations for the non-TypeScript modules this app imports for their side effects.
 *
 * ⚠ WHY THIS FILE EXISTS, measured rather than guessed. **TypeScript 6.0 turns on
 * `noUncheckedSideEffectImports` by default** -- it is not in our tsconfig and
 * `tsc --showConfig` does not list it, so it is a new default rather than a setting anyone
 * chose. Under it, `import './globals.css'` must resolve to a module with declarations, and
 * **Next 15 ships none for `*.css`**: Next resolves stylesheets in the bundler and has
 * never needed a TS declaration for them. The build failed with "Cannot find module or type
 * declarations for side-effect import of './globals.css'".
 *
 * The flag is kept ON and the gap is declared here, rather than switching the flag off. Off,
 * a genuinely mistyped side-effect import -- `import './globasl.css'` -- would also compile,
 * and that is the class of typo the flag exists for. Declared, the one real category of
 * non-module import we have is named and everything else still has to resolve.
 *
 * This is also why TypeScript is pinned at 6.0.3 rather than 7.x: `typescript-eslint@8.72.1`
 * peer-requires `<6.1.0` (QRPAGER-15 §7.2), so 6.0's defaults are the ones in force.
 */
declare module '*.css' {}
