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
    /**
     * @return array<string, class-string<DatabaseEnum&\BackedEnum>>
     *
     * ⚠ `&\BackedEnum` ADDED 2026-10-31 BY TASK 8a, and it is a CORRECTION rather than a
     * widening. `DatabaseEnum`'s own docblock already says it in prose -- "a PHP enum that
     * is mirrored by a native Postgres enum type" -- and §2.3 says the same; the TYPE just
     * did not say so, so a caller could not iterate the cases or read `->value` without
     * PHPStan refusing it. Every value below has been a STRING-BACKED enum since Task 1,
     * which is why it is `\BackedEnum` and not `\UnitEnum` -- `UnitEnum` carries `name`
     * but not `value`, and `value` is the whole point of a type that mirrors a Postgres
     * enum label.
     *
     * It was found by the consumer this registry's docblock asks for: generating
     * packages/contracts needs each class's CASES and their transition tables, which is
     * exactly what `class-string<DatabaseEnum>` alone cannot express.
     */
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
