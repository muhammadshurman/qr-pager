<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Domain\Auth\TenantPin;
use App\Domain\Tenancy\TenantRole;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use App\Platform\Auth\DeviceToken;
use App\Platform\Auth\Memberships;
use App\Platform\Auth\RequestPrincipal;
use App\Platform\Auth\RouteAbilities;
use App\Platform\Auth\TwoFactorSecrets;
use App\Platform\Auth\UserCredentials;
use App\Platform\Http\Middleware\RequireAbility;
use App\Platform\Http\Middleware\RequireTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE ARMS THE HTTP TESTS DO NOT REACH
 * ================================================================================
 *
 * `§6` wants **≥ 90 % on the code this task adds**, and `bin/coverage` put the first
 * complete build at **78.27 % (785/1003 over 36 files)**. The gap was not spread evenly: it
 * was the defensive and error arms of the auth layer, the two listing paths nothing had
 * driven, and `PasskeyController`'s ceremony, which cannot be driven here at all (`R-100`).
 *
 * So this file drives the arms a request cannot easily produce -- a malformed stored value,
 * an unmapped route, a guard that answered something impossible -- by calling the classes
 * directly. **Every case here is a real branch with a real consequence**, not a line
 * counted for its own sake: each one is the "what if this is wrong" path of something the
 * auth layer decides, and the comment on each says which.
 *
 * The one thing this file deliberately does NOT do is reach for coverage of
 * `PasskeyController`'s verify arms. Those need a credential that survives CBOR
 * deserialisation, which needs an authenticator -- `R-100`, and the honest answer is a
 * lower percentage rather than a test that pretends.
 */

it('refuses a route inside the staff group that has no ability mapped, and says where the limit is', function (): void {
    // THE FAIL-CLOSED ARM, driven through the group that actually carries RequireAbility.
    //
    // ⚠ AND THE LIMIT OF IT, which this case found. RequireAbility is wrapped round a group
    // in routes/v1.php, so it reaches routes nested in that group and NOT every /v1 route:
    // the first version of this case registered an ad-hoc `/v1` route on the `api` group and
    // got 200, because that route is outside the group.
    //
    // Moving the middleware onto the `api` group was tried and reverted -- nine test files
    // mount unnamed `/v1` probe routes there and all of them became 403. routes/v1.php
    // records that decision in full. So the property is TWO layers, and this case drives the
    // first while RoleMatrixTest's closed-world check carries the second.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // Layer 1: the middleware itself, called directly with a route it has no mapping for.
    // Direct rather than through HTTP, because the only way to get an unmapped route INSIDE
    // the group is to edit routes/v1.php -- which is what layer 2 is for.
    $middleware = app(RequireAbility::class);

    $unmapped = Request::create('/v1/whatever');
    $unmapped->setUserResolver(fn () => auth()->user());
    $unmapped->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', 'v1/whatever', []))
        ->name('deliberately.unmapped'));

    $refused = $middleware->handle($unmapped, fn () => response()->json(['reached' => true]));

    expect($refused->getStatusCode())->toBe(403, 'an unmapped staff route was served');

    // An UNNAMED route is refused the same way, because a nameless route cannot be mapped
    // and so cannot be authorised.
    $nameless = Request::create('/v1/whatever');
    $nameless->setUserResolver(fn () => auth()->user());
    $nameless->setRouteResolver(fn () => new Illuminate\Routing\Route('GET', 'v1/whatever', []));

    expect($middleware->handle($nameless, fn () => response()->json(['reached' => true]))->getStatusCode())
        ->toBe(403, 'an unnamed staff route was served');

    // And the positive control: a MAPPED route the principal holds is passed through, so the
    // two refusals above are the map and not the harness.
    //
    // The resolved-tenant BINDING has to be published by hand here, because RequireAbility
    // asks RequestPrincipal "what is this actor IN THIS TENANT" and the tenant is what
    // RequireTenantScope puts in the container during a real request. Without it the
    // positive control is a 403 for the right reason and the wrong one -- which is how this
    // case first failed.
    app()->instance(RequireTenantScope::BINDING, $tenant);
    app()->forgetInstance(RequestPrincipal::class);

    $mapped = Request::create('/v1/pager-sessions');
    $mapped->setUserResolver(fn () => auth()->user());
    $mapped->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', 'v1/pager-sessions', []))
        ->name('pager.sessions.index'));

    $passed = $middleware->handle($mapped, fn () => response()->json(['reached' => true]));

    expect($passed->getStatusCode())->toBe(200);

    expect(RouteAbilities::for('deliberately.unmapped'))->toBeNull()
        ->and(RouteAbilities::for('pager.sessions.ready'))->toBe(Ability::PagerNotify);
});

