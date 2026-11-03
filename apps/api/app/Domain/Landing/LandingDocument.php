<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * ================================================================================
 * THE SANITISATION BOUNDARY. ONCE, SERVER-SIDE, AND IT IS A VALIDATOR (D5)
 * ================================================================================
 *
 * QRPAGER-14 §6.4: "Every block is tenant-authored content, and no tenant-authored value
 * is ever HTML." This class is that boundary, and the distinction that matters is in the
 * next sentence of the ticket: it REFUSES an input that does not match the schema. It
 * never strips and re-renders.
 *
 * Why that distinction is the whole design and not a preference: a sanitiser is a
 * transformation, so its correctness is a statement about every possible input -- and the
 * history of HTML sanitisers is a history of inputs nobody thought of. A validator is a
 * predicate: the set it admits is written down, here, and anything outside it is refused
 * with a named field and a reason. There is no third behaviour.
 *
 * AND IT IS ONLY HALF OF THE PROTECTION. The renderer escapes on output as well -- `{{ }}`
 * in Blade, never `{!! !!}` -- so the two halves are independent: a validator bug alone
 * cannot inject, and an escaping bug alone cannot either. LandingXssFuzzTest drives both
 * together; ThemeTokenTest and the renderer's own cases drive them apart.
 *
 * NO RICH TEXT EXISTS IN V1 (R-128). With no markup field there is no allow-list to get
 * wrong. A tenant who needs bold text gets it later through a markup-free markdown subset.
 *
 * NO NEWLINE, EITHER, and that is the same decision one layer down: every text field is a
 * single line. A multi-line paragraph is the smallest possible rich-text feature, and
 * admitting it now would mean deciding how a line break renders -- which is markup.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it, which is why the
 * one database fact this needs (does a media id belong to this tenant?) arrives as a
 * MediaReferences port rather than as a query.
 */
final class LandingDocument
{
    /**
     * The top-level keys this document ACCEPTS, and nothing else.
     *
     * ⚠ `template` IS ON THIS LIST AND NOT ON `REQUIRED`, AND THE TWO LISTS EXIST BECAUSE OF
     * THAT. `QRPAGER-33`. Before it there was one list doing both jobs -- the allow-list for
     * `refuseUnknownKeys()` and the JSON schema's `required` -- which is fine while every
     * accepted key is also mandatory and wrong the moment one is not.
     *
     * Every document stored before `QRPAGER-33` carries no `template` member, and there are
     * live ones. Putting `template` on `required` would make all of them invalid on the next
     * read -- and `fromStored()` deliberately re-validates, so "invalid" means a tenant's
     * published page cannot be republished and their draft cannot be opened. That is the
     * failure the split prevents, and `TemplateArrangementTest`'s byte-identity case is what
     * proves it did.
     */
    public const KEYS = ['blocks', 'theme', 'seo', 'template'];

    /**
     * The top-level keys a document MUST carry, which is what the JSON schema's `required`
     * is generated from. `template` is absent on purpose -- see `KEYS` above.
     */
    public const REQUIRED = ['blocks', 'theme', 'seo'];

    /**
     * @param  list<array{type: BlockType, fields: array<string, mixed>}>  $blocks
     * @param  array<string, string>  $theme
     * @param  array{title: ?string, description: ?string, og_image: ?string}  $seo
     */
    private function __construct(
        public readonly array $blocks,
        public readonly array $theme,
        public readonly array $seo,
        public readonly LandingTemplate $template = LandingTemplate::DEFAULT,
    ) {}

    /** The document a tenant starts with: no blocks, no theme overrides, no SEO. */
    public static function empty(): self
    {
        return new self([], [], ['title' => null, 'description' => null, 'og_image' => null]);
    }

