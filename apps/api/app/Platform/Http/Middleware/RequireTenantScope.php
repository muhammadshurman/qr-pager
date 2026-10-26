<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use App\Platform\Http\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default for every /v1 route: no tenant, no request.
 *
 * IT OPENS NO SCOPE, and that is the decision B4 is about. A tenant scope opens in the
 * service layer, once per unit of work, because a request-scoped transaction would hold
 * one of the forty qrpager_app connections for the whole request -- colliding with both
 * the connection cap and the fast-503 clause. All this does is resolve the tenant id and
 * make it available; TenantContext is what narrows a query, and TenantScopeGuard is what
 * refuses one that is not narrowed.
 *
 * The 401 carries no detail about WHY, deliberately. "No tenant could be resolved" and
 * "that tenant is not yours" are the same answer from outside, and a different one would
 * tell a caller which tenant ids exist.
 */
final class RequireTenantScope
{
    /** Where the resolved tenant id is published for this request. */
    public const BINDING = 'tenancy.request_tenant';

    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        // instance(), so it is forgotten with the container at the end of the request --
        // the same reason TenantContext is scoped(). A singleton here would carry one
        // request's tenant id into the next on an Octane worker, which is the whole
        // class of bug §2.10 and R-022 are about.
        app()->instance(self::BINDING, $tenant);

        return $next($request);
    }
}
