<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * Every block's fields, their kinds and their bounds -- THE source (QRPAGER-14 §6.2, D4).
 *
 * ================================================================================
 * THERE IS NO SECOND DEFINITION OF A BLOCK ANYWHERE
 * ================================================================================
 *
 * Three things read this and nothing hand-copies it:
 *
 *   - LandingDocument::fromInput()  validates against it (the refusal boundary, D5)
 *   - LandingSchemaExport           generates docs/contracts/landing-page.schema.json
 *   - the renderer                  walks it to decide what each block may contain
 *
 * `bin/landing-schema-check` re-exports and diffs, as a step in bin/gate and in CI, so the
 * committed artifact cannot go stale -- the same mechanism openapi:check already is, for
 * the same reason: a stale generated file is the quietest kind of wrong.
 *
 * ================================================================================
 * EVERY BOUND HERE IS A VALIDATOR RULE WITH A TEST, NOT A COMMENT
 * ================================================================================
 *
 * §6.5's bounds: at most MAX_BLOCKS blocks per page, at most 12 items in a list block, and
 * at most MAX_MEDIA_REFERENCES media references across the whole document including the
 * SEO image. The per-list caps alone sum to more than the document cap, which is
 * deliberate: the document cap is what actually bounds the cost of a publish (one
 * server-side copy per referenced asset), so it is checked over the whole document rather
 * than inferred from the lists.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
final class BlockSchema
{
    /**
     * At most this many blocks on a page. Blocks REPEAT -- two galleries is a legitimate
     * page -- which is what makes this bound reachable and therefore worth having.
     */
    public const MAX_BLOCKS = 24;

    /** At most this many items in any list block (§6.5). */
    public const MAX_ITEMS = 12;

    /**
     * At most this many media references in one document, SEO image included.
     *
     * This is the number §6.5's cost argument rests on: a publish copies one asset per
     * reference, so the cost of a publish is O(this) and nothing else.
     */
    public const MAX_MEDIA_REFERENCES = 24;

    /** The draft document's hard ceiling, matching landing_pages_draft_is_bounded. */
    public const MAX_DOCUMENT_BYTES = 262144;

    /** SEO bounds (§6.4). */
    public const MAX_SEO_TITLE = 60;

    public const MAX_SEO_DESCRIPTION = 160;

    /**
     * The field map for one block type.
     *
     * @return array<string, Field>
     */
    public static function fields(BlockType $type): array
    {
        return match ($type) {
            BlockType::Hero => [
                'heading' => Field::text(80),
                'subheading' => Field::text(160, required: false),
                'image' => Field::media(required: false),
                'cta_label' => Field::text(40, required: false),
                'cta_url' => Field::url(required: false),
            ],
            BlockType::HowItWorks => [
                'heading' => Field::text(80),
                'steps' => Field::itemList(6, [
                    'title' => Field::text(60),
                    'body' => Field::text(200),
                ]),
            ],
            BlockType::MenuHighlights => [
                'heading' => Field::text(80),
                'items' => Field::itemList(self::MAX_ITEMS, [
                    'name' => Field::text(80),
                    'description' => Field::text(200, required: false),
                    // TEXT, not a number. A price is a currency, a format and sometimes a
                    // range ("from 12"), and modelling it as a number here would invent a
                    // currency the product has not chosen. It is displayed, never summed.
                    'price' => Field::text(20, required: false),
                    'image' => Field::media(required: false),
                ]),
            ],
            BlockType::Gallery => [
                'heading' => Field::text(80, required: false),
                'images' => Field::itemList(self::MAX_ITEMS, [
                    'image' => Field::media(),
                    // Required, and that is an accessibility decision rather than a
                    // content one: the caption is the img alt text, and §12's bar does not
                    // allow a gallery of unlabelled images.
                    'caption' => Field::text(120),
                ]),
            ],
            BlockType::HoursLocation => [
                'heading' => Field::text(80),
                'address' => Field::text(200),
                'hours' => Field::itemList(7, [
                    'day' => Field::text(20),
                    'hours' => Field::text(40),
                ]),
                // A MAP IS THREE NUMBERS, NEVER AN EMBED (§6.4). No iframe, no provider
                // URL, no script tag: the renderer draws a static link from these, so a
                // third party cannot be invited onto a tenant's published page by filling
                // in a form field.
                'latitude' => Field::number(-90, 90, required: false),
                'longitude' => Field::number(-180, 180, required: false),
                'zoom' => Field::number(1, 20, required: false),
            ],
            BlockType::Contact => [
                'heading' => Field::text(80),
                'email' => Field::email(required: false),
                'phone' => Field::telephone(required: false),
                'links' => Field::itemList(8, [
                    'label' => Field::text(40),
                    'url' => Field::url(),
                ], required: false),
            ],
            BlockType::Footer => [
                'text' => Field::text(200),
                'links' => Field::itemList(8, [
                    'label' => Field::text(40),
                    'url' => Field::url(),
                ], required: false),
            ],
        };
    }

    /**
     * Every block type's field map, keyed by type value. What the exporter walks.
     *
     * @return array<string, array<string, Field>>
     */
    public static function all(): array
    {
        $all = [];

        foreach (BlockType::cases() as $type) {
            $all[$type->value] = self::fields($type);
        }

        return $all;
    }
}
