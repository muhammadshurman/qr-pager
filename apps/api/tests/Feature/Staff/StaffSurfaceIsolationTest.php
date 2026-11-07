<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use App\Platform\Http\Middleware\RequireAbility;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * 10a's NAMED INVARIANT
 * ================================================================================
 *
 * `QRPAGER-25 §0`, verbatim:
 *
 *   *"A staff member authenticated in tenant A can reach no route and no datum of tenant B.
 *   Proven by driving EVERY mounted staff route cross-tenant over HTTP and asserting the
 *   refusal — not by reading the code."*
 *
 * ================================================================================
 * WHAT THIS FILE ADDS OVER `RoleMatrixTest`, WHICH ALREADY SWEEPS THIS SURFACE
 * ================================================================================
 *
 * `RoleMatrixTest` is the Task 5 proof and it is strong: 5 principals × every staff route ×
 * own/other tenant. Three things it does NOT do, each named by its own comments, and each is
 * why this file exists rather than being a second copy of it.
 *
 * **(1) ITS ROUTE LIST IS HAND-WRITTEN.** `staffRoutes()` is a literal table; totality is
 * enforced by comparing its keys against `RouteAbilities::names()`, which is *also* a hand-
 * written map. Both are compared against the live router, so a route cannot go MISSING — but
 * the subject list is still a list somebody typed. **This file derives the subject list from
 * the ROUTER**: a `/v1` route is a staff route if and only if it carries `RequireAbility` in
 * `gatherMiddleware()`, which is the actual mechanism rather than a description of it. A
 * route added to `routes/v1.php` later is swept here with no edit to this file at all.
 *
 * **(2) ITS CROSS-TENANT IDS DO NOT EXIST.** Its own comment says so: *"`staffRoutes()`
 * builds the keyed paths from `Str::uuid7()`, i.e. ids that do not exist — so for
 * `members.update`, `members.destroy` and `devices.destroy` this column proves 'a
 * nonexistent id is refused', not 'another tenant's REAL id is refused'."* **Every id this
 * file aims across a tenant boundary is a REAL ROW in the other tenant**, created in this
 * test, read back to prove it exists, and then driven at. That is the difference between
 * proving a 404 for absence and proving a 404 for isolation.
 *
 * **(3) IT NEVER TESTS A USER WHO BELONGS TO BOTH TENANTS.** `actingForTenant()` makes one
 * user with one membership, and its docblock says a second call makes a second user. So
 * every cross-tenant case in the suite is *a stranger is refused*. **10a is the task that
 * makes a two-membership user a real user**: the shell shows a restaurant chooser and
 * `POST /v1/auth/tenant` is how it switches. A person who legitimately holds both
 * memberships, with A selected, is the actor most likely to reach B's row — they resolve a
 * tenant, so `RequireTenantScope` does not refuse them and the refusal has to come from the
 * scoped query. That case is the third one below and it is new.
 *
 * ================================================================================
 * MECHANICAL TOTALITY, AND WHAT FAILS WHEN A ROUTE IS ADDED
 * ================================================================================
 *
 * The sweep substitutes a real id per route PARAMETER NAME, not per route. There are three
 * names on this surface (`session`, `member`, `device`), so a route that reuses one is
 * covered automatically. A route with a parameter name nobody has filled **fails the first
 * case with a message naming it** — fail closed, in the shape `RequireAbility` itself uses.
 */

/**
 * Every `/v1` route the router mounts behind `RequireAbility`, derived and never listed.
 *
 * @return array<string, array{method: string, uri: string, params: list<string>}>
 */
