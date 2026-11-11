<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-46`'s STATED INVARIANT, CLAUSE 2 — THREE STRUCTURALLY DIFFERENT PAGES
 * ================================================================================
 *
 * Muhammad's words: *"one unchanged tenant document, rendered under all three, produces three
 * structurally different pages. Assert on structure — element counts, ordering, which regions
 * exist — never on pixels."*
 *
 * ⚠ ONE DOCUMENT, AND IT IS THE SAME OBJECT FOR ALL FIVE ARRANGEMENTS. The claim is about the
 * ARRANGEMENT and nothing else, so a case that changed the content between renders would be
 * measuring the content. `renderTemplate()` (in `TemplateTokenSetTest`, which Pest loads for
 * the whole directory) takes the fixture and replaces only `template`.
 *
 * ⚠ AND THE FINGERPRINT IS A PARSED DOM, NOT A SUBSTRING COUNT. `QRPAGER-45`'s own round
 * recorded the cost of string landmarks twice over — a `>` found inside an `x-data` attribute,
 * an `innerText` that could not tell two orders apart — and this project's recurring lesson is
 * *"a test that names a property and measures a proxy for it"*. The property here is the shape
 * of the page: which region each block sits in, in what order. So each `<section>` is located
 * in the tree, named by its own heading, and reported with the class of the element that
 * CONTAINS it. That is a structure, and nothing about it is a pixel.
 *
 * ⚠ WHY A DIFFERENCE IS WORTH ASSERTING AT ALL. Three enum cases and three Blade files that
 * all `@foreach` the blocks in stored order would satisfy every other test in this feature —
 * `TemplateTotalityTest` counts sections, `TemplateTokenSetTest` reads tokens, and both would
 * be green on three identical pages. The product claim is that a restaurant CHOOSING one gets
 * a different page, and this is the only case that can fail when it does not.
 */

/**
 * Where every block sits and what contains it: a structural fingerprint.
 *
 * @return list<string> one `region/heading` per block, in document order
 */
function structuralFingerprint(string $html): array
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $main = $xpath->query('//main')?->item(0);

    expect($main)->not->toBeNull('the rendered page has no <main> at all');

    $fingerprint = [];

    /** @var DOMNodeList<DOMElement> $sections */
    $sections = $xpath->query('.//section', $main);

    foreach ($sections as $section) {
        $parent = $section->parentNode;

        $region = $parent instanceof DOMElement
            ? ($parent->getAttribute('class') !== '' ? $parent->getAttribute('class') : $parent->nodeName)
            : '?';

        /*
         * The block's own name, from the page rather than from a list: the heading a tenant
         * wrote. A footer block has no heading, so its text stands in — which is what
         * `TemplateTotalityTest` already uses to find the footer.
         */
        $heading = $xpath->query('./h2|./p', $section)?->item(0);
        $name = $heading === null ? 'unnamed' : trim((string) $heading->textContent);

        $fingerprint[] = $region.'/'.$name;
    }

    return $fingerprint;
}

it('arranges one unchanged document into three structurally different pages', function (): void {
    $prints = [];

    foreach (['rail', 'ember', 'tile'] as $template) {
        $prints[$template] = structuralFingerprint(renderTemplate($template));

        // Not vacuous: every block of the fixture is accounted for in each fingerprint, so a
        // difference below is a difference in ARRANGEMENT and not a dropped block.
        expect($prints[$template])->toHaveCount(
            count(LandingFixtures::fullPage()['blocks']),
            $template.' rendered a different number of sections than the document holds'
        );
    }

    // Pairwise, because two identical pages out of three is the same defect as three.
    foreach ([['rail', 'ember'], ['rail', 'tile'], ['ember', 'tile']] as [$a, $b]) {
        expect($prints[$a])->not->toBe(
            $prints[$b],
            "{$a} and {$b} arranged the same document identically — region for region and in "
            .'the same order — so a restaurant choosing between them is choosing nothing: '
            .implode(' | ', $prints[$a])
        );
    }

    /*
     * ⚠ AND THE DIFFERENCES ARE NAMED, not merely counted. `not->toBe` passes for two pages
     * that differ by one class name, which would satisfy the letter of the clause and none of
     * its product meaning. Each arrangement's own structural decision is asserted here, and
     * each is the one its Blade file's docblock describes.
     */
    $regions = static fn (array $print): array => array_values(array_unique(
        array_map(static fn (string $entry): string => explode('/', $entry)[0], $print)
    ));

    // RAIL: one column, with the two blocks that answer "where are you" in one region.
    expect($regions($prints['rail']))->toBe(['rail', 'rail-find']);

    // EMBER: the hero is its own region and everything else is in the narrow column.
    expect($regions($prints['ember']))->toBe(['ember-hero', 'ember-column', 'ember']);

    // TILE: the gallery and the menu share one grid; the rest is in the root.
    expect($regions($prints['tile']))->toBe(['tile', 'tile-grid']);

    // And the ORDER differs, which no region name can carry. `how_it_works` leads ember's
    // column, trails tile's grid, and sits second in rail.
    $positionOf = static function (array $print, string $name): int {
        foreach ($print as $index => $entry) {
            if (str_ends_with($entry, '/'.$name)) {
                return $index;
            }
        }

        return -1;
    };

    $how = [
        'rail' => $positionOf($prints['rail'], 'How the pager works'),
        'ember' => $positionOf($prints['ember'], 'How the pager works'),
        'tile' => $positionOf($prints['tile'], 'How the pager works'),
    ];

    expect($how['rail'])->toBeGreaterThan(-1)
        ->and($how['ember'])->toBeGreaterThan(-1)
        ->and($how['tile'])->toBeGreaterThan(-1)
        ->and($how['tile'])->toBeGreaterThan(
            $how['rail'],
            'tile does not place how_it_works after the grid, which is the decision that makes '
            .'it a third arrangement rather than a restyled second'
        );
});

it('keeps the footer last in every arrangement that takes the order into its own hands', function (): void {
    /*
     * The clause `editorial.blade.php` states in words and `TemplateTotalityTest` asserts for
     * `editorial` alone. Every arrangement `QRPAGER-46` adds reorders, so every one of them
     * owes it — and `$rest` is exactly where a forgotten block type would land AFTER the
     * footer rather than before it.
     */
    foreach (LandingTemplate::cases() as $template) {
        if ($template === LandingTemplate::Classic) {
            continue; // the builder's own order: a footer in the middle is the tenant's word
        }

        $print = structuralFingerprint(renderTemplate($template->value));
        $last = $print[count($print) - 1] ?? '';

        /*
         * ⚠ `str_contains` AND NOT `toContain($needle, $message)`. Pest's `toContain` takes
         * extra NEEDLES, not a message — so the first version of this line asserted that the
         * fingerprint contained the explanation as well, and failed on `editorial`, which
         * places the footer last perfectly well. The negated form of the same mistake is
         * worse: `not->toContain($x, $message)` is silently always-green.
         */
        expect(str_contains($last, 'Olive, 1 High Street'))->toBeTrue(
            $template->value.' does not render the footer block last: '.implode(' | ', $print)
        );
    }
});

it('gives the three new arrangements a root element of their own, and the old two none', function (): void {
    /*
     * ⚠ THE CONTROL THAT KEEPS THE FINGERPRINT HONEST. Every rule the three new arrangements
     * add is scoped under that root class — `.rail`, `.ember-hero`, `.tile-grid` — so a
     * missing root means the markup is arranged and the rules are not applied to anything, a
     * failure that LOOKS like a styling bug and is invisible to every assertion above.
     *
     * `classic` and `editorial` must have no root: `classic` reproduces the pre-feature page
     * byte for byte, and `editorial`'s own wrappers are `.band` and `.wide` INSIDE the flow.
     */
    foreach (['rail' => 'class="rail"', 'ember' => 'class="ember"', 'tile' => 'class="tile"'] as $template => $root) {
        expect(renderTemplate($template))->toContain($root);
    }

    foreach (['classic', 'editorial'] as $template) {
        $html = renderTemplate($template);

        expect($html)->not->toContain('class="rail"')
            ->and($html)->not->toContain('class="ember"')
            ->and($html)->not->toContain('class="tile"');
    }
});
