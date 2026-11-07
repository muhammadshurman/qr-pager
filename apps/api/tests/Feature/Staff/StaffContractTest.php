<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE SHAPE `apps/web`'s STAFF CLIENT READS, PINNED AGAINST THE REAL RESPONSES
 * ================================================================================
 *
 * ⚠ WHY THIS FILE EXISTS, AND IT IS A DEFECT THIS TASK SHIPPED AND THEN CAUGHT.
 *
 * `apps/web/src/staff/api.ts`'s `fetchIdentity()` read the person's name from `data.name`.
 * `AuthController::identity()` returns it at `data.user.name`. So the shell rendered
 * *"Signed in as "* -- a sentence with a hole in it, which is the exact `§12` defect this
 * task asserts against on four other screens.
 *
 * **Eight TypeScript cases passed over it**, because the FAKE in `staff-app.test.tsx`
 * returned the shape the client expected rather than the shape the server sends. A fake
 * written to match its own client cannot find a disagreement between the two, and that is not
 * a property of those tests being bad -- it is a property of where the contract lives. A
 * client reader and a server response are a contract **across two languages**, and the only
 * honest place to assert one is against the real response.
 *
 * ⚠ AND THE SECOND DEFECT WAS THE SAME FAMILY: the client mapped a **403** to *"enter your
 * second factor"*, and `AuthController::login()` answers **200** with
 * `{"two_factor_required": true}`. So the code field was a screen state no event could
 * produce -- a dead state, the shape `browser/one-handed.spec.ts` found in Task 9 by
 * iterating the state list until one timed out.
 *
 * SO WHAT IS PINNED HERE IS EVERY KEY THE CLIENT ACTUALLY READS, each with the file and the
 * function that reads it. A key renamed on either side fails here, in the language that owns
 * the shape.
 *
 * ⚠ IT IS NOT `OpenApiSpecTest`'s JOB. That file asserts the SPEC matches the ROUTES -- the
 * paths, the security schemes, the problem media types. It asserts nothing about a response
 * BODY's keys, which is what a client breaks on.
 */

/** A user with a password and one membership, plus their id. */
function contractUser(string $tenantId, TenantRole $role = TenantRole::Owner): array
{
    $id = (string) Str::uuid7();
    $email = 'contract-'.str_replace('-', '', $id).'@example.test';
    $password = 'correct-horse-battery-staple';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Hiba Mansour',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $id,
        'role' => $role->value,
    ]), 'pgsql_owner');

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

it('nests the person under `user`, which is where fetchIdentity() reads the name', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> fetchIdentity()
    //   data.user.name           the shell's "Signed in as {name}"
    //   data.memberships[].tenant_id, .role
    //   data.active_tenant
    $tenant = $this->makeTenant();
    $user = contractUser($tenant);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $response = $this->getJson('/v1/auth/me')->assertStatus(200);

    // ⚠ THE PATH, NOT MERELY THE PRESENCE. `assertJsonStructure` would pass on a payload that
    // also carried a top-level `name`, which is the shape the client wrongly assumed -- so
    // this asserts the value is at `user.name` and that there is NO top-level `name`.
    $response->assertJsonPath('user.name', 'Hiba Mansour');

    $body = $response->json();

    expect($body)->toBeArray();
    expect(array_key_exists('name', $body))->toBeFalse(
        'a top-level `name` appeared, so the client\'s original wrong read would now pass'
    );

    // The keys the client reads, each asserted at its path.
    $response->assertJsonPath('memberships.0.tenant_id', $tenant)
        ->assertJsonPath('memberships.0.role', TenantRole::Owner->value)
        ->assertJsonPath('active_tenant', $tenant);
});

