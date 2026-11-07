<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * One field of one block: its kind, whether it is required, and its bounds.
 *
 * Immutable and framework-free. BlockSchema builds these; LandingDocument validates
 * against them; the JSON Schema exporter reads them. One source, three readers
 * (QRPAGER-14 decision D4).
 */
final class Field
{
    /**
     * @param  array<string, Field>|null  $item  the per-item field map, for ItemList only
     */
    private function __construct(
        public readonly FieldKind $kind,
        public readonly bool $required,
        public readonly ?int $maxLength = null,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly ?int $maxItems = null,
        public readonly ?array $item = null,
    ) {}

    public static function text(int $maxLength, bool $required = true): self
    {
        return new self(FieldKind::Text, $required, maxLength: $maxLength);
    }

    public static function url(bool $required = true): self
    {
        return new self(FieldKind::Url, $required, maxLength: 2048);
    }

    public static function email(bool $required = true): self
    {
        return new self(FieldKind::Email, $required, maxLength: 254);
    }

    public static function telephone(bool $required = true): self
    {
        return new self(FieldKind::Telephone, $required, maxLength: 32);
    }

    public static function media(bool $required = true): self
    {
        return new self(FieldKind::Media, $required);
    }

    public static function number(int|float $min, int|float $max, bool $required = true): self
    {
        return new self(FieldKind::Number, $required, min: $min, max: $max);
    }

    /**
     * @param  array<string, Field>  $item
     */
    public static function itemList(int $maxItems, array $item, bool $required = true): self
    {
        return new self(FieldKind::ItemList, $required, maxItems: $maxItems, item: $item);
    }
}
