<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;
use DateTimeImmutable;

/**
 * What a presented QR token resolves to: exactly one session in exactly one tenant.
 *
 * `§4 › Tenancy` requires that shape in so many words, which is why `tenantId` is on this
 * object: the token is what DECIDES the tenant, so every caller downstream takes the
 * tenant from here and opens its own scope with it. Task 4 consumes this.
 *
 * `expired` is computed from expires_at rather than read from status, because the two can
 * legitimately disagree for up to one expiry-pass interval: a session past its TTL is
 * still `waiting` until the job runs. A customer must be told "expired" at the moment the
 * clock says so, not when a worker gets round to it.
 */
final class ResolvedPagerSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly PagerStatus $status,
        public readonly string $orderReference,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $readyAt,
        public readonly ?DateTimeImmutable $lastNotifiedAt,
    ) {}

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->status === PagerStatus::Expired || $this->expiresAt <= $now;
    }
}
