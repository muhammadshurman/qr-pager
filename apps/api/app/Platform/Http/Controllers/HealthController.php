<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Liveness. Reads nothing, depends on nothing, and is never shed.
 *
 * `§4 › Edge & ops`: "health and readiness endpoints keep answering ... A health check
 * that sheds with everything else makes the load balancer kill a task that is merely
 * busy, and the pressure moves to its neighbours. That is a cascade, not shedding."
 * The way to honour that is for this endpoint to have nothing it can be blocked on --
 * no Postgres, no Redis, no cache, no tenant context. If the PHP worker can run this
 * closure, the task is alive.
 *
 * Readiness is the endpoint that answers "should traffic come here", and it is
 * deliberately a different endpoint with a different answer.
 */
final class HealthController
{
    /**
     * Liveness probe.
     *
     * Answers 200 while this worker can execute PHP. It reads no dependency, so it
     * cannot fail because Postgres or Redis is unavailable, and it is never shed.
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'alive'], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
