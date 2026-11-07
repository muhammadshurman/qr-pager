<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Which tenant a SESSION request is acting in, held server-side.
 *
 * ================================================================================
 * WHY THE SESSION AND NOT A HEADER
 * ================================================================================
 *
 * A staff user may hold memberships in several tenants, so something has to say which one
 * a request means. The obvious answer is a header, and this project has already decided
 * against it: `PagerIdorTest` › *does not let a tenant id be chosen by anything the caller
 * sends* drives `X-Tenant-Id`, `X-Tenant`, `?tenant_id=` and two body fields at the staff
 * surface and requires **404** from every one. The pre-Task-5 resolver's docblock said the
 * same thing in stronger terms: a resolver that read a header "would be an unauthenticated
 * tenant-switching parameter, which is the most dangerous shape this application could
 * ship, and it would read as perfectly ordinary code".
 *
 * The session is not that shape. The cookie is opaque and server-signed, the value lives
 * in Redis (`§1`: Redis is the locked session store), and the client cannot author it —
 * it can only present a cookie that points at a server-written record. So selecting a
 * tenant becomes a **deliberate, verified action** rather than an ambient request
 * parameter, and the five-shape IDOR test above keeps passing **unchanged**, which is how
 * this class is held to that claim.
 *
 * ================================================================================
 * WRITING IT IS WHERE THE CHECK LIVES, AND READING IT IS STILL NOT TRUSTED
 * ================================================================================
 *
 * `select()` refuses a tenant the user holds no membership in, so the session can never
 * come to hold one. **And the resolver re-verifies on every request anyway** — a
 * membership revoked, or a tenant suspended, after the selection was written must stop
 * working immediately, and it does, because `AuthenticatedTenantResolver` looks the
 * selection up in the live membership set rather than believing the session. The session
 * holds a *preference*; the membership set is the authority. Both halves are tested.
 */
final class ActiveTenant
{
    /**
     * The session key. Namespaced under `tenancy.` so that it is obvious in a session dump
     * what it is, and so a future second key has somewhere to live.
     */
    public const SESSION_KEY = 'tenancy.active_tenant';

    public function __construct(private readonly Memberships $memberships) {}

    /**
     * The tenant id this session has selected, or null.
     *
     * Shape-checked on the way out as well as on the way in. A session store is
     * server-side, but it is still a store, and this value becomes a tenant scope: the
     * project's rule is that anything which becomes a scope is validated at the point of
     * use, not once at the point of writing.
     */
    public function selected(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::SESSION_KEY);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * Whether this session carries a selection AT ALL, whatever its value.
     *
     * ================================================================================
     * WHY THIS IS SEPARATE FROM selected(), and it is a real distinction
     * ================================================================================
     *
     * The resolver needs THREE answers, not two: no selection (fall back to the user's
     * sole membership), a good selection (use it), and **a selection that is present and
     * unusable** (refuse). Collapsing the third into the first is the bug this method
     * exists to prevent: a corrupted or tampered session value would fall back and the
     * request would silently act in a tenant **nobody chose** — which, for a user who
     * holds exactly one membership, succeeds and looks completely normal.
     *
     * Found by `TenantResolutionTest` › *refuses a session selection that is not a uuid*,
     * which asserted the refusal before the code did it: the first version of this class
     * had `selected()` alone, so a session value of `''` resolved the sole membership. The
     * test was right and the code changed.
     */
    public function has(Request $request): bool
    {
        return $request->hasSession() && $request->session()->exists(self::SESSION_KEY);
    }

    /**
     * Select a tenant for this session, if the user is a member of it and it is active.
     *
     * Returns false and writes nothing when the user holds no such membership or the
     * tenant is not active — the caller turns that into the same refusal every other
     * tenancy failure gets, because "not yours" and "suspended" must not be
     * distinguishable from outside.
     */
    public function select(Request $request, string $userId, string $tenantId): bool
    {
        if (! $request->hasSession() || ! Str::isUuid($tenantId)) {
            return false;
        }

        $membership = $this->memberships->in($userId, $tenantId);

        if ($membership === null || ! $membership->tenantIsActive()) {
            return false;
        }

        // Regenerated on a privilege change, like login: switching tenant changes what
        // this session may reach, and a fixated id must not carry the new authority.
        // SessionPolicyTest asserts the id moves.
        $request->session()->migrate(true);
        $request->session()->put(self::SESSION_KEY, $membership->tenantId);

        return true;
    }

    /**
     * Forget the selection.
     *
     * Called on logout, and when a login lands a user whose membership set may have
     * changed since their last session: a stale selection would otherwise be re-verified
     * and refused on every request, which looks to the user like a broken account rather
     * than a tenant they no longer belong to.
     */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
