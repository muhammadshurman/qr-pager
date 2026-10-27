<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Http\PacedRoute;
use App\Platform\Http\Problem;
use App\Platform\Http\RequestLimiter;
use App\Platform\Http\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Per IP and per tenant (§4 › Realtime, D5), with the posture Redis requires (B7).
 *
 * BOTH KEYS, because they answer different questions. Per-IP stops one client flooding
 * the service and is no protection at all behind a NAT or a mobile carrier; per-tenant
 * stops one tenant's traffic, from however many clients, consuming the capacity every
 * other tenant on the task shares.
 *
 * The tenant key comes from the TenantResolver and not from the request, so there is no
 * input a caller can vary to get a fresh bucket. Before Task 5 that resolver is the
 * harness stub, so the per-tenant limit is exercised through it -- which is D5 exactly.
 * A request with no resolvable tenant is limited by IP only and then refused by
 * RequireTenantScope; the limiter runs FIRST on purpose, so an unauthenticated flood
 * costs the limiter and not the resolver.
 *
 * FAIL OPEN when the limiter cannot be reached, which is RequestLimiter's doing and is
 * measured rather than assumed. The residual -- an attacker who downs Redis removes
 * rate limiting -- goes to §5 with owner Task 12.
 */
final class RateLimit
{
    public function __construct(
        private readonly RequestLimiter $limiter,
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string $bucket = 'api'): Response
    {
        foreach ($this->keysFor($request, $bucket) as [$key, $attempts, $seconds]) {
            if ($this->limiter->tooManyAttempts($key, $attempts, $seconds)) {
                // QA M-3: the 429 keeps Retry-After, which is what §3 row 4 asks of
                // it, and gains the body pacing where the route publishes one.
                return (new Problem(
                    ProblemType::RateLimited,
                    $request->getPathInfo(),
                    retryAfter: $this->limiter->availableIn($key),
                    pollAfter: PacedRoute::intervalFor($request),
                ))->toResponse();
            }
        }

        return $next($request);
    }

    /**
     * The buckets this request counts against, in the order they are checked.
     *
     * IP first, so a flood from one address is refused before anything resolves a
     * tenant for it.
     *
     * @return list<array{string, int, int}>
     */
    private function keysFor(Request $request, string $bucket): array
    {
        /** @var array<string, array<string, int>> $limits */
        $limits = (array) config("ratelimit.buckets.{$bucket}", []);
        $keys = [];

        if (isset($limits['per_ip'])) {
            // sha1 of the address rather than the address itself: these keys live in
            // Redis, which is shared, and an IP is personal data under §4's own posture
            // even though it is not a secret. The hash is stable, so the bucket is.
            $keys[] = [
                "ratelimit:{$bucket}:ip:".sha1((string) $request->ip()),
                (int) $limits['per_ip']['attempts'],
                (int) $limits['per_ip']['seconds'],
            ];
        }

        // The resolver is only asked when the bucket HAS a per-tenant limit: the
        // `unknown` bucket does not, because an unmatched path carries no tenant and
        // resolving one for it would be work an unauthenticated scanner can make this
        // process do.
        if (isset($limits['per_tenant']) && ($tenant = $this->tenant($request)) !== null) {
            $keys[] = [
                "ratelimit:{$bucket}:tenant:{$tenant}",
                (int) $limits['per_tenant']['attempts'],
                (int) $limits['per_tenant']['seconds'],
            ];
        }

        return $keys;
    }

    /**
     * The tenant, or null -- never an exception.
     *
     * The resolver refuses outright outside local/testing (B5), and a rate limiter is
     * not where a deployment should discover that: RequireTenantScope is, one step
     * later, with a 401 that says nothing. So a resolver that throws here means "no
     * tenant bucket", and the request is still counted against its IP.
     */
    private function tenant(Request $request): ?string
    {
        try {
            return $this->resolver->resolve($request);
        } catch (Throwable) {
            return null;
        }
    }
}
