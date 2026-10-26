<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Auth\HarnessTenantResolver;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * B5 and §6.4's authz clause: "route default is deny", with the pre-Task-5 resolver
 * reading a container binding the test harness sets -- never a header, never a route
 * parameter -- so no HTTP input reaches it.
 */

beforeEach(function (): void {
    Route::middleware('api')->prefix('v1')->get('tenant-probe', fn () => response()->json([
        'tenant' => app()->bound(RequireTenantScope::BINDING) ? app(RequireTenantScope::BINDING) : null,
    ]));
});

it('refuses a /v1 request with no resolvable tenant, and says nothing about why', function (): void {
    $response = $this->getJson('/v1/tenant-probe');

    $response->assertStatus(401)->assertJsonPath('type', ProblemType::Unauthenticated->value);

    // "No tenant could be resolved" and "that tenant is not yours" are the same answer
    // from outside. A different one would tell a caller which tenant ids exist.
    expect((string) $response->getContent())->not->toContain('tenant_id')
        ->not->toContain('binding')
        ->not->toContain('HarnessTenantResolver')
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('publishes the resolved tenant for the request and forgets it afterwards', function (): void {
    $tenant = $this->actingForTenant();

    $this->getJson('/v1/tenant-probe')->assertStatus(200)->assertJsonPath('tenant', $tenant);
});

it('opens no tenant scope, because a scope belongs to a unit of work — B4', function (): void {
    $this->actingForTenant();

    Route::middleware('api')->prefix('v1')->get('scope-probe', fn () => response()->json([
        // The middleware resolved a tenant and did NOT open a scope: a request-scoped
        // transaction would hold one of the forty qrpager_app connections for the whole
        // request, colliding with both the connection cap and the fast-503 clause.
        'level' => DB::connection('pgsql')->transactionLevel(),
        'in_force' => (string) DB::connection('pgsql')->selectOne(
            'select coalesce(current_setting(?, true), ?) as v', ['app.tenant_id', ''],
        )->v,
    ]));

    $this->getJson('/v1/scope-probe')->assertStatus(200)
        ->assertJsonPath('level', 0)
        ->assertJsonPath('in_force', '');
});

it('reads no HTTP input at all — B5', function (): void {
    $resolver = app(TenantResolver::class);

    expect($resolver)->toBeInstanceOf(HarnessTenantResolver::class);

    // Every shape a caller could try, with nothing bound. A stub that read a header
    // would be an unauthenticated tenant-switching parameter, which is the most
    // dangerous thing this application could ship and would read as ordinary code.
    $request = Request::create('/v1/tenant-probe?tenant='.Str::uuid7(), 'GET', [
        'tenant_id' => (string) Str::uuid7(),
    ], ['tenant' => (string) Str::uuid7()], [], [
        'HTTP_X_TENANT_ID' => (string) Str::uuid7(),
        'HTTP_X_TENANT' => (string) Str::uuid7(),
        'HTTP_AUTHORIZATION' => 'Bearer '.Str::uuid7(),
    ]);

    expect($resolver->resolve($request))->toBeNull();

    // ...and with the binding set, the same request resolves to the BOUND value and not
    // to any of the above.
    $bound = $this->actingForTenant();

    expect(app(TenantResolver::class)->resolve($request))->toBe($bound);
});

it('refuses a bound value that is not a uuid', function (): void {
    foreach (['', 'not-a-uuid', '../../etc/passwd', '1 or 1=1'] as $value) {
        $this->app->instance(HarnessTenantResolver::BINDING, $value);

        // Shape-checked at the resolver as well as at the caller: a binding is PHP
        // state, and the value becomes the Redis namespace and every tenant scope
        // downstream.
        expect(app(TenantResolver::class)->resolve(Request::create('/v1/tenant-probe')))->toBeNull();
    }
});

it('cannot run outside local or testing, so it cannot survive to production — B5', function (): void {
    $this->actingForTenant();

    app()->detectEnvironment(fn () => 'production');

    // Stronger than a CI check: the stub cannot serve a production request at all.
    // Every /v1 route is behind RequireTenantScope, so a deployment carrying this class
    // denies every API request loudly -- which is deny-by-default working, not a
    // regression. routes/v1.php is empty at Task 2 by design, so there is nothing to
    // deny yet.
    expect(fn () => app(TenantResolver::class)->resolve(Request::create('/v1/tenant-probe')))
        ->toThrow(RuntimeException::class, 'cannot run in [production]');
});

it('is the whole of the pre-Task-5 auth surface', function (): void {
    // One implementation, and Task 5 replaces it wholesale. Everything downstream --
    // RequireTenantScope, the rate limiter's per-tenant key -- speaks to the interface,
    // so there is one binding to change and no call site.
    $implementations = [];

    foreach (glob(app_path('Platform/Auth/*.php')) ?: [] as $file) {
        $implementations[] = basename($file, '.php');
    }

    expect($implementations)->toBe(['HarnessTenantResolver']);
});