it('exposes the route-ability map and the matrix as data, which the tests above read', function (): void {
    // Both accessors exist so that the test and the code read ONE source. Driven here
    // because a reader of RoleMatrixTest sees `RouteAbilities::names()` and not `all()`.
    $all = RouteAbilities::all();

    expect($all)->toHaveKey('members.store')
        ->and($all['members.store'])->toBe(Ability::MembersWrite->value)
        ->and(array_keys($all))->toEqualCanonicalizing(RouteAbilities::names());

    expect(RoleMatrix::all())->toHaveKey('device')
        ->and(Principal::fromTenantRole(TenantRole::Manager))->toBe(Principal::Manager)
        ->and(Principal::Owner->isTenantScoped())->toBeTrue()
        ->and(Principal::PlatformAdmin->isTenantScoped())->toBeFalse();
});

it('reads a tenant pin out of an ability string, and answers an impossible tenant for a malformed one', function (): void {
    // THE MALFORMED-PIN ARM, and the failure direction is what matters. A pin that is not a
    // uuid must NOT read as "unpinned, so narrow nothing" -- that is the direction that
    // widens. DeviceToken answers '' instead, which can match no membership, so the
    // resolver refuses.
    expect(TenantPin::for('abc'))->toBe('tenant:abc')
        ->and(TenantPin::isPin('tenant:abc'))->toBeTrue()
        ->and(TenantPin::isPin('pager:read'))->toBeFalse()
        ->and(TenantPin::tenantFrom('tenant:abc'))->toBe('abc')
        ->and(TenantPin::tenantFrom('pager:read'))->toBeNull();

    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $user = User::query()->findOrFail((string) auth()->id());

    // A token pinned to something that is not a uuid at all.
    $token = $user->createToken('broken', [TenantPin::for('not-a-uuid'), Ability::PagerRead->value], now()->addHour());

    $request = Request::create('/v1/pager-sessions');
    $request->setUserResolver(fn () => $user);
    $user->withAccessToken($token->accessToken);

    expect(DeviceToken::pinnedTenant($request))->toBe(
        '',
        'a malformed pin read as unpinned, which is the direction that widens',
    );

    expect(DeviceToken::isTokenRequest($request))->toBeTrue()
        ->and(DeviceToken::allows($request, Ability::PagerRead))->toBeTrue()
        ->and(DeviceToken::allows($request, Ability::MembersWrite))->toBeFalse();
});

it('treats a session request as unpinned and holding no token ability', function (): void {
    // The other side of the same helper: with no bearer token there is nothing to narrow,
    // and `allows()` must answer FALSE rather than true -- a session request's permission
    // comes from the matrix, and a helper that said "yes" here would hand every session the
    // device ability set.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $request = Request::create('/v1/pager-sessions');
    $request->setUserResolver(fn () => auth()->user());

    expect(DeviceToken::pinnedTenant($request))->toBeNull()
        ->and(DeviceToken::isTokenRequest($request))->toBeFalse()
        ->and(DeviceToken::allows($request, Ability::PagerRead))->toBeFalse();

    expect($tenant)->not->toBeEmpty();
});

it('answers no principal when there is no user, no tenant, or an impossible user id', function (): void {
    // RequestPrincipal's three refusal arms. Each one is a guard against something that
    // should be impossible, which is exactly why none of them is reached by a normal
    // request -- and why a bug in one would be invisible without this.
    $principal = app(RequestPrincipal::class);

    // No user at all.
    $bare = Request::create('/v1/pager-sessions');
    $bare->setUserResolver(fn () => null);

    expect($principal->for($bare))->toBeNull()
        ->and($principal->may($bare, Ability::PagerRead))->toBeFalse();

    // A user whose identifier is not a uuid, which means a guard produced something
    // impossible. The honest answer is "no principal", not a database lookup with it.
    $odd = new User;
    $odd->forceFill(['id' => 'not-a-uuid']);

    $oddRequest = Request::create('/v1/pager-sessions');
    $oddRequest->setUserResolver(fn () => $odd);

    expect($principal->for($oddRequest))->toBeNull();

    // A real user, but no tenant published for the request -- so there is no scope in which
    // to have a role.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $user = auth()->user();
    app()->forgetInstance(RequireTenantScope::BINDING);

    $noTenant = Request::create('/v1/pager-sessions');
    $noTenant->setUserResolver(fn () => $user);

    expect(app(RequestPrincipal::class)->for($noTenant))->toBeNull();
});

