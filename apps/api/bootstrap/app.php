<?php

use App\Domain\Http\ProblemType;
use App\Features\Pager\Console\ExpirePagerSessionsCommand;
use App\Platform\Console\BootstrapDatabaseCommand;
use App\Platform\Console\CheckBootCommand;
use App\Platform\Console\RefreshReadinessCommand;
use App\Platform\Http\Middleware\ArmTenantScopeGuard;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Middleware\ShedLoad;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemRenderer;
use App\Platform\Logging\ReportRedactedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
