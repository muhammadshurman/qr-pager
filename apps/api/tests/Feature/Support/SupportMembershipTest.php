<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\Principal;
use App\Domain\Auth\RoleMatrix;
use App\Domain\Auth\SupportAccessRefused;
use App\Domain\Tenancy\TenantRole;
use App\Features\Support\SupportAccess;
use App\Platform\Tenancy\SupportMemberships;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * `QRPAGER-22`'s NAMED INVARIANT, DRIVEN. All five clauses.
 * ================================================================================
 *
 * *"A platform admin holding no active support membership can read no tenant row; one holding
 * an active membership can read only that tenant's rows; and expiry or release returns them
 * to the first state with no residual ability."*
 *
 * Built to `§4 › Tenancy`'s clause of 2026-11-04 exactly: lifetime fixed at 60 minutes and
 * not chosen by the taker, read-only, one at a time, take/act/release audited with tenant,
 * actor and reason.
 */

/** A platform admin with a password, holding no membership anywhere. */
function supportAdmin(): array
{
    $userId = (string) Str::uuid7();
    $address = 'admin-'.str_replace('-', '', $userId).'@example.test';
    $password = 'correct-horse-battery-staple';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Platform support',
        'email' => $address,
        'password' => Hash::make($password),
        'is_platform_admin' => true,
    ]);

    return ['user_id' => $userId, 'email' => $address, 'password' => $password];
}

function support(): SupportAccess
{
    return app(SupportAccess::class);
}

/** A reader built fresh, because `Memberships` memoises per instance. */
function heldBy(string $userId): ?string
{
    return app()->make(SupportMemberships::class)->heldTenantId($userId);
}

/** The support audit rows this tenant holds. */
function supportAudit(string $tenantId): array
{
    return withTenant($tenantId, fn (): array => DB::select(
        "select action, actor_type, actor_user_id, context::text as context
           from audit_log
          where tenant_id = ? and action like 'support.%'",
        [$tenantId],
    ));
}

/** Rows in `tenant_users` for this user, read as root so RLS cannot hide one. */
function membershipRows(string $userId): int
{
    return (int) DB::connection('pgsql_root')->table('tenant_users')->where('user_id', $userId)->count();
}

it('is read-only, derived from Ability::isWrite rather than from a list anybody typed', function (): void {
    /*
     * ⚠ **CLAUSE: *"It is read-only: it carries no write ability on any tenant table."***
     *
     * Derived, so a NEW write ability added to `Ability` later fails here until somebody
     * decides whether support holds it. A hand-checked list is how a read-only clause stops
     * being true with every test still green.
     */
    $writes = array_values(array_filter(Ability::cases(), static fn (Ability $a): bool => $a->isWrite()));
    $reads = array_values(array_filter(Ability::cases(), static fn (Ability $a): bool => ! $a->isWrite()));

    // Not vacuous: there ARE writes to exclude and reads to hold.
    expect($writes)->not->toBe([])
        ->and($reads)->not->toBe([])
        ->and(RoleMatrix::abilitiesFor(Principal::Support))->not->toBe([]);

    foreach ($writes as $write) {
        expect(RoleMatrix::allows(Principal::Support, $write))
            ->toBeFalse("support holds the write ability {$write->value}");
    }

    foreach (RoleMatrix::abilitiesFor(Principal::Support) as $held) {
        expect($held->isWrite())->toBeFalse("support holds {$held->value}, which writes");
    }
});

it('gives a platform admin with no support membership nothing, which is the BEFORE state', function (): void {
    // Clause 1, and the state release and expiry must return them to.
    expect(RoleMatrix::abilitiesFor(Principal::PlatformAdmin))->toBe([]);

    $admin = supportAdmin();

    expect(heldBy($admin['user_id']))->toBeNull()
        ->and(membershipRows($admin['user_id']))->toBe(0);
});

