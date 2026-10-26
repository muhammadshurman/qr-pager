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
     */
    public function __invoke(): JsonResponse
    {
        $snapshot = $this->store->read();
        $budget = (int) config('shedding.readiness.freshness');
        $now = time();

        if ($snapshot === null) {
            return $this->answer(null, 'no readiness snapshot is available', $now);
        }

        if (! $snapshot->isFresh($now, $budget)) {
            return $this->answer($snapshot, 'the readiness snapshot is stale', $now);
        }

        if (! $snapshot->postgresUp) {
            return $this->answer($snapshot, 'postgres is not reachable', $now);
        }

        if (! $snapshot->redisUp) {
            return $this->answer($snapshot, 'redis is not reachable', $now);
        }

        return $this->answer($snapshot, null, $now);
    }

    private function answer(?ReadinessSnapshot $snapshot, ?string $reason, int $now): JsonResponse
    {
        $body = [
            'ready' => $reason === null,
            'reason' => $reason,
            'probe' => $snapshot?->toArray(),
            'probe_age_seconds' => $snapshot?->ageInSeconds($now),
            'freshness_budget_seconds' => (int) config('shedding.readiness.freshness'),
        ];

        return new JsonResponse($body, $reason === null ? 200 : 503, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
