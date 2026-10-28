<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The generated shared contract: BlockSchema as a JSON Schema document (§6.2, D4).
 *
 * ================================================================================
 * WHY THIS IS GENERATED AND NOT WRITTEN
 * ================================================================================
 *
 * Task 11's renderer consumes `docs/contracts/landing-page.schema.json`. If that file were
 * hand-written it would be a SECOND definition of a block, and the two would agree only
 * for as long as somebody kept them agreeing -- which §6's "one source only. Never a
 * duplicated enum" forbids in those words, and which `packages/contracts` is already
 * generated for on the TypeScript side.
 *
 * `php artisan landing:schema-export` writes it; `bin/landing-schema-check` re-exports and
 * diffs; both bin/gate and CI run the check. The committed artifact therefore cannot go
 * stale without something going red -- the same mechanism `openapi:check` already is, for
 * the same reason: a stale generated file is the quietest kind of wrong.
 *
 * THE ENCODING IS PART OF THE CONTRACT. The check is a byte diff, so the document has to
 * be deterministic: arrays in schema order, no sorting that depends on locale, and the
 * encoder flags pinned in one place (self::encode()). A generator whose output moves
 * between runs makes the check cry wolf and then get ignored.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it. The command in
 * Features does the file I/O.
 */
final class LandingSchema
{
    public const ID = 'https://qrpager.local/contracts/landing-page.schema.json';

    public const RELATIVE_PATH = 'docs/contracts/landing-page.schema.json';

    /** Characters a text field may never contain: C0 and DEL (LandingDocument::text()). */
    private const TEXT_PATTERN = '^[^\u0000-\u001F\u007F]*$';

    private const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    /**
     * The whole document, as a nested array ready to encode.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => self::ID,
            'title' => 'QR Pager tenant landing page',
            'description' => 'Generated from App\Domain\Landing\BlockSchema by '
                .'`php artisan landing:schema-export`. Do not edit by hand: '
                .'`bin/landing-schema-check` fails when this file and the PHP source disagree.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => LandingDocument::KEYS,
            'properties' => [
                'blocks' => [
                    'type' => 'array',
                    'maxItems' => BlockSchema::MAX_BLOCKS,
                    'items' => ['oneOf' => self::blocks()],
                ],
                'theme' => self::theme(),
                'seo' => self::seo(),
            ],
            // Not a JSON Schema keyword, and deliberately kept: a consumer that enforces
            // only the schema would miss the document-level media cap, which is a bound on
            // the COST of a publish rather than on the shape of a document. Stated here so
            // Task 11's renderer can read the number rather than rediscover it.
            'x-bounds' => [
                'maxMediaReferences' => BlockSchema::MAX_MEDIA_REFERENCES,
                'maxDocumentBytes' => BlockSchema::MAX_DOCUMENT_BYTES,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function blocks(): array
    {
        $blocks = [];

        foreach (BlockType::cases() as $type) {
            $fields = BlockSchema::fields($type);

            $blocks[] = [
                'title' => $type->label(),
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['type', 'fields'],
                'properties' => [
                    'type' => ['const' => $type->value],
                    'fields' => self::fieldMap($fields),
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, mixed>
     */
    private static function fieldMap(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $name => $field) {
            $properties[$name] = self::field($field);

            if ($field->required) {
                $required[] = $name;
            }
        }

        $map = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
        ];

        // Omitted entirely when empty: `"required": []` is legal and noisy, and an empty
        // key that appears and disappears is exactly the kind of churn a byte diff reports.
        if ($required !== []) {
            $map['required'] = $required;
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private static function field(Field $field): array
    {
        // An optional field is nullable in the stored document: LandingDocument writes
        // null for an absent one rather than omitting the key, so a consumer never has to
        // distinguish "absent" from "cleared".
        $nullable = static fn (string $type): array|string => $field->required ? $type : [$type, 'null'];

        return match ($field->kind) {
            FieldKind::Text => [
                'type' => $nullable('string'),
                'maxLength' => $field->maxLength,
                'pattern' => self::TEXT_PATTERN,
            ],
            FieldKind::Url => [
                'type' => $nullable('string'),
                'format' => 'uri',
                'pattern' => '^https://',
                'maxLength' => $field->maxLength,
            ],
            FieldKind::Email => [
                'type' => $nullable('string'),
                'format' => 'email',
                'maxLength' => $field->maxLength,
            ],
            FieldKind::Telephone => [
                'type' => $nullable('string'),
                'pattern' => '^\+?[0-9][0-9 \-]{4,30}$',
            ],
            FieldKind::Media => [
                'type' => $nullable('string'),
                'description' => 'A media_assets id belonging to this tenant. A foreign id '
                    .'resolves to nothing and the publish is refused.',
                'pattern' => self::UUID_PATTERN,
            ],
            FieldKind::Number => [
                'type' => $nullable('number'),
                'minimum' => $field->min,
                'maximum' => $field->max,
            ],
            FieldKind::ItemList => array_filter([
                'type' => $nullable('array'),
                // A required list means AT LEAST ONE ITEM, in the contract as well as in
                // the validator and in the panel's Repeater -- all three agree.
                'minItems' => $field->required ? 1 : null,
                'maxItems' => $field->maxItems,
                'items' => self::fieldMap($field->item ?? []),
            ], static fn (mixed $value): bool => $value !== null),
        };
    }

    /** @return array<string, mixed> */
    private static function theme(): array
    {
        $properties = [];

        foreach (ThemeTokens::NAMES as $name => $kind) {
            $properties[$name] = match ($kind) {
                ThemeTokenKind::Colour => [
                    'type' => 'string',
                    'pattern' => '^#(?:[0-9a-f]{3}|[0-9a-f]{6})$',
                ],
                ThemeTokenKind::Length => [
                    'type' => 'string',
                    'pattern' => '^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?(?:px|rem|em|%)$',
                ],
                ThemeTokenKind::FontChoice => [
                    'type' => 'string',
                    'enum' => array_keys(ThemeTokens::STACKS),
                ],
            };
        }

        return [
            'type' => 'object',
            'description' => 'A theme overrides TOKENS ONLY, never components. '
                .'additionalProperties is false, which is that rule in the contract.',
            'additionalProperties' => false,
            'properties' => $properties,
        ];
    }

    /** @return array<string, mixed> */
    private static function seo(): array
    {
        return [
            'type' => 'object',
            'description' => 'structured_data is DERIVED from these and is never authored, '
                .'so it is absent here and an input carrying it is refused.',
            'additionalProperties' => false,
            'required' => ['title', 'description', 'og_image'],
            'properties' => [
                'title' => [
                    'type' => ['string', 'null'],
                    'maxLength' => BlockSchema::MAX_SEO_TITLE,
                    'pattern' => self::TEXT_PATTERN,
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'maxLength' => BlockSchema::MAX_SEO_DESCRIPTION,
                    'pattern' => self::TEXT_PATTERN,
                ],
                'og_image' => [
                    'type' => ['string', 'null'],
                    'pattern' => self::UUID_PATTERN,
                ],
            ],
        ];
    }

    /**
     * The exact bytes of the committed artifact.
     *
     * ONE place decides the encoding, because the check is a byte diff and a flag that
     * differs between the writer and the checker would report a stale file forever.
     * UNESCAPED_SLASHES and UNESCAPED_UNICODE keep the patterns readable; the trailing
     * newline is what makes the file a well-formed text file rather than one git reports
     * as having no newline at end of file.
     */
    public static function encode(): string
    {
        return json_encode(
            self::document(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
