<?php

declare(strict_types=1);

use App\Features\Pager\Http\CustomerPagerController;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\ShedLoad;
use App\Platform\Http\PacedRoute;
use Illuminate\Support\Facades\Route;

/*
 * THE CUSTOMER SURFACE. The only routes in this project with no account behind them.
 *
 * A SEPARATE FILE FROM routes/v1.php, AND THAT IS THE POINT. Everything in v1.php is on
 * the `api` middleware group, whose last entry is RequireTenantScope -- deny-by-default,
 * 401 with no detail when no tenant resolves. These routes must NOT be on it: a customer
 * has no account and no tenant to resolve, and `§4 › Tenancy` is explicit that the token
 * IS their authentication. Two authentication surfaces, never confused -- so they are two
 * files on two groups, and a route cannot drift onto the wrong one by being added in the
 * wrong place.
 *
 * Mounted under the same /v1 prefix by bootstrap/app.php, because the versioning policy
 * is about the PUBLIC PATH and not about which file a route lives in: every public route
 * is under /v1 and a published path is never changed in place (PLAN.md §3 row 2, §6).
 *
 * NO ROUTE HERE TAKES AN ID, which is this surface's whole IDOR answer. The session is
 * whatever the token resolves to, so the parameter an attacker would tamper with does not
 * exist. There is no tenant field in any of these bodies either.
 *
 * ALL FOUR ARE POST, including the one that only reads. `PLAN.md §3` row 3 as amended
 * requires the raw token to reach no URL path or query string and nowhere a server logs
 * it, and a request body is the only place on an HTTP request that is in neither an
 * access log, a Referer, a proxy's URL trace, nor a CDN cache key. The polling endpoint
 * pays an unusual verb to keep the highest-frequency carrier of that secret off all four.
 *
 * ---------------------------------------------------------------------------------
 * THE SHEDDING ORDER IS THE ASYMMETRY TO NOTICE
 * ---------------------------------------------------------------------------------
 *
 * `§4 › Edge & ops`: "Load shedding drops nice-to-haves (live timer, analytics) before
 * alerts." So ShedLoad is on the POLLING route and on nothing else here.
 *
 *   status      -> ShedLoad.  It is the live timer: the nice-to-have, by name.
 *   session     -> no ShedLoad. It is how a customer reaches the alert path at all.
 *   subscription-> no ShedLoad. Shedding it means the customer gets NO push, which is
 *                  dropping the alert first and the nice-to-have never -- the stated
 *                  order exactly inverted.
 *
 * Under pressure a waiting customer therefore loses the live countdown and keeps the
 * buzz, which is the trade the invariant asks for. A test pins it, because an asymmetry
 * nobody asserts is a line somebody tidies.
 */

Route::prefix('pager')->group(function (): void {
    /*
     * The scan. The one customer route that reaches Postgres, so it carries the tightest
     * bucket in the project (R-063) and no load shedding.
     */
    Route::post('session', [CustomerPagerController::class, 'resolve'])
        ->middleware([RateLimit::class.':pager-token'])
        ->name('pager.customer.session');

    /*
     * The poll. Redis-only, zero Postgres, and the only route here that sheds.
     */
    Route::post('status', [CustomerPagerController::class, 'poll'])
        ->middleware([ShedLoad::class, RateLimit::class.':pager-poll'])
        // IT DECLARES ITS OWN PACING, and the two middlewares in front of it read the
        // declaration rather than the route's name (QA M-3/M-4, 2026-10-27). §3 row 4
        // says EVERY polling response carries `poll_after_seconds`, and three of this
        // route's five response shapes are produced before the handler runs: ShedLoad's
        // 503, RateLimit's 429, and validation's 422. The first two are "come back
        // later" answers and now carry the interval; the 422 deliberately does not,
        // because a malformed request is not a request to retry.
        ->defaults(PacedRoute::KEY, true)
        ->name('pager.customer.status');

    /*
     * The device. POST registers or updates, DELETE removes -- one path, two verbs,
     * because they are two operations on one resource rather than two resources.
     */
    Route::post('subscription', [CustomerPagerController::class, 'subscribe'])
        ->middleware([RateLimit::class.':pager-subscribe'])
        ->name('pager.customer.subscribe');

    Route::delete('subscription', [CustomerPagerController::class, 'unsubscribe'])
        ->middleware([RateLimit::class.':pager-subscribe'])
        ->name('pager.customer.unsubscribe');
});
