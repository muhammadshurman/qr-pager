<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingRenderer;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * `QRPAGER-33` INVARIANT CLAUSE 3 — NO BLOCK THE TENANT PLACED IS ABSENT
 * ================================================================================
 *
 * *"For every template, every block type in `docs/contracts/landing-page.schema.json` appears
 * in the output — the test reads the committed schema, so a block type added later fails until
 * each arrangement handles it."*
 *
 * ⚠ **THE SUBJECT LIST IS DERIVED TWICE OVER AND NEITHER LIST IS IN THIS FILE.** The
 * arrangements come from `LandingTemplate::cases()` and the block types from the COMMITTED
 * JSON contract. So the scope cut Muhammad made -- two arrangements now, *"a third is then a
 * template file and an enum value, not a run"* -- is true because of this file: a third case
 * is swept here with no new assertion, and it fails until its Blade file places every block.
 *
 * ⚠ **AND IT IS THE REASON AN ARRANGEMENT MAY NAME SOME TYPES AND NOT OTHERS.**
 * `editorial.blade.php` places five and renders the remainder through `$rest`. Without this
 * proof, a later arrangement that forgot `$rest` would silently drop whatever it had not
 * thought of -- which is the failure the retired kit's own `rest()` note described, and which
 * no reading of the Blade file reliably catches.
 */

