<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use App\Platform\Auth\RouteAbilities;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE FULL ROLE MATRIX -- PLAN.md §3 row 5, as amended at this task's start
 * ================================================================================
 *
 * §3.1 row 5 rejected the original gate clause in terms: *"Full role-matrix test" does not
 * say what full means. Name the matrix -- roles × actions × own-tenant/other-tenant -- or
 * it is unfalsifiable by anyone but its author.* The gate now names it, and this file is it:
 *
 *     5 principals  ×  every /v1 staff route  ×  { own tenant, another tenant }
 *
 * THREE PROPERTIES, and the first is the one that makes the other two mean anything:
 *
 *   1. TOTALITY IS MECHANICAL. The first case iterates the router and requires every /v1
 *      route to be in exactly one of three named sets -- the staff map, the customer
 *      surface, or the auth surface. A route in none of them fails the suite, so "every
 *      route" is enforced rather than promised, and a route added without a matrix row
 *      cannot pass unnoticed. That is the difference between this and a test that checks
 *      the routes its author happened to remember.
 *
 *   2. THE MATRIX IS ONE ARRAY, read by the code and by this test. RoleMatrix::GRANTS is
 *      what RequireAbility decides from, so a wrong cell here is a wrong DECISION and not
 *      just a wrong expectation. A matrix living only inside its own assertion would prove
 *      that one copy of a list agrees with itself.
 *
 *   3. EVERY CELL IS DRIVEN THROUGH HTTP, not read off the array. §11.2 requires IDOR to be
 *      proven by a test rather than by reading code, and the own-tenant/other-tenant column
 *      is exactly that.
 */

/**
 * Every /v1 staff route, with enough to make a real request.
 *
 * @return array<string, array{method: string, path: callable(string, string): string, body: array<string, mixed>, targets: bool}>
 */
/**
 * A platform admin with no membership in any tenant.
 *
 * A local copy rather than a shared helper: Pest declares test-file functions in the global
 * namespace, so reusing `makePlatformAdmin` from PlatformAdminIsNotABypassTest would be a
 * redeclaration the moment both files load in one run.
 */
function makeMatrixPlatformAdmin(string $password = 'correct-horse-battery-staple'): array
{
    $id = (string) Str::uuid7();
    $email = 'matrix-admin-'.substr(str_replace('-', '', $id), 0, 12).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Platform Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'is_platform_admin' => true,
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

function staffRoutes(): array
{
    return [
        // `targets` says whether this route carries ANOTHER tenant's identifier in its
        // path. It decides which assertion the cross-tenant case can make: a targeting
        // route must not succeed, and a non-targeting one must not return any of the other
        // tenant's data. Conflating the two made the first draft of that case assert
        // something false -- an owner reading their OWN board was reported as a breach.
        'pager.sessions.index' => [
            'method' => 'getJson',
            'path' => fn (string $t, string $s): string => '/v1/pager-sessions',
            'body' => [],
            'targets' => false,
        ],
        'pager.sessions.store' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => '/v1/pager-sessions',
            'body' => ['order_reference' => 'A-1'],
            'targets' => false,
        ],
        'pager.sessions.ready' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => "/v1/pager-sessions/{$s}/ready",
            'body' => [],
            'targets' => true,
        ],
        'pager.sessions.renotify' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => "/v1/pager-sessions/{$s}/renotify",
            'body' => [],
            'targets' => true,
        ],
        'pager.sessions.complete' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => "/v1/pager-sessions/{$s}/complete",
            'body' => [],
            'targets' => true,
        ],
        'pager.sessions.cancel' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => "/v1/pager-sessions/{$s}/cancel",
            'body' => [],
            'targets' => true,
        ],
        'members.index' => [
            'method' => 'getJson',
            'path' => fn (string $t, string $s): string => '/v1/members',
            'body' => [],
            'targets' => false,
        ],
        'members.store' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => '/v1/members',
            'body' => ['email' => 'nobody-'.Str::random(8).'@example.test', 'role' => 'staff'],
            'targets' => false,
        ],
        'members.update' => [
            'method' => 'patchJson',
            'path' => fn (string $t, string $s): string => '/v1/members/'.Str::uuid7(),
            'body' => ['role' => 'manager'],
            'targets' => true,
        ],
        'members.destroy' => [
            'method' => 'deleteJson',
            'path' => fn (string $t, string $s): string => '/v1/members/'.Str::uuid7(),
            'body' => [],
            'targets' => true,
        ],
        'devices.store' => [
            'method' => 'postJson',
            'path' => fn (string $t, string $s): string => '/v1/devices',
            'body' => ['name' => 'pass', 'ability' => Ability::PagerRead->value, 'ttl_minutes' => 60],
            'targets' => false,
        ],
        'devices.destroy' => [
            'method' => 'deleteJson',
            'path' => fn (string $t, string $s): string => '/v1/devices/'.Str::uuid7(),
            'body' => [],
            'targets' => true,
        ],
    ];
}

