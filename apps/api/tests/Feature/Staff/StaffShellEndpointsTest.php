<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The two routes Task 10a adds, driven over HTTP.
 *
 * `RoleMatrixTest` already answers *who may call them* -- 5 principals × every staff route ×
 * own/other tenant, mechanically total -- and `StaffSurfaceIsolationTest` answers *can they
 * be aimed across a tenant boundary*. Neither answers what this file does: **what the body
 * carries, and what it must never carry.**
 *
 * That matters here more than it usually would, because both routes exist for `§12` reasons.
 * `GET /v1/tenant` exists so a screen can name the restaurant instead of showing a slug, and
 * `GET /v1/devices` exists so a screen can list credentials without ever showing one. A
 * response that carried the slug and not the name, or that carried a token, would satisfy
 * every authorisation assertion in the suite and fail the clause the route was added for.
 */

/**
 * The user `TestCase::actingForTenant()` just authenticated.
 *
 * ⚠ THAT HELPER RETURNS THE TENANT ID, NOT THE USER ID, which is worth a function rather
 * than a comment: the first version of this file passed its return value to
 * `User::findOrFail()` and got `ModelNotFoundException` on a uuid that is a real tenant.
 * The two are both uuids, so nothing but the lookup can tell them apart.
 */
function actingUser(): User
{
    $id = auth()->id();

    expect($id)->toBeString('no user is authenticated, so actingForTenant() did not run');

    return User::query()->findOrFail((string) $id);
}

it('names the restaurant rather than identifying it — the §12 clause the route exists for', function (): void {
    $tenant = $this->makeTenant();

    // The name is set explicitly, and it is deliberately NOT derivable from the slug: if the
    // route answered with the slug under a `name` key, a test whose fixture name WAS the slug
    // would pass. `makeTenant()` generates both, so they are read back here and asserted to
    // differ before anything else is claimed.
    $row = withTenant($tenant, fn () => DB::selectOne('select name, slug, timezone from tenants'));

    expect($row->name)->not->toBe($row->slug, 'the fixture made name and slug identical, so this case could not tell them apart');

    $this->actingForTenant($tenant, TenantRole::Staff);

    $response = $this->getJson('/v1/tenant')->assertStatus(200);

    $response->assertJsonPath('tenant.name', $row->name)
        ->assertJsonPath('tenant.slug', $row->slug)
        ->assertJsonPath('tenant.timezone', $row->timezone)
        ->assertJsonPath('tenant.status', 'active');

    // AND NO IDENTIFIER. `§12`'s zero-technical-background bar is about what reaches a
    // screen, and a field the client never asked for is a field a client will eventually
    // render. The tenant's uuid is the one a staff shell has no use for at all.
    $body = (string) $response->getContent();

    expect(str_contains($body, $tenant))->toBeFalse('GET /v1/tenant published the tenant uuid');

    // Nothing shared may cache a restaurant's data off a shared kitchen device.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('is a scoped read and not a cross-tenant one, so another restaurant is invisible rather than filtered', function (): void {
    // The distinction is the point. A route that read every tenant and filtered in PHP would
    // pass an "answers my own name" assertion and be a cross-tenant read `§4 › Tenancy` does
    // not name. What proves it is scoped is that the statement has NO predicate at all --
    // `TenantProfile` selects from `tenants` with no `where` -- so if the scope were absent
    // the answer would be *every* tenant and the count below would not be one.
    $mine = $this->makeTenant();
    $theirs = $this->makeTenant();

    $visible = withTenant($mine, fn () => DB::select('select id from tenants'));

    expect($visible)->toHaveCount(1, 'the unpredicated select saw more than the caller\'s own tenant');
    expect((string) $visible[0]->id)->toBe($mine);

    $this->actingForTenant($mine, TenantRole::Owner);

    $body = (string) $this->getJson('/v1/tenant')->assertStatus(200)->getContent();

    expect(str_contains($body, $theirs))->toBeFalse('GET /v1/tenant reached another restaurant');
});

