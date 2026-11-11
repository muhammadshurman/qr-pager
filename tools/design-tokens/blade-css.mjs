/**
 * CSS OUT OF A BLADE TEMPLATE — `R-267`, closed by `QRPAGER-46`.
 *
 * ================================================================================
 * WHY THIS FILE EXISTS, IN THE ROW'S OWN WORDS
 * ================================================================================
 *
 * `R-267`: *"THE PUBLIC LANDING PAGE — THE ONLY SURFACE A CUSTOMER SEES — HAS NEVER BEEN
 * SCANNED BY ANYTHING."* Its stylesheet is an inline `<style>` block in
 * `apps/api/resources/views/landing/page.blade.php`, and the row proved the hole by
 * INJECTION rather than by reading: `.landing-gap-proof{color:#ff00ff;padding:13px;font-size:17px}`
 * placed in that block was reported clean by all three scanners — `scan-css` walks `.css` and
 * this is `.blade.php`, `scan-panel`'s subjects come from the panel `@source` globs, and
 * `composer guard`'s paths carry no `resources/`.
 *
 * The row also says what the fix is NOT: *"⚠⚠ THE FIX IS A PARSER, NOT A GLOB — AND THIS ROW
 * SAYS SO TO STOP THE NEXT BUILDER ESTIMATING IT AS ONE LINE … Adding `resources/views` to a
 * `.css` walk achieves nothing at all."* And what it is: *"a Blade-aware extractor that pulls
 * every `<style>` body out of a template, hands it to `scanSource()` unchanged, and maps
 * offsets back to the template's own line numbers so a finding names a real line."*
 *
 * ================================================================================
 * ⚠ IT MASKS RATHER THAN EXTRACTS, AND THAT IS WHAT MAKES THE LINE NUMBERS REAL
 * ================================================================================
 *
 * The obvious shape — cut each `<style>` body out, scan it, add an offset to every line — has
 * to get the arithmetic right for every finding in every block, and nothing in a finding says
 * which block it came from. So instead this returns a string the SAME LENGTH as the template
 * with everything that is not CSS replaced by spaces, newlines kept where they were. Then
 * `scanSource(masked, 'the template')` computes line and column itself, from the template's
 * own geometry, and there is no offset to be wrong.
 *
 * ================================================================================
 * ⚠ WHAT IS BLANKED INSIDE A `<style>` BODY, AND WHY EACH ONE MATTERS
 * ================================================================================
 *
 *   `{{-- … --}}`   a Blade comment. `page.blade.php`'s own style block carries several
 *                   paragraphs of prose INSIDE the element, and that prose names the very
 *                   literals this scanner reports — `#f5f5f5`, `#f6f6f6`, `1rem`. Left in,
 *                   every explanation of a finding would become a finding.
 *   `{{ … }}`,      an echo. No value reaches `page.blade.php`'s style element today (D5, and
 *   `{!! … !!}`     its own docblock says so), but a scanner that reported the CONTENTS of an
 *                   echo would be reporting PHP, not CSS.
 *   `@php … @endphp` a PHP block: PHP, not CSS, by definition.
 *   `@directive`,   `@if`, `@elseif`, `@endif`, `@media` — note that `@media` is CSS and is
 *   `@directive(…)` deliberately NOT blanked: the blanker only removes `@word` forms whose
 *                   name is a Blade directive, which is a CLOSED list here rather than a
 *                   guess, because `@media`, `@supports`, `@layer`, `@container`,
 *                   `@keyframes`, `@font-face` and `@import` are all legal CSS at-rules and
 *                   blanking them would hide the declarations inside them.
 *
 * ================================================================================
 * ⚠ `<style>` WITH ATTRIBUTES, AND A TEMPLATE WITH SEVERAL BLOCKS
 * ================================================================================
 *
 * Both are handled, and the tests drive both: `<style nonce="…">` is still a style element,
 * and a template may carry more than one block. What is NOT handled, stated rather than
 * implied: a `<style>` tag assembled by Blade itself (`<{{ 'style' }}>`), and a CSS string
 * built in PHP and echoed. Neither exists in this repository, both would be `composer guard`
 * rule 11a violations in `app/`, and a scanner that pretended to cover them would be the
 * false-coverage claim this project has now paid for three times.
 */