it('covers every /v1 route in exactly one named set, so "full" is enforced and not promised', function (): void {
    // PROPERTY 1. This is the case that makes the rest of the file worth something: without
    // it, a new staff route simply would not appear in the cross-product below and nothing
    // would say so.
    $v1 = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/v1/') && $uri !== '/v1') {
            continue;
        }

        $v1[$route->getName() ?? '(unnamed) '.$uri] = $uri;
    }

    // The three surfaces, each named rather than inferred from a path prefix -- §11.3
    // requires public routes to be "explicitly marked and justified", and a prefix rule
    // would mark a future route public by where somebody put it.
    $staff = RouteAbilities::names();

    // The customer token path. No account, no membership, no role: their authorisation IS
    // the token, so an Ability would be meaningless (§4 › Tenancy, two surfaces).
    $customer = [
        'pager.customer.session',
        'pager.customer.status',
        'pager.customer.subscribe',
        'pager.customer.unsubscribe',
    ];

    // The auth surface runs BEFORE a principal exists -- and before a tenant does -- so
    // requiring an ability of it is circular. routes/auth.php carries the reasoning; the
    // names are listed here so that a route added to that file without being accounted for
    // fails this case rather than quietly joining a set that requires nothing of it.
    //
    // ⚠ BEING IN THIS SET ASSERTS NOTHING ABOUT WHO MAY CALL THE ROUTE, and for a while
    // that was the whole of the auth surface's authorisation story. It is a NAME
    // allowlist: it proves a route was accounted for, not that anything bounds it.
    // Task 5's security review walked through the gap -- every controller here gated on
    // `$request->user() instanceof User`, which a KDS device's bearer token satisfies, so a
    // `pager:read` kitchen screen reached all twelve of these. `DeviceTokenOnAuthSurfaceTest`
    // is the case that asserts something per route, and it enumerates the group from the
    // live router so it cannot fall behind this list.
    $auth = [
        'auth.login',
        'auth.logout',
        'auth.tenant',
        'auth.me',
        // Two-factor. `challenge` is the only one that does not need an ESTABLISHED
        // session, because it is the step that completes one -- it reads the pending user
        // id from the server-side session instead. (This used to read "the only one
        // reachable without a session", which the security review falsified in the most
        // direct way available: with a device bearer token, every route in this set was
        // reachable without one.)
        'auth.two-factor.enable',
        'auth.two-factor.confirm',
        'auth.two-factor.disable',
        'auth.two-factor.challenge',
        // Passkeys. Each ceremony is options-out then credential-back, and the challenge
        // between them is held server-side.
        'auth.passkeys.options',
        'auth.passkeys.register',
        'auth.passkeys.login-options',
        'auth.passkeys.login',
    ];

    $accounted = [...$staff, ...$customer, ...$auth];
    $unaccounted = array_values(array_diff(array_keys($v1), $accounted));

    expect($unaccounted)->toBe([], 'these /v1 routes are in no named set: '.implode(', ', $unaccounted));

    // And the reverse: a name in the map with no route behind it is a stale entry, which
    // would make RequireAbility's fail-closed branch unreachable for it.
    $phantom = array_values(array_diff($accounted, array_keys($v1)));

    expect($phantom)->toBe([], 'these names are mapped but route to nothing: '.implode(', ', $phantom));

    // Every staff route this file can drive. If these two disagree, the cross-product below
    // is silently skipping a route that IS mapped.
    expect(array_keys(staffRoutes()))->toEqualCanonicalizing($staff);

    // The two probes stay out of /v1 on purpose and are RouteSurfaceTest's business.
    expect(AppServiceProvider::PUBLIC_PATHS)->toBe(['/healthz', '/readyz']);
});

