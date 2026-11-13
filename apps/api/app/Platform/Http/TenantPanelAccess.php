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

    /**
     * May a membership with this role reach the studio at all?
     *
     * ⚠⚠ ONE PREDICATE, TWO CALLERS, AND THAT IS THE WHOLE REASON IT IS A METHOD.
     * `membership()` below asks it per REQUEST, which is what `User::canAccessPanel()` and
     * therefore Filament's own gate rest on. `QRPAGER-48` asks it per MEMBERSHIP, to publish
     * on `/v1/auth/me` whether the staff app should offer a way into the studio at all.
     *
     * The staff app is a different process and cannot call `allows()`, so without this the
     * button's condition would be a second implementation of `self::ROLES` in TypeScript —
     * free to drift from the gate it is supposed to predict, and showing a control that
     * answers 401. That is the duplicated-source defect `§6.2` mechanises against, and this
     * payload already refuses it once for `grantable_roles`:
     *
     *   "The client already holds `role`, so it COULD compute the list itself: that would be
     *    a second implementation of `TenantRole::maySeniorityGrant()` in TypeScript."
     *
     * ⚠ AND IT IS NOT THE ENFORCEMENT. The panel still refuses on its own, per request,
     * through `canAccessPanel()`. This only decides whether a door is DRAWN; the lock is
     * `membership()`, and a client is a client.
     */
    public static function permits(TenantRole $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** The membership this request may act under, or null -- which always means refuse. */
    public static function membership(): ?Membership
    {
        $membership = app(ActiveMembership::class)->resolve(request());

        if ($membership === null || ! self::permits($membership->role)) {
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
