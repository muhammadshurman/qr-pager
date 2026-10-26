<?php

use App\Platform\Console\BootstrapDatabaseCommand;
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
        },
    )
    // Laravel's health: '/up' was removed here. It was the only other public route, it
    // duplicated liveness, and the framework's HealthCheck controller runs the
    // configured health checks -- which touch dependencies. §4 › Edge & ops wants the
    // opposite: a liveness endpoint with nothing it can be blocked on.
    ->withCommands([
        BootstrapDatabaseCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The probes' own group. Empty, and that is the mechanism behind "while health
        // and readiness keep answering": they carry no rate limiter, no tenant
        // middleware and no load shedding, so there is nothing that can shed them.
        $middleware->group('probe', []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );
    })->create();
