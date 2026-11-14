// Types for `colour-keywords.mjs`, which `browser/theme-paint.spec.ts` imports so that the colour
// keywords the harness looks for are the SAME list `scan-css` reads (QRPAGER-49C).
export interface AllowedColourKeyword {
  readonly computed: string | null
  readonly reason: string
}

export const ALLOWED_COLOUR_KEYWORDS: Readonly<Record<string, AllowedColourKeyword>>
export const NAMED_COLOURS: readonly string[]
export const SYSTEM_COLOURS: readonly string[]
export const COLOUR_KEYWORDS: ReadonlySet<string>
export function isLiteralColourKeyword(identifier: string): boolean
export function isAllowedColourKeyword(identifier: string): boolean
export function computedNonThemedMembers(): {
  keyword: string
  computed: string
  reason: string
}[]
export function literalColourKeywords(
  text: string,
): { index: number; keyword: string; property: string }[]
export function colourKeywordPattern(): string
