{{--
    EDITORIAL — the hero leads, then the story, with the gallery given the full measure.
    `QRPAGER-33`, and the second of the two arrangements Muhammad's scope cut authorises.

    WHAT IT CHANGES AND WHY. `how_it_works` is promoted to sit directly under the hero,
    because on a pager product the question a first-time visitor has is "what happens when I
    scan this". The gallery then runs the full measure, and the menu follows.

    ⚠ IT RENDERS NO BLOCK. Every `@include('landing.block')` below is the one block renderer,
    so this file decides order, grouping and emphasis and nothing else. That is what makes a
    third arrangement a file and an enum value rather than a run.

    ⚠ AND NOTHING THE ARRANGEMENT DOES NOT NAME IS DROPPED, which is the clause that makes
    this safe to add a block type to later. `$rest` is every block whose type this file does
    not place, rendered before the footer — the `rest()` safety net the retired kit described
    in the same words: "so a block type added later still renders instead of vanishing".
    `TemplateTotalityTest` drives it off the committed JSON schema rather than off this list,
    so a new block type fails until it renders here.

    THE FOOTER IS LAST WHATEVER THE TENANT DID. In `classic` a footer sits where it was
    arranged, because `classic` is the builder's own order and a tenant who puts a footer in
    the middle has said something. An arrangement that has already taken the order into its
    own hands cannot then leave a footer stranded mid-page.
--}}
@php
    $byType = static function (string $type) use ($blocks): array {
        return array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === $type));
    };

    // Named in the order they are placed below, so this list and the markup cannot disagree.
    $placed = ['hero', 'how_it_works', 'gallery', 'menu_highlights', 'footer'];

    $rest = array_values(array_filter(
        $blocks,
        static fn (array $b): bool => ! in_array($b['type'], $placed, true),
    ));
@endphp
@foreach ($byType('hero') as $block)
@include('landing.block')
@endforeach
@if ($byType('how_it_works') !== [])
<div class="band">
@foreach ($byType('how_it_works') as $block)
@include('landing.block')
@endforeach
</div>
@endif
@if ($byType('gallery') !== [])
<div class="wide">
@foreach ($byType('gallery') as $block)
@include('landing.block')
@endforeach
</div>
@endif
@foreach ($byType('menu_highlights') as $block)
@include('landing.block')
@endforeach
@foreach ($rest as $block)
@include('landing.block')
@endforeach
@foreach ($byType('footer') as $block)
@include('landing.block')
@endforeach
