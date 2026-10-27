<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * "A TENANT-PROVISIONING ACTOR CANNOT READ ANOTHER TENANT'S DATA"
 * ================================================================================
 *
 * `PLAN.md §3.1` row 5's FIRST invariant, and the one `QRPAGER-12 §4.1` names as the clause
 * that blocks `task-5` once the `§11` round limit is reached. So it gets a file of its own
 * rather than a case inside a larger one.
 *
 * THE ACTOR. "A tenant-provisioning actor" is the platform admin -- the principal Task 6's
 * wizard will create restaurants as. `QRPAGER-12 §5` and the rank-1 prompt both say the same
 * thing in the same words: *a platform admin is NOT a tenant bypass*, and if one is ever
 * needed, that is a new named path and `PROJECT_INSTRUCTIONS.md §4` must be edited first --
 * stop and report, do not assume.
 *
 * SO THIS TASK DID NOT ADD ONE, and this file is the evidence rather than the promise. The
 * flag grants nothing: `RoleMatrix` gives `Principal::PlatformAdmin` an EMPTY ability list,
 * and the resolver has no branch for `is_platform_admin` at all -- its absence is the
 * control, which is precisely the kind of thing a test has to pin, because an absence is
 * invisible in a diff once it has been there a while.
 *
 * `PLAN.md §3.1` row 6 already records the open question for Task 6: platform-admin
 * cross-tenant reads are not on `§4`'s list and Task 6 needs that settled before it builds
 * the dashboard. Nothing here pre-empts that decision; what it does is make the decision
 * unavoidable rather than quietly answered.
 */

/** A platform admin with a known password, and no tenant membership at all. */
function makePlatformAdmin(string $password = 'correct-horse-battery-staple'): array
{
    $id = (string) Str::uuid7();
    $email = 'admin-'.substr(str_replace('-', '', $id), 0, 12).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Platform Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'is_platform_admin' => true,
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

it('holds no tenant ability at all, which is the flag granting nothing', function (): void {
    expect(RoleMatrix::abilitiesFor(Principal::PlatformAdmin))->toBe([]);

    // Every ability, driven rather than argued, so that adding one to the enum without
    // deciding about this principal fails here.
    foreach (Ability::cases() as $ability) {
        expect(RoleMatrix::allows(Principal::PlatformAdmin, $ability))->toBeFalse(
            "a platform admin holds {$ability->value}, which would be a tenant bypass",
        );
    }
});

it('resolves no tenant and reaches no staff route in a tenant it has no membership in', function (): void {
    // THE INVARIANT, driven through HTTP. The admin is real, authenticated, and holds the
    // platform flag; the tenant is real and active; there is real data in it.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);
    $session = $this->makeSession($tenant);

    // A different, authenticated principal: the platform admin, with NO membership.
    $admin = makePlatformAdmin();

    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $admin['email'],
        'password' => $admin['password'],
    ])->assertStatus(200)
        ->assertJsonPath('user.is_platform_admin', true)
        // AND THE LOGIN OFFERS NO RESTAURANTS, which is the first place the property shows:
        // a platform admin with no membership has nothing to act in.
        ->assertJsonPath('memberships', []);

    // Every staff route, refused. 401 rather than 403: with no membership the resolver
    // answers no tenant at all, so RequireTenantScope refuses before RequireAbility ever
    // runs -- which is a stronger property than an authorisation failure, because it means
    // there is no point at which the request is inside a tenant.
    $routes = [
        ['getJson', '/v1/pager-sessions', []],
        ['postJson', '/v1/pager-sessions', ['order_reference' => 'A-9']],
        ['postJson', "/v1/pager-sessions/{$session}/ready", []],
        ['postJson', "/v1/pager-sessions/{$session}/complete", []],
        ['postJson', "/v1/pager-sessions/{$session}/cancel", []],
        ['getJson', '/v1/members', []],
        ['postJson', '/v1/members', ['email' => 'x@example.test', 'role' => 'owner']],
        ['postJson', '/v1/devices', ['name' => 'x', 'ability' => 'pager:read', 'ttl_minutes' => 60]],
    ];

    foreach ($routes as [$method, $path, $body]) {
        $response = $method === 'getJson'
            ? $this->getJson($path)
            : $this->{$method}($path, $body);

        expect($response->status())->toBe(
            401,
            "a platform admin reached {$method} {$path} in a tenant it is not a member of",
        );

        // AND NOTHING OF THE TENANT'S LEAKED INTO THE REFUSAL -- with `instance` excluded,
        // and the exclusion is correct rather than convenient.
        //
        // RFC 7807's `instance` member echoes the requested PATH, so for the keyed routes it
        // necessarily contains the session id the caller just sent. That is the caller's own
        // input coming back, not a disclosure: they knew it before they asked. The first
        // version of this assertion read the raw body and failed on exactly that, which
        // would have been a false positive dressed as a security finding.
        //
        // The tenant id is checked against the WHOLE body, because it appears in no path
        // here and so has no innocent reason to be present.
        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : [];
        unset($decoded['instance']);

        $remainder = json_encode($decoded, JSON_THROW_ON_ERROR);

        expect(str_contains((string) $response->getContent(), $tenant))->toBeFalse(
            "the refusal named the tenant on {$method} {$path}",
        )->and(str_contains($remainder, $session))->toBeFalse(
            "the refusal carried the session id outside `instance` on {$method} {$path}",
        );
    }

    // Nothing moved.
    $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('status'));

    expect($status)->toBe('waiting');
});

