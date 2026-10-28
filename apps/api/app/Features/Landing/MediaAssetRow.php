<?php

declare(strict_types=1);

namespace App\Features\Landing;

/**
 * One `media_assets` row, typed.
 *
 * The third of this task's row objects, and for the reason the other two exist: a column
 * read as `$row->original_name` on a query builder `stdClass` is untyped to PHPStan and
 * to the reader, so a column renamed in a migration fails in the panel at runtime rather
 * than in `composer stan`.
 */
final class MediaAssetRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $objectKey,
        public readonly string $originalName,
        public readonly string $contentType,
        public readonly int $byteSize,
        public readonly string $checksum,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $createdAt,
    ) {}

    public static function from(object $row): self
    {
        $int = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return new self(
            (string) ($row->id ?? ''),
            (string) ($row->object_key ?? ''),
            (string) ($row->original_name ?? ''),
            (string) ($row->content_type ?? ''),
            (int) ($row->byte_size ?? 0),
            (string) ($row->checksum ?? ''),
            $int($row->width ?? null),
            $int($row->height ?? null),
            is_string($row->created_at ?? null) ? $row->created_at : null,
        );
    }

    /** What a human recognises in a list: the name, disambiguated by size when known. */
    public function label(): string
    {
        return $this->width !== null && $this->height !== null
            ? $this->originalName.' ('.$this->width.'x'.$this->height.')'
            : $this->originalName;
    }
}
