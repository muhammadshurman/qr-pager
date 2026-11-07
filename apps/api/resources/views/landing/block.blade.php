{{--
    ONE BLOCK, RENDERED. The only place a block's CONTENT is decided — `QRPAGER-33`.

    ⚠ THIS FILE IS THE WHOLE REASON AN ARRANGEMENT IS CHEAP. It was the body of
    `page.blade.php`'s `@foreach` until 2026-11-03, where there was exactly one order and
    so no reason to separate the two jobs. With two arrangements there is: three
    arrangements each writing their own seven renderings would be twenty-one places a
    required field could be forgotten, and a test could only check the ones it knew about.
    The retired `packages/ui/src/templates` made the same split and said the same thing.

    So: an arrangement decides ORDER, GROUPING and EMPHASIS and includes this; this
    decides MARKUP. `TemplateTotalityTest` reads the committed JSON schema and requires
    every block type to appear in every arrangement's output, which is what makes "no
    block the tenant placed is absent" mechanical rather than a habit.

    IT TAKES `$block` FROM THE INCLUDING SCOPE, plus `$asset` and `$map` from the
    renderer's own view data — Blade's `@include` inherits both, so there is no argument
    list to keep in step with `LandingRenderer`.

    ⚠ EVERY TENANT-AUTHORED VALUE IS `{{ }}` AND NEVER `{!! !!}`, unchanged by the move:
    `LandingXssFuzzTest` drives the validator and this escaping together, and it reads the
    rendered page rather than this file, so the move is covered by what already existed.
--}}
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