function mountedStaffRoutes(): array
{
    $out = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/v1')) {
            continue;
        }

        if (! in_array(RequireAbility::class, $route->gatherMiddleware(), true)) {
            continue;
        }

        // The name is how a failure message identifies the route, and `RequireAbility`
        // refuses an unnamed route anyway -- so an unnamed one here is a defect in
        // routes/v1.php and is reported as itself rather than skipped.
        $name = $route->getName() ?? '(unnamed) '.$uri;

        $verb = array_values(array_filter(
            $route->methods(),
            static fn (string $m): bool => ! in_array($m, ['HEAD', 'OPTIONS'], true),
        ))[0] ?? 'GET';

        $out[$name] = [
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

/**
 * A body that passes validation for the routes that require one.
 *
 * Keyed by route name, and DELIBERATELY ALLOWED TO BE INCOMPLETE: a route with no entry
 * gets an empty body, which for a write route means a 422. A 422 is still a refusal, so the
 * invariant's assertion holds either way -- but a 422 proves validation and not isolation,
 * which is weaker than this file wants. So the first case below requires every route whose
 * verb is not GET to have an entry here, and fails naming the route when one does not.
 *
 * @return array<string, array<string, string|int>>
 */
function staffRouteBodies(): array
{
    return [
        'pager.sessions.store' => ['order_reference' => 'X-1'],
        'pager.sessions.ready' => [],
        'pager.sessions.renotify' => [],
        'pager.sessions.complete' => [],
        'pager.sessions.cancel' => [],
        'members.store' => ['email' => 'nobody-'.Str::random(10).'@example.test', 'role' => 'staff'],
        'members.update' => ['role' => 'manager'],
        'members.destroy' => [],
        'devices.store' => ['name' => 'kitchen', 'ability' => Ability::PagerRead->value, 'ttl_minutes' => 60],
        'devices.destroy' => [],
        // Task 10d's. `pager.sessions.history` is a GET and needs no entry.
        'pager.sessions.reissue' => [],
    ];
}

/**
 * Real rows in `$tenantId`, one per route parameter name on this surface.
 *
 * The session comes from `TestCase::makeSession()`, which is `protected`, so the caller
 * makes it and hands it in -- the two helpers below need nothing from the test case and are
 * plain functions. Every value is read back after it is written, so a filler that silently
 * failed to create anything cannot make the sweep vacuous, which is the failure mode of a
 * cross-tenant test whose "other tenant's id" turns out to name nothing.
 *
 * @return array<string, string>
 */
function realIdsIn(string $tenantId, string $sessionId): array
{
    $found = withTenant($tenantId, fn () => DB::table('pager_sessions')->where('id', $sessionId)->value('id'));

    expect($found)->toBe($sessionId, 'the fixture session was not created, so the sweep would be vacuous');

    return [
        'session' => $sessionId,
        'member' => fixtureMembership($tenantId),
        'device' => fixtureDeviceToken($tenantId),
    ];
}

/** A real `tenant_users` row in `$tenantId`, read back to prove it exists. */
function fixtureMembership(string $tenantId): string
{
    $userId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Other tenant staff',
        'email' => 'other-'.str_replace('-', '', $userId).'@example.test',
    ]);

    $id = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => TenantRole::Staff->value,
    ]), 'pgsql_owner');

    $found = withTenant($tenantId, fn () => DB::table('tenant_users')->where('id', $id)->value('id'));

    expect($found)->toBe($id, 'the fixture membership was not created, so the sweep would be vacuous');

    return $id;
}

/**
 * A real device token pinned to `$tenantId`.
 *
 * Issued through the real service, so the abilities pin is the one the application writes
 * rather than one this test invents -- `DeviceTokens::revoke()` matches on that pin, and a
 * hand-built row would prove nothing about the real comparison.
 */
function fixtureDeviceToken(string $tenantId): string
{
    $issuerId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $issuerId,
        'name' => 'Other tenant owner',
        'email' => 'issuer-'.str_replace('-', '', $issuerId).'@example.test',
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $issuerId,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $issued = app(DeviceTokens::class)->issue(
        issuer: User::query()->findOrFail($issuerId),
        tenantId: $tenantId,
        name: 'other tenant screen',
        ability: Ability::PagerRead,
        ttlMinutes: 60,
        actorIp: null,
    );

    $found = DB::table('personal_access_tokens')->where('id', $issued['id'])->value('id');

    expect($found)->toBe($issued['id'], 'the fixture device token was not created, so the sweep would be vacuous');

    return $issued['id'];
}

