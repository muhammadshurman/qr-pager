<?php

declare(strict_types=1);

use App\Features\Devices\Http\DeviceController;
use App\Features\Members\Http\MemberController;
use App\Features\Pager\Http\PagerSessionController;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RequireAbility;
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
 * Staff authenticate as users -- the real resolver arrived at Task 5 and the test-only stub
 * that stood in for it is deleted (R-035). A customer has no account and authenticates ONLY by
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

/*
 * AUTHORISATION, ADDED AT TASK 5. ON A GROUP IN THIS FILE, AND IN TWO LAYERS -- and the
 * second layer exists because the first one alone does NOT cover what the first draft of
 * this comment claimed it did.
 *
 * RequireTenantScope answers "is there a tenant for this request". RequireAbility answers
 * "may this actor do THIS", and until Task 5 nothing did -- every route below had been
 * behind the deny-by-default tenant guard since it was written, which is not the same
 * question.
 *
 * It takes NO PARAMETER: the ability is looked up by route NAME in
 * App\Platform\Auth\RouteAbilities, and a route inside this group that is not in that map
 * is refused with 403 rather than served. A per-route `ability:...` parameter would make
 * "every staff route is covered" unobservable, because a route missing the parameter is
 * indistinguishable from one that was meant to be open.
 *
 * ⚠ LAYER ONE'S LIMIT, measured rather than reasoned about. A group wrapper only reaches
 * routes somebody remembered to nest in it, so a staff route added to this file OUTSIDE the
 * group gets no authorisation check at all. That is a real hole and it is closed by layer
 * two rather than by this group:
 *
 *   LAYER 1, runtime: RequireAbility fails CLOSED inside this group -- an unmapped route
 *            here is a 403, so forgetting the map entry is a loud refusal.
 *   LAYER 2, build:   RoleMatrixTest iterates the WHOLE router and fails if any /v1 route
 *            is in neither the staff map nor the named customer or auth sets. A route added
 *            outside this group is in no set, so the suite goes red.
 *
 * The obvious fix -- move RequireAbility onto the `api` middleware group so it reaches every
 * /v1 route -- WAS TRIED AND REVERTED, and the reason is worth recording: the group is the
 * whole of routes/v1.php, but it is also what nine test files mount their own ad-hoc `/v1`
 * probe routes on. Those probes are unnamed and unmappable by construction, so they all
 * became 403 and twenty-odd tests went red on infrastructure rather than on behaviour. The
 * alternative -- letting an UNNAMED route through -- is precisely the fail-open this is
 * written to prevent. So the runtime check keeps the narrower scope, the build check carries
 * the totality, and the limit is written down here instead of being claimed away.
 */
Route::middleware([RequireAbility::class])->group(function (): void {

    Route::prefix('pager-sessions')->group(function (): void {
        /*
         * The board. A kitchen display's only read, and the one ability a KDS device token may
         * carry -- which is why it exists here rather than at Task 10 with the screen that
         * consumes it.
         */
        Route::get('/', [PagerSessionController::class, 'index'])
            ->name('pager.sessions.index');

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

    /*
     * STAFF MANAGEMENT -- R-007's Task 5 half.
     *
     * `members:write` is held by owner and manager and by neither staff nor a device token, so
     * this is the surface where the role matrix stops being a formality: it is the only place
     * where staff and manager give different answers to the same request. The member id is a
     * uuid in the path and the tenant is not -- the membership is looked up inside the resolved
     * tenant's scope, where another tenant's row is invisible and the answer is 404.
     */
    Route::prefix('members')->group(function (): void {
        Route::get('/', [MemberController::class, 'index'])->name('members.index');

        Route::post('/', [MemberController::class, 'store'])->name('members.store');

        Route::patch('/{member}', [MemberController::class, 'update'])
            ->whereUuid('member')
            ->name('members.update');

        Route::delete('/{member}', [MemberController::class, 'destroy'])
            ->whereUuid('member')
            ->name('members.destroy');
    });

    /*
     * KDS DEVICE TOKENS. Issuing one is a credential-minting act, so it carries its own tighter
     * bucket on top of the api group's and is audited inside the tenant's scope. Revoking is
     * keyed and answers 404 for a token pinned to another tenant, which is §11.2's IDOR rule
     * applied to a credential rather than to a row.
     */
    Route::prefix('devices')->group(function (): void {
        Route::post('/', [DeviceController::class, 'store'])
            ->middleware([RateLimit::class.':devices-issue'])
            ->name('devices.store');

        Route::delete('/{device}', [DeviceController::class, 'destroy'])
            ->whereUuid('device')
            ->name('devices.destroy');
    });
});
