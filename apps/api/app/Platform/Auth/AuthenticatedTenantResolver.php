<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Membership;
use App\Platform\Http\TenantResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * THE REAL TENANT RESOLVER. It replaces the pre-Task-5 harness resolver, which is DELETED
 * in this same commit rather than disabled — `PROJECT_INSTRUCTIONS.md §4 › Tenancy` calls a
 * stub that survives Task 5 a **Critical** finding, and `R-035` is the row saying nothing
 * mechanical would have noticed. The deleted class is deliberately not named anywhere under
 * `app/`: `StubRemovedTest` greps this tree for it and requires zero hits, so naming it even
 * in a comment would fail that assertion — which is the assertion working, not a nuisance.
 *
 * `TenantResolver`'s own docblock set this class's contract before it existed: *"Task 5
 * replaces the implementation and nothing else."* `RequireTenantScope`, the rate limiter's
 * per-tenant key and the tenant-scope guard all speak to the interface, so this is the one
 * place in the application where "which tenant is this request" is decided.
 *
 * ================================================================================
 * NOTHING THE CALLER SENDS CHOOSES THE TENANT — and that is a test, not an intention
 * ================================================================================
 *
 * **The first draft of this class took the tenant from an `X-Tenant` header, and that was
 * wrong.** `PagerIdorTest` › *does not let a tenant id be chosen by anything the caller
 * sends* already drives five shapes at the staff surface — `X-Tenant-Id`, **`X-Tenant`**,
 * `?tenant_id=`, and `tenant_id` / `tenant` in the body — and asserts **404** for every one
 * of them. A header-based resolver would have turned that case into a 401: still a refusal,
 * but it would have meant *weakening an existing invariant test to fit a new design*. The
 * design changed instead. `QRPAGER-12 §10.3(b)` records the reversal.
 *
 * So there are exactly two sources for the tenant, and neither is request input:
 *
 *   - **A bearer token names its own tenant**, pinned in its abilities. That is not client
 *     input in the relevant sense: the token *is* the credential, and it was minted by the
 *     server with that tenant in it. A holder cannot edit it — `§2.7` gives `qrpager_app`
 *     `UPDATE (last_used_at, updated_at)` on `personal_access_tokens` and nothing else, so
 *     rewriting `abilities` is not a check that could be wrong, it is a grant that does not
 *     exist.
 *   - **A session request reads the SERVER-SIDE session**, via `ActiveTenant`. The value is
 *     written at login and by one explicit switch action, each of which re-verifies
 *     membership. A session cookie is opaque and server-signed; its contents never travel
 *     from the client.
 *
 * And whichever source answers, steps 3 and 4 below re-verify it on **every single
 * request**. The source is a selection; the membership set is the authority.
 *
 * ================================================================================
 * THE FOUR STEPS, in order, and every one of them can only narrow
 * ================================================================================
 *
 *   1. A principal, from the guard. No principal → null.
 *   2. A tenant selection, from the token or the session, or the user's sole membership.
 *   3. The selection is looked up in THE USER'S OWN MEMBERSHIPS (§2.8). Not a member →
 *      null. This is the step that makes 2 harmless.
 *   4. The tenant's status must be `active` → `R-081`. Suspended → null.
 *
 * **Every refusal is the same `null`**, and `RequireTenantScope` turns all of them into one
 * bodiless 401. That is not laziness: "that tenant does not exist", "that tenant is not
 * yours" and "that tenant is suspended" must be indistinguishable from outside, or the
 * mechanism becomes an oracle for which tenant ids exist and which are suspended. The
 * operator gets the detail through the log; the caller never does.
 *
 * ================================================================================
 * A PLATFORM ADMIN IS NOT A TENANT BYPASS
 * ================================================================================
 *
 * There is no branch for `is_platform_admin` in this file, and its absence is the control.
 * A platform admin goes through steps 2–4 exactly as anyone else does, so in a tenant where
 * they hold no membership they resolve **no tenant** and are refused by the ordinary 401.
 * `QRPAGER-12 §5` and the rank-1 prompt both say a platform admin is not a bypass, and the
 * prompt adds: if one is needed, that is a new named path and `PROJECT_INSTRUCTIONS.md §4`
 * must be edited first — stop and report, do not assume. So this task does not add it, and
 * `PLAN.md §3.1` row 6 already owns the decision for Task 6.
 */
final class AuthenticatedTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly Memberships $memberships,
        private readonly ActiveTenant $activeTenant,
    ) {}

    public function resolve(Request $request): ?string
    {
        // 1 ---------------------------------------------------------------- a principal
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $userId = $user->getAuthIdentifier();

        // Shape-checked even though it comes from the guard. The value becomes a tenant
        // scope and a Redis namespace downstream, and `§4 › Realtime` makes that a thing
        // to validate rather than trust -- a guard producing something impossible should
        // resolve no tenant, not reach the database with it.
        if (! is_string($userId) || ! Str::isUuid($userId)) {
            return null;
        }

        // 2 and 3 ------------------------------------------- selected, then verified
        $membership = $this->select($request, $userId);

        if ($membership === null) {
            return null;
        }

        // 4 ------------------------------------------------------------------- R-081
        //
        // A suspended tenant authenticates nobody. Enforced HERE, at the resolver, which
        // is what R-081's review-by cell requires and which the row argues for over the
        // two alternatives it rejects in advance: a per-query filter would put the check
        // in every call site, and a database-side control is ruled out by measurement --
        // §2.7 grants qrpager_app UPDATE on tenants.status, so the role whose requests
        // would be refused can rewrite the column. The check belongs where the credential
        // is exchanged, and this is that place: before any route body runs, for every /v1
        // route at once, with the same bodiless 401 as every other refusal.
        if (! $membership->tenantIsActive()) {
            return null;
        }

        return $membership->tenantId;
    }

    /**
     * Step 2 and step 3: what was selected, narrowed to what the user actually holds.
     *
     * The return value is a `Membership` and never a bare id, so a caller cannot reach
     * step 4 without the row that proves the membership.
     */
    private function select(Request $request, string $userId): ?Membership
    {
        // A bearer token pins its own tenant. Checked FIRST and exclusively: a device
        // token must never be able to borrow a session's selection, and a request
        // carrying both is a shape this application does not issue.
        $pinned = DeviceToken::pinnedTenant($request);

        if ($pinned !== null) {
            // '' is the malformed-pin sentinel from DeviceToken, and it is a uuid-shaped
            // impossibility on purpose: it can match no membership, so a token whose
            // tenant ability is garbage resolves nothing rather than falling through to
            // the session.
            return $this->memberships->in($userId, $pinned);
        }

        // THREE ANSWERS, NOT TWO, and the third is the one that matters: a selection that
        // is PRESENT but unusable is a refusal, never a fall-back. Collapsing it into
        // "nothing selected" would make a corrupted or tampered session silently act in a
        // tenant nobody chose -- and for a user with exactly one membership that succeeds
        // and looks entirely normal. ActiveTenant::has() is what separates the two cases,
        // and TenantResolutionTest asserted this before the code did it.
        if ($this->activeTenant->has($request)) {
            $selected = $this->activeTenant->selected($request);

            return $selected === null ? null : $this->memberships->in($userId, $selected);
        }

        // Nothing selected at all: the answer is the user's single membership, or nothing.
        // Never "the first of several" -- Memberships::sole() refuses that and says why.
        return $this->memberships->sole($userId);
    }
}
