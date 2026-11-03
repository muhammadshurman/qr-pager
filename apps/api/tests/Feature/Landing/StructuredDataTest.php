<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocument;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\StructuredData;
use Illuminate\Support\Facades\DB;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * R-131's JSON-LD, AND THE ENCODER IS THE SUBJECT — Task 11 (QRPAGER-18)
 * ================================================================================
 *
 * `§3` row 11's gate asks for structured data. Task 7 deferred it with a precise argument:
 * the content of a `<script type="application/ld+json">` is JSON, not HTML, so `{{ }}` would
 * turn the JSON's own quotes into `&quot;` and produce invalid JSON that looks fine in the
 * page source — while `{!! !!}` is forbidden in that template by decision D5, which
 * `LandingXssFuzzTest` asserts about the file's SOURCE.
 *
 * `StructuredData` is the third way that note itself named: an `Htmlable`, which `{{ }}`
 * passes through unescaped. The encoding decision therefore moved out of a template line and
 * into a class — **which is the only reason this file can exist.** A template line cannot be
 * driven with a restaurant called `</script><img onerror=…>`; this can, and does.
 *
 * ⚠ **THE LOAD-BEARING CASE IS THE FIRST ONE BELOW.** Everything else here is shape.
 */

beforeEach(fn () => LandingFixtures::bucket());

/** @param array<string, mixed> $overrides */
function structuredFor(array $overrides = []): ?StructuredData
{
    $input = array_replace_recursive([
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'Hi']]],
        'theme' => [],
        'seo' => ['title' => 'Alpha Cafe', 'description' => 'A cafe.'],
    ], $overrides);

    return StructuredData::for(
        LandingDocument::fromInput($input, new StubMediaReferences),
        'alpha.test',
        'Alpha Cafe',
    );
}

it('cannot close its own element, whatever a tenant calls their restaurant', function (): void {
    /*
     * ⚠ THE ONE CASE THIS CLASS EXISTS FOR. A `</script>` in an SEO title is the entire
     * threat at this position: the element's content is RAW TEXT, so nothing after a literal
     * `</script` is inside it any more and everything after it is markup.
     *
     * Driven against the ENCODED OUTPUT rather than the array: what matters is the bytes.
     */
    $hostile = [
        '</script><img src=x onerror=alert(1)>',
        '</SCRIPT ><script>alert(1)</script>',
        '</script\u0000>',
        '<!--</script>-->',
        '</script>',
        '"}]]><script>alert(1)</script>',
    ];

    foreach ($hostile as $title) {
        $html = structuredFor(['seo' => ['title' => $title]])?->toHtml() ?? '';

        expect($html)->not->toBeEmpty();

        // Not one of the three characters that could leave the element or open a tag.
        expect(str_contains($html, '<'))->toBeFalse("'{$title}' put a '<' in the JSON");
        expect(str_contains($html, '>'))->toBeFalse("'{$title}' put a '>' in the JSON");
        // `</` cannot appear even if JSON_HEX_TAG were ever dropped, because slashes stay
        // escaped -- which is why JSON_UNESCAPED_SLASHES is deliberately not set.
        expect(str_contains($html, '</'))->toBeFalse("'{$title}' put a '</' in the JSON");

        // AND IT IS STILL VALID JSON, which is the half a naive escaper fails: the value has
        // to survive the round trip, not merely be unrecognisable.
        $decoded = json_decode($html, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded)->toBeArray();
        expect($decoded['name'])->toBe($title, 'the title did not survive the round trip');
    }
});

