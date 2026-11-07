<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Features\Support\SupportAccess;
use App\Platform\Http\Middleware\RequireAbility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * `QRPAGER-22` INVARIANT CLAUSE 2, OVER HTTP — THE STANDING NON-NEGOTIABLE
 * ================================================================================
 *
 * *"One holding an active membership can read only that tenant's rows."*
 *
 * `§0.0e`'s lean mode keeps exactly one thing whatever else is dropped: *"every new route
 * taking an id is proven inaccessible cross-tenant by a test driven over HTTP, reusing 10a's
 * existing router-derived pattern. His reason: this is the one bug class I cannot recover
 * from."* `QRPAGER-22` adds no `/v1` route, but it adds a NEW PRINCIPAL that reaches every
 * existing one — which is the same exposure arriving from the other direction, and a bigger
 * one: a platform admin is the account most likely to hold support for several restaurants
 * over a week.
 *
 * ⚠ **THE SUBJECT LIST IS DERIVED FROM THE ROUTER**, like `StaffSurfaceIsolationTest`'s: a
 * `/v1` route is in scope if and only if it carries `RequireAbility`. A route added later is
 * swept with no edit here.
 */

/** Every `/v1` route behind `RequireAbility`, derived and never listed. */
function supportSweptRoutes(): array
{
    $out = [];

    /** @var Illuminate\Routing\Route $route */
    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/v1')) {
            continue;
        }

        if (! in_array(RequireAbility::class, $route->gatherMiddleware(), true)) {
            continue;
        }

        $verb = array_values(array_filter(
            $route->methods(),
            static fn (string $m): bool => ! in_array($m, ['HEAD', 'OPTIONS'], true),
        ))[0] ?? 'GET';

        $out[$route->getName() ?? '(unnamed) '.$uri] = [
            'method' => match ($verb) {
                'POST' => 'postJson',
                'PATCH' => 'patchJson',
                'PUT' => 'putJson',
                'DELETE' => 'deleteJson',
                default => 'getJson',
            },
            'uri' => $uri,
            'params' => array_values($route->parameterNames()),
        ];
    }

    ksort($out);

    return $out;
}

/** A platform admin who can sign in. */
function isolationAdmin(): array
{
    $id = (string) Str::uuid7();
    $email = 'iso-'.str_replace('-', '', $id).'@example.test';
    $password = 'correct-horse-battery-staple';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Support holder',
        'email' => $email,
        'password' => Hash::make($password),
        'is_platform_admin' => true,
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

it('derives a non-empty staff surface, so the sweep below is not a loop over nothing', function (): void {
    $routes = supportSweptRoutes();

    expect(count($routes))->toBeGreaterThan(5)
        // And every one is named, because an unnamed route is a defect `RequireAbility`
        // refuses anyway -- reported as itself rather than skipped.
        ->and(array_filter(array_keys($routes), static fn (string $n): bool => str_contains($n, '(unnamed)')))
        ->toBe([]);
});

it('reaches NO route of another tenant while holding support for one', function (): void {
    /*
     * Two restaurants with real rows in both, so every id aimed at beta EXISTS: a 404 for a
     * row that was never there proves absence, not isolation -- the defect
     * `RoleMatrixTest` and `bin/issue-staff-fixture` both record.
     */
    $alpha = $this->makeTenant(slug: 'support-iso-alpha');
    $beta = $this->makeTenant(slug: 'support-iso-beta');

    $betaSession = $this->makeSession($beta, ['order_reference' => 'B-1']);
    $betaMemberId = (string) Str::uuid7();
    $betaUser = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $betaUser,
        'name' => 'Beta owner',
        'email' => 'betaowner-'.str_replace('-', '', $betaUser).'@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    withTenant($beta, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $betaMemberId,
        'tenant_id' => $beta,
        'user_id' => $betaUser,
        'role' => 'owner',
    ]), 'pgsql_owner');

    $admin = isolationAdmin();

    app(SupportAccess::class)->take($alpha, $admin['id'], 'a customer complaint about alpha');

    $this->postJson('/v1/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])
        ->assertOk();

    // The session resolves ALPHA, because that is the only membership they hold.
    expect($this->getJson('/v1/auth/me')->json('active_tenant'))->toBe($alpha);

    /*
     * ⚠ **TWO QUESTIONS, AND THE FIRST VERSION OF THIS CASE CONFLATED THEM — MEASURED.** It
     * drove every swept route with beta's ids and called any 2xx a leak, and four routes
     * answered 200: `members.index`, `pager.sessions.index`, `pager.sessions.history` and
     * `tenant.show`. None of them takes a path parameter, so there was nothing to aim at
     * beta: they read the ACTIVE tenant, which is alpha, which the holder legitimately has.
     * A 200 there is correct behaviour and the assertion was wrong.
     *
     * So the sweep splits:
     *
     *   PARAMETERISED routes  aimed at beta's REAL ids — must refuse. This is lean mode's
     *                         clause verbatim: "every new route taking an id is proven
     *                         inaccessible cross-tenant".
     *   PARAMETERLESS routes  must answer, and must carry NONE of beta's data — which is the
     *                         other half of "can read only that tenant's rows", and is a
     *                         stronger statement than a status code.
     */
    $leaked = [];
    $parameterised = 0;
    $parameterless = 0;

    // Beta's own identifiers, none of which may appear in any answer alpha's holder gets.
    $betaNeedles = [$betaSession, $betaMemberId, $betaUser, $beta, 'support-iso-beta'];

    foreach (supportSweptRoutes() as $name => $route) {
        $uri = $route['uri'];

        if ($route['params'] === []) {
            $parameterless++;

            $response = $this->{$route['method']}($uri, []);
            $body = (string) $response->getContent();

            foreach ($betaNeedles as $needle) {
                if ($needle !== '' && str_contains($body, $needle)) {
                    $leaked[] = "{$name} returned beta's {$needle}";
                }
            }

            continue;
        }

        $parameterised++;

        foreach ($route['params'] as $param) {
            $value = match (true) {
                str_contains($param, 'session') => $betaSession,
                str_contains($param, 'member') => $betaMemberId,
                default => (string) Str::uuid7(),
            };

            $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], $value, $uri);
        }

        $status = $this->{$route['method']}($uri, [])->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $leaked[] = "{$name} answered {$status} for beta's id at {$uri}";
        }
    }

    // Neither half may be empty, or the sweep proves only the other one.
    expect($parameterised)->toBeGreaterThan(0, 'no parameterised route was aimed at beta')
        ->and($parameterless)->toBeGreaterThan(0, 'no parameterless route was checked for beta data')
        ->and($leaked)->toBe([], 'a support holder for one restaurant reached another restaurant');
});

