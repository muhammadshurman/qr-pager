<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingPageStatus;

/**
 * One `landing_pages` row, typed.
 *
 * A value object rather than the query builder's `stdClass`, for the reason
 * ClaimedOutboxRow is one: `$row->published_hostname` on an `object` is untyped to
 * PHPStan AND untyped to the reader, so a column renamed in a migration fails at runtime
 * in the panel rather than in `composer stan`. from() is the single place a column name
 * is read, so the migration and this class are the only two things that have to agree.
 */
final class LandingPageRow
{
    public function __construct(
        public readonly string $id,
        public readonly LandingPageStatus $status,
        public readonly string $draftDocument,
        public readonly ?string $publishedDocument,
        public readonly ?string $publishedChecksum,
        public readonly ?string $publishedHostname,
        public readonly ?string $publishedAt,
    ) {}

    public static function from(object $row): self
    {
        return new self(
            (string) ($row->id ?? ''),
            LandingPageStatus::from((string) ($row->status ?? 'draft')),
            is_string($row->draft_document ?? null) ? $row->draft_document : '{}',
            is_string($row->published_document ?? null) ? $row->published_document : null,
            is_string($row->published_checksum ?? null) ? $row->published_checksum : null,
            is_string($row->published_hostname ?? null) ? $row->published_hostname : null,
            is_string($row->published_at ?? null) ? $row->published_at : null,
        );
    }
}