it('grants each principal exactly the abilities the matrix names, and nothing by inheritance', function (): void {
    // The array as a whole, pinned. Not a derivation -- a ladder computed from seniority
    // would make `device` a rung on it, and the whole point of `device` is that it is not.
    expect(RoleMatrix::all())->toBe([
        'platform_admin' => [],
        'owner' => [
            'pager:read', 'pager:create', 'pager:notify', 'pager:resolve',
            'members:read', 'members:write', 'devices:write',
        ],
        'manager' => [
            'pager:read', 'pager:create', 'pager:notify', 'pager:resolve',
            'members:read', 'members:write', 'devices:write',
        ],
        'staff' => ['pager:read', 'pager:create', 'pager:notify', 'pager:resolve'],
        'device' => ['pager:read'],
    ]);

    // THE EMPTY CELL IS THE IMPORTANT ONE. A platform admin holds no tenant ability at all,
    // which is QRPAGER-12 §5's "a platform admin is NOT a tenant bypass" expressed as data.
    expect(RoleMatrix::abilitiesFor(Principal::PlatformAdmin))->toBe([]);

    // A device holds ONE, and it is the only ability issuable to one.
    expect(RoleMatrix::abilitiesFor(Principal::Device))->toBe([Ability::PagerRead])
        ->and(Ability::issuableToDevice())->toBe([Ability::PagerRead]);

    // Staff cannot manage people or screens. This is the cell that makes the matrix
    // non-vacuous: without the member routes, no two principals would differ anywhere.
    foreach ([Ability::MembersRead, Ability::MembersWrite, Ability::DevicesWrite] as $ability) {
        expect(RoleMatrix::allows(Principal::Staff, $ability))->toBeFalse()
            ->and(RoleMatrix::allows(Principal::Manager, $ability))->toBeTrue();
    }
});

it('answers every principal × every staff route the way the matrix says, in its own tenant', function (): void {
    // PROPERTY 3, own-tenant column. Driven through HTTP, one request per cell.
    //
    // A 403 means RequireAbility refused. Anything else means it did not -- and this case
    // deliberately does NOT assert a specific success status, because what is under test is
    // the authorisation decision and not the handler: a mapped route may legitimately answer
    // 200, 201, 204, 404 (a uuid that does not exist) or 422 (a body this file did not
    // bother to make valid). Lumping those together is correct here and it is why the
    // assertion is "403 or not 403".
    foreach ([TenantRole::Owner, TenantRole::Manager, TenantRole::Staff] as $role) {
        foreach (staffRoutes() as $name => $route) {
            $tenant = $this->makeTenant();
            $this->actingForTenant($tenant, $role);
            $session = $this->makeSession($tenant);

            $principal = Principal::fromTenantRole($role);
            $ability = RouteAbilities::for($name);

            expect($ability)->not->toBeNull("{$name} is not in RouteAbilities");

            $expected = RoleMatrix::allows($principal, $ability);

            $method = $route['method'];
            $response = $this->{$method}(($route['path'])($tenant, $session), $route['body']);

            if ($expected) {
                expect($response->status())->not->toBe(
                    403,
                    "{$role->value} was refused {$name}, which the matrix grants",
                );
            } else {
                expect($response->status())->toBe(
                    403,
                    "{$role->value} was ALLOWED {$name}, which the matrix does not grant",
                );
            }
        }
    }
});

