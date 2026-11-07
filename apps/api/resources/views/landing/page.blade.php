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

     ⚠ NO NEWLINE BEFORE `</style>`, WHICH IS LOAD-BEARING RATHER THAN FUSSY. For `classic`
     the `@if` is false, so this must emit NOTHING -- a newline here would put one byte into
     every page published before this feature existed and break `QRPAGER-33`'s invariant
     clause 2 by exactly that byte. Measured at 4,626 against 4,625 on the attempt that left
     a Blade comment on a line of its own, which is why the comment closes on the `@if`.

     ⚠ AND THE COMMENT CLOSES ON THIS LINE rather than the one above, for the same reason:
     a Blade comment on a line of its own leaves that line's newline in the output, which
     was measured as the one-byte difference (4,626 against 4,625) on the first attempt.

     ⚠ AND THE RULES ARE HERE RATHER THAN ON THE ENUM BECAUSE `composer guard` REFUSED THEM
     THERE. Rule 11, locked in `PROJECT_INSTRUCTIONS.md §3`: *"a theme overrides TOKENS ONLY
     -- no <style, no CSS rule"*, scanned over `app/` and `routes/`. A `match` returning CSS
     from `LandingTemplate` tripped `[css-rule-set]` twice, correctly: the rule exists so CSS
     lives in CSS. So the arrangement's rules sit beside the page's own, behind one `@if`.
     Logical properties only, like everything else here, so RTL needs no second rule. --}}@if ($template === \App\Domain\Landing\LandingTemplate::Editorial)
.band{background:var(--color-surface,#f5f5f5);padding-block:var(--space-section,3rem);padding-inline:1rem;margin-inline:-1rem}
.wide{margin-inline:-1rem}
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
