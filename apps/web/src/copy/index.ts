import type { Locale } from '@qrpager/ui'

import { AR } from './ar.ts'
import { EN, type CopyKey } from './en.ts'

export type { CopyKey }
export { EN, AR }

/**
 * The two catalogues, by locale. `@qrpager/ui`'s `LOCALES` is the one list of launch
 * languages, and this record is keyed by its member type -- so adding `LOCALES[2]` in the
 * kit is a type error here until a catalogue exists for it, rather than a locale that
 * resolves to `undefined` at run time.
 */
const CATALOGUES: Readonly<Record<Locale, Readonly<Record<CopyKey, string>>>> = {
  en: EN,
  ar: AR,
}

/**
 * Substitute `{name}` placeholders.
 *
 * ⚠ A MISSING VARIABLE IS LEFT VISIBLE AS `{name}` RATHER THAN BLANKED. A silent empty
 * string produces *"Order ."* on the one screen that has to be read across a room, and
 * nothing fails. The literal brace is ugly, which is the point: it is caught in a
 * screenshot and in `copy.test.ts`, which asserts no rendered state contains a `{`.
 */
function interpolate(template: string, vars?: Readonly<Record<string, string>>): string {
  if (vars === undefined) return template

  return template.replace(/\{(\w+)\}/g, (whole, name: string) => vars[name] ?? whole)
}

/** A translator bound to one locale. Components take this, never a locale. */
export type Translate = (key: CopyKey, vars?: Readonly<Record<string, string>>) => string

export function translatorFor(locale: Locale): Translate {
  const catalogue = CATALOGUES[locale]

  return (key, vars) => interpolate(catalogue[key], vars)
}
