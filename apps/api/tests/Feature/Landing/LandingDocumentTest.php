<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use Illuminate\Support\Str;
use Tests\Support\StubMediaReferences;

/*
 * The sanitisation boundary (§6.4, decision D5), driven field by field.
 *
 * WHAT THIS FILE IS FOR, as distinct from LandingXssFuzzTest: that one asks "can a payload
 * reach the rendered page", end to end. This one asks "does every rule in BlockSchema
 * actually bind", including the rules that have nothing to do with injection -- lengths,
 * required fields, list caps, unknown keys. A validator with one hole in a bound is not
 * an XSS, it is a 256 KB document that takes the panel down, and nothing in the fuzz
 * suite would notice.
 */

/** A minimal valid document: one hero, nothing optional set. */
function heroDocument(array $fields = [], array $extra = []): array
{
    return array_merge([
        'blocks' => [[
            'type' => 'hero',
            'fields' => array_merge(['heading' => 'Welcome'], $fields),
        ]],
        'theme' => [],
        'seo' => ['title' => null, 'description' => null, 'og_image' => null],
    ], $extra);
}

function parse(array $input, array $media = []): LandingDocument
{
    return LandingDocument::fromInput($input, new StubMediaReferences($media));
}

/** The LandingDocumentInvalid a closure throws, or null. */
function refusal(callable $callback): ?LandingDocumentInvalid
{
    try {
        $callback();

        return null;
    } catch (LandingDocumentInvalid $e) {
        return $e;
    }
}

it('accepts the smallest valid document, so every refusal below is discriminating', function (): void {
    // The positive control, and it is first on purpose. Without it every case in this
    // file would pass against a validator that refused everything -- R-059's shape.
    $document = parse(heroDocument());

    expect($document->blocks)->toHaveCount(1)
        ->and($document->blocks[0]['type'])->toBe(BlockType::Hero)
        ->and($document->blocks[0]['fields']['heading'])->toBe('Welcome')
        ->and($document->blocks[0]['fields']['subheading'])->toBeNull()
        ->and($document->isEmpty())->toBeFalse()
        ->and($document->mediaReferences())->toBe([]);
});

it('refuses a top-level key the schema does not have', function (): void {
    // structured_data is the case that matters: §6.4 says it is DERIVED and never
    // authored, so an input carrying it is refused rather than ignored. Silently
    // dropping it would let a tenant believe they had set it.
    $refusal = refusal(fn () => parse(heroDocument(extra: ['scripts' => ['https://evil.test/x.js']])));

    expect($refusal?->field)->toBe('document')
        ->and($refusal?->reason)->toContain('scripts');

    $seo = refusal(fn () => parse(array_merge(heroDocument(), [
        'seo' => ['title' => null, 'description' => null, 'og_image' => null,
            'structured_data' => ['@type' => 'Restaurant']],
    ])));

    expect($seo?->field)->toBe('seo')
        ->and($seo?->reason)->toContain('structured_data');
});

it('refuses an unknown block type and an unknown field inside a known block', function (): void {
    expect(refusal(fn () => parse(['blocks' => [['type' => 'script', 'fields' => []]]]))?->field)
        ->toBe('blocks.0.type');

    expect(refusal(fn () => parse(heroDocument(['onclick' => 'alert(1)'])))?->field)
        ->toBe('blocks.0.fields');
});

it('refuses a missing required field and accepts an absent optional one as null', function (): void {
    expect(refusal(fn () => parse(['blocks' => [['type' => 'hero', 'fields' => []]]]))?->field)
        ->toBe('blocks.0.fields.heading');

    // Empty string is the same as absent: a form posts '' for a cleared input, and
    // treating that as "a required field was supplied" is how a blank heading ships.
    expect(refusal(fn () => parse(heroDocument(['heading' => ''])))?->field)
        ->toBe('blocks.0.fields.heading');

    expect(parse(heroDocument())->blocks[0]['fields']['cta_url'])->toBeNull();
});

