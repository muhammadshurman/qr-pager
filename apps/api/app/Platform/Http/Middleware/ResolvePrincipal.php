<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate the request from EITHER surface, and say which one answered.
 *
 * ================================================================================
 * WHY THIS EXISTS, measured rather than designed up front
 * ================================================================================
 *
 * `RequireTenantScope` and the resolver behind it ask `$request->user()`, which resolves
 * the **default** guard — `web`, the session. A KDS device presenting a bearer token
 * therefore answered **401 on every route**, including the one route its ability permits.
 * Measured: `DeviceTokenScopeTest`'s positive control, which exists precisely so that the
 * refusals around it cannot pass for a token that never authenticated at all.
 *
 * ================================================================================
 * WHY NOT `auth:sanctum`, THE OBVIOUS ANSWER
 * ================================================================================
 *
 * Sanctum's guard already falls back to the session for "stateful" domains, so
 * `auth:sanctum` alone would cover both surfaces — if `sanctum.stateful` listed this
 * application's own domain. `config/sanctum.php` deliberately leaves that list **empty**,
 * and its comment says why: a stateful domain is precisely a mechanism for letting one
 * surface answer as the other, and `§4 › Tenancy` requires the two never to be confused.
 *
 * So the two guards are tried **explicitly, in a fixed order**, and nothing is inferred
 * from a domain list:
 *
 *   1. **The session** (`web`). The staff surface.
 *   2. **The bearer token** (`sanctum`). The KDS/device surface.
 *
 * Session first, because a browser request carrying both a session cookie and a stray
 * `Authorization` header is a staff request with a header on it, not a device — and the
 * reverse order would silently demote that user to `Principal::Device`, which holds one
 * ability. Fixed order means that is a decision with a reason rather than whichever guard
 * happened to be asked first.
 *
 * ================================================================================
 * IT AUTHENTICATES AND AUTHORISES NOTHING
 * ================================================================================
 *
 * No refusal happens here. A request with no credential passes through with no user, and
 * `RequireTenantScope` — which runs after this — answers the bodiless 401. That keeps
 * exactly one place in the application where "no" is said to an unauthenticated caller,
 * which is what makes its "every refusal is the same answer" property checkable.
 */
final class ResolvePrincipal
{
    /** The guards tried, in order. */
    public const GUARDS = ['web', 'sanctum'];

    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::GUARDS as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user !== null) {
                // shouldUse so that auth() elsewhere in the request agrees with the user
                // the resolver saw. Without it, `auth()->id()` in a controller could answer
                // null for a perfectly authenticated device request -- two readings of the
                // same request, which is the shape §4a keeps recording.
                $this->auth->shouldUse($guard);

                $request->setUserResolver(fn () => $user);

                return $next($request);
            }
        }

        return $next($request);
    }
}
