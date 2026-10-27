<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Domain\Pager\PagerEvent;

/**
 * One outbox row this worker has leased, with the tenant it belongs to.
 *
 * `tenantId` is the load-bearing field and it is why this object exists rather than the
 * raw row being passed around: it is read cross-tenant during the claim -- the outbox
 * queue is one of §4 › Tenancy's four named cross-tenant paths -- and then it is the ONLY
 * source of the tenant scope every subsequent statement runs inside. Measured 2026-10-27:
 * with no tenant context this role sees zero rows in push_subscriptions, so the scope is
 * not optional and the id has to come from somewhere that cannot be influenced by input.
 * It comes from a row the worker read through its own policy.
 *
 * `event` is nullable because `outbox.type` is varchar(64) rather than a Postgres enum
 * (§2.5), so the column can hold a string PagerEvent does not have a case for -- a row
 * written by a future version during a rolling deploy, for instance. The worker treats
 * that as a row it cannot handle and dead-letters it rather than crashing the pass or
 * leaving it to be retried forever by every version that does not understand it.
 */
final class ClaimedOutboxRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly ?string $sessionId,
        public readonly ?PagerEvent $event,
        public readonly string $rawType,
        public readonly string $dedupeKey,
        public readonly int $attempts,
    ) {}
}
