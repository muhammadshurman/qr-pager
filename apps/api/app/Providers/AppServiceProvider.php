<?php

namespace App\Providers;

use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
use Dedoc\Scramble\Scramble;
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
        //
    }
}
