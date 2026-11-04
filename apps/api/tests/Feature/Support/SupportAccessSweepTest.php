<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Support\SupportAccess;
use App\Platform\Database\DatabaseRoles;
use App\Platform\Tenancy\SupportMemberships;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * `support:sweep` — HOUSEKEEPING, AND ITS BOUND IS THE WHOLE TEST. `QRPAGER-22`.
 * ================================================================================
 *
 * The sweep enforces nothing: a lapsed support membership grants no ability the instant it
 * lapses, because `public.user_memberships()` filters it in SQL for every reader at once, and
 * `SupportMembershipTest` drives that with the row still present. What this frees is the
 * admin's one-at-a-time slot, which the partial unique index holds because an index predicate
 * cannot reference `now()`.
 *
 * So the thing worth proving is the BOUND. A sweep that took one row too many would remove a
 * real person from a real restaurant, and it runs unattended every five minutes.
 */

/** A membership row, inserted as the owner so the test can choose its expiry. */
function membership(string $tenantId, string $userId, ?string $expiresAt): string
{
    $id = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => TenantRole::Staff->value,
        'expires_at' => $expiresAt,
    ]), 'pgsql_owner');

    return $id;
}

function person(): string
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Sweep fixture',
        'email' => 'sweep-'.str_replace('-', '', $id).'@example.test',
        'password' => 'x',
    ]);

    return $id;
}

/**
 * A platform admin, defined HERE and not borrowed from `SupportMembershipTest`.
 *
 * ⚠ A Pest file-scope function is only defined when ITS file is loaded, so borrowing one
 * makes a file pass or die on test ORDER. This file did exactly that and died with
 * `Call to undefined function supportAdmin()` -- the trap `Tests\Support\LandingFixtures`
 * records in its own docblock and that `MemberConsentTest` already avoids by the same means.
 */
function sweepAdmin(): string
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Sweep admin',
        'email' => 'sweepadmin-'.str_replace('-', '', $id).'@example.test',
        'password' => 'x',
        'is_platform_admin' => true,
    ]);

    return $id;
}

/** Live support tenant for this user, read fresh. */
function sweepHeldBy(string $userId): ?string
{
    return app()->make(SupportMemberships::class)->heldTenantId($userId);
}

/** Rows in `tenant_users` for this user, as root so RLS cannot hide one. */
function sweepRows(string $userId): int
{
    return (int) DB::connection('pgsql_root')->table('tenant_users')->where('user_id', $userId)->count();
}

function rowExists(string $membershipId): bool
{
    return DB::connection('pgsql_root')->table('tenant_users')->where('id', $membershipId)->exists();
}

it('deletes a LAPSED support membership and leaves everything else alone', function (): void {
    $tenant = $this->makeTenant(slug: 'sweep-bound');

    // Three rows that must survive and one that must not. The survivors are the bound.
    $ordinary = membership($tenant, person(), null);
    $liveSupport = membership($tenant, person(), (string) now()->addMinutes(30));
    $otherTenantOrdinary = membership($this->makeTenant(slug: 'sweep-other'), person(), null);
    $lapsed = membership($tenant, person(), (string) now()->subMinute());

    $swept = (int) DB::connection(DatabaseRoles::connection('worker'))
        ->selectOne('select public.sweep_support_memberships() as swept')->swept;

    expect($swept)->toBe(1, 'the sweep took a different number of rows than the one that lapsed')
        ->and(rowExists($lapsed))->toBeFalse('the lapsed membership survived')
        ->and(rowExists($ordinary))->toBeTrue('an ORDINARY membership was swept')
        ->and(rowExists($liveSupport))->toBeTrue('a LIVE support membership was swept')
        ->and(rowExists($otherTenantOrdinary))->toBeTrue('another tenant\'s membership was swept');
});

it('frees the slot, so the admin can take support again after one lapses', function (): void {
    /*
     * The reason the sweep exists at all, driven end to end. Before it runs the lapsed row
     * still holds `(user_id)` in the partial unique index — correctly, since an index cannot
     * know about `now()` — so a second take is refused. After it runs, the take succeeds.
     */
    $admin = sweepAdmin();
    $first = $this->makeTenant(slug: 'sweep-slot-a');
    $second = $this->makeTenant(slug: 'sweep-slot-b');

    app(SupportAccess::class)->take($first, $admin, 'first visit');

    withTenant($first, fn () => DB::connection('pgsql_owner')->update(
        "update tenant_users set expires_at = now() - interval '1 minute' where user_id = ?",
        [$admin],
    ), 'pgsql_owner');

    // Lapsed: no ability, but the slot is still held.
    expect(sweepHeldBy($admin))->toBeNull()
        ->and(sweepRows($admin))->toBe(1);

    DB::connection(DatabaseRoles::connection('worker'))
        ->selectOne('select public.sweep_support_memberships() as swept');

    expect(sweepRows($admin))->toBe(0)
        ->and(app(SupportAccess::class)->take($second, $admin, 'second visit'))->toBeString();
});

it('gives the worker EXECUTE on the function and no privilege on the table', function (): void {
    /*
     * ⚠ THE GRANT POSTURE, READ BACK FROM THE CLUSTER. The plain `DELETE` answered
     * `42501`, and the fix was deliberately NOT `GRANT DELETE ON tenant_users TO
     * qrpager_worker`: `DELETE` has no column-level form, so that grant would let a
     * background role remove ANY membership. This asserts the narrow posture held.
     */
    $tablePrivileges = array_map(
        static fn (object $r): string => (string) $r->privilege_type,
        DB::connection('pgsql_root')->select(
            "select privilege_type from information_schema.table_privileges
              where table_name = 'tenant_users' and grantee = 'qrpager_worker'
              order by privilege_type"
        )
    );

    expect($tablePrivileges)->toBe(['SELECT'], 'the worker gained a write privilege on tenant_users');

    // And it can execute the one function it needs.
    $executors = (string) DB::connection('pgsql_root')->selectOne(
        "select coalesce(array_to_string(p.proacl, ','), '') as acl
           from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'sweep_support_memberships'"
    )->acl;

    /*
     * ⚠ `str_contains(...)->toBeFalse($message)` FOR THE NEGATIVES, NOT
     * `not->toContain($needle, $message)`. `composer guard` refused the second form under
     * `vacuous-negated-contains`, and its message explains it exactly: that matcher is
     * VARIADIC, so a message passed as a second argument is read as another NEEDLE and the
     * assertion becomes "does not contain BOTH" -- which a message that never appears makes
     * trivially true. It would have passed whatever the ACL said.
     *
     * The repository has a guard for this because it is easy to write and invisible to read.
     * Mine is the third instance it has caught.
     */
    expect($executors)->toContain('qrpager_worker=X');

    expect(str_contains($executors, 'qrpager_app=X'))
        ->toBeFalse('the app role can execute the sweep function');

    // PUBLIC appears in an ACL as a grant with an empty grantee, i.e. a leading `=`.
    expect(str_starts_with(ltrim($executors, '{'), '=X'))
        ->toBeFalse('EXECUTE is granted to PUBLIC');
});
