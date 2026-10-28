<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/**
 * Mirrored by the Postgres enum type [provisioning_status] (PLAN.md §2.3, §2.4).
 *
 * THE TRANSITION TABLE LIVES HERE, for the reason PagerStatus' does: the panel, the
 * provisioning job and the tests all read allows(), so the set of legal moves is one
 * table and not a condition repeated at call sites that can disagree.
 *
 * `running -> pending` is the one judgement call and it is deliberate: a job killed
 * mid-flight leaves the row at `running`, and the only way a later run may pick it up is
 * to put it back. `attempts` is what stops that being a loop.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
enum ProvisioningStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Every transition this domain permits, and nothing else.
     *
     * `completed` is terminal with no exit at all: a provisioned tenant is not
     * re-provisioned, because its id is already taken and the insert that would do it
     * raises 23505. `failed` may be retried, which is what makes a transient failure
     * recoverable without a second row.
     *
     * @return array<string, list<self>>
     */
    public static function allows(): array
    {
        return [
            self::Pending->value => [self::Running, self::Failed],
            self::Running->value => [self::Completed, self::Failed, self::Pending],
            self::Completed->value => [],
            self::Failed->value => [self::Running, self::Pending],
        ];
    }

    /** Whether this status may move to $next. */
    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, self::allows()[$this->value], true);
    }

    /** Terminal in the sense that nothing further is expected of it. */
    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }
}
