<?php

declare(strict_types=1);

use App\Domain\Auth\Membership;
use App\Domain\Http\ProblemType;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\AuthenticatedTenantResolver;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * TENANT RESOLUTION AFTER TASK 5 -- rewritten, and three cases deliberately deleted
 * ================================================================================
 *
 * This file tested the pre-Task-5 stub. The stub is gone (R-035), so the cases that were
 * about IT rather than about the PROPERTY are gone with it, and the deletions are listed
 * here rather than left for someone to notice the file got shorter:
 *
 *   - "cannot run outside local or testing" -- that was the stub's self-defence. The real
 *     resolver's whole job is to run in production. Deleted, and the property it was
 *     protecting (a misdeployed resolver must refuse rather than serve) is now covered by
 *     the two security L-3 cases at the bottom, which drive a resolver that THROWS.
 *   - "is the whole of the pre-Task-5 auth surface", a glob() of app/Platform/Auth
 *     asserting exactly one file. R-035 records that this was the only mechanism that
 *     would have caught a surviving stub and that it was worth nothing, because it fires
 *     only if Task 5's resolver lands in that same directory -- which it does. Deleted and
 *     replaced by tests/Feature/Auth/StubRemovedTest.php, whose assertions fail wherever
 *     the stub lands.
 *   - "refuses a bound value that is not a uuid" -- there is no container binding to steer
 *     tenancy with any more. Replaced by the session-selection shape check below.
 *
 * What is NOT deleted is the important one: "reads no HTTP input at all". It matters MORE
 * now, not less, because the real resolver has a legitimate reason to want a tenant from
 * the request and must still refuse to take one.
 */

beforeEach(function (): void {
    Route::middleware('api')->prefix('v1')->get('tenant-probe', fn () => response()->json([
        'tenant' => app()->bound(RequireTenantScope::BINDING) ? app(RequireTenantScope::BINDING) : null,
    ]));
});