/** The Blade directives this blanker removes. A closed list: everything else may be CSS. */
const BLADE_DIRECTIVES = [
  'if',
  'elseif',
  'else',
  'endif',
  'unless',
  'endunless',
  'isset',
  'endisset',
  'empty',
  'endempty',
  'foreach',
  'endforeach',
  'forelse',
  'emptyforelse',
  'endforelse',
  'for',
  'endfor',
  'while',
  'endwhile',
  'switch',
  'case',
  'break',
  'default',
  'endswitch',
  'include',
  'includeIf',
  'includeWhen',
  'each',
  'php',
  'endphp',
  'json',
  'class',
  'style',
  'verbatim',
  'endverbatim',
  'production',
  'endproduction',
  'env',
  'endenv',
]

/** Replace every non-newline character of `text` with a space, so offsets never move. */
function blank(text) {
  return String(text).replace(/[^\n]/g, ' ')
}

/**
 * Everything in `source` that is not CSS inside a `<style>` element, blanked.
 *
 * The result has the same length and the same newline positions as `source`, so a finding's
 * line and column are the template's own.
 */
export function maskBladeCss(source) {
  /*
   * ⚠⚠ COMMENTS ARE BLANKED BEFORE THE `<style>` ELEMENTS ARE FOUND, AND THE ORDER WAS
   * MEASURED RATHER THAN REASONED. `page.blade.php`'s style element contains a Blade comment
   * that quotes BOTH tags — *"⚠ NO NEWLINE BEFORE `</style>`"* and *"no `<style`, no CSS
   * rule"* — and with comments left in place the first version of this function found TWO
   * style elements in that one file: the first ended at the `</style>` inside the comment,
   * and the second opened at the `<style` inside it, with `[^>]*>` running to the next `>` in
   * the CSS (`.rail>section`). The union of the two happened to cover almost all the real
   * rules, so the finding list looked correct — a scanner right by accident, which is the
   * shape of defect this whole file exists to close. Blanking comments first removes the
   * possibility rather than the symptom, and `landing-blade-literals.blade.php.fixture`
   * carries both quoted tags so that it stays removed.
   */
  const text = String(source).replace(/\{\{--[\s\S]*?--\}\}/g, blank)

  /*
   * 1. Everything outside a `<style>` BODY becomes spaces.
   *
   * ⚠ THE OPENING TAG IS CAPTURED SEPARATELY RATHER THAN SEARCHED FOR WITH `indexOf('>')`,
   * which was the first version and is wrong for `<style data-x="a>b">`: the body would have
   * started mid-attribute and every line number after it would still have been right, so the
   * mistake would have shown up as a finding inside an attribute rather than as an error.
   */
  const styleTag = /(<style\b[^>]*>)([\s\S]*?)(<\/style\s*>)/gi
  let masked = ''
  let cursor = 0
  let match

  while ((match = styleTag.exec(text)) !== null) {
    const bodyStart = match.index + match[1].length
    const bodyEnd = bodyStart + match[2].length

    masked += blank(text.slice(cursor, bodyStart))
    masked += text.slice(bodyStart, bodyEnd)
    cursor = bodyEnd
  }

  masked += blank(text.slice(cursor))

  // 2. Inside what is left, blank every Blade construct.

  //    Blade comments are already gone — see the note at the top of this function, which is
  //    also why a comment's prose about `#f5f5f5` is not a finding.
  masked = masked.replace(/\{!![\s\S]*?!!\}/g, blank)
  masked = masked.replace(/\{\{[\s\S]*?\}\}/g, blank)

  //    `@php … @endphp` as a block, so the PHP between them is not read as CSS.
  masked = masked.replace(/@php\b[\s\S]*?@endphp\b/gi, blank)

  //    Then the remaining directives, by NAME, with their parenthesised argument if present.
  //    `@media` and the other CSS at-rules are not in the list and survive.
  const directive = new RegExp(`@(?:${BLADE_DIRECTIVES.join('|')})\\b(\\s*\\([^\\n]*\\))?`, 'gi')
  masked = masked.replace(directive, blank)

  return masked
}

/**
 * Does this template carry a `<style>` element at all?
 *
 * Used by the walk so that the hundreds of Blade templates with no CSS in them are not
 * scanned, reported on, or counted towards a floor.
 */
export function hasStyleBlock(source) {
  return styleBodyCount(source) > 0
}

/** The ranges of a template that `maskBladeCss` keeps, for the tests to assert on. */
export function styleBodyCount(source) {
  // Comment-blanked first, for the reason `maskBladeCss` records: a tag quoted in prose is
  // not a tag, and counting it as one is how that function found two elements in one file.
  const text = String(source).replace(/\{\{--[\s\S]*?--\}\}/g, blank)
  const found = text.match(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi)

  return found === null ? 0 : found.length
}
