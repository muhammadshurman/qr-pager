<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Tenancy\TenantRole;

/**
 * WHO is making a request. Five cases, and they are not the same thing as `tenant_role`.
 *
 * `TenantRole` is the DATA CONTRACT — the Postgres enum `tenant_role`, three values,
 * stored in `tenant_users.role` (PLAN.md §2.3). This is the AUTHORISATION SUBJECT, and it
 * has two cases that are deliberately not in that enum:
 *
 *   - `PlatformAdmin` is a boolean on `users`, not a tenant role, because a platform
 *     admin belongs to no tenant (§2.4) and a `platform_admin` value in `tenant_role`
 *     could only ever be stored attached to one;
 *   - `Device` is a KDS device token, which is a CREDENTIAL SHAPE rather than a role. The
 *     screen in a kitchen is not a person with a job title; it is a bearer token with one
 *     capability and an expiry.
 *
 * Keeping them in one enum here is what lets the role matrix be *one* array over *all*
 * principals (QRPAGER-12 §10.2(c)) without putting either of them in the data contract,
 * which is the duplicated-role-list defect §6.2 forbids.
 */
enum Principal: string
{
    case PlatformAdmin = 'platform_admin';
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';
    case Device = 'device';

    /** The principal a tenant membership's role makes its holder. */
    public static function fromTenantRole(TenantRole $role): self
    {
        return match ($role) {
            TenantRole::Owner => self::Owner,
            TenantRole::Manager => self::Manager,
            TenantRole::Staff => self::Staff,
        };
    }

    /**
     * Whether this principal is scoped to a tenant at all.
     *
     * A platform admin is not, and that is the whole of `§5`'s "a platform admin is NOT a
     * tenant bypass": being unscoped means holding no tenant, which means resolving no
     * tenant, which means the ordinary 401 — not reaching every tenant.
     */
    public function isTenantScoped(): bool
    {
        return $this !== self::PlatformAdmin;
    }
}
