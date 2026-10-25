<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [outbox_status] (PLAN.md §2.3). */
enum OutboxStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Pending = 'pending';
    case Sent = 'sent';
    case Dead = 'dead';
}
