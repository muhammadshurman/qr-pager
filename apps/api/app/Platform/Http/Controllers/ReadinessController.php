<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Readiness\ReadinessSnapshot;
use App\Platform\Readiness\ReadinessStore;
use Illuminate\Http\JsonResponse;

/**
 * Readiness: should traffic come to this task?
 *
 * Reads the carrier's Redis key and NOTHING else. No Postgres connection on the
 * request path, which is both B6 and the only way the gate's "health and readiness
 * keep answering" clause can hold while the pool is saturated -- measured, at 40/40 a
 * new app connection is refused 08006, so an endpoint that opened one would fail under
 * exactly the condition it exists to report (R-012).
 *
 * Fail-closed in every unknown case: no key, an unreadable key, a stale key, or Redis
 * unreachable all answer 503. Redis being unreachable SHOULD make this not ready --
 * the gate says readiness reflects real Postgres and Redis state -- while /healthz
 * keeps answering 200, so the load balancer stops sending traffic here without killing
 * a task that is merely unable to see its dependencies.
 */
final class ReadinessController
{
    public function __construct(private readonly ReadinessStore $store) {}

    /**
     * Readiness probe.
     *
     * 200 when the last probe is fresh and both dependencies were up; 503 otherwise,
     * with `reason` naming which. Opens no database connection.
     *
     * ONE RETURN, with the body as a literal array, and that shape is for the generated
     * spec rather than for taste. Scramble infers a response schema from what a
     * controller actually returns: with the body built in a private helper the exported
     * document described this endpoint as `{"type":"string"}` -- a generated spec that is
     * wrong in the quietest possible way, since nothing fails and the file looks
     * plausible. §7's own caveat said as much ("a controller must return a declared
     * shape rather than one branch's literals"), and this is that caveat met.
     */
    public function __invoke(): JsonResponse
    {
        $snapshot = $this->store->read();
        $budget = (int) config('shedding.readiness.freshness');
        $now = time();
        $reason = $this->reasonNotReady($snapshot, $now, $budget);

        $body = [
            'ready' => $reason === null,
            'reason' => $reason,
            'probe' => $snapshot?->toArray(),
            'probe_age_seconds' => $snapshot?->ageInSeconds($now),
            'freshness_budget_seconds' => $budget,
        ];

        // Two returns with LITERAL status codes, not one with a conditional. Scramble
        // reads the status from the call, so a `$ready ? 200 : 503` expression gave it
        // nothing to read and it fell back to describing the whole body as a string --
        // measured. Written this way the document carries both outcomes, which is better
        // documentation than the version that was easier to write.
        if ($reason === null) {
            return new JsonResponse($body, 200, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse($body, 503, ['Cache-Control' => 'no-store']);
    }

    /** Which dependency, or null when traffic should come here. */
    private function reasonNotReady(?ReadinessSnapshot $snapshot, int $now, int $budget): ?string
    {
        if ($snapshot === null) {
            return 'no readiness snapshot is available';
        }

        if (! $snapshot->isFresh($now, $budget)) {
            return 'the readiness snapshot is stale';
        }

        if (! $snapshot->postgresUp) {
            return 'postgres is not reachable';
        }

        if (! $snapshot->redisUp) {
            return 'redis is not reachable';
        }

        return null;
    }
}