it('refuses a /v1 request with no resolvable tenant, and says nothing about why', function (): void {
    $response = $this->getJson('/v1/tenant-probe');

    $response->assertStatus(401)->assertJsonPath('type', ProblemType::Unauthenticated->value);

    // "Not authenticated", "that tenant is not yours" and "that tenant is suspended" are
    // the same answer from outside. A different one would tell a caller which tenant ids
    // exist and which are suspended.
    expect((string) $response->getContent())->not->toContain('tenant_id')
        ->and((string) $response->getContent())->not->toContain('membership')
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

it('takes the tenant from no HTTP input whatsoever, even now that it has a reason to want one', function (): void {
    // THE CASE THIS FILE EXISTS FOR, and it is stronger after Task 5 than before it.
    //
    // The stub ignored HTTP input because it read a container binding. The real resolver
    // has a genuine need to know which tenant a multi-tenant user means -- and it STILL
    // takes it from no request field, because the answer comes from the server-side
    // session or from the token's own abilities. PagerIdorTest drives the same five shapes
    // through a real route and requires 404; this drives them at the resolver itself.
    $resolver = app(TenantResolver::class);

    expect($resolver)->toBeInstanceOf(AuthenticatedTenantResolver::class);

    $other = $this->makeTenant();

    // A real authenticated principal with a real membership, so the ONLY thing being
    // tested is whether any of these fields can redirect it.
    $mine = $this->actingForTenant();

    $request = Request::create('/v1/tenant-probe?tenant='.$other, 'GET', [
        'tenant_id' => $other,
    ], ['tenant' => $other], [], [
        'HTTP_X_TENANT_ID' => $other,
        'HTTP_X_TENANT' => $other,
        'HTTP_TENANT' => $other,
    ]);

    $request->setUserResolver(fn () => auth()->user());

    // Resolves to the membership, never to anything in the request.
    expect(app(TenantResolver::class)->resolve($request))->toBe($mine)
        ->and(app(TenantResolver::class)->resolve($request))->not->toBe($other);
});

it('refuses a session selection that is not a uuid, and one the user holds no membership in', function (): void {
    $this->actingForTenant();

    $resolver = app(TenantResolver::class);

    foreach (['', 'not-a-uuid', '../../etc/passwd', "1' or '1'='1"] as $value) {
        $request = Request::create('/v1/tenant-probe');
        $request->setLaravelSession(session()->driver());
        $request->setUserResolver(fn () => auth()->user());
        session()->put(ActiveTenant::SESSION_KEY, $value);

        // Shape-checked at the resolver as well as where it is written: a session store is
        // server-side, but the value becomes the Redis namespace and every tenant scope
        // downstream, so it is validated at the point of use.
        //
        // '' and a malformed value both fall back to NOTHING rather than to the sole
        // membership, because a selection that cannot be honoured is a refusal -- falling
        // back would make a corrupted session silently act in a tenant nobody chose.
        expect($resolver->resolve($request))->toBeNull("a session value of '{$value}' resolved a tenant");
    }

    // A well-formed uuid the user is not a member of: same refusal, different reason.
    $foreign = $this->makeTenant();
    $request = Request::create('/v1/tenant-probe');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(fn () => auth()->user());
    session()->put(ActiveTenant::SESSION_KEY, $foreign);

    expect($resolver->resolve($request))->toBeNull('a foreign tenant in the session resolved');
});

it('refuses a suspended tenant at the resolver, before any route runs — R-081', function (): void {
    $tenant = $this->actingForTenant();

    // The membership is real and the role is owner. None of that matters.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('tenants')->where('id', $tenant)
        ->update(['status' => TenantStatus::Suspended->value]), 'pgsql_owner');

    $this->getJson('/v1/tenant-probe')->assertStatus(401);

    // And the same bodiless answer as every other refusal, so that suspended is not
    // distinguishable from unknown or from not-yours.
    $response = $this->getJson('/v1/tenant-probe');

    expect((string) $response->getContent())->not->toContain('suspend')
        ->and($response->json())->not->toHaveKey('errors');
});

it('answers 401 and not 500 when the resolver itself refuses — security L-3', function (): void {
    // THE HALF THAT WAS NEVER ASSERTED, kept from before Task 5 and re-aimed. Measured
    // before the original fix: RequireTenantScope called resolve() bare, so a throwing
    // resolver became an uncaught throwable and every /v1 route answered 500 -- while this
    // file's own comment and the middleware's docblock both said 401.
    //
    // The stub threw because it refused to run in production. The real resolver has no
    // such clause, so the throw is injected here instead: the property under test is
    // "whatever the reason, a resolver that cannot answer produces a clean 401", and it
    // must not depend on which resolver is installed.
    $this->actingForTenant();

    $this->app->bind(TenantResolver::class, fn (): TenantResolver => new class implements TenantResolver
    {
        public function resolve(Request $request): ?string
        {
            throw new RuntimeException('the resolver could not reach the membership table');
        }
    });

    $response = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-1']);

    $response->assertStatus(401)
        ->assertJsonPath('type', ProblemType::Unauthenticated->value);

    $body = (string) $response->getContent();

    expect(str_contains($body, 'membership table'))->toBeFalse('the refusal quoted the exception message');
    expect(str_contains($body, 'RuntimeException'))->toBeFalse('the refusal named the exception class');
    expect($response->json())->not->toHaveKey('errors');
});

it('answers 401 for every staff route when the resolver refuses, not just the first — security L-3', function (): void {
    // All five, because the middleware is on the group and a per-route regression would be
    // invisible in a single-route case.
    $this->actingForTenant();
    $session = (string) Str::uuid7();

    $this->app->bind(TenantResolver::class, fn (): TenantResolver => new class implements TenantResolver
    {
        public function resolve(Request $request): ?string
        {
            throw new RuntimeException('refused');
        }
    });

    $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-1'])->assertStatus(401);

    foreach (['ready', 'renotify', 'complete', 'cancel'] as $action) {
        $this->postJson("/v1/pager-sessions/{$session}/{$action}")
            ->assertStatus(401, "the {$action} route did not answer 401 when the resolver refused");
    }
});

it('reads a membership through the §2.8 function and nothing else', function (): void {
    // The named cross-tenant path, pinned by the statement it issues. QRPAGER-12 §10.3(c)
    // claims the resolver's only read is public.user_memberships(?) with one exact-match
    // argument; this is that claim as an assertion rather than a docblock.
    $tenant = $this->actingForTenant();

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->getJson('/v1/tenant-probe')->assertStatus(200);

    $membershipReads = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'tenant_users') || str_contains($sql, 'user_memberships'),
    ));

    expect($membershipReads)->not->toBeEmpty('the resolver read no membership at all');

    foreach ($membershipReads as $sql) {
        expect($sql)->toContain('user_memberships(?)')
            ->and($sql)->not->toContain('tenant_users');
    }

    expect($tenant)->not->toBeEmpty();
});

it('builds a membership that knows a suspended tenant cannot authenticate', function (): void {
    // The value object carries the question, so every future reader of a membership has it
    // in front of them rather than re-deriving it.
    $active = new Membership('11111111-1111-7111-8111-111111111111', TenantRole::Owner, TenantStatus::Active, 'a');
    $suspended = new Membership('22222222-2222-7222-8222-222222222222', TenantRole::Owner, TenantStatus::Suspended, 'b');

    expect($active->tenantIsActive())->toBeTrue()
        ->and($suspended->tenantIsActive())->toBeFalse();
});
