{{--
    TILE — the photographs lead, as one even grid. `QRPAGER-46`.

    WHAT IT CHANGES, AND IT IS THE ONLY ARRANGEMENT THAT MERGES TWO BLOCKS INTO ONE REGION.
    `docs/design/public/tile.html` is a page of equal square cells, and the two blocks a
    restaurant fills with photographs are `gallery` and `menu_highlights`. Here they share
    ONE grid region: every dish and every photograph is a cell of the same size, in that
    order, so a restaurant whose page is mostly photographs gets a page that is mostly
    photographs.

    ⚠ THE CELLS ARE `landing.block`'s OWN MARKUP, RE-LAID OUT BY RULES. The grid in
    `page.blade.php` behind this arrangement's `@if` uses `display: contents` on the two
    sections and on their inner `.grid`, so each `<article class="card">` and each `<figure>`
    becomes a cell of the OUTER grid while still being rendered by the one block renderer.
    An arrangement that re-rendered a block to change its markup would be the
    twenty-one-places defect `block.blade.php`'s docblock describes, and
    `TemplateTotalityTest` would not catch it — it counts `<section>`s.

    ⚠ `how_it_works` FOLLOWS THE GRID HERE, which is the opposite of `editorial` and `ember`
    and is the point of a third arrangement: a restaurant that chooses this one is leading
    with its food, so the explanation of the pager comes after the thing the visitor came
    for. The two other arrangements are available to a restaurant that wants the reverse.

    ⚠ IT RENDERS NO BLOCK and drops none: `$rest` carries every type this file does not name,
    before the footer, and the footer is last whatever the tenant arranged.
--}}
@php
    $byType = static function (string $type) use ($blocks): array {
        return array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === $type));
    };

    // Named in the order they are placed below, so this list and the markup cannot disagree.
    $placed = ['hero', 'gallery', 'menu_highlights', 'how_it_works', 'footer'];

    $rest = array_values(array_filter(
        $blocks,
        static fn (array $b): bool => ! in_array($b['type'], $placed, true),
    ));

    // The cells, in the order the design reads them: the room first, then the dishes.
    $tiles = array_merge($byType('gallery'), $byType('menu_highlights'));
@endphp
<div class="tile">
@foreach ($byType('hero') as $block)
@include('landing.block')
@endforeach
@if ($tiles !== [])
<div class="tile-grid">
@foreach ($tiles as $block)
@include('landing.block')
@endforeach
</div>
@endif
@foreach ($byType('how_it_works') as $block)
@include('landing.block')
@endforeach
@foreach ($rest as $block)
@include('landing.block')
@endforeach
@foreach ($byType('footer') as $block)
@include('landing.block')
@endforeach
</div>
