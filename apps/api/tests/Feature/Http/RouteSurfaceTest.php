<?php

declare(strict_types=1);

use App\Platform\Readiness\ReadinessStore;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
 * "Every public route is under /v1" (PLAN.md §3, row 2), with the two probes as the
 * named exception approved on 2026-10-26 and recorded in §6 beside the versioning
 * policy.
 *
 * This replaces the skeleton's ExampleTest, which asserted that GET / returns 200.
 * That route was Laravel's welcome page: a public route outside /v1, so the gate and
 * the placeholder test contradicted each other. The route is gone and the clause is
 * what is tested instead.
 */

/** @return list<string> */
function publicRouteUris(): array
{
    $uris = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $uris[] = '/'.ltrim($route->uri(), '/');
    }

    $uris = array_values(array_unique($uris));
    sort($uris);

    return $uris;
}

it('serves exactly the two unversioned probes and nothing else outside /v1', function (): void {
    // The allowlist, in full. A new route outside /v1 fails here and has to be either
    // moved under /v1 or added to this list with a reason -- which is a diff a reviewer
    // sees, rather than a route table nobody reads.
    $allowed = ['/healthz', '/readyz'];

    $outside = array_values(array_filter(
        publicRouteUris(),
        static fn (string $uri): bool => ! str_starts_with($uri, '/v1') && ! in_array($uri, $allowed, true),
    ));

    // Asserted as a diff rather than per-route, so a failure names every offender at
    // once. (Pest's toContain takes further needles, not a message, so the per-route
    // form asserted that the allowlist contained the failure text -- which is how this
    // test first failed.)
    expect($outside)->toBe([], 'public routes outside /v1: '.implode(', ', $outside));
});

it('has removed Laravel /up, the welcome page and both storage routes', function (): void {
    // Each of these was a public route outside /v1 at 7f1c8f6. /up duplicated liveness
    // and ran the framework's health checks, which touch dependencies -- the opposite
    // of what §4 › Edge & ops asks of a health check. `storage/{path}` came from the
    // local disk's `serve => true` and included a PUT that writes request content to
    // storage/app/private behind a signed URL.
    expect(publicRouteUris())
        ->not->toContain('/up')
        ->not->toContain('/')
        ->not->toContain('/storage/{path}');
});

it('answers liveness without reading any dependency', function (): void {
    // Proven by taking both dependencies away from the request: if this endpoint
    // touched Postgres or Redis it could not answer with them unreachable, and §4 ›
    // Edge & ops is explicit that a liveness check which sheds with everything else
    // makes the balancer kill a task that is merely busy.
    config([
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => '59997',
        'database.redis.default.port' => '59998',
    ]);

    DB::purge('pgsql');
    Redis::purge('default');

    $response = $this->getJson('/healthz');

    $response->assertOk()->assertExactJson(['status' => 'alive']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers readiness 503 with no snapshot, and opens no database connection to do it', function (): void {
    app(ReadinessStore::class)->forget();

    // Pointed at a dead port: if readiness opened a connection this would be a 500,
    // which is R-012's coupling. It must still ANSWER.
    config(['database.connections.pgsql.port' => '59997']);
    DB::purge('pgsql');

    $this->getJson('/readyz')
        ->assertStatus(503)
        ->assertJsonPath('ready', false)
        ->assertJsonPath('reason', 'no readiness snapshot is available')
        ->assertJsonPath('probe', null);
});

it('answers readiness 503 when redis is unreachable, while liveness stays 200', function (): void {
    config(['database.redis.default.port' => '59998']);
    Redis::purge('default');

    $this->getJson('/readyz')->assertStatus(503)->assertJsonPath('ready', false);
    $this->getJson('/healthz')->assertOk();
});
