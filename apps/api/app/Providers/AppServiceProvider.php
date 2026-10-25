<?php

namespace App\Providers;

use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
