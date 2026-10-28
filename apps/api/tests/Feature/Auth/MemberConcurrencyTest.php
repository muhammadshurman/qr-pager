<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE LAST-OWNER RULE UNDER TRUE CONCURRENCY
 * ================================================================================
 *
 * `MemberDirectory` refuses to demote or remove a tenant's LAST owner, and its own comment
 * calls that "the one bound in this class with no RLS or middleware layer underneath it".
 * It was checked with an unlocked `select count(*)` inside a READ COMMITTED transaction, so
 * two concurrent demotions of two DIFFERENT owners each saw two owners, each believed it
 * was leaving one behind, and both committed:
 *
 *     A sees owners: 2     B sees owners: 2
 *     FINAL owner count for the tenant: 0
 *
 * A tenant with no owners has NO IN-PRODUCT RECOVERY at this task: `RoleMatrix` grants
 * `PlatformAdmin` nothing and the admin surface is Task 6, so nobody can repair it through
 * the API. `MembersTest` drives the rule sequentially and passes either way, which is why
 * this file exists rather than a case being added there.
 *
 * TWO PROCESSES AND NOT TWO CONNECTIONS, for the reason `PagerIdempotencyTest` states and
 * this fix makes load-bearing: the rule now takes `select ... for update`, and a second
 * connection in this one PHP process would block the only thread there is -- the test would
 * deadlock instead of demonstrating order.
 */

/** A second (or third) owner in an existing tenant. Returns the membership id. */
function extraOwner(string $tenantId, TenantRole $role = TenantRole::Owner): string
{
    $userId = (string) Str::uuid7();
    $membershipId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Second Owner',
        'email' => 'owner-'.substr(str_replace('-', '', $userId), 0, 12).'@example.test',
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $membershipId,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => $role->value,
    ]), 'pgsql_owner');

    return $membershipId;
}

/** Owners currently in the tenant, read as the owner role so RLS is not the thing under test. */
function ownerCountOf(string $tenantId): int
{
    return withTenant($tenantId, fn (): int => (int) DB::connection('pgsql_owner')
        ->table('tenant_users')
        ->where('tenant_id', $tenantId)
        ->where('role', TenantRole::Owner->value)
        ->count(), 'pgsql_owner');
}

it('never leaves a tenant with zero owners when two owners are demoted at the same instant', function (): void {
    $tenant = $this->makeTenant();

    // The acting user is an owner, and `actingForTenant` makes the membership. A second
    // owner makes the race possible: with one owner the rule refuses both callers trivially
    // and the test would pass against the unlocked code.
    $this->actingForTenant($tenant, TenantRole::Owner);

    $actorUserId = (string) auth()->id();

    $first = withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')
        ->where('tenant_id', $tenant)
        ->where('user_id', $actorUserId)
        ->value('id'), 'pgsql_owner');

    $second = extraOwner($tenant);

    expect(ownerCountOf($tenant))->toBe(2);

    $script = base_path('tests/Support/demote-owner-in-another-process.php');
    $database = (string) config('database.connections.pgsql.database');

    // Merged, not replaced: proc_open's $env REPLACES the environment, so a bare array
    // loses PATH and everything .env resolution needs -- the lesson
    // PagerIdempotencyTest's comment records, and a child that produces no output at all
    // reads as a concurrency result rather than as a broken launch.
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
        'PAO_DISABLE' => '1',
    ]);

    // BOTH LAUNCHED BEFORE EITHER IS READ, so the two transactions really do overlap:
    // Laravel's boot is ~100 ms and the demotion is sub-millisecond.
    $children = [];

    foreach ([$first, $second] as $i => $membershipId) {
        $children[$i] = ['handle' => proc_open(
            ['php', $script, $tenant, (string) $membershipId, $actorUserId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $children[$i]['pipes'],
            base_path(),
            $env,
        ), 'pipes' => $children[$i]['pipes'] ?? []];

        expect($children[$i]['handle'])->not->toBeFalse("could not start process {$i}");
    }

    $outcomes = [];

    foreach ($children as $child) {
        $outcomes[] = trim((string) stream_get_contents($child['pipes'][1]));
        $stderr = trim((string) stream_get_contents($child['pipes'][2]));
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['handle']);

        expect($stderr)->toBe('', "a child process wrote to stderr: {$stderr}");
    }

    sort($outcomes);

    // THE INVARIANT, asserted first because it is the one that matters: whatever the
    // interleaving, the tenant still has an owner. Against the unlocked count this read 0.
    expect(ownerCountOf($tenant))->toBe(1,
        'two concurrent demotions left the tenant with the wrong number of owners',
    );

    // And the shape of the outcome: exactly one caller won, and the loser was REFUSED
    // rather than erroring. A deadlock, a serialisation failure or an exception would all
    // leave the invariant intact and are all still wrong -- a 40P01 surfacing to a staff
    // user as a 500 is not "the rule held".
    expect($outcomes)->toBe(['demoted', 'refused'],
        'expected exactly one demotion and one refusal, got: '.implode(', ', $outcomes),
    );
});

it('still refuses the last owner when nothing is racing it', function (): void {
    // The sequential half, kept here beside the concurrent one so the lock cannot be
    // "fixed" by removing the rule: one owner, demotion refused, removal refused, and the
    // row unchanged after both.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $actorUserId = (string) auth()->id();

    $membershipId = withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')
        ->where('tenant_id', $tenant)
        ->where('user_id', $actorUserId)
        ->value('id'), 'pgsql_owner');

    $this->patchJson("/v1/members/{$membershipId}", ['role' => 'staff'])->assertStatus(404);
    $this->deleteJson("/v1/members/{$membershipId}")->assertStatus(404);

    expect(ownerCountOf($tenant))->toBe(1);
});
