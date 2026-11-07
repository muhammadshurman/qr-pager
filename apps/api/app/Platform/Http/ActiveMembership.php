<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Auth\Membership;
use Illuminate\Http\Request;

/**
 * The membership a session request is acting under -- tenant AND role, in one answer.
 *
 * ================================================================================
 * WHY THIS EXISTS BESIDE TenantResolver RATHER THAN WIDENING IT
 * ================================================================================
 *
 * `TenantResolver` answers "which tenant", and four surfaces built at Tasks 2-5 depend on
 * exactly that shape. Task 7's tenant-scoped panel needs the ROLE too, because
 * `QRPAGER-14 D1` restricts editing to `owner` and `manager` -- `§4 › Security`'s
 * seniority rule applied to content.
 *
 * It is a SECOND INTERFACE and not a second lookup: the implementation resolves the same
 * membership `AuthenticatedTenantResolver` does, through the same `public.user_memberships`
 * function (`§2.8`'s second named cross-tenant path), with the same re-verification on
 * every request. A feature module reading `tenant_users` itself to learn a role would be
 * a second answer to a question this project keeps in one place, which is the property
 * RequireTenantScope's docblock depends on.
 *
 * IT LIVES IN Platform AND IS IMPLEMENTED IN Platform\Auth, which is the pattern
 * TenantResolver already set: deptrac keeps `app/Platform/Auth` out of reach of every
 * feature module, so the interface is how a feature asks.
 *
 * ⚠ IT MAY ANSWER null, AND A CALLER MUST TREAT THAT AS "REFUSE". Null means no session,
 * no membership, a suspended tenant, or more than one membership -- and a panel that
 * showed a page on a null answer would be showing it to someone with no tenant at all.
 */
interface ActiveMembership
{
    /**
     * The one membership this request acts under, or null.
     *
     * Null for a user holding memberships in SEVERAL tenants with none selected: the
     * tenant-scoped panel is a single-tenant surface by `D1` ("a membership ... in
     * exactly one tenant"), and guessing which one would be the ambient-parameter shape
     * `ActiveTenant`'s docblock refuses.
     */
    public function resolve(Request $request): ?Membership;
}
