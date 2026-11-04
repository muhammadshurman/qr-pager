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

    /**
     * A platform admin inside a temporary support membership. `QRPAGER-22`.
     *
     * ⚠ **A SIXTH PRINCIPAL AND NOT A FOURTH `tenant_role`, WHICH IS THE WHOLE DESIGN.**
     * `§4 › Tenancy`, 2026-11-04: *"inside it holds only the abilities that membership
     * grants … It is read-only: it carries no write ability on any tenant table."* A
     * `support` ROLE would sit on `TenantRole`'s seniority ladder and appear in
     * `StoreMemberRequest`'s `in:` rule — a role a manager could grant. There is no such
     * value, so there is nothing to grant: this case is derived from
     * `tenant_users.expires_at` being non-null, and `RoleMatrix` is where read-only is
     * enforced.
     *
     * It IS tenant-scoped, unlike `PlatformAdmin`: that is the point of the clause. The same
     * person is `PlatformAdmin` outside a support membership and `Support` inside one, and
     * `§5`'s *"a platform admin is NOT a tenant bypass"* survives because the membership is
     * explicit, single, expiring and audited rather than ambient.
     */
    case Support = 'support';

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
     * The principal a membership makes its holder, expiry included.
     *
     * ⚠ **THE EXPIRY WINS OVER THE ROLE, AND IT HAS TO.** A support membership stores the
     * least-privileged role so that a mis-resolution fails closed; if the role decided, a
     * support membership would grant `staff` — which holds `pager:create` and
     * `pager:notify`, both WRITES on a tenant table, and `§4`'s read-only clause would be
     * false. So a membership that expires resolves `Support` whatever its role says.
     */
    public static function forMembership(TenantRole $role, ?string $expiresAt): self
    {
        return $expiresAt === null ? self::fromTenantRole($role) : self::Support;
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