/** A user with a password and memberships in every tenant given. */
function staffUserInTenants(array $tenantIds, TenantRole $role = TenantRole::Owner): array
{
    $password = 'correct-horse-battery-staple';
    $userId = (string) Str::uuid7();
    $email = 'dual-'.str_replace('-', '', $userId).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Dual member',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    foreach ($tenantIds as $tenantId) {
        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => $role->value,
        ]), 'pgsql_owner');
    }

    return ['id' => $userId, 'email' => $email, 'password' => $password];
}

/* -------------------------------------------------------------------------------- */

it('derives the staff surface from the router, and every route is covered by this file', function (): void {
    // CASE 1. The guard that makes the two cases below worth something. Without it a route
    // added to routes/v1.php would simply not be swept, and nothing would say so.
    $routes = mountedStaffRoutes();

    /*
     * Not vacuous: the surface is not empty, and the count is asserted as an exact value
     * rather than "more than zero" -- a derivation that silently returned one route would
     * otherwise pass. 14 at 10a: 6 pager, 4 members, 3 devices, 1 tenant.
     *
     * 16 at 10d: `pager.sessions.history` and `pager.sessions.reissue`. Both are swept here
     * for their ability and their path, and `history` additionally has its OWN isolation file
     * — `PagerHistoryIsolationTest` — because this sweep structurally cannot reach what that
     * route leaks: it takes no path parameter, so there is nothing foreign to substitute, and
     * the cross-tenant question is about the CONTENT of a 200 rather than a refusal.
     */
    expect($routes)->toHaveCount(16, 'the derived staff surface changed size: '.implode(', ', array_keys($routes)));

    // The three parameter names this file fills. A route carrying a fourth fails here,
    // naming it, rather than being swept with an unsubstituted placeholder in its path.
    $fillable = ['session', 'member', 'device'];
    $unfillable = [];
    $bodyless = [];
    $bodies = staffRouteBodies();

    foreach ($routes as $name => $route) {
        foreach ($route['params'] as $param) {
            if (! in_array($param, $fillable, true)) {
                $unfillable[] = $name.' {'.$param.'}';
            }
        }

        if ($route['method'] !== 'getJson' && ! array_key_exists($name, $bodies)) {
            $bodyless[] = $name;
        }
    }

    expect($unfillable)->toBe([], 'add a real-id filler in realIdsIn() for: '.implode(', ', $unfillable));
    expect($bodyless)->toBe([], 'add a valid body in staffRouteBodies() for: '.implode(', ', $bodyless));

    // And every unnamed route is a defect rather than something to skip: RequireAbility
    // refuses one at runtime, so one here would be a 403 that looks like authorisation.
    $unnamed = array_values(array_filter(array_keys($routes), fn (string $n): bool => str_starts_with($n, '(unnamed)')));

    expect($unnamed)->toBe([], 'these staff routes have no name: '.implode(', ', $unnamed));
});

