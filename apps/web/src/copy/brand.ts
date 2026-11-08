/**
 * THE PRODUCT NAME, AND IT IS NOT COPY.
 *
 * ⚠ IT LIVES HERE RATHER THAN IN `en.ts` AND `ar.ts` BECAUSE IT IS A BRAND, NOT A STRING TO
 * TRANSLATE — the owner's ruling of 2026-11-08. Putting it in the catalogues forced a
 * transliteration: `copy.test.ts` refuses any Arabic value that is still the English string
 * AND any value not written in Arabic script, so `QR Pager` could not stay Latin there. A
 * name on a sign does not change language, so the catalogues never see it and both locales
 * read this constant.
 */
export const BRAND = 'QR Pager'
