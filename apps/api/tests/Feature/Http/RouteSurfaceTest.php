<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Readiness\ReadinessStore;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
    //
    // The fallback is on it because it is not a surface: it matches paths that do not
    // exist, and the gate names it -- "the rate limiter covers unknown routes (404s)".
    // Without it every unmatched path would get Laravel's HTML 404 instead of a
    // problem+json document, and nothing would count a scan.
    //
    // `/passkey-harness` joined it at Task 5, and it is the one entry that is NOT a
    // surface and not a 404 catcher. PLAN.md §3 row 5 asks for "a minimal unstyled harness
    // page ... purely to exercise the WebAuthn browser API", because the credential
    // ceremony lives in navigator.credentials and cannot be driven from HTTP. It is a test
    // fixture that happens to need a URL.
    //
    // It is NOT under /v1 deliberately: putting it there would publish it into the
    // versioned public contract, which is worse than an unversioned fixture. And it is not
    // served in production at all -- the case below drives that, which is what makes this
    // allowlist entry acceptable rather than a hole.
    $allowed = ['/healthz', '/readyz', '/passkey-harness', '/{fallbackPlaceholder}'];

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

it('loads the passkey harness page only outside production', function (): void {
    // The other half of the allowlist entry above: a test fixture with a URL must be
    // unreachable where it matters.
    //
    // Read from bootstrap/app.php, the same way the drain route's condition is read two
    // cases down, and for the same reason: the environment is global state that a running
    // test cannot change for a route table that was built at boot. Re-booting a second
    // application inside the suite to prove one `if` is a worse test than reading the `if`,
    // and the first version of this case did something worse still -- it asserted the
    // PREDICATE (`! app()->environment('production')` is false under production), which is
    // a tautology about PHP rather than a fact about this application.
    //
    // The route IS present here, which is the positive control: the suite runs as `testing`,
    // so a condition that excluded everything would fail the line below rather than pass
    // quietly.
    expect(publicRouteUris())->toContain('/passkey-harness');

    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->toContain("if (! app()->environment('production')) {\n                require __DIR__.'/../routes/harness.php';");
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
        // `probe` is gone entirely rather than null (R-031): the capacity figures were
        // readable by anyone, on a group with no limiter.
        ->assertJsonMissingPath('probe')
        ->assertJsonPath('probe_age_seconds', null);
});

it('answers readiness 503 when redis is unreachable, while liveness stays 200', function (): void {
    config(['database.redis.default.port' => '59998']);
    Redis::purge('default');

    $this->getJson('/readyz')->assertStatus(503)->assertJsonPath('ready', false);
    $this->getJson('/healthz')->assertOk();
});

it('answers an unknown path with a problem document and counts it', function (): void {
    $response = $this->getJson('/no-such-path-'.Str::uuid7());

    $response->assertStatus(404)->assertJsonPath('type', ProblemType::NotFound->value);

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        // No tenant middleware on the fallback, deliberately: an unknown path has no
        // tenant to resolve, and answering 401 would tell a scanner which paths exist.
        ->and($response->status())->not->toBe(401);
});

it('rate limits unknown paths on their own tighter bucket — the gate names this clause', function (): void {
    $limit = (int) config('ratelimit.buckets.unknown.per_ip.attempts');

    expect($limit)->toBeGreaterThan(0)
        // Tighter than the API's own bucket: a 404 costs almost nothing to serve and
        // everything to scan with.
        ->and($limit)->toBeLessThan((int) config('ratelimit.buckets.api.per_ip.attempts'))
        // And no per-tenant limit, because an unmatched path carries no tenant.
        ->and(config('ratelimit.buckets.unknown.per_tenant'))->toBeNull();

    $fallback = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RoutingRoute $route): bool => $route->isFallback);

    expect($fallback)->not->toBeNull()
        ->and($fallback?->gatherMiddleware())->toContain(RateLimit::class.':unknown');
});

it('does not carry the drain proof’s slow route in the repository', function (): void {
    // bootstrap/app.php loads routes/drain.php when it exists and the environment is not
    // production. That is the condition the drain proof relies on, and this is the other
    // half of the bargain: the file is mounted into the drain container by compose and
    // must never be committed, or /drain/slow becomes a real public route that holds a
    // worker thread for three seconds.
    expect(is_file(base_path('routes/drain.php')))->toBeFalse(
        'routes/drain.php is committed; it is a mount, not a route file',
    );

    // The source it is mounted FROM does exist, and lives outside routes/ so no loader
    // can pick it up by accident.
    expect(is_file(base_path('docker/drain/routes.php')))->toBeTrue();

    // And it is not in the served surface, which is what actually matters.
    expect(publicRouteUris())->not->toContain('/drain/slow');
});

it('loads the drain route only outside production, when it is mounted', function (): void {
    // Read from bootstrap/app.php rather than exercised, because exercising it would
    // mean writing routes/drain.php into the repository -- which the case above forbids.
    // Both conditions in one expression, so neither can be dropped without the other
    // being noticed.
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->toContain("! app()->environment('production') && is_file(__DIR__.'/../routes/drain.php')");
});