it('accounts for every /v1 route the sweep does NOT cover, with the test that does', function (): void {
    /*
     * ⚠ `§11` SECURITY L-7 / QA L-1: THE DERIVATION MISSED `/v1/auth/*` AND SAID NOTHING.
     *
     * `mountedStaffRoutes()` selects routes behind `RequireAbility`, which is a real mechanism
     * and a NARROWER set than "every mounted staff route" — the words 10a's invariant is
     * written in. Twelve `/v1/auth/*` routes carry no ability middleware (they are how a
     * credential is presented, so there is no ability to check yet), and four customer pager
     * routes are a different surface entirely. All sixteen were simply absent from the sweep,
     * and absent in the way that costs most: the file's own case asserted that the derivation
     * covered everything *it* derived.
     *
     * One of the sixteen is a genuine cross-tenant lever — `POST /v1/auth/tenant` takes a
     * `tenant_id` in the BODY, so the path-parameter machinery above cannot reach it at all.
     * The case after this one drives it.
     *
     * So the complement is ENUMERATED here with a reason and a named covering test each. This
     * is not the sweep, and it does not pretend to be: it is the mechanism that makes a route
     * added outside `RequireAbility` fail a test instead of quietly joining a blind spot.
     */
    $covered = array_keys(mountedStaffRoutes());

    /**
     * Every `/v1` route NOT behind `RequireAbility`, why, and what covers it.
     *
     * @var array<string, string>
     */
    $outside = [
        // THE CREDENTIAL SURFACE. No ability is checkable before a credential is presented,
        // which is why `RequireAbility` is absent rather than forgotten.
        'auth.login' => 'presents a credential · AuthLoginTest, SessionPolicyTest',
        'auth.logout' => 'destroys the session · SessionPolicyTest, StaffSessionPolicyTest',
        'auth.me' => 'the caller\'s OWN identity, never another tenant\'s · StaffContractTest',
        'auth.tenant' => 'selects a tenant · the case below, PlatformAdminIsNotABypassTest',
        'auth.passkeys.options' => 'registration options for the caller · PasskeyCeremonyTest',
        'auth.passkeys.register' => 'registers a credential for the caller · PasskeyCeremonyTest',
        'auth.passkeys.login-options' => 'pre-session, by design · PasskeyCeremonyTest',
        'auth.passkeys.login' => 'presents a credential · PasskeyCeremonyTest',
        'auth.two-factor.enable' => 'the caller\'s own second factor · TwoFactorTest',
        'auth.two-factor.confirm' => 'the caller\'s own second factor · TwoFactorTest',
        'auth.two-factor.disable' => 'the caller\'s own second factor · TwoFactorTest',
        'auth.two-factor.challenge' => 'completes a login · TwoFactorTest, StaffContractTest',
        // THE CUSTOMER SURFACE. A different principal (a QR token, not a staff session), and
        // `PagerIdorTest` is the file that drives IT across the boundary.
        'pager.customer.session' => 'customer surface, token principal · PagerIdorTest',
        'pager.customer.status' => 'customer surface, token principal · PagerIdorTest',
        'pager.customer.subscribe' => 'customer surface, token principal · PagerIdorTest',
        'pager.customer.unsubscribe' => 'customer surface, token principal · PagerIdorTest',
    ];

    $all = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/v1')) {
            continue;
        }

        $all[] = $route->getName() ?? '(unnamed) '.$uri;
    }

    sort($all);

    // ⚠ EXACT ON BOTH SIDES AND ON THE TOTAL. 16 swept + 16 accounted for = 32 `/v1` routes.
    // A route added anywhere fails one of these three, and a route MOVED from one set to the
    // other fails two of them. (14 + 16 = 30 at 10a; 10d added two swept routes.)
    expect($all)->toHaveCount(32, 'the /v1 surface changed size: '.implode(', ', $all));
    expect($covered)->toHaveCount(16);
    expect($outside)->toHaveCount(16);

    $unaccounted = array_values(array_diff($all, $covered, array_keys($outside)));

    expect($unaccounted)->toBe([], 'these /v1 routes are in neither the sweep nor the accounted list: '.implode(', ', $unaccounted));

    // And nothing claims to be in both, which would let a route be "covered" by a sentence
    // while the sweep stopped reaching it.
    $both = array_values(array_intersect($covered, array_keys($outside)));

    expect($both)->toBe([], 'these routes are both swept and excused: '.implode(', ', $both));

    /*
     * Every excuse names a covering test rather than only a reason -- a reason alone is the
     * shape of a deferral, and this list is an account of coverage.
     *
     * ⚠ `str_contains(...)->toBeTrue($message)` AND NOT `toContain($needle, $message)`.
     * Pest's `toContain` takes a VARIADIC list of needles and no message, so the two-argument
     * form asserts that the subject contains the failure message too -- which is how the
     * first version of this loop failed with *"To contain: the entry for auth.login gives no
     * covering test"*. In the NEGATED form the same mistake is worse and silent: `not
     * ->toContain($x, $message)` passes as long as the message is absent, so a guard written
     * that way is always green.
     */
    foreach ($outside as $name => $why) {
        expect(str_contains($why, '·'))->toBeTrue("the entry for {$name} gives no covering test");
    }
});

