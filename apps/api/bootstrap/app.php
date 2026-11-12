<?php

use App\Domain\Http\ProblemType;
use App\Features\Contracts\Console\ExportSiteKeyMirrorCommand;
use App\Features\Contracts\Console\ExportStatusMirrorCommand;
use App\Features\Landing\Console\AdvanceDomainsCommand;
use App\Features\Landing\Console\ExportLandingSchemaCommand;
use App\Features\Landing\Console\SeedDemoTenantCommand;
use App\Features\Notifications\Console\WorkOutboxCommand;
use App\Features\Pager\Console\ExpirePagerSessionsCommand;
use App\Features\Provisioning\Console\AggregateTenantStatisticsCommand;
use App\Features\Provisioning\Console\ProvisionTenantsCommand;
use App\Features\Support\Console\SweepSupportAccessCommand;
use App\Platform\Console\BootstrapDatabaseCommand;
use App\Platform\Console\CheckBootCommand;
use App\Platform\Console\RefreshReadinessCommand;
use App\Platform\Http\Middleware\ArmTenantScopeGuard;
use App\Platform\Http\Middleware\HardenResponse;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RefuseDeviceToken;
use App\Platform\Http\Middleware\RefuseUnservedVendorRoutes;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Middleware\ResolvePrincipal;
use App\Platform\Http\Middleware\ShedLoad;
use App\Platform\Http\PanelProblemPage;
use App\Platform\Http\PanelSignInRedirect;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

            // THE WEBAUTHN HARNESS PAGE (Task 5), and the second conditional load.
            // PLAN.md §3 row 5 asks for a minimal unstyled page to exercise
            // navigator.credentials, because the credential ceremony cannot be driven from
            // HTTP -- and a test fixture with a URL has no business being reachable anywhere
            // but a developer's own machine and the suite.
            //
            // No is_file() check, unlike the drain route: this file IS committed, because
            // the gate names the page as a deliverable rather than as something mounted by
            // compose. So the condition is the environment alone.
            //
            // ================================================================================
            // ⚠ AN ALLOWLIST, NOT `! environment('production')`. QRPAGER-31, MEASURED.
            // ================================================================================
            //
            // It read `! app()->environment('production')` until 2026-11-03, which admits
            // EVERY environment name that is not exactly that one. Driven as four real
            // processes through `tests/Support/get-path-in-another-process.php`, before the
            // change:
            //
            //     local 200 · testing 200 · staging 200 · production 404
            //
            // So a staging deployment -- or any env named `demo`, `qa`, `preview`, or
            // `production-eu` -- served a page carrying an email/password sign-in form, with
            // its own CSRF token, posting to the real `/v1/auth/login` and
            // `/v1/auth/passkeys`. Nothing on it is a vulnerability in itself; it is a second
            // credential surface that no task owns, on a host strangers can reach, and
            // `§4 › Tenancy`'s "two authentication surfaces, never confused" is the clause it
            // makes false. Reported from a browser as "a login page renders as unstyled raw
            // HTML", which it does, by design -- the report was right about the page being
            // there and wrong about why it looked like that.
            //
            // The drain route above keeps `! environment('production')` deliberately and the
            // two are NOT inconsistent: that one carries a second condition the deployment
            // artefact cannot satisfy (`routes/drain.php` is never committed, and
            // RouteSurfaceTest asserts its absence from the repository), so the environment
            // name is not the only thing standing between it and a request. This page has no
            // second condition, so its first one has to be the tight one.
            if (app()->environment(['local', 'testing'])) {
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
        // Task 6's two platform commands, registered for the same reason as the three
        // above: they live in app/Features, which withCommands' directory scan does not
        // cover, and an explicit list is what makes `php artisan` the record of what
        // exists. BOTH ARE SCHEDULED in routes/console.php in the same commit that
        // created them, which is R-058's ruling applied before it could be repeated: "a
        // command registered and scheduled nowhere does not enforce anything."
        ProvisionTenantsCommand::class,
        AggregateTenantStatisticsCommand::class,
        // QRPAGER-22's support-access sweep, registered here because it lives in
        // app/Features and SCHEDULED in routes/console.php in the same commit, which is
        // R-058's ruling. ⚠ Unlike the two above it enforces NOTHING: a lapsed support
        // membership grants no ability the instant it lapses, because
        // `public.user_memberships()` filters it in SQL for every reader at once. All this
        // frees is the admin's one-at-a-time slot, which a partial unique index holds
        // because an index predicate cannot reference `now()`. That is why it runs every
        // five minutes rather than every minute.
        SweepSupportAccessCommand::class,
        // `QRPAGER-48` item 1: the demo restaurant a design can be judged from. NOT
        // scheduled and NOT a fixture -- it is deliberately kept out of the population
        // `apps/api/bin` seeds, because the thing that made the last studio review
        // impossible was being handed an e2e tenant full of this suite's own timestamps.
        // It refuses outside local/testing, like every sibling that writes as the owner.
        SeedDemoTenantCommand::class,
        // Task 7's generator for the shared block contract. NOT scheduled, and that is
        // not R-058's shape repeating: it enforces nothing on a timer, it WRITES a
        // committed artifact. What keeps it honest is `--check`, which bin/gate and CI
        // both run, so the file cannot go stale without something going red.
        ExportLandingSchemaCommand::class,
        // Task 7's domain lifecycle, per tenant. NOT scheduled, and that is not R-058's
        // shape: a scheduler entry would have to name a tenant and there is no "the
        // tenant" at schedule time. SR-3 and R-130 carry the argument.
        AdvanceDomainsCommand::class,
        // Task 8a's generator for the TypeScript status mirror. NOT scheduled, for the
        // same reason as ExportLandingSchemaCommand directly above: it enforces nothing on
        // a timer, it WRITES a committed artifact, and `--check` is what keeps it honest --
        // bin/gate and CI both run it, so the file cannot go stale without something going
        // red. DatabaseEnums::map() is its source, so an enum added to that registry
        // appears in the TypeScript without anyone remembering to add it.
        ExportStatusMirrorCommand::class,
        // Task 11's generator for the TypeScript published-site key mirror (`ADR-005`).
        // A SECOND command rather than a second artifact of the one above, because the two
        // have different sources -- the enum registry there, `PublishedSite` here -- and one
        // command writing both would go stale in a way that names neither. Not scheduled,
        // for the same reason as its two siblings: it writes a committed artifact and
        // `--check` is what keeps it honest.
        ExportSiteKeyMirrorCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // GLOBAL, and prepended: the first invariant in §3.1 row 2 is "a request
        // without tenant context never reaches the DB", and a route group is something
        // a later route can be added without. This runs before routing, so an
        // unmatched path is armed too -- a 404 that touches the database on its way out
        // is still a request. Arming is all it does; the refusal is
        // TenantScopeGuard's, before the statement reaches Postgres.
        $middleware->prepend(ArmTenantScopeGuard::class);

        // ALSO GLOBAL, and also prepended, because it has to run before routing: Task 6's
        // Filament install added two vendor routes that answered 500 to an ANONYMOUS GET
        // (`/filament/exports/{export}/download` and the imports equivalent, both reading
        // tables this application never created) plus Livewire's `preview-file`, which
        // nothing in this application has a caller for. Competing routes would have worked
        // only while this application's routes are registered before the package's, and
        // nothing pins that order -- a global middleware does not care. See the class for
        // the measurement and for why the Livewire prefix is a pattern rather than a
        // literal.
        //
        // ⚠ IT REFUSED `upload-file` TOO UNTIL 2026-10-31, on the argument that the panel
        // had no file upload -- which BEING GLOBAL is exactly what it was not entitled to
        // assume: Task 7 mounted a second panel whose media page is Livewire's ordinary
        // upload path, and the page could not receive a file for the whole of that task.
        // The lesson belongs here rather than only in the class: a path list in a global
        // middleware is a claim about every panel the application will ever mount.
        $middleware->prepend(RefuseUnservedVendorRoutes::class);

        /*
         * THE RESPONSE-HARDENING HEADERS, ON EVERY RESPONSE — Task 12, `R-038` and `R-235`.
         *
         * ⚠ PREPENDED LAST, SO IT IS THE OUTERMOST MIDDLEWARE, AND THAT IS THE WHOLE
         * REGISTRATION. A middleware's post-`$next()` code runs on the way back out, outermost
         * LAST — so the outermost one is the only position that sees the final response
         * whatever produced it: a controller, a group's refusal, `RefuseUnservedVendorRoutes`'s
         * own 404, the rate limiter's 429, or the exception handler's problem document.
         * Anywhere further in and the headers would be missing from exactly the responses an
         * attacker generates most of.
         *
         * `prepend()` pushes to the FRONT, so the LAST call wins the outermost slot. That is
         * why this sits BELOW the other two rather than above them — ordering that reads as
         * arbitrary and is not. `ResponseHeaderTest` drives all three route shapes plus a 404,
         * a 429 and a rendered exception for exactly this reason.
         *
         * GLOBAL and not per-group, because `R-038`'s acceptance clause asks for the headers
         * *"set in one place rather than per controller"* and the two probes are outside every
         * group. Four controllers agreeing on a cache header is how `R-235` happened.
         */
        $middleware->prepend(HardenResponse::class);

        // LIVEWIRE'S UPDATE ENDPOINT, metered through the one group it belongs to
        // (security H-2). Filament's tables, modals and forms all talk to the server
        // through `POST /livewire-<hash>/update`, which Livewire registers itself with
        // the `web` group and `RequireLivewireHeaders` and nothing else -- so it was the
        // busiest panel route and the only one with no bucket. Measured: 10 anonymous
        // POSTs each answered 419 and minted one Redis key.
        //
        // appendToGroup('web') rather than re-registering the route with
        // Livewire::setUpdateRoute(): that would mean choosing the path ourselves, and
        // the path is derived from APP_KEY on purpose so a scanner cannot guess it.
        // Measured at `263bb16`: the `web` group's ONLY members are Livewire's three
        // routes (`route:list` with middleware filtered), so this widens nothing else --
        // and PanelRateLimitTest asserts that membership, so if a future route joins the
        // group it is a deliberate act rather than a surprise.
        $middleware->appendToGroup('web', RateLimit::class.':panel');

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
        // ⚠ THE LIMITER IS ON THE GROUP, AS A COARSE BACKSTOP, AND SHEDDING IS NOT. This
        // paragraph used to say neither was, and the `§11` review of this task's fix diff
        // found it still saying so eighteen lines above the limiter that is now the group's
        // first entry -- the same false claim this round corrected in routes/auth.php and
        // config/ratelimit.php, left standing in the file it was changing.
        //
        // Each route in routes/auth.php still names its OWN bucket, because a login and a
        // tenant selection do not deserve the same allowance; the group's bucket exists
        // because group middleware runs FIRST, so without it every refusal the four entries
        // below produce was unmetered. Both layers bind and the tighter wins.
        //
        // Shedding is still NOT here, and that is now the only half of the original decision
        // that stands: dropping a login under load locks staff out of a restaurant mid-
        // service, which needs Task 14's numbers to decide. `R-105`.
        // RefuseDeviceToken runs LAST, and the order is the whole of it: it reads the
        // access token ResolvePrincipal resolved, so it cannot run before it. A
        // `pager:read` device token used to satisfy every controller's
        // `$request->user() instanceof User` and reach all of this surface --
        // RefuseDeviceToken's docblock has the measurements and
        // DeviceTokenOnAuthSurfaceTest drives every route on the group.
        $middleware->group('auth-surface', [
            // ⚠ THE LIMITER RUNS FIRST, AND THE ORDER IS THE FIX. Laravel runs group
            // middleware before route middleware, so while the five buckets in
            // routes/auth.php were named per route, every refusal the four entries BELOW
            // produce happened before any bucket was consulted -- unmetered, and two of
            // those paths need no credential at all. Measured at the §11 review of this
            // task's fix diff: 100 CSRF-less logins gave 100 x 419, zero 429s, and one new
            // Redis session key each. config/ratelimit.php's `auth-surface` entry has the
            // three measurements and why the double-count with the per-route buckets is
            // deliberate. This is the same ordering this file already argues for on the
            // `api` group, twenty lines down.
            RateLimit::class.':auth-surface',
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
        //
        // ⚠ AND SINCE TASK 8b, THE PANEL SCREENS GET THE SAME DOCUMENT AS HTML. §6's third
        // column asks a *source down* screen to say "it is us, not them, and whether to
        // wait"; the walk measured all seven panel screens answering 503 problem+json,
        // which is raw JSON in a restaurant owner's browser.
        //
        // THE ORDER IS LOAD-BEARING: the Problem is produced FIRST, by the same renderer,
        // and only then handed to the page. PanelProblemPage never sees the throwable, so
        // there is no path from an exception to an HTML body -- which is the property that
        // makes an HTML branch safe here at all, measured at 7f1c8f6 as 862,104 bytes
        // carrying the SQL. PanelWalkTest drives nine disclosure needles over the HTML.
        $exceptions->render(function (Throwable $error, Request $request): SymfonyResponse {
            $problem = app(ProblemRenderer::class)->render($error, $request);
            $page = app(PanelProblemPage::class);

            /*
             * ⚠ AND SINCE QRPAGER-38, A SIGNED-OUT BROWSER ON A PANEL PATH IS SENT TO THE
             * SIGN-IN SCREEN INSTEAD OF BEING TOLD IT NEEDS ONE -- `R-253`. BEFORE the page,
             * because the page is the dead end: measured signed out, `/studio` answered a
             * 1,990-byte 401 titled "Authentication is required." with nothing on it to
             * press, and the panels have no login form of their own BY DESIGN (`§4 ›
             * Tenancy`), so the only honest answer is the address of the one form there is.
             *
             * It returns null for everything else, which is nearly everything: only an
             * `Unauthenticated` problem, only a real document navigation, and only on a
             * registered panel's path. `/v1` therefore keeps its problem document unchanged
             * -- it is not a panel path -- and so does a Livewire request, which asks for
             * JSON. `PanelSignInRedirect`'s docblock argues all three.
             */
            $redirect = app(PanelSignInRedirect::class)->for($problem, $request);

            if ($redirect !== null) {
                return $redirect;
            }

            return $page->wants($request)
                ? $page->respond($problem)
                : $problem->toResponse();
        });
    })->create();
