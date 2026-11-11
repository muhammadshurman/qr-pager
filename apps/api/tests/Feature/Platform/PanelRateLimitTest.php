<?php

declare(strict_types=1);

use App\Platform\Http\Middleware\RateLimit;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
 * SECURITY H-2's REGRESSION TEST (§11 review, 2026-10-28). Red without the fix.
 *
 * Every other surface in this application carried a bucket -- `/v1`, the auth group, even
 * the unknown-path fallback with `unknown`. The panel's four routes and Livewire's update
 * endpoint carried NONE, and `StartSession` persists a session to carry a CSRF token BEFORE
 * `Authenticate` refuses: so each anonymous 401 minted one Redis key with a 7200 s TTL, on
 * a path needing no credential. Redis is the locked session, cache AND queue store (§1), so
 * that loop fills the store the customer pager path, the outbox and `markReady` depend on.
 */

/** @return list<string> */
function panelUris(): array
{
    return ['/platform', '/platform/restaurants', '/platform/audit-log'];
}

it('meters every panel route on the panel bucket', function (string $uri): void {
    // Read from the ROUTER, not from the provider's source, so the assertion is about what
    // is actually mounted. The gathered middleware is what a request really runs.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => '/'.ltrim($r->uri(), '/') === $uri);

    expect($route)->not->toBeNull("no route is mounted at {$uri}")
        ->and($route->gatherMiddleware())->toContain(RateLimit::class.':panel');
})->with(panelUris());

it('meters POST /platform/logout too, so the one state-changing panel route is not the gap', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'platform/logout');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(RateLimit::class.':panel');
});

it('meters Livewire’s update endpoint, which carries most of the panel’s traffic', function (): void {
    // Filament's tables, modals and forms all talk to the server through this one route,
    // which Livewire registers itself with the `web` group and nothing else. It was the
    // busiest panel route and the only one with no bucket.
    //
    // DRIVEN, NOT READ OFF THE ROUTE OBJECT. `Route::gatherMiddleware()` returns the
    // route's own list WITHOUT expanding a group name, so it reports `web` and never the
    // members appended to it — the first version of this case asserted against that and
    // failed while the limiter was correctly wired. Behaviour is the claim anyway: the
    // endpoint must refuse past the cap.
    $update = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => str_contains($r->uri(), 'livewire-') && str_ends_with($r->uri(), '/update'));

    expect($update)->not->toBeNull('no Livewire update route is mounted: the panel cannot work');

    $uri = '/'.ltrim($update->uri(), '/');
    $limit = (int) config('ratelimit.buckets.panel.per_ip.attempts');

    $first = $this->post($uri);

    // Before the cap: Livewire's own refusal (419 for a missing CSRF token, or 400 for
    // missing Livewire headers). Not a 429, which is the point of the comparison.
    expect($first->getStatusCode())->not->toBe(429);

    for ($i = 0; $i < $limit; $i++) {
        $this->post($uri);
    }

    expect($this->post($uri)->getStatusCode())->toBe(429, 'the Livewire update endpoint is unmetered');
});

it('keeps the web group to Livewire’s routes alone, which is why appending to it is safe', function (): void {
    // THE PREMISE OF THE FIX, pinned. The limiter reaches Livewire's endpoint by being
    // appended to the `web` group; that is only a narrow change while `web` has no other
    // members. Measured at the time of the fix: three routes, all Livewire's. If a future
    // route joins the group, that becomes a deliberate act rather than a surprise.
    $inWeb = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => in_array('web', $r->middleware(), true))
        ->map(fn ($r): string => $r->uri())
        ->values()
        ->all();

    expect($inWeb)->not->toBe([], 'nothing is in the web group: this test has lost its subject');

    foreach ($inWeb as $uri) {
        expect(str_starts_with($uri, 'livewire-'))
            ->toBeTrue("a non-Livewire route [{$uri}] joined the web group, which the panel bucket now also meters");
    }
});

it('refuses an anonymous flood on a panel route, rather than serving it unmetered', function (): void {
    // The bucket driven to refusal, which is the shape R-063 established for the token
    // endpoint. 240/min, so the loop is bounded by the config value rather than by a
    // number written here.
    $limit = (int) config('ratelimit.buckets.panel.per_ip.attempts');

    expect($limit)->toBeGreaterThan(0);

    $statuses = [];

    for ($i = 0; $i < $limit + 2; $i++) {
        $statuses[] = $this->get('/platform')->getStatusCode();
    }

    // Before the cap: the ordinary refusal, which is 401 and not 429.
    expect($statuses[0])->toBe(401)
        // After it: 429, which is the finding closed.
        ->and($statuses[$limit + 1])->toBe(429)
        // EXACTLY two, not "at least one": the loop makes `$limit + 2` requests, the first
        // `$limit` are allowed and the last two are not, so the count is knowable and a
        // limiter that refused early or late passes ">= 1" while failing this.
        ->and(array_count_values($statuses)[429] ?? 0)->toBe(2);
});

it('answers the 429 with a problem document and a Retry-After header', function (): void {
    $limit = (int) config('ratelimit.buckets.panel.per_ip.attempts');

    for ($i = 0; $i < $limit; $i++) {
        $this->get('/platform');
    }

    $response = $this->get('/platform');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        // §3 row 4's clause: every mapped 429/503 carries Retry-After.
        ->and($response->headers->get('Retry-After'))->not->toBeNull();
});

