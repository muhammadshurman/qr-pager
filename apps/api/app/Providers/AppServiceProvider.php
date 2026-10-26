<?php

namespace App\Providers;

use App\Platform\Auth\HarnessTenantResolver;
use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantScopeGuard;
use App\Platform\Http\TenantResolver;
use App\Platform\Readiness\PressureGauge;
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
        // scoped(), not singleton(), and D10's measurement is the reason. Three
        // requests through the real Octane worker resolved this class as three
        // different objects although it was bound singleton() -- not because the
        // binding says so, but because nothing warms it, so each request resolved it
        // inside `clone $this->app` and the sandbox flush discarded it. That is a
        // guarantee by accident: anything that resolves it during boot moves it into
        // the root application and it becomes shared for the worker's life, carrying
        // $openScopes between requests. scoped() says what is meant, and
        // FlushTemporaryContainerInstances forgets it on OperationTerminated, so the
        // guarantee survives someone warming it.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(RuntimeRoleGuard::class);

        // One guard for the worker's life, deliberately: it holds the armed flag that
        // says a request is in flight, and it has to be the same object the global
        // middleware arms and the connection listener consults. Its state is a single
        // boolean about THIS process, never about a tenant, so sharing it across
        // requests carries nothing between them -- and the request boundary disarms it
        // in both of the two ways a request can end (R-022).
        $this->app->singleton(TenantScopeGuard::class);

        // The pre-Task-5 resolver (B5). One binding, replaced wholesale by Task 5 --
        // which is why RequireTenantScope, the rate limiter's per-tenant key and
        // everything downstream speak to the INTERFACE and not to this class. The stub
        // reads a container binding a test sets, never an HTTP input, and refuses to run
        // outside local/testing, so it cannot serve a production request at all.
        $this->app->bind(TenantResolver::class, HarnessTenantResolver::class);

        // One gauge for the worker's life, because CPU throttling is a cumulative
        // counter and the signal is its DELTA over a window: a per-request instance
        // would have no previous reading and could never report throttling at all.
        // It holds two integers about this process and nothing about any tenant.
        $this->app->singleton(PressureGauge::class);

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