it('refuses POST /v1/auth/tenant aimed at a tenant the caller is not in, which no path parameter can reach', function (): void {
    /*
     * ⚠ THE ONE ROUTE OUTSIDE THE SWEEP THAT IS A REAL CROSS-TENANT LEVER — `§11` SECURITY
     * L-7 / QA L-1.
     *
     * `POST /v1/auth/tenant` takes its `tenant_id` in the BODY, so the sweep above — which
     * substitutes real ids into path PARAMETERS — cannot reach it however many routes it
     * derives. And it is the highest-value target on the staff surface: succeeding here would
     * put beta's tenant id into alpha's session, after which every scoped query in the
     * application would run against beta legitimately.
     *
     * Driven with beta's REAL id, by two actors: a member of alpha only, and the
     * two-membership user that 10a makes real (selecting a third tenant they are in no
     * relation to). 404 both times — the row is invisible, which is the same answer the rest
     * of the surface gives — and no datum of beta's in either body.
     */
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $gamma = $this->makeTenant();

    // A MEMBER OF ALPHA ONLY.
    $stranger = staffUserInTenants([$alpha]);

    $this->postJson('/v1/auth/login', [
        'email' => $stranger['email'],
        'password' => $stranger['password'],
    ])->assertStatus(200);

    $refused = $this->postJson('/v1/auth/tenant', ['tenant_id' => $beta]);

    $refused->assertStatus(404);
    expect(str_contains((string) $refused->getContent(), $beta))->toBeFalse(
        'the refusal echoed the tenant id back, which is a membership oracle',
    );

    // ⚠ AND THE SESSION DID NOT MOVE. The status is about the answer; this is about the
    // state, and a 404 that switched the session anyway is the defect this case is for.
    $me = $this->getJson('/v1/auth/me');

    $me->assertStatus(200);
    expect($me->json('active_tenant'))->toBe($alpha, 'a refused switch moved the session anyway');

    $this->postJson('/v1/auth/logout')->assertStatus(204);

    // THE TWO-MEMBERSHIP USER, SWITCHING TO A THIRD TENANT. The actor who resolves a tenant
    // legitimately is the one most likely to reach another's row -- CASE 3's argument, applied
    // to the switch itself.
    $both = staffUserInTenants([$alpha, $beta]);

    $this->postJson('/v1/auth/login', [
        'email' => $both['email'],
        'password' => $both['password'],
    ])->assertStatus(200);

    $this->postJson('/v1/auth/tenant', ['tenant_id' => $alpha])->assertStatus(204);

    $refusedThird = $this->postJson('/v1/auth/tenant', ['tenant_id' => $gamma]);

    $refusedThird->assertStatus(404);
    expect(str_contains((string) $refusedThird->getContent(), $gamma))->toBeFalse(
        'the refusal echoed the third tenant id back',
    );

    // Still alpha, not gamma and not beta: a refused switch must not land the session
    // anywhere at all.
    expect($this->getJson('/v1/auth/me')->json('active_tenant'))->toBe($alpha);

    // THE POSITIVE CONTROL. Beta is a tenant this user IS in, so the switch must SUCCEED --
    // without it every assertion above would pass against a route that refuses everything,
    // which is the chooser broken rather than the boundary held.
    $this->postJson('/v1/auth/tenant', ['tenant_id' => $beta])->assertStatus(204);
    expect($this->getJson('/v1/auth/me')->json('active_tenant'))->toBe($beta);
});

