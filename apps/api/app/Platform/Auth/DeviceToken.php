<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Ability;
use App\Domain\Auth\TenantPin;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * A KDS device token: ONE tenant, ONE capability, an EXPLICIT expiry.
 *
 * `QRPAGER-12 §5`: *"A KDS device token lives on a shared screen in a kitchen. Scope it to
 * exactly one tenant and one capability, and make expiry explicit."* Three bounds, and
 * each one is mechanical rather than operational.
 *
 * ================================================================================
 * HOW THE TENANT IS CARRIED, and why it is not a column
 * ================================================================================
 *
 * As an ability string, `tenant:<uuid>`, beside the capability. `personal_access_tokens`
 * has **no `tenant_id` column** and `QRPAGER-12 §10.1(e)` records the reason in full: a
 * `tenant_id` makes it a tenant table, C4 then forces RLS `ENABLE` + `FORCE` on it, and the
 * row becomes invisible to the very lookup that must happen *before* a tenant is known —
 * whose only escape is a fifth named cross-tenant path, which `§4 › Tenancy` forbids
 * without editing `PROJECT_INSTRUCTIONS.md` first.
 *
 * **So the tenant in the token is a CLAIM, and a claim is not an authority.** It is used
 * only to NARROW: `AuthenticatedTenantResolver` resolves the tenant from the holder's own
 * memberships first and then requires the token's tenant to equal it. A token naming a
 * tenant its holder is not a member of resolves nothing. A token naming no tenant narrows
 * nothing and is bounded by the membership check alone.
 *
 * ================================================================================
 * WHY `abilities` AND `expires_at` ARE NOT WRITABLE BY THE APP ROLE
 * ================================================================================
 *
 * `§2.7`, as this task's migration sets it: `qrpager_app` holds `UPDATE (last_used_at,
 * updated_at)` on `personal_access_tokens` and nothing else. A token that could rewrite its
 * own `abilities` or push out its own `expires_at` is exactly the escalation this task's
 * second named invariant forbids, and withholding the grant makes that **structural** — a
 * bug in this file cannot widen a token, because the role cannot write the columns.
 */
final class DeviceToken
{
    /**
     * The tenant this request's token is pinned to, or null when it is not pinned.
     *
     * Null has two causes and they are deliberately the same answer here: there is no
     * bearer token at all (a session request), or the token carries no `tenant:` ability.
     * Neither is an authority to act anywhere — both mean "this check narrows nothing",
     * and the membership check in the resolver is what bounds the request.
     */
    public static function pinnedTenant(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof HasAbilities) {
            return null;
        }

        foreach (self::abilitiesOf($token) as $ability) {
            $tenantId = TenantPin::tenantFrom($ability);

            if ($tenantId === null) {
                continue;
            }

            // A malformed pin is a refusal, not an unpinned token. Returning null here
            // would turn "tenant:garbage" into "narrows nothing", which is the failure
            // direction that widens — so an impossible value yields an impossible tenant
            // and the resolver's hash_equals cannot match it.
            return Str::isUuid($tenantId) ? $tenantId : '';
        }

        return null;
    }

    /**
     * Whether this request's credential carries the capability.
     *
     * A session request holds no token, so `currentAccessToken()` is null and the answer
     * comes from the role matrix instead — which is why this is only ever one half of an
     * authorisation decision and never the whole of it.
     */
    public static function allows(Request $request, Ability $ability): bool
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof HasAbilities) {
            return false;
        }

        return $token->can($ability->value);
    }

    /**
     * Whether this request was authenticated by a bearer token rather than a session.
     *
     * The two authentication surfaces must never be confused (`§4 › Tenancy`), and the
     * places that need to tell them apart — CSRF applies to one and not the other, and the
     * principal is `Device` for one and a tenant role for the other — ask here rather than
     * each sniffing the request their own way.
     *
     * ================================================================================
     * ⚠ A REAL TOKEN ROW, AND NOT `HasAbilities` — WHY THE PREDICATE IS NARROWER
     * ================================================================================
     *
     * This read `instanceof HasAbilities`, and **Sanctum's `TransientToken` implements that
     * interface**. `Sanctum\Guard::__invoke` attaches one to any user it resolves from the
     * **web** guard — unconditionally, not gated on `sanctum.stateful`, which this project
     * correctly leaves empty — and `TransientToken::can()` returns `true` for every ability
     * including ones that do not exist. So the moment anything resolves the `sanctum` guard
     * on a SESSION request, this answered `true` for a staff user.
     *
     * Both `§11` reviewers of the fix diff found it independently, and what it costs is
     * worse than a mis-answer: `RefuseDeviceToken` reads the token's `getKey()` for its log
     * line, `TransientToken` has no such method, and the measured result was a **500 on
     * `GET /v1/auth/me` for a legitimate signed-in owner**. `RequestPrincipal` would
     * separately demote that user to `Principal::Device`, which holds one ability.
     *
     * Nothing resolves that guard in this tree today — `ResolvePrincipal` tries `web` first
     * and returns, and nothing else calls `auth('sanctum')` — so this was latent. It stops
     * being latent the moment any route uses `auth:sanctum`, which is the obvious shape for
     * Task 10's KDS work, and it would then be a 500 in the file holding a Critical's fix.
     *
     * `PersonalAccessToken` is this project's own model (a uuid key, `§2.4`), which is what
     * a genuine device token always is. `DeviceToken::allows()` keeps the broader
     * `HasAbilities` check because it asks a different question — "may this credential do
     * X" — and a `TransientToken` answering `true` there is harmless: `RequestPrincipal`
     * requires the matrix to grant the principal the ability as well, and the weaker of the
     * two always wins.
     */
    public static function isTokenRequest(Request $request): bool
    {
        return $request->user()?->currentAccessToken() instanceof PersonalAccessToken;
    }

    /**
     * The raw ability strings on a token.
     *
     * Read off the model attribute rather than probed with `can()`, because `can()`
     * answers a yes/no question about ONE ability and the tenant pin is a value that has
     * to be extracted. Sanctum stores the column as JSON and casts it to an array, so a
     * malformed or absent value yields an empty list and the caller treats the token as
     * unpinned — which the resolver then refuses on the membership check alone.
     *
     * @return list<string>
     */
    private static function abilitiesOf(HasAbilities $token): array
    {
        $raw = $token instanceof Model ? $token->getAttribute('abilities') : null;

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_string'));
    }
}
