<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Models\User;
use App\Platform\Http\Middleware\RequireTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WHO this request is, as an authorisation subject, and what it may therefore do.
 *
 * ================================================================================
 * THE ORDER OF THE THREE CASES IS THE WHOLE OF THE SECURITY HERE
 * ================================================================================
 *
 * 1. **A bearer token is a `Device`, whatever its holder is.** Checked FIRST, and this is
 *    the clause that makes "device token scope cannot escalate" — Task 5's second named
 *    invariant — structural rather than a policy. A device token is issued to a user, and
 *    that user is usually a manager or an owner. If the principal were derived from the
 *    membership role, a kitchen screen would hold everything its issuer holds, and the
 *    token's own single ability would be decoration. So the credential SHAPE decides, and
 *    the membership role is not consulted at all for a token request.
 *
 * 2. **A platform admin is `PlatformAdmin`**, which `RoleMatrix` grants **no tenant
 *    ability at all**. They are not a bypass (`QRPAGER-12 §5`); they hold platform-scoped
 *    powers that Task 6 builds. A platform admin who is also a member of the resolved
 *    tenant is treated as that membership instead — see the ordering comment below, which
 *    is the one case where the two could disagree.
 *
 * 3. **Otherwise the membership's role**, via `Principal::fromTenantRole()`.
 *
 * ================================================================================
 * IT NEVER DECIDES WHICH TENANT, ONLY WHAT MAY BE DONE IN IT
 * ================================================================================
 *
 * The tenant is `AuthenticatedTenantResolver`'s answer and this class takes it from
 * `RequireTenantScope::BINDING` — already resolved, already verified against the user's own
 * memberships, already checked for suspension. Two separate questions with two separate
 * classes: a reviewer looking for "can actor A reach tenant B" reads the resolver, and one
 * looking for "may this actor do this" reads here.
 */
final class RequestPrincipal
{
    public function __construct(private readonly Memberships $memberships) {}

    /**
     * The principal this request acts as inside the resolved tenant, or null when there is
     * no principal at all.
     */
    public function for(Request $request): ?Principal
    {
        // Typed as the project's own model rather than as Authenticatable, because this
        // class reads isPlatformAdmin() and that is a property of THIS application's user
        // and not of the contract. PHPStan agrees: it reports the later `instanceof User`
        // as always true, which is the same fact arriving as a warning.
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $userId = $user->getAuthIdentifier();

        if (! is_string($userId) || ! Str::isUuid($userId)) {
            return null;
        }

        // CASE 1 -- the credential shape wins. See the class docblock.
        if (DeviceToken::isTokenRequest($request)) {
            return Principal::Device;
        }

        $tenantId = $this->resolvedTenant();

        if ($tenantId === null) {
            return null;
        }

        $membership = $this->memberships->in($userId, $tenantId);

        // CASE 3, preferred over case 2 when BOTH are true, and the ordering is deliberate.
        //
        // A platform admin who genuinely holds a membership in this tenant is acting as
        // that member -- the flag is not a demotion. Reading the flag first would mean a
        // platform admin who is also an owner of one restaurant could do LESS there than an
        // ordinary owner, because RoleMatrix grants PlatformAdmin nothing. That would be a
        // strange rule, and worse, it would push somebody towards granting PlatformAdmin
        // tenant abilities to fix it -- which is the tenant bypass §5 forbids.
        if ($membership !== null) {
            /*
             * ⚠ `forMembership`, NOT `fromTenantRole`, SINCE `QRPAGER-22`. A support
             * membership stores the least-privileged ROLE so that a mis-resolution fails
             * closed, and its abilities come from its EXPIRY instead: `§4 › Tenancy`
             * (2026-11-04) makes support read-only, and `staff` holds `pager:create` and
             * `pager:notify`, which are writes. If the role decided here, the read-only
             * clause would be false for every support membership ever taken.
             *
             * The case-3-before-case-2 ordering above is untouched and still right: a
             * platform admin who holds a REAL membership here acts as that member. A support
             * membership is a real membership — an explicit, single, expiring, audited one —
             * which is exactly why it resolves through this branch rather than beside it.
             */
            return Principal::forMembership($membership->role, $membership->expiresAt);
        }

        // CASE 2 -- a platform admin with no membership here. Note what this returns: a
        // principal that RoleMatrix grants NOTHING, so every staff route refuses. It is not
        // null, because "a platform admin reaching a tenant they do not belong to" is a
        // real principal being correctly refused rather than an absence of authentication,
        // and the distinction is what PlatformAdminIsNotABypassTest asserts.
        //
        // In practice the resolver has already refused this request: it resolves no tenant
        // for a user with no membership, so the route never runs. This arm exists for the
        // case where a tenant IS resolved by another route of the same user's -- and for
        // the test that drives it directly.
        if ($user->isPlatformAdmin()) {
            return Principal::PlatformAdmin;
        }

        return null;
    }

    /**
     * Whether this request may exercise the ability, in the tenant already resolved for it.
     *
     * BOTH halves must hold for a token request, and that is the escalation bound: the
     * matrix must grant the principal the ability AND the token must carry it. A device
     * token whose abilities were somehow widened still cannot exceed what `Device` holds in
     * the matrix, and a token narrower than `Device` is bounded by its own abilities. The
     * weaker of the two wins in every combination.
     */
    public function may(Request $request, Ability $ability): bool
    {
        $principal = $this->for($request);

        if ($principal === null || ! RoleMatrix::allows($principal, $ability)) {
            return false;
        }

        if (DeviceToken::isTokenRequest($request)) {
            return DeviceToken::allows($request, $ability);
        }

        return true;
    }

    /** The tenant `RequireTenantScope` published for this request, or null. */
    private function resolvedTenant(): ?string
    {
        $app = app();

        if (! $app->bound(RequireTenantScope::BINDING)) {
            return null;
        }

        $tenantId = $app->make(RequireTenantScope::BINDING);

        return is_string($tenantId) && Str::isUuid($tenantId) ? $tenantId : null;
    }
}