it('reads no membership for an impossible user id, and refuses to pick between two', function (): void {
    /*
     * ⚠ THIS CASE USED TO BE NAMED "...and drops a row it cannot map", AND IT NEVER DID.
     *
     * Renamed at Task 5's QA review (L-3) rather than left over-claiming. `Memberships` has
     * two `continue` arms -- one for a row missing a column, one for a `role` or
     * `tenant_status` that `tryFrom()` cannot map -- and both are still UNCOVERED, which
     * the coverage report shows at `Memberships.php:93` and `:104`. A name that claims an
     * assertion the body does not make is worse than no case at all, because the next
     * reader checks the name and stops.
     *
     * THEY ARE NOT DRIVEN HERE ON PURPOSE. `role` and `tenant_status` are Postgres ENUMS,
     * so an unmappable value cannot be inserted: producing one needs
     * `ALTER TYPE ... ADD VALUE` against the live cluster, which is `R-018`'s class of test
     * -- three tests already mutate cluster-wide role attributes and that is a `§5` row,
     * not a pattern to copy. The alternative, a hand-written fake connection returning
     * crafted rows, is what `§6`'s "no test mocks database behaviour" rules out.
     *
     * So the arm stays a belt behind `§2.3`'s generated-enum check, which is the control
     * that actually stops the drift, and this case asserts only what it drives.
     */
    $reader = app(Memberships::class);

    expect($reader->for('not-a-uuid'))->toBe([])
        ->and($reader->in('not-a-uuid', (string) Str::uuid7()))->toBeNull()
        ->and($reader->sole('not-a-uuid'))->toBeNull();

    // A user with no memberships: sole() answers null rather than throwing.
    $orphan = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $orphan,
        'name' => 'Orphan',
        'email' => 'orphan-'.substr(str_replace('-', '', $orphan), 0, 12).'@example.test',
    ]);

    expect($reader->for($orphan))->toBe([])
        ->and($reader->sole($orphan))->toBeNull();

    // Two memberships: sole() REFUSES to pick, which is the property the resolver depends on.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    foreach ([$alpha, $beta] as $tenant) {
        withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'user_id' => $orphan,
            'role' => TenantRole::Staff->value,
        ]), 'pgsql_owner');
    }

    app()->forgetInstance(Memberships::class);
    $fresh = app(Memberships::class);

    expect($fresh->for($orphan))->toHaveCount(2)
        ->and($fresh->sole($orphan))->toBeNull('sole() picked one of two memberships')
        ->and($fresh->in($orphan, $alpha))->not->toBeNull()
        ->and($fresh->in($orphan, (string) Str::uuid7()))->toBeNull();
});

it('verifies a password through the function, and spends a comparison on a miss', function (): void {
    // UserCredentials' two arms. The timing arm matters: an unknown address must cost about
    // what a known one does, or the response time is the enumeration oracle the single
    // error message exists to close.
    $credentials = app(UserCredentials::class);

    $id = (string) Str::uuid7();
    $email = 'creds-'.substr(str_replace('-', '', $id), 0, 12).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Credentials',
        'email' => $email,
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    expect($credentials->verify($email, 'correct-horse-battery-staple'))->toBe($id)
        // Case-insensitive on the address, because the column is stored lower-cased and the
        // function lowers its argument.
        ->and($credentials->verify(Str::upper($email), 'correct-horse-battery-staple'))->toBe($id)
        ->and($credentials->verify($email, 'wrong-password'))->toBeNull()
        ->and($credentials->verify('nobody-'.Str::lower(Str::random(8)).'@example.test', 'anything'))->toBeNull();

    // A PASSKEY-ONLY ACCOUNT has no password at all -- §2.4 says the column is nullable for
    // exactly that -- and must not be loggable-in with an empty one.
    $passkeyOnly = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $passkeyOnly,
        'name' => 'Passkey Only',
        'email' => 'pkonly-'.substr(str_replace('-', '', $passkeyOnly), 0, 12).'@example.test',
    ]);

    $pkEmail = (string) DB::connection('pgsql_owner')->table('users')
        ->where('id', $passkeyOnly)->value('email');

    expect($credentials->verify($pkEmail, ''))->toBeNull()
        ->and($credentials->verify($pkEmail, 'anything'))->toBeNull();
});

