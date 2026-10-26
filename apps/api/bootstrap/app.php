<?php

use App\Platform\Console\BootstrapDatabaseCommand;
use App\Platform\Console\CheckBootCommand;
use App\Platform\Console\RefreshReadinessCommand;
use App\Platform\Http\Middleware\ArmTenantScopeGuard;
use App\Platform\Http\ProblemRenderer;
use App\Platform\Logging\ReportRedactedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
