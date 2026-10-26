<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [audit_actor_type] (PLAN.md §2.3). */
enum AuditActorType: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case User = 'user';
    case System = 'system';
    case Platform = 'platform';
}
