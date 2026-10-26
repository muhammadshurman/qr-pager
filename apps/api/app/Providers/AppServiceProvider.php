<?php

namespace App\Providers;

use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantScopeGuard;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request/worker lifecycle. Task 2 is responsible for
        // resetting it between Octane requests.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(RuntimeRoleGuard::class);

        // One guard for the worker's life, deliberately: it holds the armed flag that
        // says a request is in flight, and it has to be the same object the global
        // middleware arms and the connection listener consults. Its state is a single
        // boolean about THIS process, never about a tenant, so sharing it across
        // requests carries nothing between them -- and the request boundary disarms it
        // in both of the two ways a request can end (R-022).
        $this->app->singleton(TenantScopeGuard::class);

        // Scramble registers GET docs/api, GET docs/api.json and a dev-tools asset
        // route by default. The spec this project ships is a committed file generated
        // by `scramble:export` and diffed in CI, so nothing needs to serve it over
        // HTTP -- and an endpoint listing every route is not a surface to leave open
        // and then gate. Called in register(), which runs before Scramble's
        // bootingPackage() reads the flag.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Attach the tenant-scope guard to every database connection the manager
        // builds from here on, including the ones built after an Octane purge. It is
        // registered unconditionally and armed per request, so the registration cannot
        // be the thing that is missing -- see TenantScopeGuard for why the attachment
        // is beforeExecuting() and not DB::listen().
        $this->app->make(TenantScopeGuard::class)->listen($this->app->make(Dispatcher::class));
    }
}
