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
 *
 * `attempts` IS THE VALUE THE ROW CARRIED BEFORE THIS CLAIM INCREMENTED IT, which is why
 * backOff() reads `attempts + 1` as "including this one" and why leaseIdentity() below
 * exists rather than the caller repeating that arithmetic at the one place it decides
 * whether to send.
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

    /**
     * WHO HOLDS THE LEASE ON THIS ROW -- the `lease_owner` R-083's ruling names, expressed
     * in the column that already carries it.
     *
     * `outbox` has no lease_owner and no lease_expires_at; the lease is `available_at` in
     * the future plus `status = 'pending'`, and the claim's own `attempts = attempts + 1`
     * is what distinguishes one claim of a row from the next. So the attempt number this
     * claim wrote IS this worker's ownership token, and the re-lease in OutboxWorker
     * matches on it.
     *
     * IT IS A SOUND IDENTITY BECAUSE IT CANNOT COME BACK, and that is measured rather than
     * asserted -- OutboxLeaseIdentityTest drives all four paths:
     *
     *   INCREMENTED EXACTLY ONCE PER CLAIM  the claim's keyed UPDATE is the only statement
     *       in the application that writes this column; finish() and backOff() name every
     *       column they set and `attempts` is not among them.
     *   NEVER DECREMENTED, NEVER RESET      nothing writes a literal to it anywhere, so the
     *       sequence a row sees is strictly increasing.
     *   NO SECOND WRITER                    qrpager_app has no UPDATE grant on `outbox` at
     *       all (§2.7), so the only role that can move it is the worker.
     *   CANNOT WRAP ROUND TO A PAST VALUE   it is a smallint, and Postgres raises
     *       `22003 smallint out of range` on overflow rather than wrapping to a negative;
     *       `outbox_attempts_non_negative` is the second control behind that.
     */
    public function leaseIdentity(): int
    {
        return $this->attempts + 1;
    }
}