it('bounds text by CHARACTERS and not by bytes, because Arabic is a launch language', function (): void {
    // §12 names Arabic as a launch language. A bound in bytes gives an Arabic heading a
    // quarter of the room an English one gets, which is not a limit, it is a defect.
    $arabic = str_repeat('م', 80);

    expect(mb_strlen($arabic))->toBe(80)
        ->and(strlen($arabic))->toBe(160);

    expect(parse(heroDocument(['heading' => $arabic]))->blocks[0]['fields']['heading'])->toBe($arabic);

    expect(refusal(fn () => parse(heroDocument(['heading' => str_repeat('م', 81)])))?->reason)
        ->toContain('81 characters');
});

it('refuses every control character, newline included', function (): void {
    foreach (["a\nb", "a\rb", "a\tb", "a\x00b", "a\x1bb", "a\x7fb"] as $value) {
        // assertStringContainsString and not expect()->toContain(), because toContain is
        // VARIADIC: a second argument is a second NEEDLE, not a failure message. Rule 7 of
        // the repo guard exists for the ->not-> form of exactly this, and the positive
        // form cost this file one red run -- "To contain: accepted 610a62" is the error a
        // message-shaped second argument produces.
        $this->assertStringContainsString(
            'control characters',
            (string) refusal(fn () => parse(heroDocument(['heading' => $value])))?->reason,
            'accepted a heading containing 0x'.bin2hex($value),
        );
    }

    // Invalid UTF-8 is refused too: a lone continuation byte is what a truncated
    // multi-byte sequence looks like, and several downstream parsers disagree about it.
    expect(refusal(fn () => parse(heroDocument(['heading' => "bad\xC3"])))?->reason)
        ->toContain('UTF-8');
});

it('keeps markup characters as DATA rather than stripping them, which is the whole of D5', function (): void {
    // "Fish & Chips" is a menu item, not an attack, and a sanitiser would quietly change
    // what the restaurant wrote. The validator stores it verbatim; the renderer escapes it.
    $document = parse(heroDocument(['heading' => 'Fish & Chips <3']));

    expect($document->blocks[0]['fields']['heading'])->toBe('Fish & Chips <3');
});

it('refuses every url that is not an absolute https address', function (): void {
    $bad = [
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',
        'data:text/html;base64,PHNjcmlwdD4=',
        'vbscript:msgbox(1)',
        'http://example.test',
        '//example.test',
        '/relative',
        'https://user:pass@example.test',
        'https://example.test:8443',
        "https://exa\nmple.test",
        "https://example.test\x00.evil.test",
        'https://',
        'example.test',
        'https://exam ple.test',
    ];

    foreach ($bad as $url) {
        expect(refusal(fn () => parse(heroDocument(['cta_label' => 'Book', 'cta_url' => $url]))))
            ->not->toBeNull("[{$url}] was accepted as a url");
    }
});

it('re-assembles an accepted url from its parsed parts rather than passing it through', function (): void {
    // The mechanism, not a tidy-up: what is stored is built from what parse_url returned,
    // so anything parse_url did not understand cannot survive into the document.
    $document = parse(heroDocument([
        'cta_label' => 'Book',
        'cta_url' => 'https://EXAMPLE.test/book/a%20table?from=qr#top',
    ]));

    expect($document->blocks[0]['fields']['cta_url'])
        ->toBe('https://example.test/book/a%20table?from=qr#top');
});

it('refuses a map that is anything other than three numbers in range', function (): void {
    $map = fn (array $fields): array => ['blocks' => [['type' => 'hours_location', 'fields' => array_merge([
        // ONE hours row, because `hours` is the one required list in the schema: a
        // "Hours, location and map" block with no hours says nothing, and the validator,
        // the panel's Repeater and the generated contract all agree it needs one.
        'heading' => 'Find us', 'address' => '1 High Street',
        'hours' => [['day' => 'Monday', 'hours' => '11:00 to 21:00']],
    ], $fields)]]];

    // §6.4: "Never an embed, an iframe or a provider URL." There is no field that could
    // hold one -- which is the strongest form of that rule -- so the cases here are the
    // numeric ones, plus the proof that a provider URL has nowhere to go.
    expect(refusal(fn () => parse($map(['latitude' => 91]))))->not->toBeNull();
    expect(refusal(fn () => parse($map(['longitude' => -181]))))->not->toBeNull();
    expect(refusal(fn () => parse($map(['zoom' => 0]))))->not->toBeNull();
    expect(refusal(fn () => parse($map(['latitude' => 'NaN']))))->not->toBeNull();
    expect(refusal(fn () => parse($map(['latitude' => INF]))))->not->toBeNull();
    expect(refusal(fn () => parse($map(['embed' => '<iframe src="https://maps.example">']))))
        ->not->toBeNull();

    $ok = parse($map(['latitude' => 51.5, 'longitude' => -0.12, 'zoom' => 15]));
    expect($ok->blocks[0]['fields']['latitude'])->toBe(51.5);
});

