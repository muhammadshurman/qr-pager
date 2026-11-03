<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use App\Domain\Auth\AttachmentRefusal;
use App\Platform\Auth\Memberships;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * ================================================================================
 * MAY THIS ADDRESS BE ATTACHED TO A TENANT WITHOUT ITS OWNER'S CONSENT? `R-233`.
 * ================================================================================
 *
 * `§4 › Security`: *"Attaching an existing user to a tenant requires that user's acceptance
 * of an invite … Until that invite flow exists, the implementation is deliberately stricter
 * than this clause: an address that already belongs to an account holding any membership, or
 * to a platform admin, is refused outright rather than invited — so the weaker rule is never
 * the one in force. Both refusals answer with one message."*
 *
 * ⚠ **ONE RULE, TWO CALLERS, AND IT USED TO BE ONE CALLER AND A HOLE.** The provisioning form
 * implemented this in `TenantProvisioning::refusalForOwnerEmail()`; `MemberDirectory::add()`
 * implemented none of it and attached whatever the lookup found. Measured over HTTP at the
 * `task-10` tag: alpha's manager attached beta's **owner** (201) and a platform admin (201),
 * and that platform admin then signed in, resolved `principal: manager`, and read alpha's
 * members and history. That is `R-233`, and Muhammad ruled it a must-fix rather than a `§5`
 * residual because the clause above is the rule already in force.
 *
 * So the rule lives here, in Platform, where both features may reach it. Extracting it was
 * the point: two copies of a security rule agree on the day they are written.
 *
 * ================================================================================
 * ⚠ IT ADDS NO CROSS-TENANT PATH. IT USES THE ONE `§4 › Tenancy` ALREADY NAMES.
 * ================================================================================
 *
 * `§4 › Tenancy` holds exactly four named cross-tenant paths and says *"adding one requires
 * editing this file first"* — a rank-2 edit, which this is not. The second named path is **"a
 * user's own memberships"**, implemented as the `SECURITY DEFINER` function
 * `public.user_memberships(uuid)` and read here through `Memberships`, which is already its
 * reader. One exact-match uuid, a narrow `RETURNS TABLE`, the bypass on for the function's
 * own body only. `TenantProvisioning`'s own docblock recorded the same reasoning for the same
 * call: *"this adds no cross-tenant path: it uses the one that exists."*
 *
 * `users` is global and RLS-free (`§2.4`), so the address lookup and `is_platform_admin` need
 * no scope and are no kind of cross-tenant read.
 *
 * ================================================================================
 * ⚠ IN `Platform\Tenancy` AND NOT IN `Platform\Auth`, AND DEPTRAC DECIDED THAT
 * ================================================================================
 *
 * It was written in `App\Platform\Auth` and `composer deptrac` refused both callers:
 * `app/Platform/Auth/.*` is its own **Auth** layer, excluded from **Platform** by that
 * layer's own collector, and `Features` may depend on `Domain`, `Platform` and `Framework`
 * -- not on `Auth`. Two violations, one per caller, which is the rule working rather than
 * an obstacle: the Auth layer is Task 5's credential surface and a feature reaching into it
 * directly is the coupling that separation exists to prevent.
 *
 * Here it is **Platform**, which `Features` may depend on and which may itself depend on
 * `Auth` (`Platform: [Domain, Framework, Auth]`) -- so the one edge into the credential
 * surface is this class's read of `Memberships`, in one place, instead of one per feature.
 *
 * ⚠ **AND WHAT CROSSES INTO THIS PROCESS NEVER CROSSES INTO A RESPONSE.** `Memberships::for()`
 * returns another user's tenant ids, slugs and names; this class reads only *whether the list
 * is empty* and returns an enum case. No caller receives a tenant identity, and
 * `MemberConsentTest` asserts the refusal body names none.
 */
final class UserAttachment
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Memberships $memberships,
    ) {}

    /**
     * The reason this address may not be attached, or `null` if it may.
     *
     * ⚠ **`null` FOR AN ADDRESS WITH NO ACCOUNT AT ALL, WHICH IS NOT THE SAME AS "MAY BE
     * ATTACHED".** It means *this rule has nothing to say*: there is no account to consent,
     * so the caller's own unknown-address answer applies. Both callers already have one and
     * they differ — the form tells a platform admin to create the account, and
     * `POST /v1/members` answers 404 — so this class must not collapse them.
     */
    public function refusalFor(string $email): ?AttachmentRefusal
    {
        $existing = $this->connection->selectOne(
            'select id, is_platform_admin from users where email = ?',
            [Str::lower($email)],
        );

        if ($existing === null) {
            return null;
        }

        /*
         * The platform-admin case first, and the order is not arbitrary: a platform admin
         * holds no membership, so checked second it would never be reached for one that
         * somehow had one. Both answers are indistinguishable to a caller, so the order
         * costs nothing and the stricter reading is free.
         */
        if ((bool) $existing->is_platform_admin) {
            return AttachmentRefusal::PlatformAdmin;
        }

        return $this->memberships->for((string) $existing->id) === []
            ? null
            : AttachmentRefusal::AlreadyAMember;
    }
}
