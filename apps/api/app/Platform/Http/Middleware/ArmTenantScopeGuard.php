<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Database\TenantScopeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns "a request without tenant context never reaches the DB" from a property
 * somebody has to remember into one the kernel applies.
 *
 * ON THE GLOBAL STACK, not a route group's, and that is the whole design. A route
 * middleware is something a new route can forget; the global stack runs before routing,
 * so it also covers a path that matches no route at all -- a 404 that touches the
 * database on its way out is still a request, and there is a test for exactly that.
 * There is no allowlist of routes here and there is nothing to exempt: /healthz reads
 * nothing and /readyz reads one Redis key, so neither opens a connection for the guard
 * to have an opinion about.
 *
 * The guard is restored rather than cleared on the way out, so a nested request cannot
 * disarm the one around it. A request that dies without unwinding is handled at the
 * request boundary instead (R-022).
 */
final class ArmTenantScopeGuard
{
    public function __construct(private readonly TenantScopeGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response */
        return $this->guard->armedFor(static fn (): mixed => $next($request));
    }
}