/** Every block type the COMMITTED contract names, read out of the file. */
function schemaBlockTypes(): array
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode(
        (string) file_get_contents(base_path('../../docs/contracts/landing-page.schema.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $types = [];

    /** @var list<array<string, mixed>> $variants */
    $variants = $schema['properties']['blocks']['items']['oneOf'] ?? [];

    foreach ($variants as $variant) {
        /*
         * ⚠ `const`, NOT `enum`. Measured against the committed file: each `oneOf` variant
         * pins its own type with `{"const": "hero"}` rather than a one-member `enum`. The
         * first version of this reader looked for `enum`, found nothing in all seven
         * variants, and produced an EMPTY list -- which then made "every block type renders"
         * a loop over nothing. It failed loudly only because of the bridge case below and the
         * `toBeGreaterThan(3)` guard; a reader with neither would have been green and
         * vacuous.
         */
        $constant = $variant['properties']['type']['const'] ?? null;

        if (is_string($constant)) {
            $types[] = $constant;
        }
    }

    sort($types);

    return array_values(array_unique($types));
}

it('names, in the committed contract, exactly the block types the PHP enum has', function (): void {
    // The bridge the case below stands on. If the contract and the enum disagreed, "every
    // type in the schema" would be a different claim from "every type a tenant can place",
    // and the totality sweep would be total over the wrong set.
    expect(schemaBlockTypes())->toBe(
        collect(BlockType::cases())->map(fn (BlockType $t): string => $t->value)->sort()->values()->all(),
    );
});

it('renders every block type in every arrangement, derived from the contract', function (): void {
    $types = schemaBlockTypes();

    /*
     * Not vacuous: the contract names a real set, and more than one.
     *
     * ⚠ FIVE, AND THE NUMBER IS DELIBERATELY EXACT RATHER THAN A FLOOR. `QRPAGER-46` added
     * `rail`, `ember` and `tile` to `QRPAGER-33`'s two, and this line is the one place the
     * suite states how many arrangements there are — so a case added without a thought about
     * totality fails HERE, with this file's own header explaining why, rather than passing a
     * `>= 2` and being swept silently. Updating it is the thought.
     *
     * ⚠ IT READ **5** UNTIL `QRPAGER-47`, WHICH IS THE SAME MECHANISM WORKING IN THE OTHER
     * DIRECTION: retiring `classic` and `editorial` failed this line, which is where the
     * question *"is every block type still placed by every remaining arrangement"* had to be
     * answered rather than assumed.
     */
    expect($types)->toHaveCount(count(BlockType::cases()))
        ->and(count($types))->toBeGreaterThan(3)
        ->and(LandingTemplate::cases())->toHaveCount(3);

    /*
     * ONE DOCUMENT CARRYING EVERY TYPE, so an arrangement cannot pass by placing the types it
     * names and dropping the rest -- which is exactly what a per-type document would let it
     * do. `LandingFixtures::fullPage()` is that document; the bridge case above is what makes
     * "every type" checkable against it.
     */
    $page = LandingFixtures::fullPage();
    $present = array_column($page['blocks'], 'type');

    /*
     * ⚠ `in_array` AND NOT `toContain($type, $message)`. MEASURED: Pest's `toContain` takes
     * NO message argument -- every argument is another EXPECTED value -- so the message was
     * asserted to be a member of the array and this loop failed on a correct fixture. The
     * negated form of the same mistake is worse and is why it is worth a comment:
     * `not->toContain($x, $message)` passes whenever the message is absent, which it always
     * is, so it is silently always-green.
     */
    foreach ($types as $type) {
        expect(in_array($type, $present, true))
            ->toBeTrue("the fixture carries no {$type} block, so no arrangement is swept for it");
    }

    foreach (LandingTemplate::cases() as $template) {
        $document = LandingDocument::fromInput(
            array_replace($page, ['template' => $template->value]),
            new StubMediaReferences,
        );

        $html = app(LandingRenderer::class)->render(
            $document,
            'olive.pages.qrpager.test',
            'Olive',
            [],
            TenantLanguage::English,
        );

        /*
         * ⚠ COUNTED BY `<section>`, NOT BY SEARCHING FOR EACH TYPE'S OWN COPY. A block type
         * leaves no name in the rendered page -- `block.blade.php` emits a `<section>` and the
         * tenant's own words -- so the honest proxy for "every block rendered" is that there
         * are as many sections as there are blocks. A text search would pass for an
         * arrangement that rendered a block twice and dropped another.
         */
        expect(substr_count($html, '<section>'))->toBe(
            count($page['blocks']),
            "the {$template->value} arrangement rendered a different number of blocks than the document holds",
        );

        // And one authored string per block type, so the sections are not empty shells.
        foreach (LandingFixtures::authoredTextPaths() as $path) {
            $value = data_get($page, $path);

            if (is_string($value) && $value !== '' && mb_strlen($value) > 3) {
                expect(str_contains($html, e($value)))->toBeTrue(
                    "the {$template->value} arrangement dropped the value at {$path}",
                );
            }
        }
    }
});

it('places the footer last in an arrangement that takes the order into its own hands', function (): void {
    // An arrangement that has already reordered the page cannot leave a footer stranded
    // mid-document, and `$rest` is exactly where that would happen. It was written against
    // `editorial`, which `QRPAGER-47` retires; `ember` is the arrangement that now promotes
    // the hero and reorders what follows, so it is the one that owes the clause.
    $document = LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), ['template' => 'ember']),
        new StubMediaReferences,
    );

    $html = app(LandingRenderer::class)->render(
        $document, 'olive.pages.qrpager.test', 'Olive', [], TenantLanguage::English,
    );

    $footerText = (string) data_get(LandingFixtures::fullPage(), 'blocks.6.fields.text');

    expect($footerText)->not->toBe('', 'the fixture has no footer block at the expected index');

    $footerAt = strpos($html, e($footerText));
    $lastSectionAt = strrpos($html, '<section>');

    expect($footerAt)->toBeInt()
        ->and($lastSectionAt)->toBeInt()
        ->and($footerAt)->toBeGreaterThan($lastSectionAt, 'the footer block is not the last section');

    // MAX_BLOCKS is read rather than asserted, so this file carries no second copy of a bound.
    expect(count(LandingFixtures::fullPage()['blocks']))->toBeLessThanOrEqual(BlockSchema::MAX_BLOCKS);
});