it('reports no second factor for an impossible id, an unknown user, or an undecryptable column', function (): void {
    // TwoFactorSecrets' three arms, and the third is the one with a real consequence: a
    // column that cannot be decrypted is reported as ABSENT rather than thrown, because a
    // throw here would lock the account instead of falling back to the password path.
    $secrets = app(TwoFactorSecrets::class);

    expect($secrets->for('not-a-uuid'))->toBeNull()
        ->and($secrets->secret('not-a-uuid'))->toBeNull()
        ->and($secrets->recoveryCodes('not-a-uuid'))->toBe([]);

    expect($secrets->for((string) Str::uuid7()))->toBeNull();

    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Broken 2FA',
        'email' => 'broken-'.substr(str_replace('-', '', $id), 0, 12).'@example.test',
        'two_factor_secret' => 'this is not a valid encrypted payload',
        'two_factor_recovery_codes' => 'neither is this',
    ]);

    $state = $secrets->for($id);

    expect($state)->not->toBeNull()
        ->and($state['secret'])->toBeNull('an undecryptable secret was returned rather than reported absent')
        ->and($state['recovery_codes'])->toBe([])
        ->and($state['confirmed'])->toBeFalse();

    // And a user with no 2FA at all reports the same shape rather than null.
    $plain = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $plain,
        'name' => 'No 2FA',
        'email' => 'no2fa-'.substr(str_replace('-', '', $plain), 0, 12).'@example.test',
    ]);

    expect($secrets->for($plain))->toBe(['secret' => null, 'recovery_codes' => [], 'confirmed' => false]);
});

