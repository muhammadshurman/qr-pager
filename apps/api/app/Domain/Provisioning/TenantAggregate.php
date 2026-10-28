<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * One tenant's aggregates, as a type.
 *
 * THIS IS THE RULING EXPRESSED AS A SHAPE rather than only as a column list: *"an aggregate
 * carries no row-level detail — no order reference, no token, no session identifier"*
 * (`QRPAGER-13 §0`). Every property here is a count, a whole number of seconds or a window
 * bound, and there is nowhere for a session id to travel even if a future query selected
 * one. `PlatformTableContractTest` pins the same property on the table; this pins it on the
 * value that reaches the table.
 *
 * It also exists because the alternative failed PHPStan honestly rather than cosmetically:
 * the aggregate query's row was a bare `stdClass`, so every read of it was an undefined
 * property. The fix is a type, not a cast.
 *
 * In App\Domain, so no framework and no database — deptrac enforces it.
 */
final readonly class TenantAggregate
{
    /** @param array<string, int> $sessionsByStatus keyed by PagerStatus value */
    public function __construct(
        public int $sessionsTotal,
        public array $sessionsByStatus,
        public int $staffCount,
        public ?int $medianWaitSeconds,
        public ?int $p95WaitSeconds,
        public ?string $windowStart,
        public ?string $windowEnd,
    ) {}

    /**
     * The count for one status, or zero.
     *
     * Zero rather than null for a missing key: a status with no sessions genuinely has a
     * count of zero, which is different from a wait time that does not exist.
     */
    public function sessions(string $status): int
    {
        return $this->sessionsByStatus[$status] ?? 0;
    }
}
