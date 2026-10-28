<?php

use App\Domain\Http\ProblemType;
use App\Features\Notifications\Console\WorkOutboxCommand;
use App\Features\Pager\Console\ExpirePagerSessionsCommand;
use App\Platform\Console\BootstrapDatabaseCommand;
use App\Platform\Console\CheckBootCommand;
use App\Platform\Console\RefreshReadinessCommand;
use App\Platform\Http\Middleware\ArmTenantScopeGuard;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RefuseDeviceToken;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Middleware\ResolvePrincipal;
use App\Platform\Http\Middleware\ShedLoad;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemRenderer;
use App\Platform\Logging\ReportRedactedException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/v1.php',
        commands: __DIR__.'/../routes/console.php',
        // Every public API route is under /v1, and a published path is never changed
        // in place (PLAN.md §3 row 2, policy in §6).
        apiPrefix: 'v1',
        then: function (): void {
            // Unversioned on purpose, and the only routes outside /v1: a load
            // balancer's health-check path must not change because the API's major
            // version did. Approved 2026-10-26; the reason is in §6 beside the policy.
            require __DIR__.'/../routes/probes.php';

            // THE CUSTOMER SURFACE (Task 4). Under the same /v1 prefix as routes/v1.php,
            // because the versioning policy is about the public path; on a DIFFERENT
            // middleware group, because `api`'s last entry is RequireTenantScope and a
            // customer has no account and no tenant to resolve. §4 › Tenancy: two
            // authentication surfaces, never confused -- the token is the customer's
            // authentication, and these routes are the ones that consume Task 3's
            // resolver.
            //
            // Registered here rather than in the `api:` slot above because withRouting's
            // api file gets the `api` group unconditionally; there is no way to mount a
            // second file under the same prefix with a different group except from this
            // closure. Before the fallback below, which matches anything.
            Route::prefix('v1')
                ->middleware('customer')
                ->group(__DIR__.'/../routes/customer.php');

            // THE AUTH SURFACE (Task 5). Same /v1 prefix, own group, and the reason it
            // cannot be on `api` is circularity: `api`'s last entry is RequireTenantScope,
            // and the tenant is derived from the credential a login is about to establish.
            // Requiring a resolved tenant in order to authenticate is a loop with no entry
            // point. routes/auth.php carries the rest of the reasoning.
            Route::prefix('v1')
                ->middleware('auth-surface')
                ->group(__DIR__.'/../routes/auth.php');

            // The SIGTERM drain proof's slow route, and the only conditional route
            // loading in this file. Two conditions, both visible here: the file has to
            // exist, and the environment must not be production. It is mounted into the
            // drain container by compose and is never committed -- RouteSurfaceTest
            // asserts routes/drain.php is absent from the repository, so it cannot
            // arrive by being added to the tree.
            //
            // Stated plainly because it is a real trade: this is production code reading
            // the filesystem for an optional route file. The alternative was a sleep in a
            // middleware behind a header, which is a production code path a request can
            // trigger, and that is strictly worse. The gate asks for the drain to be
            // proven against the compiled app, so the proof needs a request that
            // outlives the signal.
            if (! app()->environment('production') && is_file(__DIR__.'/../routes/drain.php')) {
                require __DIR__.'/../routes/drain.php';
            }

            // THE WEBAUTHN HARNESS PAGE (Task 5), and the second conditional load. Same
            // environment condition and for a related reason: PLAN.md §3 row 5 asks for a
            // minimal unstyled page to exercise navigator.credentials, because the
            // credential ceremony cannot be driven from HTTP -- and a test fixture with a
            // URL has no business being reachable in production.
            //
            // No is_file() check, unlike the drain route: this file IS committed, because
            // the gate names the page as a deliverable rather than as something mounted by
            // compose. So the condition is the environment alone.
            if (! app()->environment('production')) {
                require __DIR__.'/../routes/harness.php';
            }

            // "The rate limiter covers unknown routes (404s)" -- PLAN.md §3, row 2,
            // which names this clause explicitly. A 404 costs almost nothing to serve
            // and everything to scan with, so an unmatched path gets its own tighter
            // bucket and a problem+json document rather than Laravel's HTML page.
            //
            // any() AND NOT fallback(), which is the whole of security finding #1.
            // Router::fallback() registers the route for GET alone -- addRoute('GET',
            // ...) -- and RouteCollection::match() looks only in the bucket for the
            // request's own method. So a POST to an unmatched path found no route at
            // all, fell through to checkForAlternateVerbs, and the ROUTER answered 405
            // before any route middleware could run. Measured at cb584c3: GET /nope
            // recorded 3 attempts against the unknown bucket while POST, PUT, DELETE
            // and OPTIONS each recorded 0, and OPTIONS /nope was answered 200 text/html
            // with an Allow header by the router's own short-circuit. The gate clause
            // held for GET and for nothing else, and every other verb was an unmetered,
            // unshed request into this application.
            //
            // any() rather than a hand-written verb list, because the list is
            // Router::$verbs and in Laravel 13 that is eight entries including QUERY --
            // a list written out here would be a copy that goes stale the next time the
            // framework adds a method, which is the shape the guard's own duplicated
            // path list just cost us (QA H-1).
            //
            // THE TRADE, stated because it changes observable behaviour: a wrong verb on
            // a real route is now 404, not 405, because the fallback sits in that verb's
            // bucket and matches. For an API that is the better answer -- a 405 tells a
            // scanner the path exists under some other verb -- but it means
            // ProblemType::MethodNotAllowed is no longer reachable through routing. The
            // case stays: ProblemRenderer still maps a MethodNotAllowedHttpException,
            // which a package or a controller can raise.
            //
            // Registered LAST, after the probes and after /v1, because a fallback
            // matches anything: declared earlier it would swallow them. It carries no
            // tenant middleware, because an unknown path has no tenant to resolve and
            // refusing it as unauthenticated would tell a scanner which paths exist.
            //
            // ->any() then ->where() then ->fallback() then ->middleware(), in that
            // order: RouteRegistrar has no fallback() method, so putting middleware
            // first fails at boot with "Method Illuminate\Routing\RouteRegistrar::
            // fallback does not exist".
            Route::any(
                '{fallbackPlaceholder}',
                fn (Request $request) => (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse(),
            )->where('fallbackPlaceholder', '.*')
                ->fallback()
                ->middleware([RateLimit::class.':unknown']);
        },
    )
    // Laravel's health: '/up' was removed here. It was the only other public route, it
    // duplicated liveness, and the framework's HealthCheck controller runs the
    // configured health checks -- which touch dependencies. §4 › Edge & ops wants the
    // opposite: a liveness endpoint with nothing it can be blocked on.
    ->withCommands([
        BootstrapDatabaseCommand::class,
        CheckBootCommand::class,
        RefreshReadinessCommand::class,
        // Task 3's expiry job. Registered rather than auto-discovered because it lives in
        // app/Features, which withCommands' directory scan does not cover -- and because
        // the list being explicit is what makes `php artisan` the record of what exists.
        // IT IS NOW SCHEDULED, in routes/console.php, and this comment said the opposite
        // until 2026-10-27 (security M-2). What is still TASK 13's, with R-047 and R-058, is
        // a PROCESS that runs `schedule:run`: the schedule is a declaration, and nothing in
        // any deployed environment reads it yet.
        //
        // The ownership note this comment carried is kept, because it is why the row reads
        // the way it does: the comment said "Task 13/14's" while §5's R-058 owner cell said
        // "Task 13 (infrastructure), with R-047 -- the same gap, the same owner", and two
        // artefacts naming different owners is a false record rather than a risk (QA L-4).
        //
        // The reasoning that kept it unscheduled -- "one scheduled against infrastructure
        // that does not exist yet is R-047's shape" -- was answered rather than overruled. A
        // schedule is a DECLARATION: nothing runs until a process runs `schedule:run`, so
        // there is no behaviour to go wrong before Task 13, and the gap stops being invisible
        // because `php artisan schedule:list` now shows it. What `withoutOverlapping()` adds
        // is for when that process exists: a mutex, default expiry 1440 minutes, released on
        // a termination signal (measured in the framework's own signature, not recalled), so
        // a pass longer than a minute cannot be overlapped by the next tick.
        ExpirePagerSessionsCommand::class,
        // Task 4's outbox worker. Registered for the same reason and supervised by
        // nothing for the same reason: a long-running worker is an orchestrator's
        // process, not a cron entry, and the ECS service definition is Task 13's
        // (R-077). `--once` is what the proofs and a scheduler drive.
        WorkOutboxCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // GLOBAL, and prepended: the first invariant in §3.1 row 2 is "a request
        // without tenant context never reaches the DB", and a route group is something
        // a later route can be added without. This runs before routing, so an
        // unmatched path is armed too -- a 404 that touches the database on its way out
        // is still a request. Arming is all it does; the refusal is
        // TenantScopeGuard's, before the statement reaches Postgres.
        $middleware->prepend(ArmTenantScopeGuard::class);

        // The probes' own group. Empty, and that is the mechanism behind "while health
        // and readiness keep answering": they carry no rate limiter, no tenant
        // middleware and no load shedding, so there is nothing that can shed them.
        // The global arming above still applies, and there is nothing for it to refuse:
        // /healthz reads nothing and /readyz reads one Redis key.
        $middleware->group('probe', []);

        // The customer group, and it is EMPTY FOR A REASON rather than unfinished.
        //
        // It cannot carry RequireTenantScope: a customer has no account, so no tenant
        // resolves before the token does, and the whole surface would answer 401.
        //
        // It does not carry RateLimit either, because the three routes need three
        // DIFFERENT buckets -- the Postgres-backed scan is 30/min while the Redis-only
        // poll is 240 -- so each route names its own in routes/customer.php and a group
        // default would silently add a fourth count to every request.
        //
        // And it does not carry ShedLoad, because §4 › Edge & ops requires nice-to-haves
        // to be dropped BEFORE alerts: only the polling route sheds, and putting it here
        // would shed the subscription route too, which is the order exactly inverted.
        //
        // The group exists so that the customer surface is a NAMED thing with its own
        // line in this file -- a route added to routes/customer.php cannot silently
        // inherit the staff stack, and a reviewer reading this file sees two surfaces.
        // ArmTenantScopeGuard is global and still applies, which is what keeps the
        // customer routes under the same "no tenant context, no statement" refusal as
        // everything else.
        $middleware->group('customer', []);

        // THE AUTH SURFACE'S GROUP (Task 5). It carries the session stack and CSRF -- a
        // login establishes a session, so the middleware that reads and writes one has to
        // be present -- and deliberately NOT RequireTenantScope or RequireAbility:
        //
        //   - RequireTenantScope would be circular, as routes/auth.php explains;
        //   - RequireAbility authorises a principal inside a tenant, and on this surface
        //     there is not yet a principal, let alone a tenant.
        //
        // ResolvePrincipal IS here, because `auth.me` and the tenant selection both need to
        // know who is asking. It refuses nothing, so a request with no credential reaches
        // the controller and gets the 401 the controller itself returns.
        //
        // Shedding and the limiter are NOT on the group: each route in routes/auth.php
        // names its own bucket, because a login and a tenant selection do not deserve the
        // same allowance and `api`'s shared bucket would give them one. That is the same
        // decision the customer group records for its three routes.
        // RefuseDeviceToken runs LAST, and the order is the whole of it: it reads the
        // access token ResolvePrincipal resolved, so it cannot run before it. A
        // `pager:read` device token used to satisfy every controller's
        // `$request->user() instanceof User` and reach all of this surface --
        // RefuseDeviceToken's docblock has the measurements and
        // DeviceTokenOnAuthSurfaceTest drives every route on the group.
        $middleware->group('auth-surface', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
            ResolvePrincipal::class,
            RefuseDeviceToken::class,
        ]);

        // TrustProxies is already on the global stack, and what it trusts is
        // config/trustedproxy.php -- read there, not configured here, because the
        // Middleware closure runs before LoadConfiguration and config() is not
        // available to it yet. That file is where the per-IP rate limit's key really
        // comes from: with nothing trusted, $request->ip() is the peer, which behind
        // the locked ALB is the balancer and not the client (security #2). BootChecks
        // refuses to serve on an empty or catch-all value.

        // Every /v1 route, in this order and for a reason each. The limiter runs FIRST
        // so an unauthenticated flood costs the limiter and not the resolver; the tenant
        // requirement runs after it and is deny-by-default -- no tenant, no request.
        // Neither is on the probe group, which is what "while health and readiness keep
        // answering" means.
        $middleware->appendToGroup('api', [
            // Shedding first: it is the cheapest refusal (one Redis read, no write) and
            // the one that protects the task rather than apportioning it. Then the
            // limiter, so an unauthenticated flood costs the limiter and not the
            // resolver. Then the tenant requirement, which is deny-by-default.
            ShedLoad::class,
            RateLimit::class,

            // ======================================================================
            // THE SESSION STACK, ADDED AT TASK 5 -- and it was a measured gap, not a
            // preference
            // ======================================================================
            //
            // The staff surface is a FIRST-PARTY BROWSER CLIENT authenticated by cookie:
            // §1 locks Redis as the session store, Fortify's login is a session login,
            // and apps/web (Task 8) is served from the same origin. None of that works on
            // a group with no session middleware.
            //
            // MEASURED, because the first version of Task 5 assumed it was already there:
            // AuthenticatedTenantResolver reads the selected tenant from the server-side
            // session, and $request->hasSession() is FALSE on this group, so the selection
            // silently never applied. Every single-membership test still passed, because
            // the resolver falls back to Memberships::sole() -- so the feature looked
            // fine and only a user with TWO memberships exposed it, answering 401 with a
            // correctly-written session key sitting unread. A fallback that hides a broken
            // primary path is worse than no fallback.
            //
            // CSRF IS IN THE LIST and it is the other half of §3 row 5's "session fixation
            // and CSRF tests". Laravel's ValidateCsrfToken returns early for a read verb
            // and while running tests, so a test that wants to prove it bites has to say
            // so explicitly -- SessionPolicyTest does.
            //
            // NOT on the customer group, which has its own (empty) group and authenticates
            // by QR token, and not on the probe group, which must keep answering: a health
            // check does not carry a cookie and must never be made to.
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
            // Authentication BEFORE the tenant requirement, because the tenant is derived
            // from the principal: the resolver reads the authenticated user's own
            // memberships, so a request with no user resolves no tenant. It refuses
            // nothing itself -- RequireTenantScope stays the one place an unauthenticated
            // caller is told no, which is what makes its "every refusal is the same
            // answer" property checkable.
            //
            // AFTER the limiter, deliberately and for the same reason the limiter is after
            // shedding: an unauthenticated flood should cost the limiter rather than two
            // guard lookups and a membership query.
            ResolvePrincipal::class,
            RequireTenantScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Redaction at the PRODUCER as well as at the sink. The RedactLogs tap covers
        // every channel in config/logging.php, but not Laravel's emergency logger,
        // which LogManager builds directly when a channel cannot be resolved and which
        // no tap reaches. Reporting here and returning false means the record is
        // already redacted whichever logger receives it.
        $exceptions->report(fn (Throwable $error) => app(ReportRedactedException::class)($error));

        // One RFC 7807 document for every uncaught throwable, for EVERY request and at
        // any APP_DEBUG setting (D3, and §4 › Edge & ops as amended on 2026-10-26).
        // Registered rather than conditional on expectsJson(): the clause is about what
        // a body carries, and .env.example ships APP_DEBUG=true, so a renderer that
        // steps aside in debug satisfies the words and not the property.
        $exceptions->render(fn (Throwable $error, Request $request) => app(ProblemRenderer::class)
            ->render($error, $request)
            ->toResponse());
    })->create();