it('refuses a staff member of tenant A on every mounted staff route aimed at tenant B\'s REAL rows', function (): void {
    // CASE 2. The invariant, over the derived surface, with ids that exist.
    $theirs = $this->makeTenant();
    $theirIds = realIdsIn($theirs, $this->makeSession($theirs));
    $bodies = staffRouteBodies();

    foreach ([TenantRole::Owner, TenantRole::Manager, TenantRole::Staff] as $role) {
        $actor = $this->makeTenant();
        $this->actingForTenant($actor, $role);

        foreach (mountedStaffRoutes() as $name => $route) {
            $path = $route['uri'];
            $sent = [];

            foreach ($route['params'] as $param) {
                // ⚠ AN ASSERTION AND NOT A SUBSCRIPT, which bin/staff-isolation-revert-proof
                // forced. Written as `$theirIds[$param]`, a parameter with no filler raised
                // an `ErrorException` for an undefined key -- and `_revert-proof.sh` rejects
                // an exception-red as evidence, correctly: it is the harness breaking rather
                // than a guard being removed. The same unfilled parameter now fails as a
                // named assertion, so the red says what is wrong.
                $id = $theirIds[$param] ?? null;

                expect($id)->toBeString("{$name} takes a {{$param}} that realIdsIn() does not fill");

                $path = str_replace('{'.$param.'}', (string) $id, $path);
                $sent[] = $param;
            }

            // No placeholder may survive. A path still carrying `{session}` would be a
            // routing miss -- a 404 from the fallback -- which reads exactly like the
            // refusal this case is asserting. That is the vacuous-pass shape, so it is
            // excluded by assertion and not by inspection.
            expect(str_contains($path, '{'))->toBeFalse("the path for {$name} kept a placeholder: {$path}");

            $response = $this->{$route['method']}($path, $bodies[$name] ?? []);

            if ($route['params'] !== []) {
                // A route that names another tenant's REAL row must not succeed. This is
                // the half RoleMatrixTest could not make, because its ids named nothing.
                expect(in_array($response->status(), [200, 201, 204], true))->toBeFalse(
                    "{$role->value} of one tenant got {$response->status()} on {$name} aimed at another tenant's real row",
                );
            }

            // And no answer, on any route, may carry an identifier belonging to the other
            // tenant. A parameterless route acts in the actor's own tenant and a 200 there
            // is correct -- so for those this content assertion is the whole of it.
            //
            // ⚠ MINUS THE IDS THIS REQUEST ITSELF SENT, AND THE FIRST VERSION OF THIS CASE
            // FAILED ON EXACTLY THAT. `devices.destroy` answers a problem+json document
            // whose `instance` member is the request path -- which, for a keyed route, is
            // the path this test just built out of the other tenant's id. So the body
            // "carried" an id the caller had typed into the URL a moment earlier. Echoing
            // the caller's own input back to them discloses nothing they did not already
            // have, and `ProblemDocument` has carried `instance` since Task 2; treating it
            // as a leak would be asserting that a 404 must not say which path 404'd.
            //
            // The honest assertion is therefore over the ids the request did NOT contain,
            // which is what a leak would actually look like. The ids that WERE sent are
            // covered by the status assertion above instead: the route refused them.
            $body = (string) $response->getContent();
            $candidates = ['tenant' => $theirs] + $theirIds;

            foreach ($sent as $param) {
                unset($candidates[$param]);
            }

            // Not vacuous: something must still be checked on every route. The tenant id is
            // never a path parameter anywhere on this surface, so it is always in the set.
            expect($candidates)->not->toBe([], "nothing was left to assert about {$name}'s body");

            foreach ($candidates as $label => $id) {
                expect(str_contains($body, $id))->toBeFalse(
                    "{$name} leaked another tenant's {$label} id to a {$role->value} who is not in it",
                );
            }
        }
    }

    // Nothing of theirs moved. The assertions above are about answers; this is about the
    // data, which is what the invariant is written over.
    $after = withTenant($theirs, fn (): array => [
        'session' => DB::table('pager_sessions')->where('id', $theirIds['session'])->value('status'),
        'members' => DB::table('tenant_users')->where('id', $theirIds['member'])->count(),
    ]);

    expect($after['session'])->toBe('waiting', 'a cross-tenant sweep changed another tenant\'s session');
    expect($after['members'])->toBe(1, 'a cross-tenant sweep removed another tenant\'s membership');

    $tokenSurvived = DB::table('personal_access_tokens')->where('id', $theirIds['device'])->count();

    expect($tokenSurvived)->toBe(1, 'a cross-tenant sweep revoked another tenant\'s device token');
});