it('bounds a list block and validates every item in it', function (): void {
    $gallery = fn (array $images): array => ['blocks' => [[
        'type' => 'gallery', 'fields' => ['images' => $images],
    ]]];

    $asset = (string) Str::uuid7();

    expect(refusal(fn () => parse(
        $gallery(array_fill(0, BlockSchema::MAX_ITEMS + 1, ['image' => $asset, 'caption' => 'x'])),
        [$asset],
    ))?->reason)->toContain('at most '.BlockSchema::MAX_ITEMS);

    // A caption is REQUIRED, and that is an accessibility decision rather than a content
    // one: it is the img alt text, and §12's bar does not allow unlabelled images.
    expect(refusal(fn () => parse($gallery([['image' => $asset]]), [$asset]))?->field)
        ->toBe('blocks.0.fields.images.0.caption');

    expect(refusal(fn () => parse($gallery(['not-an-object']), [$asset]))?->field)
        ->toBe('blocks.0.fields.images.0');

    expect(refusal(fn () => parse($gallery(['a' => ['image' => $asset, 'caption' => 'x']]), [$asset]))?->reason)
        ->toContain('ordered list');
});

it('refuses a media id that is not this tenants, with the same words as one that does not exist', function (): void {
    $mine = (string) Str::uuid7();
    $theirs = (string) Str::uuid7();

    $document = parse(heroDocument(['image' => $mine]), [$mine]);
    expect($document->mediaReferences())->toBe([$mine]);

    // The two refusals are IDENTICAL, deliberately. A different message for "exists but
    // is not yours" would make the field an existence oracle for other tenants' media.
    $foreign = refusal(fn () => parse(heroDocument(['image' => $theirs]), [$mine]));
    $absent = refusal(fn () => parse(heroDocument(['image' => (string) Str::uuid7()]), [$mine]));

    expect($foreign?->reason)->toBe($absent?->reason)
        ->and($foreign?->reason)->toContain('not in your library');

    expect(refusal(fn () => parse(heroDocument(['image' => 'not-a-uuid']), [$mine]))?->reason)
        ->toContain('not an image reference');
});

it('bounds the number of media references over the whole document, not per list', function (): void {
    // The per-list caps sum to more than the document cap, which is why this is checked
    // over the document. It is the number §6.5's cost argument rests on: one server-side
    // copy per reference, so a publish costs O(this).
    $assets = [];

    for ($i = 0; $i <= BlockSchema::MAX_MEDIA_REFERENCES; $i++) {
        $assets[] = (string) Str::uuid7();
    }

    $images = array_map(
        static fn (string $id): array => ['image' => $id, 'caption' => 'x'],
        array_slice($assets, 0, BlockSchema::MAX_ITEMS),
    );

    $more = array_map(
        static fn (string $id): array => ['name' => 'Dish', 'image' => $id],
        array_slice($assets, BlockSchema::MAX_ITEMS, BlockSchema::MAX_ITEMS),
    );

    $input = [
        'blocks' => [
            ['type' => 'gallery', 'fields' => ['images' => $images]],
            ['type' => 'menu_highlights', 'fields' => ['heading' => 'Menu', 'items' => $more]],
            ['type' => 'hero', 'fields' => ['heading' => 'Hi', 'image' => $assets[24]]],
        ],
    ];

    expect(refusal(fn () => parse($input, $assets))?->reason)
        ->toContain('at most '.BlockSchema::MAX_MEDIA_REFERENCES.' images');

    // And the same document one reference lighter is accepted, so the cap is a cap and
    // not a refusal of every document with images in it.
    array_pop($input['blocks']);
    expect(parse($input, $assets)->mediaReferences())->toHaveCount(BlockSchema::MAX_MEDIA_REFERENCES);
});

