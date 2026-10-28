<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Http\PasskeyController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * A DEVICE TOKEN IS REFUSED ON THE WHOLE AUTH SURFACE
 * ================================================================================
 *
 * `PLAN.md §3.1` row 5's second named invariant is "device token scope cannot escalate",
 * and `DeviceTokenScopeTest` proves it for the STAFF surface: one capability, one tenant,
 * an explicit expiry, the matrix as the ceiling. This file covers the surface that test
 * does not reach, and the gap was real.
 *
 * WHAT WAS WRONG. `routes/auth.php` is mounted on the `auth-surface` group, which carries
 * the session stack and `ResolvePrincipal` and -- deliberately, for reasons that file
 * states and that still hold -- neither `RequireTenantScope` nor `RequireAbility`. So the
 * only gate left on each of these routes was the controller's own
 * `$request->user() instanceof User`. `ResolvePrincipal` tries `web` and then `sanctum`,
 * so A BEARER TOKEN SATISFIES THAT TEST. A `pager:read` device token on a kitchen screen
 * therefore reached every session-management route its issuer could: the cross-tenant
 * membership map from `auth.me`, a fresh TOTP secret and all eight recovery codes from
 * `two-factor/enable`, the removal of the second factor from `two-factor/disable`, and the
 * registration ceremony that mints a new passkey -- a full staff session as an owner.
 *
 * `RoleMatrixTest`'s closed-world check could not see it: it lists these twelve names in a
 * `$auth` ALLOWLIST, and membership in that set asserts nothing about who may call them.
 *
 * THE RULE THIS FILE PINS. The auth surface is the staff SESSION surface. A request that
 * authenticated by bearer token is refused on all of it, by a middleware on the group
 * rather than a check per controller -- so a route added to `routes/auth.php` inherits the
 * refusal instead of having to remember it. The last case below is what makes that
 * structural: it enumerates the group from the live router and fails if any route on it is
 * not driven here.
 */

/** Issue a `pager:read` device token through the real endpoint, exactly as a KDS gets one. */
function authSurfaceDeviceToken(): array
{
    $response = test()->postJson('/v1/devices', [
        'name' => 'kitchen-screen',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 60,
    ]);

    $response->assertStatus(201);

    return $response->json('device');
}

/**
 * An owner of TWO tenants, holding a device token pinned to the first.
 *
 * Two tenants because the leak with the longest reach was `auth.me`'s membership list: the
 * token is pinned to alpha and the response named beta. One tenant could not show that.
 *
 * @return array{token: string, user: string, alpha: string, beta: string}
 */
