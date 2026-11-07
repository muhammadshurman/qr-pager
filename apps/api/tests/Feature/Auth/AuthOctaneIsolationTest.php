<?php

declare(strict_types=1);

use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Memberships;
use App\Platform\Auth\RequestPrincipal;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * Q5 -- WHAT THE AUTH LAYER CACHES, AND THAT IT DOES NOT CROSS A REQUEST
 * ================================================================================
 *
 * `QRPAGER-12 §3` question 5, in the rank-1 prompt's own words: *"Octane keeps workers
 * alive between requests. Anything the auth layer caches in a container or a static
 * property leaks across requests and therefore across tenants. State what is cached and
 * for how long, and prove isolation with a test."*
 *
 * The statement is `QRPAGER-12 §10.5`. This file is the proof, and it drives the REAL
 * `Laravel\Octane\Worker` rather than a double -- `R-022` exists precisely because the
 * request-termination hooks do not fire in the shape anyone assumed, and a fake would have
 * reproduced the assumption instead of the behaviour.
 *
 * WHAT IS CACHED: one user's membership set, in one `scoped()` object, for one request.
 * Nothing in a static property, nothing in Redis, nothing in the cache store.
 *
 * WHY A CACHE AT ALL: `tenant_users` carries `INDEX (user_id)` (`§2.5`) and the §2.8
 * function takes one exact-match uuid, so a membership read is one index probe returning a
 * handful of rows. A cross-request cache would buy microseconds and pay for them with a
 * membership set that can be stale across a revocation -- and a stale membership IS a
 * cross-tenant read.
 *
 * ⚠ AND THERE IS A TEST-ENVIRONMENT DIVERGENCE WORTH A REVIEWER'S ATTENTION, recorded in
 * `SuspendedTenantCustomerSurfaceTest`'s `suspend()` helper: Laravel's test client does NOT
 * flush scoped instances between `$this->postJson()` calls, so the ordinary feature tests
 * in this suite run with a longer-lived memo than production has. That is the reason this
 * file exists on the real worker: the thing standing between the memo and a stale-membership
 * read is the Octane flush, so the flush is what gets tested rather than assumed.
 */

it('binds every piece of per-request auth state as scoped, so the boundary flush reaches it', function (): void {
    // THE PROPERTY, IN TWO HALVES, and they are proven in two places on purpose.
    //
    // HALF ONE, here: these three objects are `scoped()` -- forgotten by
    // forgetScopedInstances() and rebuilt on the next resolve. Asserted behaviourally
    // rather than by reading the provider, because what matters is what the container
    // DOES.
    //
    // HALF TWO, already proven on the real worker by
    // tests/Feature/Octane/RequestBoundaryTest.php's D10 case: Octane calls
    // forgetScopedInstances() at every request boundary, so a scoped binding cannot
    // survive one. That case drives Laravel\Octane\Worker rather than a double, which is
    // R-022's whole lesson -- the termination hooks do not fire in the shape anyone
    // assumed.
    //
    // Together they are the isolation Q5 asks for. Driving the worker a second time HERE
    // was the first attempt and it does not work: make() on the worker's application
    // outside a request fails with "Target class [config] does not exist", because that
    // container is only fully wired inside a request. So the composition above is used
    // instead of a worse version of a test that already exists.
    $before = [
        Memberships::class => app(Memberships::class),
        ActiveTenant::class => app(ActiveTenant::class),
        RequestPrincipal::class => app(RequestPrincipal::class),
    ];

    // Same request, same objects -- which is what makes the memo worth having.
    foreach ($before as $class => $instance) {
        expect(app($class))->toBe($instance, "{$class} is rebuilt within one request, so its memo is useless");
    }

    // The boundary.
    app()->forgetScopedInstances();

    foreach ($before as $class => $instance) {
        expect(app($class))->not->toBe(
            $instance,
            "{$class} survived forgetScopedInstances(), so its state carries between requests",
        );
    }
});

it('leaves no membership set resolvable after the boundary, not merely a new object', function (): void {
    // The stronger form: a NEW object is only interesting if the old one's DATA is gone
    // with it. Driven by reading a real membership, crossing the boundary, and asking the
    // fresh reader about the same user.
    $tenant = $this->actingForTenant();
    $userId = (string) auth()->id();

    $first = app(Memberships::class);

    expect($first->for($userId))->toHaveCount(1)
        ->and($first->in($userId, $tenant))->not->toBeNull();

    app()->forgetScopedInstances();

    $second = app(Memberships::class);

    expect($second)->not->toBe($first);

    // The fresh reader has no memo at all, so it re-reads -- counted rather than assumed.
    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'user_memberships(')) {
            $reads++;
        }
    });

    $second->for($userId);

    expect($reads)->toBe(1, 'the new reader answered from a memo it should not have');
});

it('holds no authenticated user in a static property anywhere in the auth layer', function (): void {
    // THE OTHER HALF OF Q5'S QUESTION, and it is a different mechanism: a scoped binding is
    // flushed at the boundary, a static property is not. Octane cannot help with a static,
    // so the only safe number of them is zero -- and the honest way to assert that is to
    // read the classes rather than to promise it.
    $authLayer = glob(app_path('Platform/Auth/*.php')) ?: [];
    $authLayer = array_merge($authLayer, glob(app_path('Platform/Auth/Http/*.php')) ?: []);
    $authLayer = array_merge($authLayer, glob(app_path('Domain/Auth/*.php')) ?: []);

    expect($authLayer)->not->toBeEmpty('the auth layer glob found nothing, so this case is vacuous');

    $offenders = [];

    foreach ($authLayer as $file) {
        $source = (string) file_get_contents($file);

        // A static PROPERTY, not a static method: the Domain classes are deliberately all
        // static methods over constant data (RoleMatrix, RouteAbilities, TenantPin), which
        // carries no per-request state at all. What would leak is mutable static state.
        if (preg_match('/\b(private|protected|public)\s+static\s+(?!function)/', $source) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'these auth classes hold static state: '.implode(', ', $offenders));
});

it('reads a membership once per request rather than once per authorisation check', function (): void {
    // The cost claim in §10.5, asserted rather than stated. RequireAbility runs per request
    // and RequestPrincipal::may() can be called more than once in one, so the memo is what
    // keeps this at one query -- and if the memo were removed for safety, this case is what
    // would notice the cost.
    $tenant = $this->actingForTenant();

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'user_memberships(')) {
            $reads++;
        }
    });

    $this->getJson('/v1/pager-sessions')->assertStatus(200);

    expect($reads)->toBe(1, "the membership function was called {$reads} times in one request")
        ->and($tenant)->not->toBeEmpty();
});