it('takes one, fixes the lifetime at 60 minutes, and resolves the Support principal', function (): void {
    $admin = supportAdmin();
    $tenant = $this->makeTenant(slug: 'support-take');

    $before = Carbon::now();
    $membershipId = support()->take($tenant, $admin['user_id'], 'customer reported a blank page');

    $row = DB::connection('pgsql_root')->table('tenant_users')->where('id', $membershipId)->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->tenant_id)->toBe($tenant)
        // The floor role, so a bypassed resolution fails closed rather than open.
        ->and((string) $row->role)->toBe(TenantRole::Staff->value)
        ->and($row->expires_at)->not->toBeNull();

    /*
     * ⚠ A WINDOW AROUND 60 MINUTES, NOT AN EQUALITY: the timestamp is the database's and the
     * comparison is the test's, so a strict `===` would be a clock-skew flake. A one-minute
     * window still refuses 30 or 480, which is what "fixed at 60 minutes and not chosen by
     * the taker" is about.
     */
    $expires = Carbon::parse((string) $row->expires_at);

    expect($expires->greaterThan($before->copy()->addMinutes(59)))->toBeTrue()
        ->and($expires->lessThan($before->copy()->addMinutes(61)))->toBeTrue()
        ->and(SupportAccess::LIFETIME_MINUTES)->toBe(60)
        // The principal is Support, not the stored role.
        ->and(Principal::forMembership(TenantRole::Staff, (string) $row->expires_at))
        ->toBe(Principal::Support);
});

it('refuses a second one, because §4 says never more than one at a time', function (): void {
    $admin = supportAdmin();
    $first = $this->makeTenant(slug: 'support-one-a');
    $second = $this->makeTenant(slug: 'support-one-b');

    support()->take($first, $admin['user_id'], 'first');

    expect(fn () => support()->take($second, $admin['user_id'], 'second'))
        ->toThrow(SupportAccessRefused::class);

    // The refusal left the first alone, which is the half an exception does not prove.
    expect((int) DB::connection('pgsql_root')->table('tenant_users')
        ->where('user_id', $admin['user_id'])->whereNotNull('expires_at')->count())->toBe(1);
});

it('enforces one-at-a-time in the DATABASE, not only in the service', function (): void {
    /*
     * The partial unique index, driven directly. The service's check is the friendly answer;
     * this is the guarantee, and it is what a race between two takes meets -- without it both
     * would pass the read and both would insert.
     */
    $admin = supportAdmin();
    $a = $this->makeTenant(slug: 'support-index-a');
    $b = $this->makeTenant(slug: 'support-index-b');

    support()->take($a, $admin['user_id'], 'first');

    $code = null;

    try {
        withTenant($b, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $b,
            'user_id' => $admin['user_id'],
            'role' => TenantRole::Staff->value,
            'expires_at' => Carbon::now()->addMinutes(60),
        ]), 'pgsql_owner');
    } catch (Throwable $e) {
        $code = $e->getPrevious()?->getCode() ?? $e->getCode();
    }

    expect((string) $code)->toBe('23505', 'the database accepted a second support membership');
});

it('refuses a take with no reason, before anything exists to audit', function (): void {
    $admin = supportAdmin();
    $tenant = $this->makeTenant(slug: 'support-no-reason');

    foreach (['', '   '] as $blank) {
        expect(fn () => support()->take($tenant, $admin['user_id'], $blank))
            ->toThrow(SupportAccessRefused::class);
    }

    expect(membershipRows($admin['user_id']))->toBe(0)
        ->and(supportAudit($tenant))->toBe([]);
});

it('refuses anybody who is not a platform admin', function (): void {
    $tenant = $this->makeTenant(slug: 'support-not-admin');

    $userId = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Ordinary person',
        'email' => 'ordinary-'.str_replace('-', '', $userId).'@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
        'is_platform_admin' => false,
    ]);

    expect(fn () => support()->take($tenant, $userId, 'trying it on'))
        ->toThrow(SupportAccessRefused::class);

    expect(membershipRows($userId))->toBe(0);
});

it('writes take, act and release to the audit trail with tenant, actor and reason', function (): void {
    /*
     * ⚠ CLAUSE: *"Taking it, acting under it and leaving it are each written to the audit
     * trail with the tenant, the actor and the reason."* All three, with the actor type
     * `platform` rather than `user` -- a support holder is not a member of the restaurant,
     * and the trail has to say which kind of actor it was.
     */
    $admin = supportAdmin();
    $tenant = $this->makeTenant(slug: 'support-audit');

    support()->take($tenant, $admin['user_id'], 'blank page on their live site');
    support()->recordAction($tenant, $admin['user_id'], 'read the landing draft');
    support()->release($admin['user_id'], 'finished');

    $rows = supportAudit($tenant);
    $actions = array_map(static fn (object $r): string => (string) $r->action, $rows);

    sort($actions);

    expect($actions)->toBe([SupportAccess::ACTED, SupportAccess::RELEASED, SupportAccess::TAKEN]);

    foreach ($rows as $row) {
        expect((string) $row->actor_type)->toBe('platform')
            ->and((string) $row->actor_user_id)->toBe($admin['user_id']);
    }

    $taken = array_values(array_filter($rows, static fn (object $r): bool => $r->action === SupportAccess::TAKEN));

    expect((string) $taken[0]->context)->toContain('blank page on their live site')
        ->and((string) $taken[0]->context)->toContain('lifetime_minutes');
});

