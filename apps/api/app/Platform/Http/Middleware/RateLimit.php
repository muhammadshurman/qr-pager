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
    /**
     * The bucket a PANEL STACK declares, and the only one `route_buckets` may replace.
     *
     * A route-level limiter passes its own bucket deliberately and must be left alone.
     */
    private const STACK_BUCKET = 'panel';

    public function __construct(
        private readonly RequestLimiter $limiter,
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string $bucket = 'api'): Response
    {
        foreach ($this->keysFor($request, $this->bucketFor($request, $bucket)) as [$key, $attempts, $seconds]) {
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
     * The bucket this request counts against, which is the argument unless a ROUTE asks
     * for a different one.
     *
     * ================================================================================
     * ⚠ WHY THIS EXISTS, AND WHY A ROUTE-LEVEL `->middleware()` DOES NOT WORK
     * ================================================================================
     *
     * `QRPAGER-45` needed the two `/studio/preview` routes on their own bucket: the live
     * preview re-requests the document and its images on every debounce fire, which is not
     * the "human clicking through an admin panel" the `panel` bucket is sized for.
     *
     * The first attempt did it at the route — `withoutMiddleware(RateLimit:panel)` plus
     * `->middleware(RateLimit:studio-preview)` — and the `§11` security round of
     * 2026-11-11 (H-1) measured what that actually produced. **Route middleware is
     * APPENDED and then sorted, and this class is not in Laravel's priority list, so the
     * limiter ran LAST**: after `StartSession` and after `Authenticate`. Measured with
     * `route:list`, on `/studio` the order is `SetUpPanel → RateLimit:panel → … →
     * StartSession → … → Authenticate`; on `/studio/preview` it was
     * `… StartSession → … → Authenticate → … → RateLimit:studio-preview`.
     *
     * The consequences were both of the ones the panel's own stack comment exists to
     * prevent, and they are Task 6's security H-2 / `R-027` arriving again:
     *
     *   1. an anonymous flood was **completely unmetered** — `Authenticate` refused it
     *      with a 401 before the limiter ever ran, so no counter was even created;
     *   2. every one of those requests still **minted a 7200 s Redis session key**,
     *      because `StartSession` had already run — filling the instance that is also the
     *      cache and queue store the customer pager path depends on.
     *
     * So the bucket is chosen HERE instead, and `RateLimit:panel` keeps the first position
     * its own comment says it must have. The map lives in config beside the buckets rather
     * than in this class, so this stays "which bucket is this request's" and never "which
     * routes exist".
     */
    private function bucketFor(Request $request, string $bucket): string
    {
        /*
         * ⚠ ONLY THE STACK'S OWN BUCKET IS OVERRIDDEN, AND THAT QUALIFIER IS LOAD-BEARING.
         * The preview routes carry TWO instances of this middleware: the panel stack's, at
         * index 1, and a route-level one appended after `Authenticate` to meter per tenant.
         * Without this check the map rewrote BOTH — the late instance was invoked with
         * `studio-preview-tenant` and silently counted against `studio-preview` instead, so
         * the per-tenant half never metered and a test driving it saw four 200s.
         */
        if ($bucket !== self::STACK_BUCKET) {
            return $bucket;
        }

        $name = $request->route()?->getName();

        if ($name === null) {
            return $bucket;
        }

        /** @var array<string, string> $overrides */
        $overrides = (array) config('ratelimit.route_buckets', []);

        return is_string($overrides[$name] ?? null) ? $overrides[$name] : $bucket;
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
