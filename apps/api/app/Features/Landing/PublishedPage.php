<?php

declare(strict_types=1);

namespace App\Features\Landing;

/** What a successful publish produced. Returned so the panel can say it without re-reading. */
final class PublishedPage
{
    /** @param list<string> $assetKeys */
    public function __construct(
        public readonly string $hostname,
        public readonly string $checksum,
        public readonly int $byteSize,
        public readonly array $assetKeys,
        public readonly string $publishedAt,
    ) {}
}
