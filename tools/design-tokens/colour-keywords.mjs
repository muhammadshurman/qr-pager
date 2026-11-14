/*
 * ================================================================================
 * THE COLOUR KEYWORDS — ONE DEFINITION, TWO READERS — QRPAGER-49B
 * ================================================================================
 *
 * Two guards ask "is this colour something a restaurant could have chosen?", in two languages:
 *
 *   - `scan-css` reads SOURCE, where the answer is a keyword: `transparent` is fine, `red` is a
 *     literal.
 *   - `tools/landing/paint-classify.mjs` reads what an engine COMPUTED, where the keyword never
 *     survives: `transparent` comes back as `rgba(0, 0, 0, 0)`.
 *
 * They had two lists, written by two hands in two tickets, and nothing made them agree. Muhammad's
 * ruling (QRPAGER-49B, item 2): *"derive this allowlist and the harness's declared non-themed set
 * from ONE definition in one place."* This is that place. `ALLOWED_COLOUR_KEYWORDS` is the whole
 * allowlist; each entry says what an engine computes it to, or `null` when it resolves to
 * ANOTHER colour (`currentcolor` and the CSS-wide keywords take their value from somewhere else,
 * so they are not a colour of their own and the harness has no member to declare for them).
 * The harness's `NON_THEMED` is every entry with a computed form, and nothing else.
 *
 * ⚠ THE LIST IS SMALL ON PURPOSE AND IT DOES NOT GROW TO FIT A FINDING. Adding a keyword here
 * is a ruling the owner makes; it widens BOTH guards at once, which is the point of there being
 * one place.
 */

/**
 * The only colour keywords source may contain.
 *
 * `computed` is the serialisation an engine returns for it, or null when the keyword resolves
 * to another value and so never appears in a computed style as itself.
 */
export const ALLOWED_COLOUR_KEYWORDS = Object.freeze({
  transparent: Object.freeze({
    computed: 'rgba(0, 0, 0, 0)',
    reason:
      'fully transparent black: nothing is painted, so there is no pixel for a theme to reach, ' +
      'and no restaurant could have chosen it as a colour.',
  }),
  currentcolor: Object.freeze({
    computed: null,
    reason: 'resolves to the element’s own `color`, which is itself judged.',
  }),
  inherit: Object.freeze({ computed: null, reason: 'CSS-wide keyword: takes the parent’s value.' }),
  initial: Object.freeze({
    computed: null,
    reason: 'CSS-wide keyword: the property’s initial value.',
  }),
  unset: Object.freeze({ computed: null, reason: 'CSS-wide keyword: inherit or initial.' }),
  revert: Object.freeze({
    computed: null,
    reason: 'CSS-wide keyword: the previous origin’s value.',
  }),
  'revert-layer': Object.freeze({
    computed: null,
    reason: 'CSS-wide keyword: the previous cascade layer’s value.',
  }),
})

/**
 * Keywords that are a colour, by CSS Color 4 §6.1 — the 148 named colours — and by §6.2 — the
 * system colours, current and deprecated. Everything here is a literal; none is allowlisted.
 *
 * ⚠ SYSTEM COLOURS ARE LITERALS TOO. `CanvasText` is a colour the user agent picked, not the
 * tenant, and a page that paints with it has a pixel the theme cannot reach. Muhammad's wording
 * was *"every other colour keyword is a literal"*, and these are colour keywords.
 */
export const NAMED_COLOURS = `
aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet
brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue
darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange
darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise
darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen
fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred
indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan
lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen
lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta
maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue
mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin
navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen
paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple
red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue
slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet
wheat white whitesmoke yellow yellowgreen
`
  .trim()
  .split(/\s+/)

export const SYSTEM_COLOURS = `
accentcolor accentcolortext activetext buttonborder buttonface buttontext canvas canvastext field
fieldtext graytext highlight highlighttext linktext mark marktext selecteditem selecteditemtext
visitedtext
activeborder activecaption appworkspace background buttonhighlight buttonshadow captiontext
inactiveborder inactivecaption inactivecaptiontext infobackground infotext menu menutext scrollbar
threeddarkshadow threedface threedhighlight threedlightshadow threedshadow window windowframe
windowtext
-webkit-focus-ring-color -webkit-link -webkit-activelink -webkit-text
`
  .trim()
  .split(/\s+/)

/*
 * ⚠ `Object.freeze` DOES NOT FREEZE A `Set` — the §11 QA round's L-6 — so the mutators are removed
 * from this instance. The type file says `ReadonlySet`, and now it is one.
 */
export const COLOUR_KEYWORDS = (() => {
  const set = new Set([...NAMED_COLOURS, ...SYSTEM_COLOURS])

  for (const method of ['add', 'delete', 'clear']) {
    Object.defineProperty(set, method, {
      value: () => {
        throw new TypeError(`COLOUR_KEYWORDS is read-only (${method})`)
      },
    })
  }

  return set
})()

/**
 * The alternation that finds a colour KEYWORD in a computed value, as a regular-expression
 * SOURCE string: every colour keyword `scan-css` calls a literal plus the allowlist, whole words
 * only, so `background` never matches inside `background-color` and `gray` never inside
 * `graytext`.
 *
 * ⚠ IT LIVES HERE, NOT IN `browser/theme-paint.spec.ts`, BECAUSE THE SPEC'S CLOSURE IS
 * SERIALISED INTO THE PAGE AND CANNOT BE UNIT-TESTED (QA L-2). The closure now receives this
 * string and wraps it in `new RegExp(…, 'gi')`; the construction is tested here. Every keyword is
 * escaped, so a future entry containing `.` or `+` cannot change the pattern (QA L-6).
 *
 * @returns {string}
 */
