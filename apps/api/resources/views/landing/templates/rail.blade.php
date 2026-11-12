{{--
    RAIL — one ruled column. `QRPAGER-46`, the first of the three real templates.

    ⚠ IT RENDERS NO BLOCK, like every arrangement: every `@include('landing.block')` below is
    the ONE block renderer. This file decides order, grouping and emphasis and nothing else,
    which is what `block.blade.php`'s docblock means by *"three arrangements each writing
    their own seven renderings would be twenty-one places a required field could be
    forgotten"*.

    WHAT IT CHANGES AND WHY. `docs/design/public/rail.html` is a single column whose every
    region is separated from the next by ONE hairline rule — no cards, no bands, no grid. So
    the arrangement's job here is almost entirely GROUPING: the page keeps the reading order a
    first-time visitor needs (what this is → what it costs → what it looks like → where you
    are), and the two blocks that answer *"where are you"* are put side by side in one region
    rather than stacked, because they are one answer.

    ⚠ THE RULES THAT MAKE IT LOOK LIKE A LEDGER ARE IN `page.blade.php`, BEHIND AN `@if`, and
    that is `composer guard` rule 11 rather than a preference: *"a theme overrides TOKENS ONLY
    — no `<style`, no CSS rule"*, scanned over `app/` and `routes/`. `QRPAGER-33` tried to
    hold an arrangement's CSS in a PHP `match` and the guard refused it, correctly.

    ⚠ AND NOTHING THE ARRANGEMENT DOES NOT NAME IS DROPPED. `$rest` is every block whose type
    this file does not place, rendered before the footer — the `rest()` safety net
    `editorial.blade.php` already carries, and `TemplateTotalityTest` drives it off the
    committed JSON schema rather than off this list, so a block type added to `BlockSchema`
    later fails the gate until it renders here.
--}}
@php
    $byType = static function (string $type) use ($blocks): array {
        return array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === $type));
    };

    // Named in the order they are placed below, so this list and the markup cannot disagree.
    $placed = ['hero', 'how_it_works', 'menu_highlights', 'gallery', 'hours_location', 'contact', 'footer'];

    $rest = array_values(array_filter(
        $blocks,
        static fn (array $b): bool => ! in_array($b['type'], $placed, true),
    ));

    // The pair that answers one question. Rendered as one region when EITHER is present, so
    // a restaurant with an address and no contact block still gets the region rather than a
    // stray section with a rule on one side.
    $find = array_merge($byType('hours_location'), $byType('contact'));
@endphp
<div class="rail">
@foreach ($byType('hero') as $block)
@include('landing.block')
@endforeach
@foreach ($byType('how_it_works') as $block)
@include('landing.block')
@endforeach
@foreach ($byType('menu_highlights') as $block)
@include('landing.block')
@endforeach
@foreach ($byType('gallery') as $block)
@include('landing.block')
@endforeach
@if ($find !== [])
<div class="rail-find">
@foreach ($find as $block)
@include('landing.block')
@endforeach
</div>
@endif
@foreach ($rest as $block)
@include('landing.block')
@endforeach
@foreach ($byType('footer') as $block)
@include('landing.block')
@endforeach
</div>