it('reaches the published page as JSON that parses, with the element intact', function (): void {
    // End to end through the real renderer and the real template: the bytes a crawler reads.
    $tenant = $this->makeTenant(slug: 'structured');

    app(LandingPages::class)->saveDraft($tenant, [
        'blocks' => [
            ['type' => 'hero', 'fields' => ['heading' => 'Alpha Cafe']],
            [
                'type' => 'hours_location',
                'fields' => [
                    'heading' => 'Hours',
                    'address' => '1 Alpha Street</script>',
                    'hours' => [['day' => 'Monday', 'hours' => '08:00 - 22:00']],
                ],
            ],
            ['type' => 'contact', 'fields' => ['heading' => 'Contact', 'email' => 'a@example.test']],
        ],
        'theme' => [],
        'seo' => ['title' => 'Alpha Cafe', 'description' => 'A cafe.'],
    ]);

    app(LandingPages::class)->publish($tenant);

    $hostname = withTenant($tenant, fn (): string => (string) DB::table('landing_pages')
        ->where('tenant_id', $tenant)
        ->value('published_hostname'));

    $html = (string) MediaLibrary::disk()
        ->get(PublishedSite::pointer($hostname));

    expect($html)->toContain('<script type="application/ld+json">');

    // Extracted from the served bytes and parsed, which is the only assertion that proves the
    // encoder works in the position it is used in.
    expect(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m))->toBe(1);

    $decoded = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['@type'])->toBe('Restaurant');
    expect($decoded['name'])->toBe('Alpha Cafe');
    expect($decoded['url'])->toBe('https://'.$hostname.'/');
    expect($decoded['address']['streetAddress'])->toBe('1 Alpha Street</script>');
    expect($decoded['email'])->toBe('a@example.test');

    // Exactly ONE script element in the whole page, and it is this one.
    expect(substr_count($html, '<script'))->toBe(1);
});

it('emits only terms it declares, in both directions', function (): void {
    /*
     * The vocabulary check `StructuredData::TERMS` exists for, and its limits are stated in
     * that constant's own docblock: this is a committed term list, not a live schema.org
     * validation, because this machine has no network and schema.org's vocabulary is not an
     * artefact of this repository.
     *
     * Both directions: a term emitted without being declared fails, and a term declared that
     * nothing can emit fails too -- the second half is what stops the list from growing into
     * a wish list that permits anything.
     */
    $document = structuredFor([
        'blocks' => [
            ['type' => 'hero', 'fields' => ['heading' => 'Hi']],
            [
                'type' => 'hours_location',
                'fields' => [
                    'heading' => 'Hours',
                    'address' => '1 Alpha Street',
                    'hours' => [['day' => 'Monday', 'hours' => '08:00 - 22:00']],
                ],
            ],
            [
                'type' => 'contact',
                'fields' => ['heading' => 'Contact', 'email' => 'a@example.test', 'phone' => '+10000000'],
            ],
        ],
    ]);

    expect($document)->not->toBeNull();

    $keys = [];
    $walk = function (array $node) use (&$walk, &$keys): void {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($document->toArray());

    $keys = array_values(array_unique($keys));

    expect(array_diff($keys, StructuredData::TERMS))->toBe(
        [],
        'the document emits a term StructuredData::TERMS does not declare'
    );
    expect(array_diff(StructuredData::TERMS, $keys))->toBe(
        [],
        'StructuredData::TERMS declares a term nothing can emit -- a list that permits more '
        .'than the code produces is not a vocabulary check'
    );
});

it('omits the element rather than asserting a business exists on no evidence', function (): void {
    // NULL rather than a skeleton: `@type: Restaurant` plus a slug-derived name tells a
    // crawler nothing and makes a claim on the strength of a draft.
    expect(structuredFor(['seo' => ['title' => '   ']]))->toBeNull();
});

it('does not reformat authored opening hours into a grammar it would be guessing', function (): void {
    /*
     * schema.org's `openingHours` has a `Mo-Fr 08:00-22:00` grammar. Producing it from
     * "Monday to Friday" means PARSING a free-text day range and publishing the result as
     * structured fact -- and `QRPAGER-18 §5`'s fourth trap is exactly that: *"Structured data
     * that misrepresents the business is an SEO penalty and a trust problem."* The authored
     * strings are passed through instead.
     */
    $document = structuredFor([
        'blocks' => [
            ['type' => 'hero', 'fields' => ['heading' => 'Hi']],
            [
                'type' => 'hours_location',
                'fields' => [
                    'heading' => 'Hours',
                    'address' => '1 Alpha Street',
                    'hours' => [
                        ['day' => 'Monday to Friday', 'hours' => '08:00 - 22:00'],
                        ['day' => 'Weekends', 'hours' => 'closed'],
                    ],
                ],
            ],
        ],
    ]);

    expect($document?->toArray()['openingHours'])->toBe([
        'Monday to Friday 08:00 - 22:00',
        'Weekends closed',
    ]);
});

it('names a tenant’s own host and never a template default', function (): void {
    expect(structuredFor()?->toArray()['url'])->toBe('https://alpha.test/');
});