    /**
     * Validate tenant input into a document, or refuse it by name.
     *
     * @param  array<mixed>  $input
     *
     * @throws LandingDocumentInvalid
     */
    public static function fromInput(array $input, MediaReferences $media): self
    {
        self::refuseUnknownKeys('document', $input, self::KEYS);

        $blocks = self::blocks($input['blocks'] ?? [], $media);
        $theme = self::theme($input['theme'] ?? []);
        $seo = self::seo($input['seo'] ?? [], $media);
        $template = self::template($input['template'] ?? null);

        $document = new self($blocks, $theme, $seo, $template);

        // ⚠ THE 256 KB BOUND IS CHECKED HERE, AND IT WAS NOT. §6.5 says "every bound is
        // a validator rule with a test, not a comment", and this one was a column CHECK
        // and a number in the generated contract and nothing in between. Measured by the
        // §11 QA review: 24 menu_highlights blocks, each at the schema's own maxima with
        // 4-byte characters, is accepted by every rule above and encodes to 370,087
        // bytes -- so the tenant met `landing_pages_draft_is_bounded` as a raw 23514
        // instead of §12.1's "{Field} can't be published: {reason}", and the panel's
        // save() catch never fired.
        //
        // Measured on the ENCODED form, because that is what the column stores and what
        // the CHECK counts. The same encoder flags LandingPages uses, for the same
        // reason the schema check is a byte diff: two encodings would disagree about the
        // bound by exactly the escaping they differ in.
        $encoded = json_encode(
            $document->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (strlen((string) $encoded) > BlockSchema::MAX_DOCUMENT_BYTES) {
            throw new LandingDocumentInvalid(
                'document',
                'is '.strlen((string) $encoded).' bytes; the limit is '
                .BlockSchema::MAX_DOCUMENT_BYTES.' bytes',
            );
        }

        // THE DOCUMENT-LEVEL CAP, checked over the whole document rather than inferred
        // from the per-list bounds, which sum to more than it. This is the number §6.5's
        // cost argument rests on: a publish copies one asset per reference, so the cost of
        // a publish is O(this).
        $references = $document->mediaReferences();

        if (count($references) > BlockSchema::MAX_MEDIA_REFERENCES) {
            throw new LandingDocumentInvalid(
                'document',
                'a page may reference at most '.BlockSchema::MAX_MEDIA_REFERENCES
                .' images, and this one references '.count($references),
            );
        }

        return $document;
    }

    /**
     * Rebuild a document from a stored jsonb column.
     *
     * Deliberately goes through fromInput(): a document that was valid when it was stored
     * may not be valid now -- an asset it references can have been deleted, and the schema
     * can have been narrowed by a deploy. Trusting stored data because it was validated
     * once is how a "stored XSS" differs from a reflected one.
     *
     * @param  array<mixed>  $stored
     *
     * @throws LandingDocumentInvalid
     */
    public static function fromStored(array $stored, MediaReferences $media): self
    {
        return self::fromInput($stored, $media);
    }

    /**
     * The document as it is written to jsonb and as the exporter's schema describes it.
     *
     * @return array{blocks: list<array{type: string, fields: array<string, mixed>}>, theme: array<string, string>, seo: array{title: ?string, description: ?string, og_image: ?string}}
     */
    public function toArray(): array
    {
        return [
            'blocks' => array_map(
                static fn (array $block): array => [
                    'type' => $block['type']->value,
                    'fields' => $block['fields'],
                ],
                $this->blocks,
            ),
            'theme' => $this->theme,
            'seo' => $this->seo,
            'template' => $this->template->value,
        ];
    }

    /**
     * Every media id this document references, SEO image included, de-duplicated.
     *
     * What the publisher copies into the site prefix, and what the media library checks
     * before allowing a delete (§12.2's "in use" state).
     *
     * @return list<string>
     */
    public function mediaReferences(): array
    {
        $found = [];

        foreach ($this->blocks as $block) {
            foreach (BlockSchema::fields($block['type']) as $name => $field) {
                $value = $block['fields'][$name] ?? null;

                if ($field->kind === FieldKind::Media && is_string($value)) {
                    $found[] = $value;

                    continue;
                }

                if ($field->kind !== FieldKind::ItemList || ! is_array($value)) {
                    continue;
                }

                foreach ($value as $item) {
                    foreach ($field->item ?? [] as $itemName => $itemField) {
                        if ($itemField->kind === FieldKind::Media && is_string($item[$itemName] ?? null)) {
                            $found[] = $item[$itemName];
                        }
                    }
                }
            }
        }

        if (is_string($this->seo['og_image'])) {
            $found[] = $this->seo['og_image'];
        }

        return array_values(array_unique($found));
    }

    /**
     * The first block type that references $mediaId, or null.
     *
     * §12.2's "in use" state needs the BLOCK's name -- "{name} is used by the {block}
     * block. Remove it there first." -- because "this image is in use" leaves the tenant
     * hunting for where.
     */
    public function blockUsing(string $mediaId): ?BlockType
    {
        $mediaId = strtolower($mediaId);

        foreach ($this->blocks as $block) {
            $document = new self([$block], [], ['title' => null, 'description' => null, 'og_image' => null]);

            if (in_array($mediaId, $document->mediaReferences(), true)) {
                return $block['type'];
            }
        }

        return null;
    }

    /** Is this document worth publishing at all? §12.1's empty state. */
    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    // ----------------------------------------------------------------------------
    // Validation. Every method below either returns a validated value or throws.
    // ----------------------------------------------------------------------------

    /**
     * @return list<array{type: BlockType, fields: array<string, mixed>}>
     */
    private static function blocks(mixed $input, MediaReferences $media): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            throw new LandingDocumentInvalid('blocks', 'must be an ordered list of blocks');
        }

