<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\LandingSchema;
use App\Domain\Landing\ThemeTokens;
use Illuminate\Support\Facades\Artisan;

/*
 * §6's shared-contract clause, for Task 7's contract:
 *
 *   "Any shared contract (status enum, DTO) is generated from its single source,
 *    committed, and CI fails when it is stale -- never hand-copied across languages."
 *
 * docs/contracts/landing-page.schema.json is what Task 11's Next.js renderer consumes.
 * There is no second definition of a block anywhere, and these cases are what keeps that
 * true: the committed bytes match the generator, the generator describes every block and
 * every field, and the staleness check BITES -- proven, not assumed, because an
 * always-green check is indistinguishable from no check at all (R-054, R-059).
 */

function committedSchemaPath(): string
{
    return dirname(base_path(), 2).'/'.LandingSchema::RELATIVE_PATH;
}

it('has the generated contract committed, byte for byte', function (): void {
    expect(is_file(committedSchemaPath()))->toBeTrue(LandingSchema::RELATIVE_PATH.' is not committed');

    expect(file_get_contents(committedSchemaPath()))->toBe(LandingSchema::encode());
});

it('generates the same bytes three times running, so the check cannot cry wolf', function (): void {
    // A byte diff over a generator whose output moves between runs is a check that fails
    // for no reason and then gets ignored. bin/openapi-check records the same measurement
    // for the same reason.
    expect(LandingSchema::encode())->toBe(LandingSchema::encode())
        ->and(LandingSchema::encode())->toBe(LandingSchema::encode());
});

it('describes every block type and every field of each, so the exporter cannot drop one', function (): void {
    $document = LandingSchema::document();
    $variants = $document['properties']['blocks']['items']['oneOf'];

    expect($variants)->toHaveCount(count(BlockType::cases()));

    $described = [];

    foreach ($variants as $variant) {
        $type = $variant['properties']['type']['const'];
        $described[] = $type;

        // additionalProperties false at BOTH levels: the block object and its fields. A
        // schema that let unknown keys through would describe a document the validator
        // refuses, which is worse than no schema -- Task 11 would build against it.
        expect($variant['additionalProperties'])->toBeFalse()
            ->and($variant['properties']['fields']['additionalProperties'])->toBeFalse();

        $expected = array_keys(BlockSchema::fields(BlockType::from($type)));
        expect(array_keys($variant['properties']['fields']['properties']))->toBe($expected);

        foreach (BlockSchema::fields(BlockType::from($type)) as $name => $field) {
            $property = $variant['properties']['fields']['properties'][$name];

            // A required field is a bare type; an optional one is nullable, because
            // LandingDocument writes null for an absent field rather than omitting it.
            expect(is_array($property['type']))->toBe(! $field->required, "{$type}.{$name}");
        }
    }

    expect($described)->toBe(BlockType::values());
});

it('pins the theme to exactly the token list, which is the tokens-only rule in the contract', function (): void {
    $theme = LandingSchema::document()['properties']['theme'];

    expect($theme['additionalProperties'])->toBeFalse()
        ->and(array_keys($theme['properties']))->toBe(array_keys(ThemeTokens::NAMES));

    // A font is a CHOICE from a list, never a stack the tenant writes -- so the schema
    // carries the four keys and never a free string.
    expect($theme['properties']['font.body']['enum'])->toBe(array_keys(ThemeTokens::STACKS));
});

it('carries the document-level bounds a JSON Schema keyword cannot express', function (): void {
    // x-bounds is not a JSON Schema keyword and is kept deliberately: a consumer that
    // enforced only the schema would miss the media cap, which bounds the COST of a
    // publish rather than the shape of a document.
    expect(LandingSchema::document()['x-bounds'])->toBe([
        'maxMediaReferences' => BlockSchema::MAX_MEDIA_REFERENCES,
        'maxDocumentBytes' => BlockSchema::MAX_DOCUMENT_BYTES,
    ]);
});

it('fails the staleness check on a modified artifact, and passes on the real one', function (): void {
    // THE CHECK IS PROVEN TO BITE, against a SCRATCH COPY and never the committed file.
    // R-018 is the §5 row recording that every `finally` in this repository is correct and
    // none of them survives a kill -9, an OOM or a CI timeout -- so a test that overwrote
    // the real contract and restored it afterwards would leave a corrupted committed
    // artifact behind on any hard stop. QA L-2 made the same call for bin/openapi-check.
    $scratch = tempnam(sys_get_temp_dir(), 'qrpager-landing-schema-');

    try {
        // Current: passes.
        file_put_contents($scratch, LandingSchema::encode());
        expect(Artisan::call('landing:schema-export', ['--check' => true, '--path' => $scratch]))->toBe(0);

        // One byte different: fails. `24` -> `25` on the block cap is the smallest change
        // that a consumer would actually get wrong.
        file_put_contents($scratch, str_replace('"maxItems": 24', '"maxItems": 25', LandingSchema::encode()));
        expect(Artisan::call('landing:schema-export', ['--check' => true, '--path' => $scratch]))->toBe(1);

        // Absent: fails too, rather than being treated as "nothing to compare".
        unlink($scratch);
        expect(Artisan::call('landing:schema-export', ['--check' => true, '--path' => $scratch]))->toBe(1);
    } finally {
        @unlink($scratch);
    }

    // And the real one, through the real path, with no option at all.
    expect(Artisan::call('landing:schema-export', ['--check' => true]))->toBe(0);
});
