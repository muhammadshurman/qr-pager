<?php

declare(strict_types=1);

use App\Features\Pager\Http\PagerSessionController;
use App\Platform\Http\Middleware\RateLimit;
use Illuminate\Support\Facades\Route;

/*
 * The public API surface, served under /v1 by bootstrap/app.php's apiPrefix.
 *
 * Every route here is on the `api` middleware group -- ShedLoad, then RateLimit, then
 * RequireTenantScope, in that order and for the reasons bootstrap/app.php gives. That
 * makes the whole surface DENY-BY-DEFAULT: no tenant, no request, answered 401 with no
 * detail about why.
 *
 * STAFF ROUTES ONLY, which is the §4 › Tenancy split rather than an accident of ordering.
 * Staff authenticate as users -- the real resolver is Task 5's and until then these sit
 * behind Task 2's test-only stub. A customer has no account and authenticates ONLY by
 * presenting the QR token, which resolves to exactly one session in exactly one tenant;
 * that resolver is built in this task (App\Features\Pager\PagerTokenLookup) and the
 * endpoints that consume it -- polling, subscriptions -- are Task 4's. So there is
 * deliberately no customer-facing route in this file yet, and when Task 4 adds one it will
 * not be on RequireTenantScope, because the token is its authentication.
 *
 * The session id is a path parameter and the tenant is NOT. The tenant comes from the
 * container binding RequireTenantScope published, so no HTTP input can choose one; the id
 * is then looked up inside that tenant's scope, where another tenant's row is invisible
 * and the answer is 404.
 *
 * `whereUuid`, so a non-uuid id is a routing miss -- a 404 from the fallback -- rather
 * than a uuid cast error inside a query. It also keeps the unknown-path rate limiter in
 * front of garbage ids instead of the api bucket.
 *
 * The versioning policy for a breaking change is in PLAN.md §6. A published path is never
 * changed in place.
 */

Route::prefix('pager-sessions')->group(function (): void {
    /*
     * Creating a session is the expensive one -- a CSPRNG draw, an insert and an audit row
     * -- and it is the only route here that takes a body, so it gets its own tighter
     * bucket on top of the api group's. The four transition routes act on a session that
     * already exists and stay on the api bucket.
     */
    Route::post('/', [PagerSessionController::class, 'store'])
        ->middleware([RateLimit::class.':pager-create'])
        ->name('pager.sessions.store');

    Route::post('/{session}/ready', [PagerSessionController::class, 'ready'])
        ->whereUuid('session')
        ->name('pager.sessions.ready');

    Route::post('/{session}/renotify', [PagerSessionController::class, 'renotify'])
        ->whereUuid('session')
        ->name('pager.sessions.renotify');

    Route::post('/{session}/complete', [PagerSessionController::class, 'complete'])
        ->whereUuid('session')
        ->name('pager.sessions.complete');

    Route::post('/{session}/cancel', [PagerSessionController::class, 'cancel'])
        ->whereUuid('session')
        ->name('pager.sessions.cancel');
});
