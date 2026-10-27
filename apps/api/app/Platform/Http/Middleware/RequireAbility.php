<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Auth\RequestPrincipal;
use App\Platform\Auth\RouteAbilities;
use App\Platform\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorisation for the staff surface: the principal must hold the ability this route
 * requires, in the tenant already resolved for the request.
 *
 * ================================================================================
 * IT FAILS CLOSED ON AN UNMAPPED ROUTE, which is the point
 * ================================================================================
 *
 * The ability is looked up by ROUTE NAME in `RouteAbilities`, not passed as a middleware
 * parameter. A staff route that nobody mapped therefore gets **403 and not a free pass** —
 * so forgetting the map is a loud refusal rather than an open endpoint. `RoleMatrixTest`
 * additionally fails the suite when any `/v1` staff route is unmapped, so the mistake is
 * caught at build time as well as at runtime; this is the runtime half, and it exists
 * because a build-time check is only as good as the suite being run.
 *
 * An unnamed route is treated the same way. Laravel lets a route go nameless, and a
 * nameless staff route cannot be mapped, so it cannot be authorised either.
 *
 * ================================================================================
 * 403 AND NOT 404, AND WHY THAT IS NOT AN IDOR LEAK
 * ================================================================================
 *
 * `§11.2`'s IDOR rule makes cross-tenant access answer **404**: another tenant's row must
 * be indistinguishable from one that does not exist. That rule is satisfied one layer
 * below this one and by a different mechanism — the resolver never returns a tenant the
 * user is not a member of, so a cross-tenant request is refused at 401 before reaching a
 * route, and a cross-tenant ID *inside* a resolved tenant is invisible to the query and
 * answers 404. Neither path reaches this middleware.
 *
 * What this middleware refuses is a principal acting in **their own** tenant without the
 * ability — a staff member trying to change a colleague's role. They already know the
 * tenant exists and that they are in it, so 403 discloses nothing they did not bring with
 * them, and 404 would be a lie that makes the API harder to use for no security gain.
 */
final class RequireAbility
{
    public function __construct(private readonly RequestPrincipal $principal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        $ability = is_string($name) ? RouteAbilities::for($name) : null;

        if ($ability === null) {
            // FAIL CLOSED. An unmapped or unnamed staff route is refused, because the
            // alternative is serving it unauthorised. The body says nothing about the
            // cause -- an operator reads the log, a caller gets the same 403 as any other
            // refusal, and a missing map entry is not a hint worth publishing.
            report(new \RuntimeException(
                'RequireAbility refused an unmapped staff route: '.($name ?? '(unnamed)').
                '. Add it to App\Platform\Auth\RouteAbilities::MAP, or put it in the '.
                'customer or auth set in RoleMatrixTest if it is not a staff route.'
            ));

            return (new Problem(ProblemType::Forbidden, $request->getPathInfo()))->toResponse();
        }

        if (! $this->principal->may($request, $ability)) {
            return (new Problem(ProblemType::Forbidden, $request->getPathInfo()))->toResponse();
        }

        return $next($request);
    }
}
