<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [tenant_status] (PLAN.md §2.3). */
enum TenantStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Active = 'active';
    case Suspended = 'suspended';
}
