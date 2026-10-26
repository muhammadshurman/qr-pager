<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A PHP enum that is mirrored by a native Postgres enum type.
 *
 * The PHP side is the single source (PLAN.md §2.3). Task 8 generates the
 * TypeScript mirror from the same registry (ADR-003).
 */
interface DatabaseEnum
{
    /** @return list<string> */
    public static function values(): array;
}