        if (count($input) > BlockSchema::MAX_BLOCKS) {
            throw new LandingDocumentInvalid(
                'blocks',
                'a page may hold at most '.BlockSchema::MAX_BLOCKS.' blocks',
            );
        }

        $blocks = [];

        foreach ($input as $index => $block) {
            $path = "blocks.{$index}";

            if (! is_array($block)) {
                throw new LandingDocumentInvalid($path, 'must be a block object');
            }

            self::refuseUnknownKeys($path, $block, ['type', 'fields']);

            $type = BlockType::tryFrom(is_string($block['type'] ?? null) ? $block['type'] : '');

            if (! $type instanceof BlockType) {
                throw new LandingDocumentInvalid(
                    $path.'.type',
                    'is not one of the '.count(BlockType::cases()).' block types',
                );
            }

            $fields = $block['fields'] ?? [];

            if (! is_array($fields)) {
                throw new LandingDocumentInvalid($path.'.fields', 'must be an object of fields');
            }

            $blocks[] = [
                'type' => $type,
                'fields' => self::fields($path.'.fields', BlockSchema::fields($type), $fields, $media),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, Field>  $schema
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    private static function fields(string $path, array $schema, array $input, MediaReferences $media): array
    {
        self::refuseUnknownKeys($path, $input, array_keys($schema));

        $fields = [];

        foreach ($schema as $name => $field) {
            $value = $input[$name] ?? null;

            if ($value === null || $value === '') {
                if ($field->required) {
                    throw new LandingDocumentInvalid("{$path}.{$name}", 'is required');
                }

                $fields[$name] = null;

                continue;
            }

            $fields[$name] = self::value("{$path}.{$name}", $field, $value, $media);
        }

        return $fields;
    }

    private static function value(string $path, Field $field, mixed $value, MediaReferences $media): mixed
    {
        return match ($field->kind) {
            FieldKind::Text => self::text($path, $value, (int) $field->maxLength),
            FieldKind::Url => self::url($path, $value),
            FieldKind::Email => self::email($path, $value),
            FieldKind::Telephone => self::telephone($path, $value),
            FieldKind::Media => self::media($path, $value, $media),
            FieldKind::Number => self::number($path, $value, $field),
            FieldKind::ItemList => self::itemList($path, $field, $value, $media),
        };
    }

    /**
     * Plain text: valid UTF-8, no control characters at all, length-bounded.
     *
     * `<`, `>` and `&` are KEPT AS DATA here and escaped at render (§6.4). Stripping them
     * would be the sanitiser this class refuses to be, and would silently change what a
     * restaurant wrote -- "Fish & Chips" is not a security problem, it is a menu item.
     *
     * The length bound counts CHARACTERS, not bytes: a bound in bytes would let the field
     * hold a quarter as much Arabic as English, and Arabic is a launch language (§12).
     */
    private static function text(string $path, mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            throw new LandingDocumentInvalid($path, 'must be text');
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new LandingDocumentInvalid($path, 'is not valid UTF-8');
        }

        // C0 and DEL, newline included -- every text field is one line (R-128). A control
        // character has no meaning in a heading and several of them have meaning in some
        // downstream parser, which is the whole argument for an allow-list of characters.
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new LandingDocumentInvalid($path, 'may not contain control characters or line breaks');
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($length > $maxLength) {
            throw new LandingDocumentInvalid($path, "is {$length} characters; the limit is {$maxLength}");
        }

        return $value;
    }

    /**
     * An absolute https: URL, PARSED AND RE-ASSEMBLED -- never passed through (§6.4).
     *
     * Re-assembly is the mechanism, not a tidy-up: what is stored is built from the parts
     * parse_url returned, so anything parse_url did not understand cannot survive. A
     * `javascript:` scheme, a `data:` URI, an embedded credential, a control character and
     * a second scheme smuggled past a naive prefix check all fail here.
     */
    private static function url(string $path, mixed $value): string
    {
        if (! is_string($value)) {
            throw new LandingDocumentInvalid($path, 'must be a web address');
        }

        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new LandingDocumentInvalid($path, 'may not contain spaces or control characters');
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new LandingDocumentInvalid($path, 'is not a complete web address');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new LandingDocumentInvalid($path, 'must start with https://');
        }

        // Credentials in a URL are a phishing primitive and have no legitimate use on a
        // restaurant's page.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LandingDocumentInvalid($path, 'may not carry a username or password');
        }