export function colourKeywordPattern() {
  const escape = (word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const words = [...Object.keys(ALLOWED_COLOUR_KEYWORDS), ...COLOUR_KEYWORDS].map(escape)

  return `(?<![\\w-])(?:${words.join('|')})(?![\\w-])`
}

/** Is this identifier a colour keyword that is NOT allowlisted — i.e. a literal? */
export function isLiteralColourKeyword(identifier) {
  return COLOUR_KEYWORDS.has(String(identifier).toLowerCase())
}

/** Is this identifier on the allowlist? */
export function isAllowedColourKeyword(identifier) {
  return Object.hasOwn(ALLOWED_COLOUR_KEYWORDS, String(identifier).toLowerCase())
}

/**
 * The computed serialisations of the allowlisted keywords that HAVE one — what the harness
 * declares as non-themed. Derived, never written out a second time.
 */
export function computedNonThemedMembers() {
  return Object.entries(ALLOWED_COLOUR_KEYWORDS)
    .filter(([, entry]) => entry.computed !== null)
    .map(([keyword, entry]) => ({ keyword, computed: entry.computed, reason: entry.reason }))
}

/**
 * Properties whose value is NOT a colour even when it spells a colour's name: an animation or a
 * font family called `Gold`, a grid area called `red`. A property is exempt only by being named
 * here, so an UNKNOWN property is scanned — the list fails closed, because the hole this closes
 * was a list of properties that did not include the one that was typed.
 */
const NON_COLOUR_PROPERTIES = new Set(
  `
font font-family font-feature-settings font-variation-settings font-display
animation animation-name animation-timeline
transition transition-property will-change
grid-area grid-row grid-column grid-row-start grid-row-end grid-column-start grid-column-end
grid-template-areas counter-reset counter-increment counter-set content quotes
container container-name view-transition-name anchor-name position-anchor
list-style list-style-type src unicode-range
`
    .trim()
    .split(/\s+/),
)

/**
 * Every literal colour keyword in a stretch of CSS text, with its offset.
 *
 * Reads DECLARATIONS — `property: value` up to `;`, `{` or `}` — which is what the old pattern
 * did not do: it demanded one of `; ! ) end-of-text` after the keyword, so `background:red}`
 * and `border:1px solid red}` and `color:red"` all passed. Here the value ends wherever a
 * declaration ends, and every identifier in it is judged, so a colour is found in a shorthand,
 * a gradient, a `var()` fallback and a custom property alike.
 *
 * What is not read as a colour: the interior of `url()` and of a string, a custom-property
 * REFERENCE (`var(--color-red)` is one identifier, not `red`), and any property in
 * `NON_COLOUR_PROPERTIES`.
 *
 * @param {string} text comments already blanked
 * @returns {Array<{ index: number, keyword: string, property: string }>}
 */
export function literalColourKeywords(text) {
  const found = []

  /*
   * ⚠ STRINGS AND `url()` ARE BLANKED ON THE WHOLE TEXT BEFORE ANY DECLARATION IS CUT — the
   * §11 QA round's M-1. They used to be blanked inside the value AFTER the cut, so the `;` in
   * `url(data:image/png;base64,AAA) red` ended the declaration at `png` and the `red` after the
   * url was never in any declaration. A CSS string cannot span a line, so neither does this one:
   * a stray apostrophe blanks to the end of its line and no further.
   */
  const clean = text
    .replace(/"(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'/g, (m) => ' '.repeat(m.length))
    .replace(/url\([^)]*\)/gi, (m) => ' '.repeat(m.length))

  const declaration = /(?<![\w-])(--[\w-]+|-?[a-z][\w-]*)\s*:(?!:)([^;{}]*)/gi
  let decl

  while ((decl = declaration.exec(clean)) !== null) {
    /*
     * ⚠ A "DECLARATION" THAT ENDS IN `{` IS A SELECTOR OR AN AT-RULE PRELUDE — the QA round's
     * M-2. `li:hover mark{…}` reads as property `li`, value `hover mark`, and `mark` is a system
     * colour; so does `a:has(menu)` and `@media (prefers-color-scheme:dark)`. A declaration
     * ends at `;`, at `}` or at the end of its attribute, never at `{`.
     */
    if (clean[decl.index + decl[0].length] === '{') continue

    // A vendor prefix does not make `-webkit-transition` a colour property.
    const property = decl[1].toLowerCase().replace(/^-(?:webkit|moz|ms|o)-/, '')
    if (NON_COLOUR_PROPERTIES.has(property)) continue

    const valueStart = decl.index + decl[0].length - decl[2].length
    const value = decl[2]
    const identifier = /(?<![\w#.\-@\\])-?[a-z][\w-]*(?![\w-])/gi
    let id

    while ((id = identifier.exec(value)) !== null) {
      if (isLiteralColourKeyword(id[0])) {
        found.push({
          index: valueStart + id.index,
          keyword: id[0],
          property: decl[1].toLowerCase(),
        })
      }
    }
  }

  return found
}
