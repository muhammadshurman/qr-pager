<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use App\Platform\Readiness\PressureGauge;
use App\Platform\Readiness\ReadinessStore;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * "A saturated DB pool returns a fast 503, and memory/CPU pressure returns 503 -- while
 * health and readiness keep answering" (PLAN.md §3, row 2).
 *
 * FAST means no database. The pool figure comes from the readiness key a scheduled
 * command wrote (§6.3), so the refusal costs one Redis read and zero Postgres round
 * trips -- which is the only way this can work at all: at 40/40 a new app connection is
 * refused 08006, so a middleware that measured the pool by connecting to it would fail
 * under exactly the condition it exists to detect. That is R-012 restated one layer up.
 *
 * IT IS ON THE /v1 GROUP AND NOT ON THE PROBES, which is the second half of the clause.
 * The probe group is empty on purpose: a health check that sheds with everything else
 * makes the load balancer kill a task that is merely busy, and the pressure moves to its
 * neighbours. That is a cascade, not shedding.
 *
 * AND IT FAILS OPEN WHERE READINESS FAILS CLOSED. The asymmetry is deliberate and it is
 * the thing to understand about this file. Readiness answers "should traffic come here",
 * so not knowing must mean no. Shedding answers "should I refuse this request", so not
 * knowing must mean carry on -- otherwise losing Redis takes the whole API down, and the
 * gate's own clause is "Redis is taken down and the API keeps serving". Same key, two
 * questions, two postures.
 */
final class ShedLoad
{
    public function __construct(
        private readonly ReadinessStore $store,
        private readonly PressureGauge $gauge,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reason = $this->reasonToShed(time());

        if ($reason === null) {
            return $next($request);
        }

        // The reason goes to the log and not to the body: "saturation 0.95" tells a
        // caller how close this task is to the edge, which is a capacity measurement of
        // somebody else's infrastructure. The envelope is the fixed Unavailable title.
        $this->log->warning('shedding load', ['reason' => $reason]);

        return (new Problem(
            ProblemType::Unavailable,
            $request->getPathInfo(),
            retryAfter: max(1, (int) config('shedding.readiness.cadence')),
        ))->toResponse();
    }

    /** Null when this request should be served. */
    private function reasonToShed(int $now): ?string
    {
        $snapshot = $this->store->read();
        $threshold = (float) config('shedding.pool_saturation');

        // A missing or stale snapshot does NOT shed -- see the class docblock. Readiness
        // has already reported not-ready for the same reading, so the load balancer is
        // already being told to stop sending traffic; refusing the requests already in
        // flight as well would turn a Redis outage into an outage.
        if ($snapshot !== null
            && $snapshot->isFresh($now, (int) config('shedding.readiness.freshness'))
            && $threshold > 0
            && $snapshot->saturation() >= $threshold) {
            return 'pool saturation '.$snapshot->saturation().' >= '.$threshold;
        }

        $memory = $this->gauge->memoryFraction();
        $memoryThreshold = (float) config('shedding.memory.fraction');

        if ($memory !== null && $memoryThreshold > 0 && $memory >= $memoryThreshold) {
            return 'memory fraction '.$memory.' >= '.$memoryThreshold;
        }

        if ($this->gauge->cpuIsThrottled($now)) {
            return 'cpu throttled past the configured budget';
        }

        return null;
    }
}