it('refuses a user who belongs to BOTH tenants, with A selected, on every route aimed at B', function (): void {
    // CASE 3. THE ACTOR 10a MAKES REAL, AND THE ONE THE SUITE HAS NEVER HAD.
    //
    // Every other cross-tenant case in this repository uses a stranger: a principal with no
    // membership in the target tenant resolves NO tenant, so RequireTenantScope answers 401
    // before any query runs. That is a strong property and it is not this one.
    //
    // This actor holds BOTH memberships and has SELECTED A. So a tenant resolves, the
    // ability check passes, and the only thing left between them and B's row is the scoped
    // query -- which is exactly where an isolation defect would live. The refusal must come
    // from row-level security making B's row invisible inside A's scope, and the answer must
    // therefore be 404 rather than 401.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaIds = realIdsIn($beta, $this->makeSession($beta));
    $user = staffUserInTenants([$alpha, $beta]);
    $bodies = staffRouteBodies();

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    // Two memberships, so nothing is selected yet and the resolver refuses to pick. Proven
    // rather than assumed, because it is what makes the selection below meaningful.
    $this->getJson('/v1/tenant')->assertStatus(401);

    $this->postJson('/v1/auth/tenant', ['tenant_id' => $alpha])->assertStatus(204);

    // The selection took effect, and it chose ALPHA. Without this the case could pass with
    // no tenant resolved at all, which is case 2 again wearing a different costume.
    $this->getJson('/v1/tenant')->assertStatus(200)->assertJsonPath('tenant.slug', fn (string $slug): bool => $slug !== '');

    $keyed = 0;

    foreach (mountedStaffRoutes() as $name => $route) {
        if ($route['params'] === []) {
            // A parameterless route cannot be aimed at beta at all: it acts in the resolved
            // tenant, which is alpha. Asserted as content, as in case 2.
            $body = (string) $this->{$route['method']}($route['uri'], $bodies[$name] ?? [])->getContent();

            foreach (['tenant' => $beta] + $betaIds as $label => $id) {
                expect(str_contains($body, $id))->toBeFalse(
                    "{$name} leaked beta's {$label} id to a member of both who selected alpha",
                );
            }

            continue;
        }

        $path = $route['uri'];

        foreach ($route['params'] as $param) {
            // An assertion, not a subscript -- see the note in the case above.
            $id = $betaIds[$param] ?? null;

            expect($id)->toBeString("{$name} takes a {{$param}} that realIdsIn() does not fill");

            $path = str_replace('{'.$param.'}', (string) $id, $path);
        }

        expect(str_contains($path, '{'))->toBeFalse("the path for {$name} kept a placeholder: {$path}");

        $response = $this->{$route['method']}($path, $bodies[$name] ?? []);
        $keyed++;

        // 404 EXACTLY, and not merely "not a success". A 403 would mean the ability check
        // refused -- it did not, this actor is an owner of alpha -- and a 401 would mean no
        // tenant resolved, which the assertion above rules out. 404 is the only answer that
        // means *beta's row is invisible inside alpha's scope*, which is the invariant.
        expect($response->status())->toBe(
            404,
            "{$name} answered {$response->status()} for beta's real row to a member of both who selected alpha",
        );
    }

    // Not vacuous: the keyed routes are the whole point of this case, so their number is
    // asserted. 7 at 10a -- four transitions, two keyed member routes, one device revoke.
    // 8 at 10d: `pager.sessions.reissue`, which takes a session id in its path and is
    // therefore a real cross-tenant lever. (`pager.sessions.history` takes NO path parameter,
    // so it is not keyed and this case cannot reach what it leaks -- `PagerHistoryIsolationTest`
    // drives that over the body of a 200 instead.)
    expect($keyed)->toBe(8, 'the keyed-route count changed, so this case swept a different surface');

    // And beta is untouched.
    $after = withTenant($beta, fn () => DB::table('pager_sessions')->where('id', $betaIds['session'])->value('status'));

    expect($after)->toBe('waiting', 'a member of both tenants changed beta while acting in alpha');
});
