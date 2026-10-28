<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\DeviceToken;
use App\Platform\Auth\Http\PasskeyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\TransientToken;

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
 * so A BEARER TOKEN SATISFIES THAT TEST.
 *
 * ⚠ WITH ONE CORRECTION THIS FILE OWES THE `§11` REVIEW OF THE FIX DIFF.
 * `ValidateCsrfToken` runs before `RefuseDeviceToken` in the group, so a BEARER-ONLY caller
 * was already refused 419 on every state-changing route. What a bearer token alone genuinely
 * reached is the two GETs -- `auth.me`'s cross-tenant membership map, and
 * `passkeys/options`' live challenge plus the victim's WebAuthn handle and display name.
 * Both are cross-tenant disclosures from a credential scoped to one tenant and one read-only
 * capability, which is the named invariant failing on its own.
 *
 * The POSTs -- a fresh TOTP secret with all eight recovery codes, the second factor removed,
 * the registration ceremony that mints a passkey the attacker holds -- were reachable in the
 * shape that matters for a kitchen screen: one that still holds a live session cookie AND
 * its matching CSRF token, which is what a browser-based KDS on a shared screen has.
 * `RefuseDeviceToken`'s docblock carries the measured table.
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

        // ⚠ THE KEY SET IS THE CONTROL, and the word scan below is a second line rather than
        // the first. That ordering is the `§11` review's correction: `type`, `title` and
        // `status` are CONSTANTS for a given problem type, so scanning them can only catch a
        // future change that put a leaked word into one of them. What makes this case sound
        // is THIS assertion -- a leak would have to arrive in a new key, and a new key fails
        // here. Credited explicitly so the next reader does not mistake 130 word-scan
        // assertions for 130 facts.
        $body = $response->json();

        expect(array_keys($body))->toBe(['type', 'title', 'status', 'instance'], $name);

        // ⚠ THE WHOLE DOCUMENT MINUS `instance`, which is the third version of this line.
        //
        // The first read the whole body and tripped over `/v1/auth/two-factor/challenge`
        // containing the word "challenge" -- the path, not a leak. The second narrowed to
        // `[title, status]`, and the §11 review of the fix diff pointed out that those two
        // are CONSTANTS here, so ~130 of this case's assertions could not fail.
        //
        // So: everything the refusal chose to say, with only `instance` removed -- and
        // `instance` is removed because it is the caller's own request path, which they
        // supplied. `type` stays in, so a future problem type whose URL carried a leaked
        // word would be caught.
        $chosen = $body;
        unset($chosen['instance']);

        $said = strtolower((string) json_encode($chosen));

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
        //
        // ⚠ UNDER THE TEST HARNESS, and that qualification is the honest one. CSRF is
        // skipped here (`runningUnitTests()`), so all thirteen reach `RefuseDeviceToken` and
        // answer 403. IN PRODUCTION the nine state-changing routes are refused 419 by
        // `ValidateCsrfToken` one layer earlier, so the answer varies by METHOD -- still
        // refused, and refused sooner, but not one answer. The `§11` review of the fix diff
        // measured it. The property worth having is that none of the thirteen SERVES the
        // request, and both layers give that.
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