it('answers device × every staff route the way the matrix says, in its own tenant', function (): void {
    /*
     * PROPERTY 3, own-tenant column, FOURTH PRINCIPAL.
     *
     * The case above loops the three TENANT ROLES, which is where `§3` row 5's named matrix
     * ("5 principals × every /v1 route × both tenants") was only three-fifths driven:
     * `device` and `platform_admin` had their own files with HARD-CODED route lists, so the
     * router-iterating check that makes totality mechanical did not extend to them -- a
     * route added to `staffRoutes()` grew a cell for Owner, Manager and Staff and silently
     * none for the other two. Found by Task 5's QA review.
     *
     * Driven from `staffRoutes()` here, so a new route fails the suite for FIVE principals.
     * `DeviceTokenScopeTest` keeps its own cases: they assert the four BOUNDS of a token
     * (one capability, one tenant, an explicit expiry, the matrix as a ceiling), which is a
     * different question from "does the matrix's device row describe what happens".
     */
    // ONE TOKEN PER ROUTE means one `POST /v1/devices` per route, and that bucket is
    // 10/min/IP -- so the twelfth cell answered 429 and the case failed on the limiter
    // rather than on the matrix. Raised here because the limiter is NOT what this case is
    // about; `RateLimitTest` drives `devices-issue` to a real 429 on both of its dimensions.
    config(['ratelimit.buckets.devices-issue.per_ip.attempts' => 1000]);

    foreach (staffRoutes() as $name => $route) {
        $tenant = $this->makeTenant();

        // Issued by an OWNER, so every refusal below is the token's own scope rather than a
        // weak issuer -- the same control DeviceTokenScopeTest states.
        $this->actingForTenant($tenant, TenantRole::Owner);
        $session = $this->makeSession($tenant);

        $issued = $this->postJson('/v1/devices', [
            'name' => 'matrix-probe',
            'ability' => Ability::PagerRead->value,
            'ttl_minutes' => 60,
        ]);

        $issued->assertStatus(201);

        // Drop the session and present the token, which is what a kitchen screen does.
        auth()->forgetGuards();
        app('auth')->guard('web')->logout();

        $headers = ['Authorization' => 'Bearer '.$issued->json('device.token')];

        $ability = RouteAbilities::for($name);

        expect($ability)->not->toBeNull("{$name} is not in RouteAbilities");

        // BOTH HALVES, and the weaker wins -- which is RequestPrincipal::may()'s own rule.
        // The matrix must grant `Device` the ability AND the token must carry it; this token
        // carries exactly `pager:read`, so the second half is what bounds everything but
        // the board.
        $expected = RoleMatrix::allows(Principal::Device, $ability)
            && $ability === Ability::PagerRead;

        // getJson() takes headers as its SECOND argument and the others take them third,
        // so the call is branched rather than spread -- the same shape
        // DeviceTokenOnAuthSurfaceTest needs for the same reason.
        $path = ($route['path'])($tenant, $session);

        $response = $route['method'] === 'getJson'
            ? $this->getJson($path, $headers)
            : $this->{$route['method']}($path, $route['body'], $headers);

        if ($expected) {
            expect($response->status())->not->toBe(403,
                "a device token was refused {$name}, which the matrix and its abilities grant",
            );

            // ⚠ AND THE PERMITTED CELL ASSERTS ITS CONTENT, not just "not 403". The device
            // row has exactly ONE permitted cell -- the board -- and the §11 review of the
            // fix diff showed that `return [];` in PagerSessions::board() left this case
            // green. A capability that returns nothing is not a capability that works.
            if ($name === 'pager.sessions.index') {
                expect(str_contains((string) $response->getContent(), $session))->toBeTrue(
                    'the device token read a board that did not contain its own tenant\'s session',
                );
            }
        } else {
            expect($response->status())->toBe(403,
                "a device token was ALLOWED {$name}, which it does not hold",
            );
        }
    }
});