it('bounds the number of blocks', function (): void {
    $blocks = array_fill(0, BlockSchema::MAX_BLOCKS + 1, ['type' => 'hero', 'fields' => ['heading' => 'x']]);

    expect(refusal(fn () => parse(['blocks' => $blocks]))?->reason)
        ->toContain('at most '.BlockSchema::MAX_BLOCKS.' blocks');

    array_pop($blocks);
    expect(parse(['blocks' => $blocks])->blocks)->toHaveCount(BlockSchema::MAX_BLOCKS);
});

it('refuses a telephone that could carry a tel: parameter, and an address that is not one', function (): void {
    $contact = fn (array $fields): array => ['blocks' => [[
        'type' => 'contact', 'fields' => array_merge(['heading' => 'Contact'], $fields),
    ]]];

    // `,` is a dial pause in a tel: URI and `;` introduces a parameter. Neither may reach
    // one, so neither is in the accepted character set.
    foreach (['+44 20,7946 0000', '+44 20;phone-context=x', 'call me', '+44(20)7946', '123'] as $bad) {
        expect(refusal(fn () => parse($contact(['phone' => $bad]))))
            ->not->toBeNull("[{$bad}] was accepted as a telephone number");
    }

    expect(parse($contact(['phone' => '+44 20 7946 0000']))->blocks[0]['fields']['phone'])
        ->toBe('+44 20 7946 0000');

    foreach (['not-an-email', 'a@b', '<script>@evil.test', "a@b.test\nbcc: c@d.test"] as $bad) {
        expect(refusal(fn () => parse($contact(['email' => $bad]))))
            ->not->toBeNull("[{$bad}] was accepted as an email address");
    }

    // Lower-cased on the way in, so the rendered mailto: is stable.
    expect(parse($contact(['email' => 'Hello@Example.Test']))->blocks[0]['fields']['email'])
        ->toBe('hello@example.test');
});

it('round-trips through toArray and back, which is what a stored draft does', function (): void {
    $asset = (string) Str::uuid7();

    $document = parse(heroDocument(['image' => $asset], [
        'theme' => ['color.accent' => '#ff0000', 'font.body' => 'serif'],
        'seo' => ['title' => 'Our page', 'description' => null, 'og_image' => $asset],
    ]), [$asset]);

    $again = LandingDocument::fromStored($document->toArray(), new StubMediaReferences([$asset]));

    expect($again->toArray())->toBe($document->toArray());
});

it('re-validates a STORED document, because an asset it referenced can since have been deleted', function (): void {
    // Trusting stored data because it was validated once is exactly how a stored XSS
    // differs from a reflected one. fromStored() goes through the same validator.
    $asset = (string) Str::uuid7();
    $stored = parse(heroDocument(['image' => $asset]), [$asset])->toArray();

    expect(refusal(fn () => LandingDocument::fromStored($stored, new StubMediaReferences([]))))
        ->not->toBeNull();
});

it('refuses an empty required list, which the panel refuses too', function (): void {
    // THE AGREEMENT THIS RULE EXISTS FOR. The form's Repeater and this validator used to
    // disagree: `required()` on a Repeater means "at least one item" and this class
    // accepted `[]`, so a document the panel would not let a tenant save was one this
    // class called valid. Found by driving the builder's save action, which is the only
    // path that runs both.
    $hours = fn (array $rows): array => ['blocks' => [['type' => 'hours_location', 'fields' => [
        'heading' => 'Find us', 'address' => '1 High Street', 'hours' => $rows,
    ]]]];

    expect(refusal(fn () => parse($hours([])))?->reason)->toContain('at least one item');
    expect(refusal(fn () => parse($hours([['day' => 'Monday', 'hours' => '11:00']]))))->toBeNull();

    // And an OPTIONAL list may be empty, which is the other half: a block whose list is
    // empty is a section the tenant added and has not filled in yet.
    expect(refusal(fn () => parse(['blocks' => [
        ['type' => 'gallery', 'fields' => ['images' => []]],
    ]])))->toBeNull();
});