it('stops the unmetered Redis key growth the finding was actually about', function (): void {
    // THE MEASUREMENT, not a proxy for it. The cost of the old behaviour was one persisted
    // session key per anonymous request; what the limiter buys is that the growth STOPS at
    // the cap instead of continuing for as long as the attacker keeps going.
    $limit = (int) config('ratelimit.buckets.panel.per_ip.attempts');

    // Past the cap first, so every further request is refused by the limiter.
    for ($i = 0; $i < $limit + 1; $i++) {
        $this->get('/platform');
    }

    $before = (int) Redis::connection()->dbsize();

    for ($i = 0; $i < 40; $i++) {
        expect($this->get('/platform')->getStatusCode())->toBe(429);
    }

    $after = (int) Redis::connection()->dbsize();

    // A refused request mints no session. Allowing a couple of keys for the limiter's own
    // counters rather than asserting exactly zero, because the limiter is itself in Redis.
    expect($after - $before)->toBeLessThanOrEqual(2,
        '40 refused requests created '.($after - $before).' Redis keys');
});

it('issues no Postgres statement on an anonymous panel refusal', function (): void {
    // The other half of the cost: the refusal must not reach the database either. Measured
    // at zero before the fix too, and asserted here so the limiter cannot introduce one.
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->get('/platform');

    expect($statements)->toBe([], 'an anonymous panel refusal reached Postgres: '.implode(' | ', $statements));
});

/*
 * ================================================================================
 * EVERY METERED ROUTE, NOT TWO NAMED ONES — `§11` security round of 2026-11-11, H-1
 * ================================================================================
 *
 * `R-027` (Task 6's security H-2) says the limiter must run BEFORE `StartSession`, because
 * `StartSession` persists a session to carry a CSRF token before `Authenticate` refuses:
 * each anonymous 401 would otherwise mint a 7200s Redis key in the instance that is also
 * the cache and queue store.
 *
 * ⚠ IT RECURRED ON 2026-11-11 BECAUSE ITS GUARD WAS ROUTE-SPECIFIC. `QRPAGER-45` gave the
 * two `/studio/preview` routes their own bucket with `withoutMiddleware()` plus
 * `->middleware()`, and route middleware is APPENDED and then sorted — this limiter is not
 * in Laravel's priority list — so it ran LAST, behind `Authenticate`. Anonymous traffic was
 * unmetered AND still minting session keys. The cases in this file enumerate `/platform`
 * URIs, so none of them could see it.
 *
 * Muhammad's ruling of 2026-11-11: *"Replace it with a meta-test over the route list. §4
 * already sets that precedent — a meta-test fails the build if any table lacks RLS. Two
 * per-route cases are the thing that just failed."*
 *
 * So this walks EVERY registered route and holds the ordering invariant on all of them. A
 * route added next year, by anybody, is covered on the day it is registered.
 */
it('runs the limiter before the session and the guard on every metered route', function (): void {
    $router = app('router');
    $offenders = [];
    $metered = 0;

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $middleware = array_values(array_map(
            static fn (string $m): string => explode(':', $m)[0],
            $router->gatherRouteMiddleware($route)
        ));

        /*
         * ⚠ PANEL ROUTES, AND THE SCOPE IS A DECISION RATHER THAN A CONVENIENCE. The
         * invariant belongs to stacks that DECLARE the limiter first and then refuse an
         * anonymous caller — which is every Filament panel route, identified by
         * `SetUpPanel`. It is NOT true of the `web` group, where `RateLimit:panel` is
         * deliberately APPENDED (`bootstrap/app.php`) so the per-tenant half can see a
         * resolved user on `POST /livewire-<hash>/update`; nor of the `v1` surface, which
         * authenticates by token rather than by session.
         *
         * Asserting it everywhere would have flagged fourteen pre-existing routes that are
         * arranged that way on purpose — measured when this case was first written — and a
         * meta-test that fails on correct code is `R-290`'s shape, which this repo filed
         * the same night.
         */
        if (! in_array(SetUpPanel::class, $middleware, true)) {
            continue;
        }

        $limiter = array_search(RateLimit::class, $middleware, true);

        if ($limiter === false) {
            $offenders[] = ($route->uri() ?: '/').' is a panel route with no rate limiter at all';

            continue;
        }

        $metered++;

        foreach ([StartSession::class, 'Filament\Http\Middleware\Authenticate', Authenticate::class] as $after) {
            $at = array_search($after, $middleware, true);

            if ($at !== false && $at < $limiter) {
                $offenders[] = ($route->uri() ?: '/').' runs '.class_basename($after).' before the limiter';
            }
        }
    }

    // A bound first, so a walk that found nothing cannot pass by vacancy — the shape this
    // file's own derivation cases already use.
    expect($metered)->toBeGreaterThan(5, 'almost no panel route was walked, so this proves nothing');

    expect($offenders, 'these routes refuse or start a session before the rate limiter runs, '
        .'so an anonymous flood on them is unmetered and mints a Redis session key each time')->toBe([]);
});
