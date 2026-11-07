<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Memberships;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * "SESSION FIXATION AND CSRF TESTS" -- PLAN.md §3 row 5, verbatim
 * ================================================================================
 *
 * Two of the gate's clauses, and both are the kind that are easy to satisfy on paper.
 * "Laravel migrates the session on login" is true and is not evidence: what has to hold is
 * that the id a caller had BEFORE logging in cannot be used afterwards, on every path that
 * raises privilege -- and this task adds two such paths beyond login, the tenant selection
 * and (at Task 10's screen) the 2FA challenge.
 *
 * CSRF NEEDS SAYING OUT LOUD, because Laravel's ValidateCsrfToken returns early while
 * running tests: `runningUnitTests()` is one of its first conditions. So a test that simply
 * posts without a token proves nothing at all -- it proves the middleware skipped itself.
 * The case below re-enables it deliberately, which is the only way to show it bites.
 */

/** A user with a known password, through the owner connection. */
function makeUserWithPassword(string $password = 'correct-horse-battery-staple'): array
{
    $id = (string) Str::uuid7();
    $email = 'staff-'.str_replace('-', '', $id).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Session Staff',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

it('moves the session id on login, and the pre-login id no longer authenticates', function (): void {
    $tenant = $this->makeTenant();
    $user = makeUserWithPassword();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $user['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    // Touch the auth surface first so a session exists to be fixated.
    $this->getJson('/v1/auth/me')->assertStatus(401);

    $before = session()->getId();

    expect($before)->not->toBeEmpty();

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)
        ->assertJsonPath('user.id', $user['id'])
        ->assertJsonPath('memberships.0.tenant_id', $tenant);

    $after = session()->getId();

    // THE FIXATION ASSERTION. An attacker who fixes a victim's session id before they log
    // in must not hold an authenticated session afterwards, and a moved id is what prevents
    // it.
    expect($after)->not->toBe($before, 'the session id survived a login');

    // AND THE STRONGER HALF: the old id is not merely different, it carries nothing. Driven
    // by putting the old id back and asking who we are.
    //
    // Read out of the session HANDLER rather than by re-pointing the live session object at
    // the old id: setId() then start() leaves the CURRENT session's in-memory attributes in
    // place, so that version of this assertion was reading the new session's data under the
    // old id's name and would have passed for the wrong reason. The handler is the store,
    // which is what an attacker presenting the old cookie would actually hit.
    $payload = app('session')->getHandler()->read($before);

    $decoded = $payload === '' ? [] : (unserialize(base64_decode($payload, true) ?: '') ?: []);
    $decoded = is_array($decoded) ? $decoded : [];

    $authKeys = array_values(array_filter(
        array_keys($decoded),
        static fn ($key): bool => is_string($key) && str_starts_with($key, 'login_web_'),
    ));

    expect($authKeys)->toBe([], 'the pre-login session record carries an authenticated user')
        ->and($decoded[ActiveTenant::SESSION_KEY] ?? null)->toBeNull(
            'the pre-login session record carries a selected tenant',
        );
});

it('moves the session id again when the acting tenant changes, because that is a privilege change', function (): void {
    // THE PATH THAT IS EASY TO MISS. Login is the obvious fixation point; switching tenant
    // is the one this task adds, and it changes what the session may reach -- so the same
    // rule applies and ActiveTenant::select() migrates the session for that reason.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $user = makeUserWithPassword();

    foreach ([$alpha, $beta] as $tenant) {
        withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'user_id' => $user['id'],
            'role' => TenantRole::Owner->value,
        ]), 'pgsql_owner');
    }

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    // Two memberships, so the login selected nothing and the resolver refuses to pick.
    $this->getJson('/v1/pager-sessions')->assertStatus(401);

    $before = session()->getId();

    $this->postJson('/v1/auth/tenant', ['tenant_id' => $alpha])->assertStatus(204);

    expect(session()->getId())->not->toBe($before, 'the session id survived a tenant change');

    // And the selection took effect, which is what makes the assertion above about a
    // privilege change rather than about a no-op.
    $this->getJson('/v1/pager-sessions')->assertStatus(200);
});

