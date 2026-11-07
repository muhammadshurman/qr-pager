<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Membership;
use App\Platform\Http\ActiveMembership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one implementation of ActiveMembership.
 *
 * It reads the live membership set on every call rather than believing a session, for
 * the reason AuthenticatedTenantResolver does: a membership revoked, or a tenant
 * suspended, after a selection was written has to stop working immediately.
 *
 * A PLATFORM ADMIN RESOLVES TO NOTHING HERE, and that is SR-1 rather than an oversight.
 * `§4 › Tenancy` (2026-10-28): "A platform admin holds no tenant ability and no
 * cross-tenant read." They hold no `tenant_users` row either, so `sole()` answers null
 * and the tenant-scoped panel is closed to them -- which is the ruling arriving as
 * behaviour rather than as a comment.
 */
final class AuthenticatedMembership implements ActiveMembership
{
    public function __construct(
        private readonly Memberships $memberships,
        private readonly ActiveTenant $activeTenant,
    ) {}

    public function resolve(Request $request): ?Membership
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $userId = $user->getAuthIdentifier();

        if (! is_string($userId) || ! Str::isUuid($userId)) {
            return null;
        }

        // A selected tenant wins when there is one, so a user with several memberships
        // can still use the panel after choosing -- and `in()` re-verifies the
        // membership rather than trusting the selection (ActiveTenant's docblock).
        $selected = $this->activeTenant->selected($request);

        $membership = is_string($selected)
            ? $this->memberships->in($userId, $selected)
            : $this->memberships->sole($userId);

        if ($membership === null || ! $membership->tenantIsActive()) {
            return null;
        }

        return $membership;
    }
}