it('cannot select a tenant it has no membership in', function (): void {
    // The selection endpoint is the one place a caller names a tenant, so it is the one
    // place a bypass would be easiest to introduce by accident.
    $tenant = $this->makeTenant();
    $admin = makePlatformAdmin();

    $this->postJson('/v1/auth/login', [
        'email' => $admin['email'],
        'password' => $admin['password'],
    ])->assertStatus(200);

    // 404 and not 403: "not yours" and "no such restaurant" are the same answer, or the
    // endpoint becomes an oracle for which tenant ids exist.
    $this->postJson('/v1/auth/tenant', ['tenant_id' => $tenant])->assertStatus(404);

    // And a tenant that genuinely does not exist answers identically.
    $this->postJson('/v1/auth/tenant', ['tenant_id' => (string) Str::uuid7()])->assertStatus(404);

    $this->getJson('/v1/pager-sessions')->assertStatus(401);
});

it('acts as its MEMBERSHIP where it has one, rather than being demoted by the flag', function (): void {
    // THE ORDERING CASE, and it is the one place the two principals could disagree.
    //
    // A platform admin who genuinely holds a membership is acting as that member: the flag
    // is not a demotion. Reading it first would mean a platform admin who is also an owner
    // of one restaurant could do LESS there than an ordinary owner -- which is a strange
    // rule, and worse, it would push somebody towards granting PlatformAdmin tenant
    // abilities to fix it, which is the bypass this file exists to prevent.
    $tenant = $this->makeTenant();
    $admin = makePlatformAdmin();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $admin['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $this->postJson('/v1/auth/login', [
        'email' => $admin['email'],
        'password' => $admin['password'],
    ])->assertStatus(200)
        ->assertJsonPath('user.is_platform_admin', true)
        ->assertJsonPath('memberships.0.role', TenantRole::Owner->value)
        ->assertJsonPath('memberships.0.principal', Principal::Owner->value);

    // An owner's abilities, in that one tenant, and nothing anywhere else.
    $this->getJson('/v1/members')->assertStatus(200);
    $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-1'])->assertStatus(201);

    // A second tenant it is NOT a member of stays unreachable, so the membership bought it
    // one tenant and not a bypass.
    $other = $this->makeTenant();
    $otherSession = $this->makeSession($other);

    $this->postJson('/v1/auth/tenant', ['tenant_id' => $other])->assertStatus(404);
    $this->postJson("/v1/pager-sessions/{$otherSession}/ready")->assertStatus(404);

    $status = withTenant($other, fn () => DB::table('pager_sessions')->where('id', $otherSession)->value('status'));

    expect($status)->toBe('waiting');
});

it('cannot give itself the flag, because the app role holds no UPDATE on the column', function (): void {
    // THE ESCALATION THAT IS NOT A POLICY CHECK. §2.7, as this task's migration sets it:
    // qrpager_app has INSERT on is_platform_admin and NO UPDATE. So a staff member
    // promoting themselves is not a check that could be wrong -- it is a grant that does
    // not exist, and this is the measurement rather than the claim.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $userId = (string) auth()->id();

    $state = $this->sqlStateOf(fn () => DB::update(
        'update users set is_platform_admin = true where id = ?',
        [$userId],
    ));

    expect($state)->toBe('42501', 'the app role can write users.is_platform_admin');

    // And the flag really is still false, read back through the owner.
    $flag = DB::connection('pgsql_owner')->table('users')->where('id', $userId)->value('is_platform_admin');

    expect((bool) $flag)->toBeFalse();
});
