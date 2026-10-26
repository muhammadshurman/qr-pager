<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [pager_status] (PLAN.md §2.3). */
enum PagerStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Waiting = 'waiting';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
