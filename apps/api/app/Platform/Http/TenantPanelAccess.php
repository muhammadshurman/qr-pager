<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Auth\Membership;
use App\Domain\Tenancy\TenantRole;

/**
 * Who may use a TENANT-SCOPED panel, in one place, called by everything that asks.
 *
 * ⚠ IT LIVES IN Platform AND NOT BESIDE THE PANEL, and deptrac is why -- correctly.
 * `App\Models\User::canAccessPanel()` is Filament's own door and is in the Platform
 * layer; the panel's pages are in Features. Rule 2 of the three named architecture guards
 * is "the platform layer imports no feature modules", so a copy of this rule next to the
 * panel would have meant a SECOND copy in the model -- the duplicated-check shape that
 * cost this project QA H-1 once already. deptrac refused the first arrangement and this
 * is the fix: one rule, in the layer both callers may reach.
 *
 * ================================================================================
 * WHY THIS IS NOT LEFT TO THE PANEL'S DOOR -- TASK 6'S SECURITY M-1, APPLIED FIRST
 * ================================================================================
 *
 * `PlatformDashboardPage`'s docblock records the measurement: a custom Filament PAGE
 * inherits `CanAuthorizeAccess::canAccess()`, whose own comment reads *"Custom pages
 * default to allowing access for all authenticated panel users"* -- and
 * `POST /livewire-<hash>/update` runs neither `SetUpPanel` nor Filament's `Authenticate`,
 * so for a custom page the only barrier left is the snapshot's HMAC, which is signed with
 * `APP_KEY` and bound to no user and no session.
 *
 * That finding cost Task 6 a High. It is applied here BEFORE a review can find it again:
 * every page in this panel overrides `canAccess()` and every page's actions re-ask, so a
 * Livewire call that skipped the panel's middleware is refused by the component itself.
 *
 * ================================================================================
 * D1: `owner` OR `manager`, AND NEVER A PLATFORM ADMIN
 * ================================================================================
 *
 * `staff` cannot edit the page -- `§4 › Security`'s seniority rule applied to content. A
 * platform admin holds no `tenant_users` row at all, so they resolve to no membership and
 * the panel is closed to them, which is SR-1 arriving as behaviour: "a platform admin
 * holds no tenant ability and no cross-tenant read".
 */
final class TenantPanelAccess
{
    /** The roles that may edit a tenant's public page. */
    public const ROLES = [TenantRole::Owner, TenantRole::Manager];

    /** The membership this request may act under, or null -- which always means refuse. */
    public static function membership(): ?Membership
    {
        $membership = app(ActiveMembership::class)->resolve(request());

        if ($membership === null || ! in_array($membership->role, self::ROLES, true)) {
            return null;
        }

        return $membership;
    }

    public static function allows(): bool
    {
        return self::membership() !== null;
    }

    /**
     * The tenant this request acts in, or a refusal.
     *
     * Every page calls this rather than holding a tenant id in a Livewire property: a
     * property is part of the snapshot the client round-trips, and a tenant id that
     * arrives from the client is the ambient-parameter shape `ActiveTenant`'s docblock
     * refuses in the strongest terms this project has written down.
     */
    public static function tenantId(): string
    {
        $membership = self::membership();

        if ($membership === null) {
            abort(403);
        }

        return $membership->tenantId;
    }
}
