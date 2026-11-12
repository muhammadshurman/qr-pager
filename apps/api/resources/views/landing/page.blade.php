{{--
    The published tenant landing page.

    ================================================================================
    EVERY TENANT-AUTHORED VALUE IN THIS FILE GOES THROUGH {{ }}, AND NEVER {!! !!}
    ================================================================================

    Decision D5's second, independent half. LandingDocument has already REFUSED anything
    that is not plain text, a parsed-and-re-assembled https URL, a number in range or a
    media id belonging to this tenant -- and this template still escapes all of it. A
    validator bug alone cannot inject, and an escaping bug alone cannot either.

    `{!! !!}` appears nowhere, not once, and LandingXssFuzzTest asserts that about this
    file's SOURCE as well as about its output.

    ================================================================================
    THE RULES LIVE HERE; THE THEME ONLY EVER CHANGES A VALUE
    ================================================================================

    The one tenant-derived CSS in the document is the custom-property list on <html>,
    built by ThemeStylesheet and escaped as an attribute. Every rule below reads those
    properties through var(), with a default, so a theme can change a colour and can never
    introduce, remove or re-target a rule -- which is "theme overrides tokens only, never
    components" expressed where it is actually true.

    §12: this page is exempt from the VISUAL bar until Task 8, never from the
    accessibility bar. So: one h1, headings in order, alt text on every image (the gallery
    caption is required for exactly this reason), a visible focus ring that is not removed,
    prefers-reduced-motion respected, and dir/lang set from the document.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}" style="{{ $themeStyle }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
@if ($description !== null)
<meta name="description" content="{{ $description }}">
@endif
<meta property="og:title" content="{{ $title }}">
@if ($description !== null)
<meta property="og:description" content="{{ $description }}">
@endif
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $canonical }}">
@if ($ogImage !== null)
<meta property="og:image" content="{{ $ogImage }}">
@endif
<link rel="canonical" href="{{ $canonical }}">
@if ($structuredData !== null)
<script type="application/ld+json">{{ $structuredData }}</script>
@endif
{{--
    ⚠ THE JSON-LD BLOCK ABOVE IS R-131, CLOSED AT TASK 11 — AND IT IS STILL `{{ }}`.

    The note below is Task 7's and every word of it still holds; it is kept because it is the
    argument, and the argument is what chose the solution. It named two correct encoders for
    this position and said D5 forbids the first. `App\Features\Landing\StructuredData` is
    the SECOND: an Htmlable, which `{{ }}` passes through unescaped, carrying JSON encoded
    with JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT and WITHOUT JSON_UNESCAPED_SLASHES — so
    `</script>` inside a tenant's own restaurant name cannot close this element.

    Nothing was carved out to get the feature: `{!! !!}` still appears nowhere in this
    template's code, the fuzz case below still asserts that, and the encoding decision now
    lives in a class a test drives with hostile input rather than in a template line.

    ───── Task 7's note, verbatim ─────

    ⚠ THERE IS NO JSON-LD BLOCK HERE, AND IT IS DEFERRED RATHER THAN FORGOTTEN (R-131).

    §6.4 says structured_data is DERIVED from the SEO fields and never authored, and the
    natural home for it is <script type="application/ld+json">. The content of that
    element is JSON, not HTML -- so {{ }} is the WRONG encoder for it: Blade would turn
    the JSON's own string delimiters into &quot; and a script element's content is raw
    text, where entities are never decoded. The result would be invalid JSON that looks
    fine in the page source.

    The only correct encoders for that position are {!! !!} with JSON_HEX_TAG, or an
    Htmlable that amounts to the same thing. Decision D5 says {!! !!} appears NOWHERE in
    this template, and LandingXssFuzzTest asserts that about this file's source -- a
    mechanical check worth more than one SEO feature, and not one to carve a
    self-granted exception into on the task whose named invariant is about injection.

    So this task emits the SEO and Open Graph META TAGS, which carry the same facts, and
    the ld+json block is R-131, owned by Task 11 -- the public renderer, which is where
    the page's markup becomes that task's to own.
--}}
{{-- (R-131 is closed: the element is at the top of this block's note.) --}}
<style>
:root{color-scheme:light dark}
*{box-sizing:border-box}
body{margin:0;background:var(--color-background,#ffffff);color:var(--color-text,#111111);
font-family:var(--font-body,system-ui,sans-serif);line-height:1.5}
main{max-width:64rem;margin:0 auto;padding:0 1rem}
section{padding:var(--space-section,3rem) 0;border-bottom:1px solid var(--color-muted,#dddddd)}
section:last-child{border-bottom:0}
h1,h2,h3{font-family:var(--font-heading,system-ui,sans-serif);line-height:1.2;margin:0 0 .5rem}
h1,section:first-of-type h2{font-size:var(--size-heading,2rem)}
a{color:var(--color-accent,#0b5fff)}
a:focus-visible,button:focus-visible{outline:3px solid var(--color-accent,#0b5fff);outline-offset:2px}
img{max-width:100%;height:auto;border-radius:var(--radius-small,.25rem);display:block}
.card{background:var(--color-surface,#f6f6f6);border-radius:var(--radius-large,.75rem);padding:1rem}
.grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr))}
.cta{display:inline-block;min-height:44px;min-width:44px;padding:.75rem 1.25rem;
background:var(--color-accent,#0b5fff);color:var(--color-accent-text,#ffffff);
border-radius:var(--radius-small,.25rem);text-decoration:none}
.list{list-style:none;padding:0;margin:0}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
.row{display:flex;gap:1rem;justify-content:space-between}
footer{padding:2rem 1rem;text-align:center;color:var(--color-muted,#666666)}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
{{-- QRPAGER-33: the arrangement's OWN rules, and only the chosen arrangement's.

     NO INTERPOLATION ON THIS LINE AT ALL. The rules are literal text inside an `@if`, so
     D5's `{{ }}`-never-`{!! !!}` rule has nothing to apply to and no value reaches the
     `<style>` element from anywhere. An earlier shape echoed a string from the enum and this
     comment argued about escaping it; both are gone.

     ⚠ NO NEWLINE BEFORE `</style>`, AND THE REASON IT WAS LOAD-BEARING IS NOW HISTORY. While
     `classic` existed the chain had a FALSE-for-every-branch state and had to emit nothing at
     all: a newline here put one byte into every page published before `QRPAGER-33`, measured
     at 4,626 against 4,625 on the attempt that left a Blade comment on a line of its own.
     `QRPAGER-47` retires `classic`, so every document now matches a branch and that byte can
     no longer land anywhere. The form is kept rather than relaxed — it costs nothing, it is
     the reason the comment closes ON the `@if` below, and a future arrangement that needs no
     rules of its own would bring the empty state straight back.

     ⚠ AND THE COMMENT CLOSES ON THIS LINE rather than the one above, for the same reason:
     a Blade comment on a line of its own leaves that line's newline in the output, which
     was measured as the one-byte difference (4,626 against 4,625) on the first attempt.

     ⚠ AND THE RULES ARE HERE RATHER THAN ON THE ENUM BECAUSE `composer guard` REFUSED THEM
     THERE. Rule 11, locked in `PROJECT_INSTRUCTIONS.md §3`: *"a theme overrides TOKENS ONLY
     -- no <style, no CSS rule"*, scanned over `app/` and `routes/`. A `match` returning CSS
     from `LandingTemplate` tripped `[css-rule-set]` twice, correctly: the rule exists so CSS
     lives in CSS. So the arrangement's rules sit beside the page's own, behind one `@if`.
     Logical properties only, like everything else here, so RTL needs no second rule.

     ⚠⚠ `QRPAGER-46` MADE THIS AN `@if`/`@elseif` CHAIN, AND `@elseif` IS NOT A STYLE CHOICE:
     `@endif@if (…)` DOES NOT COMPILE. Blade's statement pattern is anchored with `\B`, so a
     directive preceded by a WORD character is left as literal text — `@endif@if` produced
     *"syntax error, unexpected token endif"* on the first run, with the second `@if` sitting
     uncompiled in the cached view. A Blade comment between them does not help either, because
     `compileComments()` runs BEFORE `compileStatements()` (`BladeCompiler::compileString`), so
     by the time statements are compiled the two directives are adjacent again. `@elseif` at
     the start of a line has a newline before it, and the arrangements are mutually exclusive
     anyway — which is what the chain now says.

     ⚠ NOT ONE `var()` FALLBACK IN THE THREE BRANCHES, which is `QRPAGER-46`'s stated
     invariant rather than a house style. A fallback literal is a SECOND default competing with
     the token layer, and `R-268` is what that costs: `--color-surface` falls back to `#f5f5f5`
     in `.band` below and to `#f6f6f6` in `.card` above, live on every published page, and
     NEITHER is a Rail value. The three branches reference tokens only, and
     `ThemeStylesheet::declarations()` has already put every one of those tokens in the `style`
     attribute — there is nothing for a fallback to do. It used to ask
     `LandingTemplate::resolvesEveryToken()` first; `QRPAGER-47` retired the two cases that
     answered false, so the question had one answer left and the method is gone.
     `TemplateTokenSetTest` asserts the two sets are EQUAL in both directions.

     ⚠⚠ AND EVERY VALUE IN THE THREE BRANCHES COMES FROM THE **TWELVE LANDING TOKENS**, NOT
     FROM THE PRODUCT'S SCALE — WHICH `TemplateTokenSetTest` CAUGHT ON THE FIRST RUN. The
     branches were first written with `var(--space-24)`, `var(--space-32)` and `var(--space-96)`
     from `packages/tokens/generated/tokens.css`, which reads like the right thing to do and is
     not: `§4b` B3 traced this page's whole chain and it links NO stylesheet — the only custom
     properties it ever carries are the twelve `ThemeStylesheet` writes into the `style`
     attribute. Those three references resolved to nothing, which with no fallback paints
     nothing, and the equality case named all three in one failure (14 referenced against 12
     emitted). A smaller rhythm than `--space-section` is therefore `calc()` over it, which is
     a composition of a decision already taken rather than a new magnitude.

     ⚠ THE EMBER BAND IS A PANEL INSIDE THE MEASURE RATHER THAN FULL-BLEED, for the same
     reason and it is a real loss against the artboard. Breaking out of `main`'s padding needs
     that padding's own value, which is the literal `1rem` in the shared rules above — frozen
     by the baseline, not a token — and `margin-inline:calc(50% - 50vw)` carries a magnitude
     the scanner refuses. Inventing a fourth spacing literal here would be `R-268` with this
     ticket's name on it.

     ⚠⚠ AND `.rail .grid img`'s RATIO IS THE SECOND DEFECT THE PICTURES FOUND. `display:block`
     on the shared `.grid` is what makes this arrangement a column rather than a grid — and it
     makes every photograph full-measure, so a page with five of them rendered **6,618px** tall
     against `tile`'s 2,777, a scroll of enormous images with the ledger lost between them. A
     restaurant with a dozen dishes would publish twenty thousand pixels of photograph. The
     reference artboard has no large photographs at all; a wide band is the closest honest
     translation, and `16/5` is a RATIO rather than a magnitude, so it needs no token and the
     scanner has nothing to refuse. `tile` keeps its square cells and `ember` its own measure.

     ⚠⚠⚠ THE THREE `font-size:revert` LINES ARE A DEFECT ONLY A PICTURE COULD FIND, AND THEY
     ARE THIS TICKET'S OWN. The shared rule above reads
     `h1,section:first-of-type h2{font-size:var(--size-heading)}` — written when every block
     was a direct child of `main`, so *"the first section"* meant THE HERO and the rule gave
     the hero's heading the display size. Each of these three arrangements puts some blocks in
     a wrapper of its own, and `:first-of-type` is scoped to the PARENT — so the rule started
     matching whatever happened to be first inside the new wrapper. Rendered at 1280 with the
     defaults: `ember` showed *"How the pager works"* at 64px, `tile` showed *"The room"*, and
     `rail` showed *"Find us"* — each as large as the page's own title, with the real title
     above it at the same size.

     ⚠ **NO TEST IN THIS FEATURE COULD HAVE CAUGHT IT**, and that is not a gap to close here:
     the ticket's own invariant says *"assert on structure … never on pixels"*, and the
     structure is correct — the right heading is in the right region, in the right order. What
     is wrong is a computed font size, which is a pixel. It was found by rendering the three
     arrangements and looking at them, which is what Muhammad asked for before merging.

     ⚠ `revert` RATHER THAN A SIZE, because the correct value is *"whatever the other `h2`s
     get"* — the UA default, since no other rule sets one — and naming a size here would be a
     fourth magnitude. `revert` is in the value guard's own safe list, so it needs no token and
     no exemption. The shared rule is untouched: when this was written that kept `classic`
     byte-for-byte, and now it keeps the page's own title the one heading at the display size.

     ⚠⚠ THE TWO `.ember-hero` LINES THAT RE-COLOUR A LINK AND ITS FOCUS RING ARE THE `§11`
     SECURITY ROUND'S M-1, AND THE DEFECT WAS INVISIBLE BY ARITHMETIC. `colours.light.ink` and
     `colours.light.brand` are THE SAME HEX — `#191C22` — so with the shipped defaults
     `--color-accent` equals `--color-text`, and this band paints `background:var(--color-text)`
     while the shared rule above draws `outline:3px solid var(--color-accent)` at
     `outline-offset:2px` — i.e. OVER the band. Measured: **1.00:1**, on the page's only
     interactive control, against WCAG 2.2 SC 1.4.11's 3:1. The band's TEXT was fine (16.61:1)
     and `.cta`'s own fill is re-inverted a line above, so the one thing that disappeared was
     the focus ring — `§12`'s *"visible focus ring … required on every screen"* and this file's
     own docblock promise, broken by a colour nobody chose. A tenant who sets their own accent
     never sees it; it is the DEFAULT that collides. `a{color:var(--color-accent)}` would be
     ink-on-ink in here too — not reachable today, because only `.cta` can occur inside a hero
     and `TemplateTotalityTest` pins block markup, so that line is for the next block type.
     `TemplateContrastTest` is the proof, and it reads the ratio rather than the colours.

     ⚠ `14rem` IN `.tile-grid`'s `minmax()` IS A LAYOUT CONSTANT TOO — the `§11` QA round's
     L-2, and it is declared here rather than left for the next reader to wonder about. It is
     the measure of one tile, copied from the shared `.grid` rule's own `minmax(14rem,1fr)`
     above so the two grids agree; `grid-template-columns` is judged by no scanner rule, and
     `§1`'s ruling of 2026-11-09 is the authority: *"a one-off layout dimension is a layout
     constant and lives with the layout."*

     ⚠ THE TWO `@media (min-width:48rem)` BREAKPOINTS ARE LAYOUT CONSTANTS, AND `§1`'s RULING
     OF 2026-11-09 IS THE AUTHORITY RATHER THAN A HABIT: *"a value the system repeats is a
     token; a one-off layout dimension is a layout constant and lives with the layout."* There
     is no breakpoint in `packages/tokens` — `config/design-tokens.php` carries colours, space,
     radius, size, motion, focus, elevation and fonts, and nothing else — so a token for one
     would be a token-layer change this ticket is not scoped to make. The value appears twice,
     in one file, for one decision: *below this width the two-up regions stack*. The scanner
     does not read a media-query condition, which its own spacing pattern says in as many
     words, so this is a stated decision and not a value that slipped past a check.

     ⚠ `display:contents` ON `.tile-grid>section` COSTS NO LANDMARK, which is worth stating
     because it usually does. A `<section>` is exposed as `role="region"` only when it has an
     accessible name, and `landing.block`'s sections carry none — no `aria-label`, no
     `aria-labelledby` — so there is no landmark for `display:contents` to remove. `§12`'s
     accessibility bar applies to this page in full and this is the one rule here that could
     have touched it.

     ⚠ THE SIXTEEN FALLBACKS ABOVE ARE FROZEN, NOT FIXED — `tools/design-tokens/landing-baseline.json`,
     dated, by file and line. Muhammad's instruction for this ticket: *"Do NOT fix the pre-existing
     literals in the old templates … Forcing a rewrite of delivered work is R-250."* --}}@if ($template === \App\Domain\Landing\LandingTemplate::Rail)
.rail>section+section,.rail>.rail-find{border-top:1px solid var(--color-muted)}
.rail>section,.rail-find>section{border-bottom:0}
.rail .card{background:none;border-radius:0;padding-inline:0}
.rail .grid{display:block}
.rail .grid>*+*{margin-block-start:calc(var(--space-section)/3)}
.rail .grid img{aspect-ratio:16/5;object-fit:cover}
.rail-find>section:first-of-type h2{font-size:revert}
.rail-find{padding-block:var(--space-section)}
.rail-find>section{padding-block:0}
@media (min-width:48rem){.rail-find{display:flex;gap:calc(var(--space-section)/2)}.rail-find>section{flex:1}}
@elseif ($template === \App\Domain\Landing\LandingTemplate::Ember)
.ember-hero{background:var(--color-text);color:var(--color-background);text-align:center;padding-inline:calc(var(--space-section)/4);border-radius:var(--radius-large)}
.ember-hero section{border-bottom:0}
.ember-hero .cta{background:var(--color-background);color:var(--color-text)}
.ember-hero a{color:var(--color-background)}
.ember-hero a:focus-visible,.ember-hero button:focus-visible{outline-color:var(--color-background)}
.ember-hero img{margin-inline:auto}
.ember-column>section:first-of-type h2{font-size:revert}
.ember-column{text-align:center}
.ember-column .card,.ember-column address,.ember-column table{text-align:start}
@media (min-width:48rem){.ember-column{padding-inline:var(--space-section)}}
@elseif ($template === \App\Domain\Landing\LandingTemplate::Tile)
.tile-grid{display:grid;gap:calc(var(--space-section)/4);grid-template-columns:repeat(auto-fill,minmax(14rem,1fr))}
.tile-grid>section,.tile-grid .grid{display:contents}
.tile-grid>section>h2{grid-column:1/-1}
.tile-grid>section:first-of-type h2{font-size:revert}
.tile-grid .card,.tile-grid figure{margin:0}
.tile-grid img{aspect-ratio:1/1;object-fit:cover;width:100%}
@endif</style>
</head>
<body>
{{--
    ⚠ EXACTLY ONE h1, WHATEVER BLOCKS THE TENANT CHOSE.

    The h1 used to live inside the `hero` case alone. `hero` is not required and blocks
    REPEAT (BlockSchema says two galleries is a legitimate page), so a page built from a
    gallery and a contact block had ZERO h1 and opened at h2 -- a skipped level -- and a
    page with two heroes had TWO. §12's accessibility bar is not waived for this task,
    and the §11 QA review found that the "one h1, no skipped level" assertion was only
    ever run against a single fixture that happened to have one hero.

    So the page's own h1 is here, before any block: the SEO title, or the restaurant's
    name. It is visually hidden when a hero follows, because the hero's heading is what
    a sighted reader sees as the title -- and that heading is now an h2, so the outline
    is h1 → h2 for every possible document rather than for one of them.
--}}
<h1 @class(['sr' => $heroFirst])>{{ $title }}</h1>
<main>
@include('landing.templates.'.$template->value)
</main>
<footer>{{ $tenantName }}</footer>
</body>
</html>