it('refuses every principal on every staff route in a tenant they do not belong to', function (): void {
    // PROPERTY 3, other-tenant column -- the cell that IS this task's named invariant.
    //
    // ⚠ BUT DO NOT CREDIT THIS CASE WITH IDOR, which both `§11` reviewers of the fix diff
    // flagged for the record. `staffRoutes()` builds the keyed paths from `Str::uuid7()`,
    // i.e. ids that do not exist -- so for `members.update`, `members.destroy` and
    // `devices.destroy` this column proves "a nonexistent id is refused", not "another
    // tenant's REAL id is refused". The real cross-tenant coverage is `MembersTest`'s case
    // that reads beta's actual membership id out of `GET /v1/members` and drives 404 on it,
    // `DeviceTokenScopeTest`'s revoke case with alpha's real token id, and `PagerIdorTest`.
    // What this case does prove, over every route and every principal, is the AUTHORISATION
    // half: a principal of one tenant is refused on the whole surface of another.
    //
    // The refusal is 401 and not 403, and the distinction is the design rather than an
    // accident: a principal with no membership in the target tenant resolves NO TENANT at
    // all, so RequireTenantScope answers before RequireAbility ever runs. There is no point
    // at which a cross-tenant request is authorised and then refused -- it is refused for
    // want of a tenant, which is a stronger property than a 403 would be.
    foreach ([TenantRole::Owner, TenantRole::Manager, TenantRole::Staff] as $role) {
        $mine = $this->makeTenant();
        $theirs = $this->makeTenant();
        $theirSession = $this->makeSession($theirs);

        // Authenticated as a member of $mine only.
        $this->actingForTenant($mine, $role);

        foreach (staffRoutes() as $name => $route) {
            $method = $route['method'];

            // The path is built against THEIR tenant's session id where the route takes
            // one, so this is a real cross-tenant attempt and not just an unselected
            // tenant.
            $response = $this->{$method}(($route['path'])($theirs, $theirSession), $route['body']);

            // TWO KINDS OF ROUTE, and conflating them made the first draft of this case
            // assert something false.
            //
            // A route that TARGETS a row -- the four transitions, the two keyed member
            // routes, the device revocation -- carries another tenant's id in its path, so
            // a success would be the breach and the assertion is on the status.
            //
            // A route that targets NOTHING -- the board read, the member list, creating a
            // session, issuing a device token -- has no cross-tenant request to make at
            // all: it acts in the tenant the RESOLVER chose, which is this actor's own. A
            // 200 there is correct and the first draft of this test called it a breach. So
            // for those the assertion is on the CONTENT: the answer must carry nothing of
            // the other tenant's, which is the invariant's own wording -- "cannot READ
            // another tenant's data" -- rather than a status code.
            if ($route['targets']) {
                foreach ([200, 201, 204] as $success) {
                    expect($response->status())->not->toBe(
                        $success,
                        "{$role->value} of one tenant got {$success} on {$name} aimed at another tenant",
                    );
                }

                continue;
            }

            $body = (string) $response->getContent();

            expect(str_contains($body, $theirs))->toBeFalse(
                "{$name} leaked another tenant's id to a {$role->value} who is not in it",
            );
            expect(str_contains($body, $theirSession))->toBeFalse(
                "{$name} leaked another tenant's session id to a {$role->value} who is not in it",
            );
        }

        // And nothing of theirs moved. The negative assertions above are about the answer;
        // this is about the data, which is what the invariant is actually written over.
        $status = withTenant($theirs, fn () => DB::table('pager_sessions')
            ->where('id', $theirSession)->value('status'));

        expect($status)->toBe('waiting', "a cross-tenant request changed another tenant's session");
    }
});