        $host = strtolower($parts['host']);

        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host) !== 1) {
            throw new LandingDocumentInvalid($path, 'does not name a valid host');
        }

        $url = 'https://'.$host;

        if (isset($parts['port'])) {
            throw new LandingDocumentInvalid($path, 'may not name a port');
        }

        // Each part re-encoded from what parse_url gave back, so the stored value is this
        // function's output and never the tenant's input.
        if (isset($parts['path'])) {
            $url .= implode('/', array_map(
                static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
                explode('/', $parts['path']),
            ));
        }

        if (isset($parts['query'])) {
            $url .= '?'.str_replace('%3D', '=', rawurlencode(rawurldecode($parts['query'])));
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.rawurlencode(rawurldecode($parts['fragment']));
        }

        if (mb_strlen($url, 'UTF-8') > 2048) {
            throw new LandingDocumentInvalid($path, 'is longer than 2048 characters');
        }

        return $url;
    }

    /** An email address, stored bare. The renderer is what prefixes `mailto:`. */
    private static function email(string $path, mixed $value): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new LandingDocumentInvalid($path, 'is not an email address');
        }

        if (mb_strlen($value, 'UTF-8') > 254) {
            throw new LandingDocumentInvalid($path, 'is longer than 254 characters');
        }

        return strtolower($value);
    }

    /**
     * A telephone number, stored bare. The renderer is what prefixes `tel:`.
     *
     * Digits, an optional leading +, and spaces or hyphens as separators. No letters, no
     * parentheses and no commas: a `tel:` URI treats `,` as a dial pause and `;` as a
     * parameter separator, so neither may reach it.
     */
    private static function telephone(string $path, mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\+?[0-9][0-9 \-]{4,30}$/', $value) !== 1) {
            throw new LandingDocumentInvalid($path, 'is not a telephone number');
        }

        return $value;
    }

    /**
     * A media id that MUST resolve to an asset of this tenant.
     *
     * This is the clause the named invariant's third part rests on in the document: a
     * foreign id resolves to nothing and the publish is refused (§6.4). The refusal is
     * identical whether the id names another tenant's asset or no asset at all, so the
     * field is not an existence oracle for other tenants' media.
     */
    private static function media(string $path, mixed $value, MediaReferences $media): string
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new LandingDocumentInvalid($path, 'is not an image reference');
        }

        if (! $media->has($value)) {
            throw new LandingDocumentInvalid($path, 'names an image that is not in your library');
        }

        return strtolower($value);
    }

    private static function number(string $path, mixed $value, Field $field): int|float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new LandingDocumentInvalid($path, 'must be a number');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new LandingDocumentInvalid($path, 'must be a finite number');
        }

        if ($value < $field->min || $value > $field->max) {
            throw new LandingDocumentInvalid($path, "must be between {$field->min} and {$field->max}");
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function itemList(string $path, Field $field, mixed $value, MediaReferences $media): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LandingDocumentInvalid($path, 'must be an ordered list');
        }

        if (count($value) > (int) $field->maxItems) {
            throw new LandingDocumentInvalid($path, "may hold at most {$field->maxItems} items");
        }

        // A REQUIRED LIST MEANS AT LEAST ONE ITEM, which is what the panel's Repeater
        // means by `required()` too. The two used to disagree -- this validator accepted
        // `[]` for a required list while the form refused it, so a document the panel
        // would not let a tenant save was one this class called valid. Found by driving
        // the builder's own save action, which is the only path that runs both.
        if ($value === [] && $field->required) {
            throw new LandingDocumentInvalid($path, 'needs at least one item');
        }

        $items = [];

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                throw new LandingDocumentInvalid("{$path}.{$index}", 'must be an object');
            }

            $items[] = self::fields("{$path}.{$index}", $field->item ?? [], $item, $media);
        }

        return $items;
    }

    /**
     * Validate the arrangement, or refuse it by name. `QRPAGER-33`.
     *
     * ⚠ **ABSENT IS VALID AND MEANS `classic`; PRESENT-AND-UNKNOWN IS A REFUSAL.** Those are
     * two different answers to two different questions and the distinction is the whole of
     * invariant clause 2 and clause 4. A document written before this feature has no
     * `template` member and must keep publishing exactly as it did; a document naming
     * `brutalist` is a tenant (or a client) asking for something that does not exist, and
     * `§12.1` says a refusal names the field.
     *
     * ⚠ **AND IT IS REFUSED HERE, BEFORE ANY WRITE, NOT BY THE STUDIO'S FIELD BEING A FIELD.**
     * `fromStored()` routes through `fromInput()`, so this runs on every read as well as every
     * save -- which is what makes the clause *"exactly these names, enforced at the schema
     * boundary"* true of the stored column and not only of the form.
     */
    private static function template(mixed $input): LandingTemplate
    {
        if ($input === null) {
            return LandingTemplate::DEFAULT;
        }

        $template = is_string($input) ? LandingTemplate::tryFrom($input) : null;

        if ($template === null) {
            /*
             * The reason names the SET and not the value that was sent. `§4.2`: a refusal may
             * say what to do, never what exists -- and echoing an unknown value back into a
             * message is how a validator becomes a reflection point. The set is this
             * application's own published contract, so naming it tells a tenant what to do.
             */
            throw new LandingDocumentInvalid(
                'template',
                'must be one of '.implode(', ', LandingTemplate::values()),
            );
        }

        return $template;
    }

    /**
     * @return array<string, string>
     */
    private static function theme(mixed $input): array
    {
        if (! is_array($input)) {
            throw new LandingDocumentInvalid('theme', 'must be a map of tokens');
        }

        $theme = [];

        foreach ($input as $name => $value) {
            if (! is_string($name) || ! ThemeTokens::knows($name)) {
                throw new LandingDocumentInvalid(
                    'theme',
                    'names a token this theme does not have; a theme overrides tokens only',
                );
            }

            if (! ThemeTokens::accepts($name, $value)) {
                throw new LandingDocumentInvalid("theme.{$name}", 'is not a value this token accepts');
            }

            $theme[$name] = $value;
        }

        ksort($theme);

        return $theme;
    }

    /**
     * @return array{title: ?string, description: ?string, og_image: ?string}
     */
    private static function seo(mixed $input, MediaReferences $media): array
    {
        if (! is_array($input)) {
            throw new LandingDocumentInvalid('seo', 'must be an object');
        }

        // structured_data is DERIVED and never authored (§6.4). Refused rather than
        // ignored: silently dropping it would let a tenant believe they had set it.
        self::refuseUnknownKeys('seo', $input, ['title', 'description', 'og_image']);

        $title = ($input['title'] ?? null) === null || $input['title'] === ''
            ? null
            : self::text('seo.title', $input['title'], BlockSchema::MAX_SEO_TITLE);

        $description = ($input['description'] ?? null) === null || $input['description'] === ''
            ? null
            : self::text('seo.description', $input['description'], BlockSchema::MAX_SEO_DESCRIPTION);

        $image = ($input['og_image'] ?? null) === null || $input['og_image'] === ''
            ? null
            : self::media('seo.og_image', $input['og_image'], $media);

        return ['title' => $title, 'description' => $description, 'og_image' => $image];
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $allowed
     */
    private static function refuseUnknownKeys(string $path, array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowed, true)) {
                // The KEY is named and the value is not. A key is a schema name from a
                // bounded set; a value is tenant-authored content.
                $named = is_string($key) ? $key : (string) $key;

                throw new LandingDocumentInvalid(
                    $path,
                    'carries a field this schema does not have: '
                    .(preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $named) === 1 ? $named : 'an unnamed key'),
                );
            }
        }
    }
}
