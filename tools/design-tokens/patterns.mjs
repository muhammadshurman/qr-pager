/**
 * ================================================================================
 * THE FORBIDDEN-VALUE LIST, AND IT EXISTS EXACTLY ONCE
 * ================================================================================
 *
 * `§12`: "Design tokens are a single source. No hard-coded hex, spacing or font size in any
 * component." `QRPAGER-15 §5` makes it a gate clause and names the Tailwind arbitrary forms
 * `text-[#fff]` and `p-[13px]` specifically (D3).
 *
 * TWO MECHANISMS READ THIS FILE AND NEITHER CARRIES A COPY:
 *
 *   - the ESLint rules in ./index.mjs, over .ts/.tsx/.mjs/.js -- string literals, template
 *     chunks, JSX attributes and inline style objects;
 *   - the scanner in ./bin/scan-css.mjs, over .css, which ESLint cannot parse.
 *
 * Two readers were unavoidable: ESLint has no CSS parser here and a CSS file is where
 * Tailwind v4 keeps its theme. What WAS avoidable is two copies of the list, which is the
 * duplicated-list shape this project has already paid for twice -- `PLAN.md` QA H-1, and
 * guard rule 11c, which parses `ThemeTokens` rather than holding its own copy of the token
 * names. So: one list, two readers, and a test that asserts both readers see every pattern.
 *
 * WHY A PATTERN AND NOT A PARSER. A value this rule must catch can appear as a bare string
 * (`'#0B6B4F'`), inside a Tailwind class (`text-[#0B6B4F]`), inside a template chunk, or as
 * a CSS declaration. Those four have no common AST. What they have in common is the TEXT of
 * the value, which is what is matched -- and the matching is deliberately a superset of the
 * three named categories, because a rule that admits `rgb()` while refusing `#rgb` is a rule
 * with a documented hole in it.
 */

/**
 * Values that are not design decisions and are therefore never forbidden.
 *
 * `0` carries no unit to get wrong and no scale to belong to. `100%`, `auto`, `inherit`,
 * `initial`, `unset`, `revert`, `none`, `currentColor` and `transparent` are keywords.
 * `1px` is admitted ONLY for a hairline border width, which is the one length in the system
 * that is a physical device pixel rather than a step on the spacing scale -- and the
 * approved elevation (`0 1px 2px`) is written in those units for the same reason.
 */
const KEYWORD_SAFE =
  /^(0|0px|0rem|0%|100%|auto|inherit|initial|unset|revert|revert-layer|none|currentcolor|transparent)$/i

/**
 * Every pattern, grouped by the rule that owns it.
 *
 * `where` says which reader applies it: 'both', 'js' or 'css'. A pattern that only makes
 * sense in one place says so rather than being silently skipped by the other.
 */
