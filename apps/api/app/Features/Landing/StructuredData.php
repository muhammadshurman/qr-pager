<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use Illuminate\Contracts\Support\Htmlable;

/**
 * ================================================================================
 * THE PUBLISHED PAGE's JSON-LD, AND THE ENCODER THAT MAKES IT SAFE — R-131
 * ================================================================================
 *
 * `§3` row 11's gate asks for structured data. `landing.page`'s own docblock explains, at
 * length and correctly, why Task 7 did not emit it:
 *
 *   *"The content of that element is JSON, not HTML -- so `{{ }}` is the WRONG encoder for
 *   it: Blade would turn the JSON's own string delimiters into `&quot;` and a script
 *   element's content is raw text, where entities are never decoded. The result would be
 *   invalid JSON that looks fine in the page source. The only correct encoders for that
 *   position are `{!! !!}` with `JSON_HEX_TAG`, or an Htmlable that amounts to the same
 *   thing. Decision D5 says `{!! !!}` appears NOWHERE in this template, and
 *   LandingXssFuzzTest asserts that about this file's source."*
 *
 * **This class is the Htmlable.** It is the second of the two options that note names, and it
 * is the better one: the encoding decision moves out of a template and into a class a test
 * can drive with hostile input, while the template keeps `{{ }}` on every line and D5's
 * mechanical check stays exactly as strict as it was. Nothing is carved out.
 *
 * ⚠ **FOUR `JSON_HEX_*` FLAGS, AND EVERY ONE OF THEM IS LOAD-BEARING.** `JSON_HEX_TAG` turns
 * `<` and `>` into `<`/`>`, which is what makes `</script>` inside a tenant's
 * restaurant name unable to close the element -- the single failure this whole position is
 * about. `JSON_HEX_AMP`, `JSON_HEX_APOS` and `JSON_HEX_QUOT` are not needed for THAT, and
 * they are here because the cost is nothing and the next person to move this value into an
 * attribute should not have to re-derive which flags were enough for which position.
 * `JSON_UNESCAPED_SLASHES` is deliberately NOT set, so `/` stays `\/` and `</` cannot appear
 * even if a flag above is ever dropped. Belt, and braces, and one more belt.
 *
 * ⚠ **AND EVERY VALUE IS DERIVED, NEVER AUTHORED** — `QRPAGER-14 §6.4`'s clause. A tenant
 * cannot write a `structured_data` field, because there is no such field in `BlockSchema`:
 * what reaches here is the SEO title and description, the tenant's name, its hostname and the
 * address and contact fields of blocks it already published. A `@type` a tenant could choose
 * would be the misrepresentation `QRPAGER-18 §5`'s fourth trap names.
 */
final class StructuredData implements Htmlable
{
    /**
     * The only terms this class emits, and the assertion that keeps it honest.
     *
     * ⚠ **THIS IS A COMMITTED VOCABULARY CHECK AND NOT A LIVE SCHEMA.ORG VALIDATION, WHICH IS
     * WORTH SAYING RATHER THAN IMPLYING.** `QRPAGER-18 §5` says *"validate it against the
     * spec, don't hand-roll it"*, and the honest position is that this build machine has no
     * network and schema.org's vocabulary is not a committed artefact of this repository. So
     * what is enforced is narrower and real: the emitted document uses **only** these terms,
     * `StructuredDataTest` asserts that set equality in both directions, and each term below
     * is one whose meaning for a `Restaurant` is not in doubt. A term added here without a
     * test fails; a term emitted without being here fails.
     *
     * What is NOT claimed: that schema.org still defines each of them the same way next year.
     * That is a review item rather than something a test can hold, and it is why the list is
     * short.
     *
     * @var list<string>
     */
    public const TERMS = [
        '@context',
        '@type',
        'name',
        'url',
        'description',
        'address',
        'streetAddress',
        'openingHours',
        'telephone',
        'email',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * Build the document, or null when there is nothing true to say.
     *
     * **NULL RATHER THAN A SKELETON.** A JSON-LD block carrying only `@type: Restaurant` and a
     * slug-derived name tells a crawler nothing and asserts a business exists at a URL on the
     * strength of a draft. The element is omitted instead, which is the honest absence.
     */
    public static function for(
        LandingDocument $document,
        string $hostname,
        string $tenantName,
    ): ?self {
        $seo = $document->seo;
        $name = $seo['title'] ?? $tenantName;

        if (trim($name) === '') {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => $name,
            'url' => 'https://'.$hostname.'/',
        ];

        if (($seo['description'] ?? null) !== null && trim((string) $seo['description']) !== '') {
            $data['description'] = (string) $seo['description'];
        }

        /** @var list<array{type: string, fields: array<string, mixed>}> $blocks */
        $blocks = $document->toArray()['blocks'];

        foreach ($blocks as $block) {
            if ($block['type'] === 'hours_location') {
                $address = $block['fields']['address'] ?? null;

                if (is_string($address) && trim($address) !== '') {
                    $data['address'] = [
                        '@type' => 'PostalAddress',
                        'streetAddress' => $address,
                    ];
                }

                $hours = $block['fields']['hours'] ?? null;

                if (is_array($hours) && $hours !== []) {
                    // `openingHours` is a list of free-text strings in schema.org, which is
                    // what the tenant authored. It is NOT reformatted into the
                    // `Mo-Fr 08:00-22:00` grammar: that grammar is a claim about which days a
                    // string means, and parsing "Monday to Friday" to produce it would be a
                    // guess published as structured fact.
                    $data['openingHours'] = array_values(array_map(
                        static fn (array $row): string => trim(
                            ((string) ($row['day'] ?? '')).' '.((string) ($row['hours'] ?? ''))
                        ),
                        array_filter($hours, 'is_array'),
                    ));
                }
            }

            if ($block['type'] === 'contact') {
                foreach (['telephone' => 'phone', 'email' => 'email'] as $term => $field) {
                    $value = $block['fields'][$field] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        $data[$term] = $value;
                    }
                }
            }
        }

        return new self($data);
    }

    /** The encoded JSON, exactly as it reaches the element. */
    public function toHtml(): string
    {
        return (string) json_encode(
            $this->data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