it('refuses device and platform_admin on every staff route of a tenant they cannot act in', function (): void {
    /*
     * PROPERTY 3, other-tenant column, THE REMAINING TWO PRINCIPALS -- which completes
     * `§3` row 5's "5 principals × every /v1 route × both tenants" from ONE source.
     *
     * Before this, `device` had 2 of 12 routes cross-tenant (`DeviceTokenScopeTest`) and
     * `platform_admin` had 8 of 12 (`PlatformAdminIsNotABypassTest`), both from route lists
     * hard-coded in their own files -- so the router-iterating check could not extend them
     * and a new route grew no cell for either. QA M-1.
     *
     * ⚠ THERE IS NO OWN-TENANT COLUMN FOR `platform_admin`, and that is recorded in
     * `PLAN.md §3.1` row 6 and `§0`'s blocker cell -- NOT in `§5`, which is what this
     * comment used to cite and no row says. It is not a gap in this file: a platform admin
     * holds NO membership, so there is no tenant that is theirs. `Principal::isTenantScoped()` says so and
     * `RoleMatrix`'s PlatformAdmin row is empty. Their whole row is this column.
     *
     * The two kinds of route are discriminated exactly as the case above discriminates
     * them, and for the same reason: a non-targeting route acts in whatever tenant the
     * RESOLVER chose, so a 200 there is correct and the assertion has to be on the content.
     */
    foreach (['device', 'platform_admin'] as $principal) {
        // ⚠ EACH ITERATION STARTS WITH NO RESOLVED GUARD, and this is `R-102`'s shape again
        // rather than a production concern. The `device` pass leaves the SANCTUM guard
        // holding the token it resolved, and Laravel's test client keeps the container
        // across requests where Octane flushes it at every boundary -- so the
        // `platform_admin` pass sent a login with no Authorization header and was answered
        // 403 by `RefuseDeviceToken`, because the guard still reported a token request.
        // Measured exactly that way. In production the guard is rebuilt per request and
        // resolves the bearer token from the header that is actually present.
        auth()->forgetGuards();
        session()->flush();

        $mine = $this->makeTenant();
        $theirs = $this->makeTenant();
        $theirSession = $this->makeSession($theirs);

        $headers = [];

        if ($principal === 'device') {
            // A token pinned to MY tenant, issued by an owner of it.
            $this->actingForTenant($mine, TenantRole::Owner);

            $issued = $this->postJson('/v1/devices', [
                'name' => 'cross-tenant-probe',
                'ability' => Ability::PagerRead->value,
                'ttl_minutes' => 60,
            ]);

            $issued->assertStatus(201);

            auth()->forgetGuards();
            app('auth')->guard('web')->logout();

            $headers = ['Authorization' => 'Bearer '.$issued->json('device.token')];
        } else {
            // A platform admin with no membership anywhere, which is the point of the flag.
            $admin = makeMatrixPlatformAdmin();

            $this->postJson('/v1/auth/login', [
                'email' => $admin['email'],
                'password' => $admin['password'],
            ])->assertStatus(200);
        }

        foreach (staffRoutes() as $name => $route) {
            $path = ($route['path'])($theirs, $theirSession);

            $response = $route['method'] === 'getJson'
                ? $this->getJson($path, $headers)
                : $this->{$route['method']}($path, $route['body'], $headers);

            if ($route['targets']) {
                foreach ([200, 201, 204] as $success) {
                    expect($response->status())->not->toBe($success,
                        "{$principal} got {$success} on {$name} aimed at another tenant",
                    );
                }

                continue;
            }

            $body = (string) $response->getContent();

            // ⚠ THE STATUS FIRST, FOR THE PRINCIPAL THAT HAS NO TENANT AT ALL. The §11
            // review of the fix diff found that the platform-admin non-targeting cells
            // asserted only that two ids were absent from the body -- and a platform admin
            // gets a 401 problem document, which can never contain either, so a
            // hypothetical 200 with an empty board would have passed. A platform admin
            // resolves NO tenant, so every one of these must refuse; the device token does
            // resolve one (its own), so its non-targeting cells legitimately answer 200 and
            // are bounded by the content assertions below instead.
            if ($principal === 'platform_admin') {
                expect($response->status())->toBe(401,
                    "a platform admin got {$response->status()} on {$name}, and it holds no tenant to act in",
                );
            }

            expect(str_contains($body, $theirs))->toBeFalse(
                "{$name} leaked another tenant's id to a {$principal}",
            );
            expect(str_contains($body, $theirSession))->toBeFalse(
                "{$name} leaked another tenant's session id to a {$principal}",
            );
        }

        // And nothing of theirs moved.
        $status = withTenant($theirs, fn () => DB::table('pager_sessions')
            ->where('id', $theirSession)->value('status'));

        expect($status)->toBe('waiting',
            "a cross-tenant request by a {$principal} changed another tenant's session",
        );
    }
});

it('refuses every staff route for a suspended tenant — R-081, and every route rather than one', function (): void {
    // R-081's review-by clause: "a test asserts that a suspended tenant's staff member
    // cannot reach any /v1 route". ANY, so the loop is over the whole staff surface rather
    // than one convenient route -- a per-route regression would be invisible in a
    // single-route case, which is the same argument security L-3 made about the resolver.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);
    $session = $this->makeSession($tenant);

    // Suspended AFTER the membership exists and AFTER a session exists, so the only thing
    // that changed is the tenant's status.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('tenants')->where('id', $tenant)
        ->update(['status' => TenantStatus::Suspended->value]), 'pgsql_owner');

    foreach (staffRoutes() as $name => $route) {
        $method = $route['method'];
        $response = $this->{$method}(($route['path'])($tenant, $session), $route['body']);

        expect($response->status())->toBe(
            401,
            "{$name} did not refuse a suspended tenant's owner",
        );

        // And the body says nothing about suspension, so that suspended is not
        // distinguishable from unknown or from not-yours.
        expect((string) $response->getContent())->not->toContain('suspend');
    }
});