it('answers a second-factor login with 200 and a flag, which is why the client reads the body', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> signInWithPassword()
    //   status 200 + data.two_factor_required === true   ->   the code field
    //
    // The client's first version mapped 403 to this state, making it unreachable. 200 is the
    // status and the flag is the signal, so a client that read only the status cannot tell a
    // completed sign-in from a half-finished one.
    $tenant = $this->makeTenant();
    $user = contractUser($tenant);

    // Arm the second factor the way the application does: a CONFIRMED timestamp, which is
    // what `login()` tests -- `two_factor_secret` alone is enrolled-but-not-armed and
    // config/fortify.php's `confirm: true` is why the two differ.
    DB::connection('pgsql_owner')->table('users')->where('id', $user['id'])->update([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ]);

    // ⚠ 200 AND NOT A 4xx. Asserted as an exact value, because the whole point is that the
    // status alone does not distinguish this from a completed login.
    $response->assertStatus(200)->assertJsonPath('two_factor_required', true);

    // And the session is NOT established yet, which is what makes the flag load-bearing: a
    // client that treated this 200 as a sign-in would render a shell with no session.
    $this->getJson('/v1/tenant')->assertStatus(401);
});

it('returns the restaurant under `tenant`, with the four keys fetchTenant() reads', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> fetchTenant()
    //   data.tenant.name  .slug  .timezone  .status
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Staff);

    $row = withTenant($tenant, fn () => DB::selectOne('select name, slug, timezone from tenants'));

    $this->getJson('/v1/tenant')
        ->assertStatus(200)
        ->assertJsonPath('tenant.name', $row->name)
        ->assertJsonPath('tenant.slug', $row->slug)
        ->assertJsonPath('tenant.timezone', $row->timezone)
        ->assertJsonPath('tenant.status', 'active');
});

it('returns kitchen screens under `devices`, with the four keys fetchDevices() reads', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> fetchDevices()
    //   data.devices[].id  .name  .expires_at  .last_used_at
    // and                -> issueDevice()
    //   data.device.id  .token  .expires_at
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $issuer = User::query()->findOrFail((string) auth()->id());

    $issued = app(DeviceTokens::class)->issue(
        issuer: $issuer,
        tenantId: $tenant,
        name: 'Pass counter',
        ability: Ability::PagerRead,
        ttlMinutes: 60,
        actorIp: null,
    );

    $this->getJson('/v1/devices')
        ->assertStatus(200)
        ->assertJsonPath('devices.0.id', $issued['id'])
        ->assertJsonPath('devices.0.name', 'Pass counter')
        ->assertJsonStructure(['devices' => [['id', 'name', 'expires_at', 'last_used_at']]]);

    // The issue response's own three keys, driven over HTTP rather than read off the service
    // -- the client reads the HTTP shape and the two could diverge.
    $this->postJson('/v1/devices', [
        'name' => 'Grill',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 60,
    ])
        ->assertStatus(201)
        ->assertJsonStructure(['device' => ['id', 'token', 'expires_at']]);
});

it('accepts the ability and TTL the client sends, so its fixed values are not a guess', function (): void {
    // `issueDevice()` hard-codes `ability: 'pager:read'` and `ttl_minutes: 43200`
    // (`DEVICE_TTL_MINUTES`), because `Ability::issuableToDevice()` is a closed list of one
    // and `DeviceTokens::MAX_TTL_MINUTES` is the ceiling. If either changed on the server, the
    // client would be sending a value the server refuses -- a 422 on the one action this
    // screen exists for.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $this->postJson('/v1/devices', [
        'name' => 'Pass',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => DeviceTokens::MAX_TTL_MINUTES,
    ])->assertStatus(201);

    // And the figure the client sends IS the ceiling, asserted as an exact value so the two
    // cannot drift apart.
    expect(DeviceTokens::MAX_TTL_MINUTES)->toBe(60 * 24 * 30);

    // One above it is refused, which is what makes the clause above worth asserting.
    $this->postJson('/v1/devices', [
        'name' => 'Too long',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => DeviceTokens::MAX_TTL_MINUTES + 1,
    ])->assertStatus(422);
});
