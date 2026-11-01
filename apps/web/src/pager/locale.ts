import { LOCALES, type Locale } from '@qrpager/ui'

/**
 * ================================================================================
 * WHICH LANGUAGE A CUSTOMER GETS. `§4.1`: *"no account, phone-first, Arabic + English"*.
 * ================================================================================
 *
 * FROM THE BROWSER'S OWN LANGUAGE LIST, and there is no switcher.
 *
 * Two reasons, both clauses rather than preferences. `§4.1` asks for *"zero chrome"* on the
 * scan landing, and a language switcher is chrome -- it is a control on every screen that
 * advances nothing. And a customer scanning a QR code in a restaurant has their phone's
 * language set correctly already; a switcher serves the case where it is not, which is
 * rarer than the cost of a permanent control on a one-handed screen.
 *
 * `LOCALES` is `@qrpager/ui`'s one list of launch languages, so adding a third is a change
 * in the kit and a catalogue in `src/copy`, not an edit here.
 *
 * ⚠ THE TAG IS MATCHED ON ITS PRIMARY SUBTAG, NOT BY EQUALITY. Real phones send `ar-JO`,
 * `ar-SA`, `ar-EG`, `en-GB`, `en-US` -- never a bare `ar` or `en` -- so a `===` test against
 * `LOCALES` would resolve every Arabic phone in the region this product launches in to
 * English. BCP 47 puts the language in the first subtag, which is what this reads.
 */
export const FALLBACK: Locale = 'en'

export function resolveLocale(tags: readonly string[]): Locale {
  for (const tag of tags) {
    const primary = tag.toLowerCase().split('-')[0] ?? ''
    const match = LOCALES.find((locale) => locale === primary)

    if (match !== undefined) return match
  }

  return FALLBACK
}

/**
 * The browser's list, in the browser's own order of preference.
 *
 * `navigator.languages` rather than `navigator.language`: the first is the ordered list the
 * user actually configured and the second is only its head, so a phone set to Arabic first
 * and English second is indistinguishable from an English-only one by the singular property
 * whenever a page is loaded from a locale-specific context.
 */
export function browserLocale(nav: Pick<Navigator, 'languages' | 'language'>): Locale {
  const tags = nav.languages.length > 0 ? nav.languages : [nav.language]

  return resolveLocale(tags)
}