it('lists this restaurant\'s kitchen screens and never a credential', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $this->getJson('/v1/devices')->assertStatus(200)->assertJsonPath('devices', []);

    $issued = app(DeviceTokens::class)->issue(
        issuer: actingUser(),
        tenantId: $tenant,
        name: 'Pass counter',
        ability: Ability::PagerRead,
        ttlMinutes: 120,
        actorIp: null,
    );

    $response = $this->getJson('/v1/devices')->assertStatus(200);

    $response->assertJsonCount(1, 'devices')
        ->assertJsonPath('devices.0.id', $issued['id'])
        ->assertJsonPath('devices.0.name', 'Pass counter');

    $body = (string) $response->getContent();

    // ⚠ THE TOKEN, ASSERTED ABSENT BY ITS ACTUAL VALUE. `issue()` returns the plaintext once
    // and this is the only place in the suite that has it, so this is the only place that can
    // assert the LIST does not. Asserting "the response has no `token` key" would be weaker:
    // it would pass if the plaintext arrived under another name.
    expect(str_contains($body, $issued['token']))->toBeFalse('GET /v1/devices published the credential');

    // And not the hashed half either, which is what Sanctum stores and matches on.
    $stored = (string) DB::table('personal_access_tokens')->where('id', $issued['id'])->value('token');

    expect($stored)->not->toBe('');
    expect(str_contains($body, $stored))->toBeFalse('GET /v1/devices published the stored token hash');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('shows one restaurant none of another\'s screens, through the pin and not through RLS', function (): void {
    // `personal_access_tokens` is GLOBAL and RLS-FREE (`§2.4`), so nothing in the database
    // narrows this list -- the tenant pin inside `abilities` is the whole control. That makes
    // this the one read on the staff surface where isolation is a PHP comparison, which is
    // why it is driven over HTTP here rather than left to the service's unit case.
    $mine = $this->makeTenant();
    $theirs = $this->makeTenant();

    $this->actingForTenant($mine, TenantRole::Owner);
    $mineUser = actingUser();

    $theirIssuerId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $theirIssuerId,
        'name' => 'Their owner',
        'email' => 'theirs-'.str_replace('-', '', $theirIssuerId).'@example.test',
    ]);

    withTenant($theirs, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $theirs,
        'user_id' => $theirIssuerId,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $tokens = app(DeviceTokens::class);

    $theirDevice = $tokens->issue(
        issuer: User::query()->findOrFail($theirIssuerId),
        tenantId: $theirs,
        name: 'Their kitchen',
        ability: Ability::PagerRead,
        ttlMinutes: 60,
        actorIp: null,
    );

    $myDevice = $tokens->issue(
        issuer: $mineUser,
        tenantId: $mine,
        name: 'My kitchen',
        ability: Ability::PagerRead,
        ttlMinutes: 60,
        actorIp: null,
    );

    // Not vacuous: both rows exist in the one global table, so a list that returned
    // everything would return two.
    expect(DB::table('personal_access_tokens')->whereIn('id', [$myDevice['id'], $theirDevice['id']])->count())
        ->toBe(2, 'the fixture did not create both device tokens, so this case could not discriminate');

    $response = $this->getJson('/v1/devices')->assertStatus(200);

    $response->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.id', $myDevice['id']);

    $body = (string) $response->getContent();

    expect(str_contains($body, $theirDevice['id']))->toBeFalse('GET /v1/devices listed another restaurant\'s screen');
    expect(str_contains($body, 'Their kitchen'))->toBeFalse('GET /v1/devices listed another restaurant\'s screen name');
});

it('bounds the list in SQL, at the ceiling the class states and not at the number of rows', function (): void {
    // `§6` Q5 wants "what is bounded" answered with a number. `DeviceTokens::LIST_LIMIT` is
    // the number, and this case proves the LIMIT reaches the statement rather than being a
    // constant nobody reads -- driven at 3 rather than at 200, because the assertion is about
    // the clause and 200 rows would be a slow way to assert the same thing.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);
    $issuer = actingUser();
    $tokens = app(DeviceTokens::class);

    foreach (range(1, 5) as $n) {
        $tokens->issue(
            issuer: $issuer,
            tenantId: $tenant,
            name: 'Screen '.$n,
            ability: Ability::PagerRead,
            ttlMinutes: 60,
            actorIp: null,
        );
    }

    expect($tokens->forTenant($tenant))->toHaveCount(5, 'the default ceiling clipped a list of five');
    expect($tokens->forTenant($tenant, 3))->toHaveCount(3, 'the limit did not reach the statement');

    // And the constant is what the production path uses. Asserted as an exact value, because
    // a ceiling nobody states is the thing `§6` Q5 refuses.
    expect(DeviceTokens::LIST_LIMIT)->toBe(200);
});
