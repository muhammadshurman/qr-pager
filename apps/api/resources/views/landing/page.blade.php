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
{{--
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
</style>
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
@foreach ($blocks as $block)
@php($f = $block['fields'])
@switch($block['type'])

@case('hero')
<section>
@if ($f['image'] !== null)
<img src="{{ $asset($f['image']) }}" alt="{{ $f['heading'] }}" loading="lazy">
@endif
<h2>{{ $f['heading'] }}</h2>
@if ($f['subheading'] !== null)<p>{{ $f['subheading'] }}</p>@endif
@if ($f['cta_label'] !== null && $f['cta_url'] !== null)
<a class="cta" href="{{ $f['cta_url'] }}" rel="noopener noreferrer nofollow">{{ $f['cta_label'] }}</a>
@endif
</section>
@break

@case('how_it_works')
<section>
<h2>{{ $f['heading'] }}</h2>
<ol class="list">
@foreach ($f['steps'] ?? [] as $step)
<li class="card"><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></li>
@endforeach
</ol>
</section>
@break

@case('menu_highlights')
<section>
<h2>{{ $f['heading'] }}</h2>
<div class="grid">
@foreach ($f['items'] ?? [] as $item)
<article class="card">
@if ($item['image'] !== null)
<img src="{{ $asset($item['image']) }}" alt="{{ $item['name'] }}" loading="lazy">
@endif
<h3 class="row"><span>{{ $item['name'] }}</span>@if ($item['price'] !== null)<span>{{ $item['price'] }}</span>@endif</h3>
@if ($item['description'] !== null)<p>{{ $item['description'] }}</p>@endif
</article>
@endforeach
</div>
</section>
@break

@case('gallery')
<section>
@if ($f['heading'] !== null)<h2>{{ $f['heading'] }}</h2>@endif
<div class="grid">
@foreach ($f['images'] ?? [] as $image)
<figure style="margin:0">
<img src="{{ $asset($image['image']) }}" alt="{{ $image['caption'] }}" loading="lazy">
<figcaption>{{ $image['caption'] }}</figcaption>
</figure>
@endforeach
</div>
</section>
@break

@case('hours_location')
<section>
<h2>{{ $f['heading'] }}</h2>
<address>{{ $f['address'] }}</address>
<table class="list"><caption class="sr">Opening hours</caption><tbody>
@foreach ($f['hours'] ?? [] as $day)
<tr><th scope="row">{{ $day['day'] }}</th><td>{{ $day['hours'] }}</td></tr>
@endforeach
</tbody></table>
@if ($map($f) !== null)
{{-- A LINK, never an embed (§6.4). The three numbers build an href here, so nothing
     third-party is invited onto a tenant's published page by filling in a form field. --}}
<p><a href="{{ $map($f) }}" rel="noopener noreferrer nofollow">Open in maps</a></p>
@endif
</section>
@break

@case('contact')
<section>
<h2>{{ $f['heading'] }}</h2>
<ul class="list">
@if ($f['email'] !== null)<li><a href="mailto:{{ $f['email'] }}">{{ $f['email'] }}</a></li>@endif
@if ($f['phone'] !== null)<li><a href="tel:{{ $f['phone'] }}">{{ $f['phone'] }}</a></li>@endif
@foreach ($f['links'] ?? [] as $link)
<li><a href="{{ $link['url'] }}" rel="noopener noreferrer nofollow">{{ $link['label'] }}</a></li>
@endforeach
</ul>
</section>
@break

@case('footer')
<section>
<p>{{ $f['text'] }}</p>
<ul class="list">
@foreach ($f['links'] ?? [] as $link)
<li><a href="{{ $link['url'] }}" rel="noopener noreferrer nofollow">{{ $link['label'] }}</a></li>
@endforeach
</ul>
</section>
@break

@endswitch
@endforeach
</main>
<footer>{{ $tenantName }}</footer>
</body>
</html>