it('holds no write ability on the tenant it DOES have support for', function (): void {
    /*
     * The other half, and the one a cross-tenant sweep cannot see: inside alpha the holder is
     * legitimately present, so refusals there are about the READ-ONLY clause rather than about
     * isolation. Driven over HTTP against alpha's own ids.
     */
    $alpha = $this->makeTenant(slug: 'support-readonly');
    $session = $this->makeSession($alpha, ['order_reference' => 'A-1']);
    $admin = isolationAdmin();

    app(SupportAccess::class)->take($alpha, $admin['id'], 'checking a board complaint');

    $this->postJson('/v1/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])
        ->assertOk();

    // A READ they do hold.
    $this->getJson('/v1/pager-sessions')->assertOk();

    // And the writes they do not, each refused rather than merely absent from a screen.
    $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-2'])->assertStatus(403);
    $this->postJson("/v1/pager-sessions/{$session}/ready", [])->assertStatus(403);
    $this->postJson('/v1/members', ['email' => 'someone@example.test', 'role' => 'staff'])
        ->assertStatus(403);
    $this->postJson('/v1/devices', ['name' => 'kitchen'])->assertStatus(403);

    // The principal the API reports is `support`, not the role the row stores.
    expect($this->getJson('/v1/auth/me')->json('memberships.0.role'))->not->toBe('support');
    expect($this->getJson('/v1/auth/me')->json('active_tenant'))->toBe($alpha);
});

it('reaches nothing at all once the membership has lapsed, over HTTP', function (): void {
    // Clause 3 over the wire rather than through the reader: the session is already
    // established, the row is still present, and the next request must refuse.
    $alpha = $this->makeTenant(slug: 'support-lapse-http');
    $admin = isolationAdmin();

    app(SupportAccess::class)->take($alpha, $admin['id'], 'will lapse mid-session');

    $this->postJson('/v1/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])
        ->assertOk();

    $this->getJson('/v1/pager-sessions')->assertOk();

    withTenant($alpha, fn () => DB::connection('pgsql_owner')->update(
        "update tenant_users set expires_at = now() - interval '1 minute' where user_id = ?",
        [$admin['id']],
    ), 'pgsql_owner');

    /*
     * ⚠ **THE SCOPED MEMO IS FLUSHED FIRST, BECAUSE THAT IS WHAT A REAL SECOND REQUEST DOES
     * AND THE TEST HARNESS DOES NOT.** `Memberships` is bound `scoped()` and memoises per
     * user id; under FrankenPHP every request flushes scoped instances, so a second request
     * re-reads the function and sees the lapse. Laravel's test client sends a new request
     * through the kernel WITHOUT recreating the container, so the memo from the first call
     * survives — measured: this case answered **200** before the flush was added, from a
     * membership the database had already stopped returning.
     *
     * Flushing here makes the assertion about the application rather than about the harness.
     * The alternative — asserting the stale 200 — would have been recording a test artefact
     * as a security property.
     */
    $this->app->forgetScopedInstances();

    /*
     * ⚠ 401 AND NOT 403, and the difference is the whole mechanism. The membership is gone
     * from the application's view, so no tenant resolves at all — `RequireTenantScope`
     * answers before any ability is consulted. A 403 would mean the tenant still resolved
     * and only the ability was missing, which is a weaker guarantee.
     */
    $this->getJson('/v1/pager-sessions')->assertStatus(401);
});

it('leaves the support principal unable to write by the matrix, independent of any route', function (): void {
    // The data statement behind the HTTP cases, so a routing change cannot quietly make them
    // vacuous: whatever routes exist, this principal holds no write ability.
    foreach (Ability::cases() as $ability) {
        if (! $ability->isWrite()) {
            continue;
        }

        expect(RoleMatrix::allows(Principal::Support, $ability))->toBeFalse();
    }
});