function deviceTokenOnAuthSurface(): array
{
    $alpha = test()->makeTenant();
    $beta = test()->makeTenant();

    test()->actingForTenant($alpha, TenantRole::Owner);

    $userId = (string) auth()->id();

    withTenant($beta, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $beta,
        'user_id' => $userId,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $device = authSurfaceDeviceToken();

    // Drop the session and present the token instead, which is what a kitchen screen does.
    // The session SURVIVES as a store -- actingForTenant wrote the tenant selection into it
    // -- and that is deliberate: the request under test is one with a live session cookie
    // and no logged-in user, which is the shape a stolen screen actually sends.
    auth()->forgetGuards();
    app('auth')->guard('web')->logout();

    return ['token' => $device['token'], 'user' => $userId, 'alpha' => $alpha, 'beta' => $beta];
}

/** Every route name mounted on the `auth-surface` group, read off the live router. */
function authSurfaceRouteNames(): array
{
    $names = [];

    foreach (Route::getRoutes() as $route) {
        if (in_array('auth-surface', $route->gatherMiddleware(), true)) {
            $name = $route->getName();

            if (is_string($name)) {
                $names[] = $name;
            }
        }
    }

    sort($names);

    return $names;
}

it('is refused on every route of the auth surface, and the refusal is the same answer everywhere', function (): void {
    $ctx = deviceTokenOnAuthSurface();
    $headers = ['Authorization' => 'Bearer '.$ctx['token']];

    // THE POSITIVE CONTROL FIRST. Without it every refusal below would pass for a token
    // that simply does not authenticate -- the same trap DeviceTokenScopeTest names.
    $this->getJson('/v1/pager-sessions', $headers)->assertStatus(200);

    $credential = [
        'id' => Str::random(22),
        'rawId' => base64_encode(random_bytes(16)),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => base64_encode('{"type":"webauthn.create"}'),
            'attestationObject' => base64_encode(random_bytes(64)),
        ],
    ];

    // Each route of the group, by name, with a body that would be VALID if the caller were
    // allowed -- so a 403 here is the gate and not a validation accident. The passkey
    // register bodies matter most: laravel/passkeys deserialises in passedValidation(), so
    // an unguarded surface answers 422 from the FormRequest. A 403 proves the refusal ran
    // BEFORE the ceremony was attempted.
    // ORDERED WORST FIRST, so that running this file against the unfixed code names the
    // leak rather than an artifact. `auth.login` reads the posted credentials and never
    // consults $request->user(), so it answered 401 on bad credentials either way and put
    // an honest-looking red in front of the five routes that actually handed things over.
    $driven = [
        // 1. The membership map, including the tenant this token is NOT pinned to.
        'auth.me' => ['getJson', '/v1/auth/me', []],
        // 2. A fresh TOTP secret and all eight recovery codes, to the caller.
        'auth.two-factor.enable' => ['postJson', '/v1/auth/two-factor/enable', []],
        // 3. The second factor removed outright.
        'auth.two-factor.disable' => ['deleteJson', '/v1/auth/two-factor', []],
        // 4. A challenge plus the victim's WebAuthn handle and display name, and then
        // 5. the registration that mints a credential the attacker holds.
        'auth.passkeys.options' => ['getJson', '/v1/auth/passkeys/options', []],
        'auth.passkeys.register' => ['postJson', '/v1/auth/passkeys', ['name' => 'stolen', 'credential' => $credential]],
        // 6. The session's tenant selection, repointed.
        'auth.tenant' => ['postJson', '/v1/auth/tenant', ['tenant_id' => $ctx['beta']]],
        // The remaining routes gave nothing away on the unfixed code, and are driven
        // because the rule is the GROUP and not a list of the routes that happened to leak.
        'auth.two-factor.confirm' => ['postJson', '/v1/auth/two-factor/confirm', ['code' => '123456']],
        'auth.two-factor.challenge' => ['postJson', '/v1/auth/two-factor/challenge', ['code' => '123456']],
        'auth.passkeys.login-options' => ['getJson', '/v1/auth/passkeys/login/options', []],
        'auth.passkeys.login' => ['postJson', '/v1/auth/passkeys/login', ['credential' => $credential]],
        'auth.login' => ['postJson', '/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'correct-horse-battery-staple']],
        'auth.logout' => ['postJson', '/v1/auth/logout', []],
        // The harness page is on this group too -- it needs a session for its CSRF token
        // and the ceremony's challenge -- so it inherits the refusal rather than being an
        // exception to it. It is mounted outside production, which bounds the exposure
        // and does not remove it: a dev or staging screen is still a screen.
        'harness.passkeys' => ['getJson', '/passkey-harness', []],
    ];

    $forbiddenTitle = null;

    foreach ($driven as $name => [$method, $path, $body]) {
        $response = $method === 'getJson'
            ? $this->getJson($path, $headers)
            : $this->{$method}($path, $body, $headers);

        $response->assertStatus(403);

        $forbiddenTitle ??= $response->json('title');

        // ONE ANSWER, and nothing in it about why. The same property RequireAbility's 403
        // has: a refusal that varies is a refusal that tells the holder of a stolen screen
        // which routes exist and which credential would have worked.
        expect($response->json('type'))->toBe('https://qrpager.dev/problems/forbidden', $name);

        // THE KEY SET IS EXACTLY THE PROBLEM DOCUMENT'S, which is the assertion that makes
        // the two below meaningful: no extra field can be carrying anything, so checking
        // the values is checking all of them.
        $body = $response->json();

        expect(array_keys($body))->toBe(['type', 'title', 'status', 'instance'], $name);

        // `instance` is the request's own path and `type` is the problem's URL, so both are
        // things the caller already had. Everything else is what the refusal CHOSE to say,
        // and that is what the two checks below read -- the earlier version of this case
        // read the whole document and tripped over `/v1/auth/two-factor/challenge`
        // containing the word "challenge", which is the path and not a leak.
        $said = strtolower((string) json_encode([$body['title'], $body['status']]));

        // THE THINGS THE OPEN SURFACE HANDED OVER, asserted absent by name on every route
        // rather than only on the one that leaked each.
        foreach ([$ctx['beta'], $ctx['alpha'], $ctx['user']] as $identifier) {
            expect(str_contains($said, strtolower($identifier)))->toBeFalse(
                "the refusal for {$name} echoed a tenant or user id",
            );
        }

        foreach (['secret', 'recovery', 'challenge', 'otpauth', 'svg', 'token', 'device'] as $word) {
            expect(str_contains($said, $word))->toBeFalse(
                "the refusal for {$name} mentioned {$word}",
            );
        }

        // And the refusal is BYTE-IDENTICAL across routes apart from the path, so it
        // discloses nothing about which of them exists or what each wanted.
        expect($body['title'])->toBe($forbiddenTitle, $name);
        expect($body['instance'])->toBe($path, $name);
    }

    // NOTHING CHANGED ON THE SERVER, which is the half a status code cannot show.
    $row = DB::connection('pgsql_owner')->table('users')
        ->where('id', $ctx['user'])
        ->first(['two_factor_secret', 'two_factor_confirmed_at']);

    expect($row->two_factor_secret)->toBeNull('the device token enrolled a second factor')
        ->and($row->two_factor_confirmed_at)->toBeNull();

    expect(DB::connection('pgsql_owner')->table('passkeys')->count())->toBe(0);

    expect(session()->get(PasskeyController::REGISTRATION_KEY))->toBeNull(
        'the device token had a registration challenge issued to it',
    );
    expect(session()->get(PasskeyController::VERIFICATION_KEY))->toBeNull();

    // And the tenant selection it tried to change is still alpha's.
    expect(session()->get(ActiveTenant::SESSION_KEY))->toBe($ctx['alpha']);
});

it('cannot re-key or remove a second factor that is already in force', function (): void {
    // The worst of the losses, driven on its own because the state it needs is different:
    // a CONFIRMED factor. An attacker who can call `enable` gets a NEW secret and the old
    // authenticator stops working -- a lockout, not merely a disclosure -- and one who can
    // call `disable` removes the control outright.
    $ctx = deviceTokenOnAuthSurface();
    $headers = ['Authorization' => 'Bearer '.$ctx['token']];

    $confirmedAt = now()->subDay();

    DB::connection('pgsql_owner')->table('users')->where('id', $ctx['user'])->update([
        'two_factor_secret' => encrypt('ALREADYINFORCE23'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
        'two_factor_confirmed_at' => $confirmedAt,
    ]);

    $before = DB::connection('pgsql_owner')->table('users')
        ->where('id', $ctx['user'])->value('two_factor_secret');

    $this->postJson('/v1/auth/two-factor/enable', [], $headers)->assertStatus(403);
    $this->deleteJson('/v1/auth/two-factor', [], $headers)->assertStatus(403);

    $after = DB::connection('pgsql_owner')->table('users')
        ->where('id', $ctx['user'])
        ->first(['two_factor_secret', 'two_factor_confirmed_at']);

    expect($after->two_factor_secret)->toBe($before, 'a device token re-keyed a confirmed second factor')
        ->and($after->two_factor_confirmed_at)->not->toBeNull('a device token removed a confirmed second factor');
});

it('leaves the surface working for the staff session it exists for', function (): void {
    // THE OTHER HALF OF A FAIL-CLOSED GATE: it has to still be open for its real users, or
    // the twelve 403s above are indistinguishable from a surface that refuses everyone.
    $alpha = $this->makeTenant();
    $this->actingForTenant($alpha, TenantRole::Owner);

    $me = $this->getJson('/v1/auth/me');

    $me->assertStatus(200);

    expect($me->json('active_tenant'))->toBe($alpha);

    $this->getJson('/v1/auth/passkeys/options')->assertStatus(200);
    $this->postJson('/v1/auth/two-factor/enable', [])->assertStatus(200);

    // And an ANONYMOUS caller still gets the unauthenticated answer rather than this one,
    // so the two refusals stay distinguishable in the code even though both are refusals.
    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    session()->flush();

    $this->getJson('/v1/auth/me')->assertStatus(401);
});

it('covers every route on the auth surface, so a route added later cannot skip this file', function (): void {
    // THE CLOSED-WORLD CHECK, and the reason it is here rather than in RoleMatrixTest:
    // that file's `$auth` set is a NAME allowlist, and being named in it requires nothing
    // of a route. This asserts something about each one -- that a device bearer token was
    // driven at it and refused.
    $driven = [
        'auth.login', 'auth.logout', 'auth.me', 'auth.passkeys.login',
        'auth.passkeys.login-options', 'auth.passkeys.options', 'auth.passkeys.register',
        'auth.tenant', 'auth.two-factor.challenge', 'auth.two-factor.confirm',
        'auth.two-factor.disable', 'auth.two-factor.enable', 'harness.passkeys',
    ];

    sort($driven);

    expect(authSurfaceRouteNames())->toBe($driven,
        'a route was added to the auth surface without a device-token refusal case in this file',
    );
});
