<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [tenant_role] (PLAN.md §2.3). */
enum TenantRole: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';
}
