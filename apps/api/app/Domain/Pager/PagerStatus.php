<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/**
 * Mirrored by the Postgres enum type [pager_status] (PLAN.md §2.3).
 *
 * THE TRANSITION TABLE LIVES HERE, which §2.3 assigned to this enum rather than to the
 * data contract. One source: the service, the expiry job and the tests all read allows(),
 * so "invalid state transitions rejected" is one table and not a condition repeated at
 * five call sites that can disagree with each other.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it. The database
 * enforces a different and narrower thing: the worker's WITH CHECK pins `expired` as the
 * only status it may write cross-tenant (§2.6), and the column grants stop the app role
 * writing `expired_at` at all. Neither of those knows what a LEGAL transition is, which is
 * why this table exists as well rather than instead.
 */
enum PagerStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Waiting = 'waiting';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Every transition this domain permits, and nothing else.
     *
     * `waiting -> completed` is the one judgement call in the table and it is deliberate:
     * a customer who comes to the counter and collects without waiting to be paged is an
     * ordinary thing that happens, and refusing it would leave staff only `cancel`, which
     * records something that did not happen. The alternative -- `complete` requires
     * `ready` -- was rejected for that reason and is recorded in QRPAGER-10 §6.2.
     *
     * Both live statuses may expire, which is what the partial index on
     * `(expires_at) WHERE status IN ('waiting','ready')` is for (§2.5) and what the
     * worker's narrowed UPDATE policy allows (§2.6). The three terminal statuses go
     * nowhere: a completed order is not re-opened, it becomes a new session.
     *
     * @return list<self>
     */
    public function allows(): array
    {
        return match ($this) {
            self::Waiting => [self::Ready, self::Completed, self::Cancelled, self::Expired],
            self::Ready => [self::Completed, self::Cancelled, self::Expired],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allows(), true);
    }

    /**
     * Nothing leaves a terminal status.
     *
     * Derived from allows() rather than listed again, so a case added to the table above
     * cannot leave this method disagreeing with it.
     */
    public function isTerminal(): bool
    {
        return $this->allows() === [];
    }

    /** The two statuses a session can still be acted on in, and the two the expiry job looks for. */
    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }
}
