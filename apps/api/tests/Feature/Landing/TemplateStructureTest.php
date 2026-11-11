<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use Tests\Support\LandingFixtures;
use Tests\Support\LandingPages;

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
 * measuring the content. `LandingPages::render()` (in `TemplateTokenSetTest`, which Pest loads for
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

/** The text inside one region of the rendered page, by class, read from the parsed DOM. */
function regionText(string $html, string $class): string
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $region = (new DOMXPath($dom))
        ->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
        ?->item(0);

    expect($region)->not->toBeNull("the page carries no .{$class} region");

    return (string) $region->textContent;
}

it('arranges one unchanged document into three structurally different pages', function (): void {
    $prints = [];

    foreach (['rail', 'ember', 'tile'] as $template) {
        $prints[$template] = structuralFingerprint(LandingPages::render($template));

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

it('renders no empty region for a document that has none of its blocks', function (): void {
    /*
     * ⚠ THE `§11` QA ROUND'S M-4: every case in this feature rendered `LandingFixtures::
     * fullPage()`, which carries one block of every type — so only the TRUE arm of each
     * arrangement's conditionals was ever executed. `rail`'s `@if ($find !== [])`, `tile`'s
     * `@if ($tiles !== [])` and `ember`'s hero guard had no test, and an arrangement that
     * emitted an empty wrapper would have looked identical to one that did not.
     *
     * A footer-only document is the sparsest a tenant can publish and still have a page.
     */
    $footerOnly = LandingFixtures::fullPage();
    $footerOnly['blocks'] = array_values(array_filter(
        $footerOnly['blocks'],
        static fn (array $block): bool => $block['type'] === 'footer',
    ));

    expect($footerOnly['blocks'])->toHaveCount(1, 'the fixture no longer carries exactly one footer');

    foreach (['rail' => 'rail-find', 'tile' => 'tile-grid', 'ember' => 'ember-hero'] as $template => $region) {
        $html = LandingPages::render($template, [], ['blocks' => $footerOnly['blocks']]);

        /*
         * ⚠ `str_contains` AND NOT `not->toContain($needle, $message)` — `composer guard`'s
         * `[vacuous-negated-contains]` rule refused the first version of this line, correctly:
         * `toContain` is VARIADIC, so a message passed as a second argument is read as another
         * NEEDLE and the assertion becomes *"contains neither"* — which a message that never
         * appears makes trivially true. The negated form is the silently always-green one, and
         * it is in this repository's own notes.
         */
        expect(str_contains($html, 'class="'.$region.'"'))->toBeFalse(
            $template.' emitted its '.$region.' region for a document with none of its blocks'
        );

        // The footer still renders, exactly once, and the page is not otherwise empty.
        expect(substr_count($html, '<section>'))->toBe(1, $template.' dropped or duplicated the only block');
        expect($html)->toContain('Olive, 1 High Street');
    }

    /*
     * ⚠ AND `ember`'s COLUMN IS EMITTED UNCONDITIONALLY, which the round names and which is a
     * DECISION rather than an oversight: it is the measure every non-hero block sits in, so a
     * document whose only block is a footer still gets the wrapper and nothing inside it. An
     * empty `<div>` paints nothing, carries no role and costs one element; making it
     * conditional would put a second condition in a file whose whole job is grouping. Asserted
     * so that it stays an empty div rather than becoming an empty div with a region in it.
     */
    $ember = LandingPages::render('ember', [], ['blocks' => $footerOnly['blocks']]);

    expect($ember)->toContain('<div class="ember-column">')
        ->and($ember)->toContain('<div class="ember-column">'."\n".'</div>');
});

it('arranges a document that repeats a type, which the schema allows and the fixture does not test', function (): void {
    /*
     * `BlockSchema` says two galleries is a legitimate page, and `tile` is the arrangement that
     * MERGES two block types into one grid — so a repeated type is the case where a merge can
     * drop or double a block. The `§11` QA round measured it by hand and nothing asserted it.
     */
    $page = LandingFixtures::fullPage();
    $gallery = null;

    foreach ($page['blocks'] as $block) {
        if ($block['type'] === 'gallery') {
            $gallery = $block;
        }
    }

    expect($gallery)->not->toBeNull('the fixture carries no gallery block to repeat');

    $second = $gallery;
    $second['fields']['heading'] = 'The yard';

    $blocks = [...$page['blocks'], $second];

    foreach (['rail', 'ember', 'tile'] as $template) {
        $html = LandingPages::render($template, [], ['blocks' => $blocks]);

        expect(substr_count($html, '<section>'))->toBe(
            count($blocks),
            $template.' rendered a different number of sections than a document with a repeated type holds'
        );

        expect($html)->toContain('The room')->and($html)->toContain('The yard');
    }

    /*
     * And in `tile` both galleries are inside the one grid, which is what the merge claims.
     *
     * ⚠ READ FROM THE DOM, AND THE FIRST VERSION SLICED THE STRING — from
     * `<div class="tile-grid">` to the next `</div>`, which closes the BLOCK's own inner
     * `<div class="grid">` and not the region. The slice stopped inside the first cell and the
     * case failed on correct markup. This project has paid for the same mistake three times
     * now (a `>` found inside an `x-data` attribute, an `innerText` that could not tell two
     * orders apart): a string landmark cannot find the end of a nested element.
     */
    $grid = regionText(LandingPages::render('tile', [], ['blocks' => $blocks]), 'tile-grid');

    expect($grid)->toContain('The room')->and($grid)->toContain('The yard');
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

        $print = structuralFingerprint(LandingPages::render($template->value));
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
        expect(LandingPages::render($template))->toContain($root);
    }

    foreach (['classic', 'editorial'] as $template) {
        $html = LandingPages::render($template);

        expect($html)->not->toContain('class="rail"')
            ->and($html)->not->toContain('class="ember"')
            ->and($html)->not->toContain('class="tile"');
    }
});