it('lists a tenant device tokens without anything secret, and refuses a capability a device may not hold', function (): void {
    // DeviceTokens' listing and its two refusal arms. The listing's own prefilter is a LIKE
    // over a text column, which is the wrong thing to trust for tenancy -- so the exact pin
    // match is re-tested per row, and this drives both.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $issuer = User::query()->findOrFail((string) auth()->id());
    $tokens = app(DeviceTokens::class);

    $first = $tokens->issue($issuer, $tenant, 'pass', Ability::PagerRead, 60, '127.0.0.1');
    $second = $tokens->issue($issuer, $tenant, 'grill', Ability::PagerRead, 120, null);

    $listed = $tokens->forTenant($tenant);

    expect($listed)->toHaveCount(2);

    $encoded = (string) json_encode($listed, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($first['token'])
        ->and($encoded)->not->toContain($second['token'])
        ->and($encoded)->not->toContain('abilities')
        ->and(collect($listed)->pluck('name')->all())->toEqualCanonicalizing(['pass', 'grill']);

    // Another tenant sees none of them, which the exact pin match is what delivers.
    expect($tokens->forTenant((string) Str::uuid7()))->toBe([]);

    // THE TWO REFUSALS, as exceptions rather than as HTTP: an ability a device may not hold,
    // and an expiry outside the ceiling. The request rules refuse both too; these are the
    // service's own guard, which is what holds if a future caller reaches it another way.
    expect(fn () => $tokens->issue($issuer, $tenant, 'x', Ability::MembersWrite, 60, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $tokens->issue($issuer, $tenant, 'x', Ability::PagerRead, 0, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $tokens->issue($issuer, $tenant, 'x', Ability::PagerRead, DeviceTokens::MAX_TTL_MINUTES + 1, null))
        ->toThrow(InvalidArgumentException::class);

    // Revoking something that is not a device token of this tenant's answers false rather
    // than throwing, so the controller can turn all three causes into one 404.
    expect($tokens->revoke($issuer, $tenant, (string) Str::uuid7(), null))->toBeFalse()
        ->and($tokens->revoke($issuer, (string) Str::uuid7(), $first['id'], null))->toBeFalse()
        ->and($tokens->revoke($issuer, $tenant, $first['id'], null))->toBeTrue();
});

it('re-reads a user with the readable column list, through refresh and fresh', function (): void {
    // R-103's overrides, driven. The inherited versions use newQueryWithoutScopes() and
    // would issue `select *`, which is a 42501 on this table -- measured through the real
    // 2FA endpoint as a 500 before these existed.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $user = User::query()->findOrFail((string) auth()->id());

    DB::connection('pgsql_owner')->table('users')
        ->where('id', $user->getKey())
        ->update(['name' => 'Renamed Outside The Model']);

    expect($user->refresh()->getAttribute('name'))->toBe('Renamed Outside The Model');

    $fresh = $user->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh?->getAttribute('name'))->toBe('Renamed Outside The Model')
        // And neither carries a column the role may not read.
        ->and($fresh?->getAttribute('password'))->toBeNull()
        ->and($user->getAttribute('two_factor_secret'))->toBeNull();

    // An instance that does not exist in the database answers null / itself rather than
    // throwing, which is what the inherited contract promises.
    $unsaved = new User;

    expect($unsaved->fresh())->toBeNull()
        ->and($unsaved->refresh())->toBe($unsaved);

    // Remember-me is off by construction: validating a recaller needs a READ of a column
    // this role cannot read, so the token is never issued and never looked for.
    expect($user->getRememberToken())->toBeNull()
        ->and($user->getRememberTokenName())->toBe('');

    $user->setRememberToken('anything');

    expect($user->getRememberToken())->toBeNull('setRememberToken stored a value');

    // And the platform principal accessor, which has no HTTP caller yet.
    expect($user->platformPrincipal())->toBeNull()
        ->and($user->isPlatformAdmin())->toBeFalse();
});

it('constructs the platform-admin principal for a tenant it has no membership in, and it holds nothing', function (): void {
    /*
     * `RequestPrincipal`'s CASE 2 -- the one arm of that class the named invariant is
     * actually written over, and it was uncovered (QA L-9) although its own comment says
     * "this arm exists ... for the test that drives it directly".
     *
     * WHY IT IS HARD TO REACH, which is the whole reason it needs a direct case: in a real
     * request the resolver has already refused. A platform admin holds no membership, so no
     * tenant resolves, so `RequireTenantScope` answers 401 and no route runs -- which is
     * `§5`'s "a platform admin is NOT a tenant bypass", and it is why
     * `PlatformAdminIsNotABypassTest` can only ever observe the 401. The arm is what
     * happens if a tenant IS published for such a user, and the distinction it draws
     * matters: NOT null, because "a platform admin reaching a tenant they do not belong to"
     * is a real principal being correctly refused rather than an absence of authentication.
     */
    $admin = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $admin,
        'name' => 'Platform Admin',
        'email' => 'arm-admin-'.substr(str_replace('-', '', $admin), 0, 12).'@example.test',
        'is_platform_admin' => true,
    ]);

    // A tenant the admin is NOT a member of, published for the request as though a route
    // had resolved one.
    $tenant = $this->makeTenant();

    app()->instance(RequireTenantScope::BINDING, $tenant);

    $user = User::query()->findOrFail($admin);

    expect($user->isPlatformAdmin())->toBeTrue();

    $request = Request::create('/v1/pager-sessions');
    $request->setUserResolver(fn () => $user);

    // CASE 2: the principal is constructed, and it is PlatformAdmin and not null.
    expect(app(RequestPrincipal::class)->for($request))->toBe(Principal::PlatformAdmin);

    // AND IT HOLDS NOTHING. Driven over every ability rather than argued, so adding one to
    // the enum without deciding about this principal fails here as well as in
    // PlatformAdminIsNotABypassTest.
    foreach (Ability::cases() as $ability) {
        expect(app(RequestPrincipal::class)->may($request, $ability))->toBeFalse(
            "a platform admin may {$ability->value} in a tenant it does not belong to",
        );
    }

    // THE DISCRIMINATING HALF: the SAME request shape for an ordinary user with no
    // membership answers null, not a principal. So the arm above is the flag's work and not
    // a fallback that fires for everyone.
    $nobody = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $nobody,
        'name' => 'No Memberships',
        'email' => 'arm-nobody-'.substr(str_replace('-', '', $nobody), 0, 12).'@example.test',
    ]);

    $plain = User::query()->findOrFail($nobody);

    $plainRequest = Request::create('/v1/pager-sessions');
    $plainRequest->setUserResolver(fn () => $plain);

    app()->forgetInstance(Memberships::class);

    expect(app(RequestPrincipal::class)->for($plainRequest))->toBeNull();
});
