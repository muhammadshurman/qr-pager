<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Audit\AuditActorType;
use App\Domain\Landing\DomainStatus;
use App\Domain\Landing\LandingPageStatus;
use App\Domain\Outbox\OutboxStatus;
use App\Domain\Pager\PagerStatus;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;

/**
 * The one registry binding each Postgres enum type to its PHP source.
 *
 * EnumContractTest compares pg_enum labels against these cases, so adding a
 * value on either side fails the build until both agree. Task 8 generates
 * packages/contracts from this same map rather than hand-copying it (ADR-003).
 */
final class DatabaseEnums
{
    /** @return array<string, class-string<DatabaseEnum>> */
    public static function map(): array
    {
        return [
            'pager_status' => PagerStatus::class,
            'outbox_status' => OutboxStatus::class,
            'tenant_status' => TenantStatus::class,
            'tenant_role' => TenantRole::class,
            'audit_actor_type' => AuditActorType::class,
            // Task 6. The platform's own record of a provisioning attempt; no tenant
            // scope, because the row is written before the tenant exists (PLAN.md §2.4).
            'provisioning_status' => ProvisioningStatus::class,
            // Task 7. The tenant's own page and the hostnames it is served under; both
            // are tenant tables, so unlike provisioning_status these sit behind RLS
            // (PLAN.md §2.5).
            'landing_page_status' => LandingPageStatus::class,
            'domain_status' => DomainStatus::class,
        ];
    }
}
