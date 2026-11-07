<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel;

/**
 * What the dashboard shows, as a type.
 *
 * Every field is an aggregate OF aggregates: the platform-wide totals are sums over
 * `tenant_statistics`, and the registry counts are counts over `tenant_provisionings`.
 * Both sources are platform tables, so assembling this needs no tenant context and
 * reaches no tenant table — which is the named invariant, and is why the dashboard reads
 * a type rather than running queries in a Blade view where nothing could enumerate them.
 */
final readonly class PlatformSummary
{
    public function __construct(
        public int $restaurantsTotal,
        public int $restaurantsActive,
        public int $restaurantsPending,
        public int $restaurantsFailed,
        public int $sessionsTotal,
        public int $staffTotal,
        public ?int $medianWaitSeconds,
        public ?string $aggregatesComputedAt,
    ) {}
}