it('leaves no row and no ability after RELEASE, which is the AFTER state', function (): void {
    $admin = supportAdmin();
    $tenant = $this->makeTenant(slug: 'support-release');

    support()->take($tenant, $admin['user_id'], 'looking at a complaint');

    expect(heldBy($admin['user_id']))->toBe($tenant);

    support()->release($admin['user_id'], 'done');

    expect(heldBy($admin['user_id']))->toBeNull()
        // Deleted, not marked: the strongest reading of "leaves no residual ability".
        ->and(membershipRows($admin['user_id']))->toBe(0);

    // And they may take another, which a spent row left in place would have blocked forever.
    $again = $this->makeTenant(slug: 'support-release-again');

    expect(support()->take($again, $admin['user_id'], 'a second visit'))->toBeString();
});

it('leaves no ability after EXPIRY, reached by the clock and not by a call', function (): void {
    /*
     * ⚠ **CLAUSE 3's OTHER HALF, AND THE ONE A RELEASE TEST CANNOT STAND IN FOR.** The row is
     * still there — nothing swept it — and it must grant nothing anyway, because
     * `public.user_memberships()` filters `expires_at > now()` in SQL. So there is no window
     * in which a lapsed membership still works, and no sweeper to be late.
     *
     * ⚠ **`Carbon::setTestNow()` DOES NOT WORK HERE, AND FINDING THAT OUT IS WORTH THE
     * COMMENT.** The first version of this case moved PHP's clock forward by 61 minutes and
     * the membership was still granted: the filter is `now()` in POSTGRES, so the only clock
     * that matters is the database's and `setTestNow` cannot touch it. Driving elapsed time
     * against a database predicate means moving the ROW into the past, which is what this
     * does — the state is identical to one that lapsed while nobody was looking.
     *
     * ⚠ AND IT IS BACKDATED AS `pgsql_owner`, BECAUSE THE APP ROLE CANNOT DO IT. `UPDATE` on
     * `tenant_users` is granted on `(role, updated_at)` only, so `qrpager_app` has no write
     * that could extend or shorten a support membership. The case below asserts that too.
     */
    $admin = supportAdmin();
    $tenant = $this->makeTenant(slug: 'support-expiry');

    support()->take($tenant, $admin['user_id'], 'will be left to lapse');

    expect(heldBy($admin['user_id']))->toBe($tenant);

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        "update tenant_users set expires_at = now() - interval '1 minute' where user_id = ?",
        [$admin['user_id']],
    ), 'pgsql_owner');

    // The row is STILL PRESENT, and grants nothing.
    expect(membershipRows($admin['user_id']))->toBe(1)
        ->and(heldBy($admin['user_id']))->toBeNull();

    // And a fresh take is possible once it has lapsed, which is what "no residual" means in
    // practice: the admin is back to the BEFORE state in every respect that matters.
    expect(fn () => support()->take($tenant, $admin['user_id'], 'again after lapsing'))
        ->toThrow(SupportAccessRefused::class);

    /*
     * ⚠ THAT LAST ASSERTION IS A REFUSAL AND NOT A SUCCESS, which is worth saying because it
     * looks like the opposite of the release case. The lapsed ROW is still in the table, so
     * the partial unique index still holds `(user_id)` — correctly, since the index cannot
     * know about `now()`. The sweeper is what frees it, and `SupportAccessSweepTest` covers
     * that. Until then the admin holds no ABILITY, which is what `§4` requires, and cannot
     * take a new one, which is strictly safer than the alternative.
     */
});

it('gives the app role no write that could extend a support membership', function (): void {
    /*
     * `§4` fixes the lifetime at 60 minutes and says it is not the taker's to choose. The
     * service never offers a parameter — and the column grant is what makes that structural:
     * `2026_10_25_150300` narrows `UPDATE` on `tenant_users` to `(role, updated_at)`, and
     * `QRPAGER-22` deliberately did not widen it. So a bug in the service cannot extend one,
     * because the role cannot write the column.
     */
    $granted = array_map(
        static fn (object $r): string => (string) $r->column_name,
        DB::connection('pgsql_root')->select(
            "select column_name from information_schema.column_privileges
              where table_name = 'tenant_users' and grantee = 'qrpager_app'
                and privilege_type = 'UPDATE'
              order by column_name"
        )
    );

    expect($granted)->toBe(['role', 'updated_at'])
        ->and($granted)->not->toContain('expires_at');
});
