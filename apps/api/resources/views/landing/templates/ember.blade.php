{{--
    EMBER — a dark centred opening, then a narrow centred column. `QRPAGER-46`.

    ⚠ ITS DARKNESS IS THE TENANT'S OWN TWO COLOURS, SWAPPED. `docs/design/public/ember.html`
    is a warm near-black artboard (`#14110F`, `#FFB23F`, `#C4421C`) and NONE of those values
    is in the token layer. The ticket's rule for those files is *"REFERENCE ONLY: translate
    every literal into a token, never copy one"*, and `ThemeTokens::NAMES` may not grow in
    this ticket — so the translation of *"a dark hero"* is `color.text` as the band's ground
    and `color.background` as its ink. A restaurant whose text is near-black on paper gets a
    near-black band; one who has chosen their own two colours gets theirs, inverted. That is
    also why this arrangement needs no palette of its own: *"structure, not palette, is what
    separates them"*.

    WHAT IT CHANGES. The hero leaves the column and fills the measure as its own region; the
    rest of the page sits in a NARROWER measure than any other arrangement uses and is
    centred, so a page with three blocks still reads as designed rather than as a wide page
    with gaps. `how_it_works` leads that column for `editorial`'s reason: on a pager product
    the first question is *"what happens when I scan this"*.

    ⚠ THE FOOTER IS LAST WHATEVER THE TENANT ARRANGED, and outside the narrow column so it
    spans the page. An arrangement that has already taken the order into its own hands cannot
    leave a footer stranded mid-page — `editorial.blade.php` records the same clause.

    ⚠ IT RENDERS NO BLOCK and drops none: `$rest` carries every type this file does not name.
--}}
@php
    $byType = static function (string $type) use ($blocks): array {
        return array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === $type));
    };

    // Named in the order they are placed below, so this list and the markup cannot disagree.
    $placed = ['hero', 'how_it_works', 'menu_highlights', 'gallery', 'footer'];

    $rest = array_values(array_filter(
        $blocks,
        static fn (array $b): bool => ! in_array($b['type'], $placed, true),
    ));
@endphp
<div class="ember">
@if ($byType('hero') !== [])
<div class="ember-hero">
@foreach ($byType('hero') as $block)
@include('landing.block')
@endforeach
</div>
@endif
<div class="ember-column">
@foreach ($byType('how_it_works') as $block)
@include('landing.block')
@endforeach
@foreach ($byType('menu_highlights') as $block)
@include('landing.block')
@endforeach
@foreach ($byType('gallery') as $block)
@include('landing.block')
@endforeach
@foreach ($rest as $block)
@include('landing.block')
@endforeach
</div>
@foreach ($byType('footer') as $block)
@include('landing.block')
@endforeach
</div>
