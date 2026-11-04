<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Members\MemberDirectory;
use App\Features\Support\SupportAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE OWNER SEES SUPPORT WHILE IT IS ACTIVE. `QRPAGER-22`.
 * ================================================================================
 *
 * `§4 › Tenancy`, 2026-11-04: *"It is visible to the tenant's own owner in their members list
 * while it is active, marked as platform support with its expiry — support a tenant cannot
 * see is indistinguishable from an undisclosed cross-tenant read."*
 *
 * That last clause is the reason this file is not a UI nicety. The whole ruling rests on
 * support being a DISCLOSED, bounded act rather than an ambient reach into a tenant's data;
 * a restaurant that cannot tell the difference has been given the thing it was promised it
 * would not get.
 */

/** A platform admin. */
function visibilityAdmin(): string
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Visible support',
        'email' => 'vis-'.str_replace('-', '', $id).'@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
        'is_platform_admin' => true,
    ]);

    return $id;
}

/** An ordinary owner of this tenant. */
function visibilityOwner(string $tenantId): string
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Restaurant owner',
        'email' => 'visowner-'.str_replace('-', '', $id).'@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $id,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    return $id;
}

it('shows an ACTIVE support membership, marked, with its expiry', function (): void {
    $tenant = $this->makeTenant(slug: 'visible-support');
    $owner = visibilityOwner($tenant);
    $admin = visibilityAdmin();

    app(SupportAccess::class)->take($tenant, $admin, 'checking a complaint');

    $members = app(MemberDirectory::class)->all($tenant);

    $support = array_values(array_filter(
        $members,
        static fn (array $m): bool => $m['user_id'] === $admin,
    ));

    expect($support)->toHaveCount(1, 'the owner cannot see the support membership at all');

    // Marked AND dated: `§4` requires both, and a client cannot show the badge without the
    // expiry because there are two keys.
    expect($support[0]['platform_support'])->toBeTrue()
        ->and($support[0]['support_expires_at'])->toBeString()
        ->and($support[0]['support_expires_at'])->not->toBe('');

    // And the real owner is still listed, unmarked -- so the flag distinguishes rather than
    // being set on everybody.
    $ordinary = array_values(array_filter($members, static fn (array $m): bool => $m['user_id'] === $owner));

    expect($ordinary)->toHaveCount(1)
        ->and($ordinary[0]['platform_support'])->toBeFalse()
        ->and($ordinary[0]['support_expires_at'])->toBeNull();
});

it('stops showing it once it has LAPSED, because §4 says "while it is active"', function (): void {
    /*
     * A lapsed row grants nothing, so listing it would tell a restaurant somebody is inside
     * when nobody is — worse than silence. The sweep removes the row within five minutes
     * either way; this is what the list does in the window before that.
     */
    $tenant = $this->makeTenant(slug: 'visible-lapsed');
    visibilityOwner($tenant);
    $admin = visibilityAdmin();

    app(SupportAccess::class)->take($tenant, $admin, 'will lapse');

    expect(array_filter(app(MemberDirectory::class)->all($tenant), static fn (array $m): bool => $m['user_id'] === $admin))
        ->toHaveCount(1);

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        "update tenant_users set expires_at = now() - interval '1 minute' where user_id = ?",
        [$admin],
    ), 'pgsql_owner');

    expect(array_filter(app(MemberDirectory::class)->all($tenant), static fn (array $m): bool => $m['user_id'] === $admin))
        ->toBe([], 'a lapsed support membership is still listed as active');

    // And the row is still there, so this is the LIST's decision rather than the sweep's.
    expect((int) DB::connection('pgsql_root')->table('tenant_users')->where('user_id', $admin)->count())
        ->toBe(1);
});

it('is visible over HTTP to the restaurant owner, which is where §4 puts it', function (): void {
    // The clause says "in their members list", and the owner's members list is
    // `GET /v1/members`. Driven as the owner, not as the support holder.
    $tenant = $this->makeTenant(slug: 'visible-http');
    $ownerId = visibilityOwner($tenant);
    $admin = visibilityAdmin();

    app(SupportAccess::class)->take($tenant, $admin, 'customer said the page is blank');

    $email = (string) DB::connection('pgsql_root')->table('users')->where('id', $ownerId)->value('email');

    $this->postJson('/v1/auth/login', ['email' => $email, 'password' => 'correct-horse-battery-staple'])
        ->assertOk();

    $response = $this->getJson('/v1/members')->assertOk();

    $marked = array_values(array_filter(
        (array) $response->json('members'),
        static fn (array $m): bool => ($m['platform_support'] ?? false) === true,
    ));

    expect($marked)->toHaveCount(1, 'the owner is not told that platform support is inside')
        ->and($marked[0]['support_expires_at'])->toBeString();
});
