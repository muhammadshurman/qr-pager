<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use App\Platform\Http\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        // A RESOLVER THAT REFUSES IS "NO TENANT", NOT A SERVER FAULT (security L-3).
        //
        // resolve() was called bare, so the pre-Task-5 stub's own refusal outside
        // local/testing -- a RuntimeException, by design, so the stub cannot serve a
        // production request -- became an uncaught throwable and a generic 500. Measured as
        // part of this fix: every /v1 route answered 500 in `production`, not the 401 this
        // class's docblock and TenantResolutionTest both describe.
        //
        // Three things were wrong with that. Deny-by-default still held -- no route body
        // ran -- but a misdeployment presented as a 500 storm indistinguishable from an
        // outage rather than a clean refusal; a 500 goes through the ServerError arm of the
        // renderer rather than the Unauthenticated one, so the body was asserted nowhere on
        // that path; and RateLimit::tenant() already swallows the same throw one middleware
        // earlier, so the two disagreed about what a refusing resolver means.
        //
        // Throwable and not RuntimeException: the resolver is an interface, Task 5 replaces
        // the implementation, and "could not determine a tenant" is the same answer whatever
        // the reason. The detail reaches the operator through the log -- the exception is
        // still reported by the handler registered in bootstrap/app.php -- and never the
        // caller, who gets the same no-detail 401 as an unknown tenant. "This tenant does
        // not exist" and "that tenant is not yours" are the same answer from outside.
        try {
            $tenant = $this->resolver->resolve($request);
        } catch (Throwable $refused) {
            report($refused);

            $tenant = null;
        }

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
