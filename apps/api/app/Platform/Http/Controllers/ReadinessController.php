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
     *
     * THREE FIELDS, AND THE CAPACITY FIGURES ARE GONE (R-031, QA M-2 / security #3).
     * This used to publish the whole snapshot -- `app_backends`, `app_limit`,
     * `saturation`, `postgres_up`, `redis_up`, `measured_at` -- to any anonymous caller,
     * on the one middleware group that carries no rate limiter, while ShedLoad withheld
     * the SAME saturation figure from its 503 on the stated grounds that it tells a
     * caller how close this task is to the edge. `ShedLoadTest` asserts that absence;
     * two reviews found the contradiction independently. The shed threshold is
     * `0.9 x app_limit`, so publishing both numbers let a caller calibrate to the exact
     * onset of shedding and watch it work.
     *
     * Nothing operational is lost: ShedLoad logs its reason at warning, the carrier
     * prints the snapshot it wrote, and `reason` still names WHICH dependency failed,
     * which is what makes a 503 diagnosable. What is gone is diagnosing readiness from
     * outside the VPC, which belongs on an operator path rather than a world-readable
     * one. `probe_age_seconds` stays because it discloses only carrier liveness, and it
     * is the field that separates "this task has no snapshot" from "its carrier died".
     */
    public function __invoke(): JsonResponse
    {
        /*
         * WHAT THE TRIM DID NOT REMOVE, corrected 2026-10-27 (R-050). The docblock
         * above also said `redis_up` "published the signal R-027 says means rate
         * limiting is currently off", and offered its removal as closing that. It does
         * not: `reason` is `redis is not reachable`, on this same unlimited endpoint,
         * and the generated spec publishes that string in an enum. The docblock's own
         * next paragraph is the reason it stays -- naming the dependency is what makes
         * a 503 actionable -- so the disclosure is deliberate and only the claim about
         * it was wrong. What the trim removed is the CAPACITY figures.
         *
         * IN THE BODY AND NOT THE DOCBLOCK, deliberately, and this is R-052: Scramble
         * publishes a controller's whole docblock as the endpoint's `description` in
         * docs/openapi.json, so a paragraph written here for a maintainer becomes part
         * of a shipped artefact. Adding `§5` row ids and the words "unlimited endpoint"
         * to a published document would have been R-031's own mistake in the correction
         * to R-031. The pre-existing half -- the committed spec already carries the shed
         * threshold `0.9 x app_limit` in this endpoint's description -- is R-052, at
         * Task 12.
         */

        $snapshot = $this->store->read();
        $budget = (int) config('shedding.readiness.freshness');
        $now = time();
        $reason = $this->reasonNotReady($snapshot, $now, $budget);

        $body = [
            'ready' => $reason === null,
            'reason' => $reason,
            'probe_age_seconds' => $snapshot?->ageInSeconds($now),
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