export const PATTERNS = [
  // ---------------------------------------------------------------- colour
  {
    rule: 'no-raw-color',
    id: 'hex',
    where: 'both',
    // #rgb #rgba #rrggbb #rrggbbaa. Bounded on both sides so a 7-char git sha in a comment
    // or an id like #section-3 is not a colour.
    regex: /#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/gi,
    message:
      'hard-coded hex colour {{match}}. Use a token: var(--color-…) in CSS, or a token class.',
  },
  {
    rule: 'no-raw-color',
    id: 'color-function',
    where: 'both',
    // A functional colour notation is the same decision in different clothing. Included so
    // the rule has no documented hole: refusing #fff while admitting rgb(255,255,255) would
    // make the gate clause a formatting preference.
    regex: /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix|color)\s*\(/gi,
    message:
      'hard-coded colour function {{match}}. Use a token: var(--color-…). A colour computed ' +
      'at the use site is a second source for that colour.',
  },
  {
    rule: 'no-raw-color',
    id: 'named-color',
    where: 'css',
    // Only in CSS, and only as a whole declaration value, so prose and identifiers in
    // TypeScript are untouched. The set is the ones that actually get typed by hand.
    regex:
      /(?::|\s)\s*(white|black|red|green|blue|gray|grey|silver|navy|teal|olive|maroon|purple|fuchsia|lime|aqua|yellow|orange|pink|brown|beige|ivory|khaki|cyan|magenta)\s*(?:;|!|\)|$)/gi,
    message:
      'named CSS colour {{match}}. Use a token: var(--color-…). A named colour is outside ' +
      'the approved palette by definition -- it is not one of the eleven values.',
  },
  {
    rule: 'no-raw-color',
    id: 'tailwind-arbitrary-color',
    where: 'both',
    // D3, and QRPAGER-15 §5 names this form: text-[#fff]. Tailwind's square-bracket escape
    // hatch reintroduces the literal the token system exists to remove, and it does it
    // inside a string, where no CSS linter would ever look.
    regex:
      /\b(?:text|bg|border|ring|outline|decoration|divide|from|via|to|fill|stroke|shadow|accent|caret|placeholder)-\[\s*(?:#|rgba?\(|hsla?\(|oklch\(|color-mix\()[^\]]*\]/gi,
    message:
      'Tailwind arbitrary colour {{match}} (D3). The square-bracket escape hatch puts the ' +
      'literal back inside a string. Use a token-backed utility.',
  },

  // ---------------------------------------------------------------- spacing
  {
    rule: 'no-raw-spacing',
    id: 'css-spacing-declaration',
    where: 'css',
    // Bound to the spacing properties by name, so a `border-width: 1px` or a media query
    // breakpoint is not swept up. The approved scale is 4 8 12 16 24 32 48 64 96 and a
    // component states a step, never the number.
    regex:
      /\b(margin|margin-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end)|padding|padding-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end)|gap|row-gap|column-gap|inset|inset-block|inset-inline|top|right|bottom|left)\s*:\s*([^;{}]+)/gi,
    // A declaration is judged by its VALUE, which a bare regex cannot do -- so this pattern
    // hands the value to the guard below.
    valueGuard: true,
    message:
      'hard-coded spacing in {{match}}. Use a spacing token: var(--space-…). The approved ' +
      'scale is 4 8 12 16 24 32 48 64 96 and a component names a step, never a number.',
  },
  {
    rule: 'no-raw-spacing',
    id: 'tailwind-arbitrary-spacing',
    where: 'both',
    // QRPAGER-15 §5 names this form: p-[13px]. 13 is not on the scale, which is the point:
    // the arbitrary form is how an off-scale value gets in.
    regex:
      /\b(?:p|px|py|pt|pr|pb|pl|ps|pe|m|mx|my|mt|mr|mb|ml|ms|me|gap|gap-x|gap-y|space-x|space-y|inset|inset-x|inset-y|top|right|bottom|left|start|end|w|h|min-w|min-h|max-w|max-h|size|translate-x|translate-y)-\[[^\]]*\d[^\]]*\]/gi,
    message:
      'Tailwind arbitrary spacing or size {{match}} (D3). Use a scale step: the approved ' +
      'spacing scale is 4 8 12 16 24 32 48 64 96.',
  },
  {
    rule: 'no-raw-spacing',
    id: 'style-object-spacing',
    where: 'js',
    // An inline style object is a component carrying its own value with extra steps, and it
    // is the one form that reaches the DOM without passing through any CSS file.
    styleProperties: [
      'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
      'marginBlock', 'marginInline', 'marginBlockStart', 'marginBlockEnd',
      'marginInlineStart', 'marginInlineEnd',
      'padding', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
      'paddingBlock', 'paddingInline', 'paddingBlockStart', 'paddingBlockEnd',
      'paddingInlineStart', 'paddingInlineEnd',
      'gap', 'rowGap', 'columnGap', 'inset', 'top', 'right', 'bottom', 'left',
    ],
    message:
      'hard-coded spacing in an inline style ({{match}}). Use var(--space-…) or a token class.',
  },

  // ---------------------------------------------------------------- font size
  {
    rule: 'no-raw-font-size',
    id: 'css-font-size-declaration',
    where: 'css',
    regex: /\b(font-size|font)\s*:\s*([^;{}]+)/gi,
    valueGuard: true,
    message:
      'hard-coded font size in {{match}}. Use var(--size-…). The approved type scale is ' +
      '13 16 20 28 44 64 96 -- one ratio, with no in-between sizes.',
  },
  {
    rule: 'no-raw-font-size',
    id: 'tailwind-arbitrary-font-size',
    where: 'both',
    regex: /\b(?:text|leading|tracking)-\[[^\]]*\d[^\]]*\]/gi,
    message:
      'Tailwind arbitrary type value {{match}} (D3). The approved type scale is ' +
      '13 16 20 28 44 64 96 and carries no in-between sizes.',
  },
  {
    rule: 'no-raw-font-size',
    id: 'style-object-font-size',
    where: 'js',
    styleProperties: ['fontSize', 'lineHeight', 'letterSpacing'],
    message:
      'hard-coded type value in an inline style ({{match}}). Use var(--size-…).',
  },
]

/**
 * Is this declaration value acceptable?
 *
 * A value is acceptable when it is a token reference, a keyword, or carries no number that
 * could be a magnitude. Everything else is a decision taken at the use site, which is what
 * `§12` forbids.
 */