it('empties the session on logout rather than only forgetting the user', function (): void {
    $tenant = $this->makeTenant();
    $user = makeUserWithPassword();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $user['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $this->getJson('/v1/pager-sessions')->assertStatus(200);

    $this->postJson('/v1/auth/logout')->assertStatus(204);

    // The selected tenant went with the session, rather than waiting to be re-verified
    // against a user who is no longer there.
    expect(session()->get(ActiveTenant::SESSION_KEY))->toBeNull();

    $this->getJson('/v1/pager-sessions')->assertStatus(401);

    // And logout answers the same way with no session at all: neither an oracle nor
    // exhaustible.
    $this->postJson('/v1/auth/logout')->assertStatus(204);
});

it('refuses a state-changing staff request with no CSRF token, with the middleware really enabled', function (): void {
    // THE CASE THAT HAS TO SAY SO EXPLICITLY. ValidateCsrfToken::handle() returns early
    // when runningUnitTests() is true, so without this the assertion would be measuring the
    // middleware skipping itself -- the same vacuous shape §4a records four of.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    ValidateCsrfToken::except([]);

    $this->app->instance('middleware.csrf.enabled', true);

    // Re-enable it by removing the test-mode escape: the middleware checks
    // $this->runningUnitTests(), so the honest way to drive it is to call it directly with
    // a real request rather than to pretend the app is not under test.
    $middleware = new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    };

    // ONE session instance for both halves, and the token read off that same instance: the
    // first version took the token from session() after the middleware had rotated it, so
    // the positive control failed on a token that was merely stale.
    $session = session()->driver();
    $session->start();

    $request = Request::create('/v1/pager-sessions', 'POST', ['order_reference' => 'A-1']);
    $request->setLaravelSession($session);

    $refused = null;

    try {
        $middleware->handle($request, fn () => response()->json(['reached' => true]));
    } catch (Throwable $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull('a POST with no CSRF token was not refused')
        ->and($refused::class)->toContain('TokenMismatch');

    // AND THE POSITIVE CONTROL: the same middleware accepts the request once the token is
    // present, so the refusal above is the token check and not the harness.
    $accepted = Request::create('/v1/pager-sessions', 'POST', [
        'order_reference' => 'A-1',
        '_token' => $session->token(),
    ]);
    $accepted->setLaravelSession($session);

    $response = $middleware->handle($accepted, fn () => response()->json(['reached' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('applies CSRF to the staff surface and not to a bearer-token request', function (): void {
    // The two surfaces, and the difference is not an oversight. CSRF exists because a
    // browser attaches a cookie to a cross-site request automatically; a bearer token is
    // attached by the client deliberately, so there is nothing to forge. §4 › Tenancy
    // requires the two never to be confused, and this is one place they genuinely differ.
    $tenant = $this->makeTenant();

    // Read off the ROUTER'S GROUP DEFINITIONS and not off the route.
    //
    // gatherMiddleware() returns a route's own middleware plus the GROUP NAMES it is on --
    // 'api', 'customer' -- and not the expanded class list, which is why the first version
    // of this assertion failed on a staff route that genuinely does have CSRF. It is also
    // why AppServiceProvider::pathsOnGroup() asks about group MEMBERSHIP rather than about
    // classes. The expansion lives in the router's groups, so that is what gets asked.
    $groups = app('router')->getMiddlewareGroups();

    $staffRoute = app('router')->getRoutes()->getByName('pager.sessions.store');
    $customerRoute = app('router')->getRoutes()->getByName('pager.customer.status');
    $authRoute = app('router')->getRoutes()->getByName('auth.login');

    expect($staffRoute)->not->toBeNull()
        ->and($customerRoute)->not->toBeNull()
        ->and($authRoute)->not->toBeNull();

    expect($staffRoute->gatherMiddleware())->toContain('api')
        ->and($customerRoute->gatherMiddleware())->toContain('customer')
        ->and($authRoute->gatherMiddleware())->toContain('auth-surface');

    expect($groups['api'] ?? [])->toContain(ValidateCsrfToken::class)
        ->and($groups['auth-surface'] ?? [])->toContain(ValidateCsrfToken::class)
        ->and($groups['customer'] ?? [])->not->toContain(ValidateCsrfToken::class);

    expect($tenant)->not->toBeEmpty();
});

it('renders a CSRF refusal as 419 with a problem document, through the real kernel', function (): void {
    /*
     * THE HALF THE CASE ABOVE CANNOT REACH, and the defect it was hiding.
     *
     * That case instantiates `ValidateCsrfToken` and asserts the EXCEPTION CLASS, which is
     * the right way to prove the middleware is really enabled -- and it means no test ever
     * saw the STATUS the application answers with. `QRPAGER-12 §10.4(c)` claimed "a test
     * drives a state-changing route without a token and asserts 419". Nothing did.
     *
     * Measured at the `§11` security review: `ProblemRenderer::fromStatus()` had no `419`
     * arm, so a CSRF rejection fell through to `ProblemType::ServerError` and the API
     * answered **500 `server-error`** to a request it had correctly refused. And because
     * Laravel keeps `TokenMismatchException` on its internal don't-report list, nothing was
     * logged either: the refusal was invisible to an operator and misreported to the
     * client, which a client reading 5xx responds to by RETRYING rather than by fetching a
     * fresh token.
     *
     * Driven through the kernel here -- container-bound rather than instantiated -- so the
     * thing under test is the status the router, the middleware stack and the renderer
     * produce together.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    ValidateCsrfToken::except([]);

    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $refused = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-7']);

    $refused->assertStatus(419)
        ->assertJsonPath('type', 'https://qrpager.dev/problems/csrf-mismatch')
        ->assertJsonPath('status', 419)
        ->assertJsonPath('instance', '/v1/pager-sessions');

    // A PROBLEM DOCUMENT AND NOTHING ELSE: the same four keys as every other refusal, no
    // exception class, no stack frame and no mention of the token or the session.
    expect(array_keys($refused->json()))->toBe(['type', 'title', 'status', 'instance']);

    $body = strtolower((string) $refused->getContent());

    foreach (['tokenmismatch', 'exception', 'vendor', 'csrf_token', 'laravel_session'] as $leak) {
        expect(str_contains($body, $leak))->toBeFalse("the CSRF refusal body carried {$leak}");
    }

    // AND THE POSITIVE CONTROL: the same route, same bound middleware, WITH a valid token
    // succeeds -- so the 419 above is the token's absence and not the route being broken.
    $session = session()->driver();
    $session->start();

    $allowed = $this->withSession([])
        ->postJson('/v1/pager-sessions', [
            'order_reference' => 'A-8',
            '_token' => csrf_token(),
        ]);

    expect($allowed->status())->toBe(201,
        'a request carrying a valid CSRF token was refused, so the 419 above proves nothing',
    );
});

it('offers no suspended tenant and never names one as the active tenant', function (): void {
    /*
     * TWO FINDINGS IN ONE STATE, which is why they are driven together.
     *
     * QA L-4: `identity()` filters suspended memberships out of `login` and `me` with a
     * `continue`, and that line was uncovered -- "only active tenants are offered" was an
     * argument in a comment, not a tested property. Given `R-081`'s own reasoning about
     * suspension not being an oracle, it is worth pinning.
     *
     * SEC L-5: `active_tenant` was read straight from the session, so a caller whose
     * selected tenant had since been suspended got `{"memberships":[],"active_tenant":
     * "<suspended id>"}` -- the two fields contradicting each other, and a client offering a
     * tenant that answers 401 on every other route.
     *
     * ⚠ NO HTTP REQUEST BEFORE THE SUSPENSION, and that ordering is forced rather than
     * chosen. `Memberships` memoises per user for the life of a request
     * (`QRPAGER-12 §10.5`), and in this suite the container outlives the request -- `R-102`.
     * Written the other way round, with a baseline `me` call first, the membership set read
     * during that call is still answered to the second call and the case fails on stale data
     * rather than on behaviour: measured, 2 memberships instead of 1. Neither
     * `app()->forgetScopedInstances()` nor `forgetInstance(Memberships::class)` cleared it
     * on this path, so the honest fix is to not need the flush, which is the same reason
     * `RoleMatrixTest`'s suspended case makes no request first.
     *
     * The baseline that matters is kept, and it is a stronger one: the membership really IS
     * returned by the cross-tenant function, so what removes it from the payload is the
     * filter and not an absent row.
     */
    $live = $this->makeTenant();
    $this->actingForTenant($live, TenantRole::Owner);

    $userId = (string) auth()->id();
    $suspended = $this->makeTenant();

    withTenant($suspended, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $suspended,
        'user_id' => $userId,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    withTenant($suspended, fn () => DB::connection('pgsql_owner')->table('tenants')
        ->where('id', $suspended)
        ->update(['status' => TenantStatus::Suspended->value]), 'pgsql_owner');

    // THE BASELINE: both memberships exist and both are visible to the named cross-tenant
    // read, one active and one not. So the payload below is the filter's work.
    $all = app(Memberships::class)->for($userId);

    expect($all)->toHaveCount(2)
        ->and(array_map(fn ($membership): bool => $membership->tenantIsActive(), $all))
        ->toBe([true, false]);

    // Select the suspended one deliberately, which is the state that produced the
    // contradiction: the session holds it, and it is no longer offerable.
    session()->put(ActiveTenant::SESSION_KEY, $suspended);

    $after = $this->getJson('/v1/auth/me');
    $after->assertStatus(200);

    $offered = array_column((array) $after->json('memberships'), 'tenant_id');

    expect($offered)->toBe([$live],
        'me offered a suspended tenant, or dropped the live one with it',
    );

    // AND THE TWO FIELDS AGREE. `active_tenant` is either one of the offered memberships or
    // null -- never a tenant that is absent from the list beside it.
    expect($after->json('active_tenant'))->toBeNull(
        'me named a suspended tenant as active while omitting it from memberships',
    );

    // The suspended id appears NOWHERE in the payload, so the refusal is not an oracle
    // either: the response is the same one a user with only the live membership would get.
    expect(str_contains((string) $after->getContent(), $suspended))->toBeFalse();
});