it('does not mistake a session user for a device, even when the sanctum guard has been resolved', function (): void {
    /*
     * BOTH `§11` REVIEWERS OF THE FIX DIFF FOUND THIS, and the measured cost was a 500.
     *
     * `DeviceToken::isTokenRequest()` read `instanceof HasAbilities`, and Sanctum's
     * `TransientToken` implements that interface. `Sanctum\Guard::__invoke` attaches one to
     * any user it resolves from the WEB guard — unconditionally — and
     * `TransientToken::can()` answers true for every ability, including invented ones. So
     * resolving the sanctum guard once on a session request made `isTokenRequest()` true
     * for a signed-in owner; `RefuseDeviceToken` then read the token's `getKey()`, which
     * `TransientToken` does not have, and `GET /v1/auth/me` answered **500**.
     *
     * Nothing in this tree resolves that guard today, which is why it was latent rather
     * than broken. It stops being latent the moment any route uses `auth:sanctum` — the
     * obvious shape for Task 10's KDS work — so the predicate is narrowed to this project's
     * own token model and this case holds it there.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // Resolve the sanctum guard, which is what attaches the TransientToken. This is the
    // line that made the old predicate true.
    $resolved = app('auth')->guard('sanctum')->user();

    expect($resolved)->not->toBeNull('the sanctum guard resolved nobody, so this case proves nothing');

    $token = $resolved->currentAccessToken();

    // THE PRECONDITION, asserted rather than assumed: the user really is carrying a
    // TransientToken, and it really does satisfy the interface the old predicate used.
    expect($token)->toBeInstanceOf(TransientToken::class)
        ->and($token)->toBeInstanceOf(HasAbilities::class)
        ->and($token->can('this-ability-does-not-exist'))->toBeTrue(
            'TransientToken stopped answering true for everything, so this case is testing nothing',
        );

    // AND THE PREDICATE SAYS NO.
    //
    // ⚠ ON A REQUEST THAT ACTUALLY CARRIES THE USER, which is the difference between an
    // assertion and a decoration. The first version of this line passed `request()` -- the
    // test's own ambient request, which has no user resolver -- and so returned false with
    // the BROKEN predicate too. It was vacuous, and it was vacuous in the file written to
    // fix vacuous assertions. Caught by running it against the old predicate, which is the
    // only thing that ever catches this.
    $carrying = Request::create('/v1/auth/me');
    $carrying->setUserResolver(fn () => $resolved);

    expect(DeviceToken::isTokenRequest($carrying))->toBeFalse(
        'a session user carrying a TransientToken was read as a device',
    );

    // Driven through HTTP as well, because the 500 was an HTTP outcome: the staff session
    // still reaches its own surface rather than being refused or erroring.
    $me = $this->getJson('/v1/auth/me');

    $me->assertStatus(200)->assertJsonPath('active_tenant', $tenant);

    // And the staff surface still answers as the MEMBERSHIP role, not as Principal::Device
    // -- which is the other half of what the old predicate broke.
    $this->getJson('/v1/members')->assertStatus(200);
});

it('refuses a malformed bearer token with 401 and no reported exception', function (): void {
    /*
     * `R-110`, closed here rather than carried: the parent `findToken()` put an
     * attacker-chosen id straight into `where id = ?` on a uuid column, so
     * `Bearer zzz|q` raised `22P02` and the renderer answered **422** on an
     * AUTHENTICATION failure -- plus one failed Postgres statement and ~337 bytes of
     * `local.ERROR` echoing the attacker's own string, per request, with no credential.
     *
     * Both `§11` reviews of the fix diff measured it. The second one named the
     * inconsistency that made it worth fixing rather than filing: this task downgraded
     * `RefuseDeviceToken` to `Log::warning` precisely to stop an unbounded ERROR flood, and
     * this is the same flood on the path that needs no credential.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // A real token first, as the positive control: the shape check must not refuse a
    // credential that works.
    $issued = $this->postJson('/v1/devices', [
        'name' => 'kds-shape',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 60,
    ]);

    $issued->assertStatus(201);

    auth()->forgetGuards();
    app('auth')->guard('web')->logout();
    session()->flush();

    $this->getJson('/v1/pager-sessions', ['Authorization' => 'Bearer '.$issued->json('device.token')])
        ->assertStatus(200);

    auth()->forgetGuards();

    // AND THE MALFORMED ONES ANSWER 401, not 422 -- on the staff surface, where the
    // statement used to be sent.
    foreach ([
        'Bearer zzz|q',
        "Bearer 1'||(select version())||'|q",
        'Bearer not-a-uuid|anything',
        'Bearer |',
    ] as $header) {
        $response = $this->getJson('/v1/pager-sessions', ['Authorization' => $header]);

        expect($response->status())->toBe(401, "[{$header}] answered {$response->status()}");

        // The attacker's own string is not echoed back either.
        expect(str_contains((string) $response->getContent(), 'zzz'))->toBeFalse();
        expect(str_contains((string) $response->getContent(), 'version()'))->toBeFalse();
    }

    // A WELL-FORMED but unknown id still answers 401, which is what makes the refusal about
    // the credential rather than about the shape check alone.
    $this->getJson('/v1/pager-sessions', ['Authorization' => 'Bearer '.Str::uuid7().'|nope'])
        ->assertStatus(401);
});