/**
 * Split a declaration value on TOP-LEVEL whitespace, counting parentheses.
 *
 * ⚠ THIS WAS A REGEX WITH A LOOKAHEAD AND IT WAS WRONG. `(?![^(]*\))` cannot see nesting,
 * so it tore `clamp(var(--a), var(--b), var(--c))` into three fragments and then rejected
 * each one for carrying a digit. The GOOD fixture is what caught it: two legitimate lines
 * came back as findings, which is the half of the proof that stops a rule being disabled
 * for crying wolf. Depth-counting is the only thing that gets nesting right.
 */
function splitTopLevel(value) {
  const parts = []
  let depth = 0
  let current = ''
  for (const ch of value) {
    if (ch === '(') depth += 1
    else if (ch === ')') depth -= 1

    if (depth === 0 && /\s/.test(ch)) {
      if (current !== '') {
        parts.push(current)
        current = ''
      }
    } else {
      current += ch
    }
  }
  if (current !== '') parts.push(current)
  return parts
}

/** A token reference with no fallback, which is the only form that launders nothing. */
const BARE_VAR = /var\(\s*--[a-z0-9-]+\s*\)/gi

/** A number carrying a CSS length or percentage unit: a magnitude typed at the use site. */
const DIGIT_WITH_UNIT =
  /\d\s*(?:px|rem|em|%|vh|vw|vmin|vmax|dvh|dvw|svh|lvh|ch|ex|pt|pc|cm|mm|in|q)\b/i

/**
 * Is this declaration value acceptable?
 *
 * Acceptable means: a token reference, a keyword, a unitless ratio, a hairline `1px`, or a
 * composition of those. Anything else is a magnitude or a colour decided at the use site,
 * which is what `§12` forbids.
 *
 * ORDER MATTERS AND IS THE PART THAT WAS WRONG ONCE. This function used to early-return on
 * "the value CONTAINS a var() reference", so `padding: var(--space-16) 9px` passed: one
 * token laundered the literal beside it. The BAD fixture predicted that line as a finding,
 * the rule did not produce it, and that is how the hole was found -- before a reviewer saw
 * it. The shorthand is split FIRST now, and every part is judged on its own.
 */
export function valueIsAcceptable(rawValue) {
  const value = String(rawValue).trim().replace(/\s*!important\s*$/i, '')

  if (value === '') return true
  if (KEYWORD_SAFE.test(value)) return true

  // A shorthand is only as good as its worst part.
  const parts = splitTopLevel(value)
  if (parts.length > 1) return parts.every((part) => valueIsAcceptable(part))

  // A comma-separated list at one level -- `font-family: a, b` -- likewise.
  if (value.includes(',') && !/^(?:var|calc|clamp|min|max|round)\(/i.test(value)) {
    const commaParts = value.split(',').map((p) => p.trim()).filter(Boolean)
    if (commaParts.length > 1) return commaParts.every((part) => valueIsAcceptable(part))
  }

  // A token reference as the WHOLE value. A fallback is judged too: `var(--space-16, 13px)`
  // types 13px at the use site, and the fallback is what renders when the token is absent.
  const varRef = value.match(/^var\(\s*--[a-z0-9-]+\s*(?:,([\s\S]*))?\)$/i)
  if (varRef) return varRef[1] === undefined || valueIsAcceptable(varRef[1])

  // calc/clamp/min/max over tokens is a composition of decisions already taken. Strip the
  // bare token references and the safe keywords; whatever is left must carry no magnitude.
  // A var() WITH a fallback is deliberately NOT stripped, so a literal hidden in a fallback
  // is still seen.
  const fn = value.match(/^(?:calc|clamp|min|max|round)\(([\s\S]*)\)$/i)
  if (fn) {
    const stripped = String(fn[1])
      .replace(BARE_VAR, ' ')
      .replace(/\b(?:100%|0)\b/g, ' ')
    return !DIGIT_WITH_UNIT.test(stripped)
  }

  // A bare unitless number is a ratio or a multiplier, not a magnitude.
  if (/^-?\d+(?:\.\d+)?$/.test(value)) return true

  // 1px survives for a hairline border and for the approved elevation; nothing else does.
  if (/^1px$/i.test(value)) return true

  // Anything left carrying a digit is a hard-coded magnitude.
  return !/\d/.test(value)
}

/** Every rule id this file defines, so a reader cannot silently apply a subset. */
export const RULE_IDS = [...new Set(PATTERNS.map((p) => p.rule))]

/** Patterns a given reader must apply. */
export function patternsFor(reader) {
  return PATTERNS.filter((p) => p.where === 'both' || p.where === reader)
}
