<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Platform\Auth\ActiveTenant;
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
    $email = 'staff-'.substr(str_replace('-', '', $id), 0, 12).'@example.test';

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
