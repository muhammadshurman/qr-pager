import { TENANT_LANGUAGE, type TenantLanguage } from '@qrpager/contracts'

/**
 * ================================================================================
 * THE LAUNCH LANGUAGES AND THE DIRECTION DERIVED FROM THEM. NO REACT IN THIS FILE.
 * ================================================================================
 *
 * ⚠ **WHY THIS IS A MODULE OF ITS OWN, AND IT IS NOT TIDINESS. `QRPAGER-30`, MEASURED AS A
 * FAILED BUILD.** These four exports lived in `theme.tsx` beside `ThemeProvider`. That was
 * harmless while the file's hooks were `createContext`, `useMemo` and `useContext`, and it
 * stopped being harmless the moment `R-221`'s fix added a `useEffect`: Next refuses a module
 * reaching a SERVER component that uses that hook, and `apps/web/app/layout.tsx` is a server
 * component that needs exactly these values for its pre-hydration `lang`/`dir` pair.
 *
 *     x You're importing a component that needs `useEffect`. This React Hook only works in
 *       a Client Component.
 *       ../../packages/ui/src/theme.tsx -> ../../packages/ui/src/index.ts -> ./app/layout.tsx
 *
 * `packages/ui` carries **no `'use client'` in any file** -- the consumer provides the
 * boundary -- so the fix is not a directive here; it is to stop a server component having to
 * import a component tree to read two strings. Reached as `@qrpager/ui/locale`, which is a
 * narrower import than the barrel for every caller that only wants the language list.
 *
 * ⚠ **`LOCALES` IS THE GENERATED CONTRACT, NOT A COPY OF IT.** It read `['en', 'ar'] as
 * const` until 2026-11-03, a hand-written twin of a list that now has a database behind it:
 * `tenants.language` is the Postgres enum `tenant_language`, mirrored from
 * `App\Domain\Tenancy\TenantLanguage` by `php artisan contracts:export` and byte-diffed by
 * `composer contracts:check`. Pointing at `TENANT_LANGUAGE` makes the kit's idea of a launch
 * language the SAME object as the column's, so a third language is one PHP case plus one
 * migration -- and a kit that disagreed with the column would fail the gate instead of
 * rendering a page in a language no restaurant can select.
 * `packages/ui/test/theme-document.test.tsx` asserts that with `toBe`, not `toEqual`:
 * identity is what catches a re-typed literal that happens to be equal.
 *
 * The ORDER differs from the old literal (`ar` first, as the enum declares it) and nothing
 * reads it ordered: `resolveLocale` matches by equality on the BCP 47 primary subtag, and
 * `apps/web/src/copy/index.ts` is keyed by the member TYPE rather than by position.
 */

export type Direction = 'ltr' | 'rtl'

/** The launch languages. `ar` is not a later addition (§12). */
export const LOCALES = TENANT_LANGUAGE
export type Locale = TenantLanguage

/**
 * Which locales are written right to left. The one place that mapping exists on the TS side.
 *
 * ⚠ ITS PHP TWIN IS `TenantLanguage::direction()`, and the two are held together by
 * `TenantLanguageContractTest`, which drives every case and requires the same answer. `dir`
 * is DERIVED and never stored on either side -- Muhammad's ruling of 2026-11-03: *"two
 * columns that must agree are two sources of truth, and the disagreement here does not fail
 * loudly, it renders a restaurant's page backwards."*
 */
const RTL_LOCALES: readonly Locale[] = ['ar']

export function directionFor(locale: Locale): Direction {
  return RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr'
}
