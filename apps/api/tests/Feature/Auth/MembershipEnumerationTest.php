<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * WHAT THE `SECURITY DEFINER` READERS DO *NOT* BOUND — PINNED AS A MEASURED FACT
 * ================================================================================
 *
 * ⚠ THIS FILE ASSERTS THAT SOMETHING IS POSSIBLE. That is unusual and it is deliberate.
 *
 * `PLAN.md §2.8` rule 1 says of these functions: *"one exact-match argument, so each answers
 * one question and cannot enumerate"*. `Memberships`' docblock said *"No input shape returns
 * a second user's memberships"*. Both read as a DATABASE-level bound. The `§11` review of
 * Task 5's fix diff measured that neither is one: a `SECURITY DEFINER` function runs with the
 * tenant bypass on, so a role that can read `users` can join over it and get every row.
 *
 * The real bound is the PHP one — every call site passes a server-derived id, pinned by
 * `MembershipCallSiteTest`. That is a genuine control. It is not RLS, and `§10.3(d)` leans on
 * RLS as the thing that holds *"even if the policy check is wrong"*, which for reads through
 * these two functions it does not.
 *
 * So rather than leave three documents implying a backstop that is not there, the absence is
 * driven here. If a later task adds one — a bound argument, a per-call nonce, a narrower
 * grant — these cases go RED, and that is exactly the signal wanted: the prose and the
 * schema will have stopped disagreeing. `R-108` owns the decision.
 */

/** A user with a membership in a fresh tenant, and a 2FA secret. Returns the user id. */
function enumerationFixture(TenantRole $role = TenantRole::Owner): string
{
    $userId = (string) Str::uuid7();
    $tenant = test()->makeTenant();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Enumeration Fixture',
        'email' => 'enum-'.substr(str_replace('-', '', $userId), 0, 12).'@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
        'two_factor_secret' => encrypt('ENUMERATIONSECRET'),
        'two_factor_confirmed_at' => now(),
    ]);

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $userId,
        'role' => $role->value,
    ]), 'pgsql_owner');

    return $userId;
}

it('cannot read tenant_users directly, which is the control that DOES hold', function (): void {
    // THE BASELINE, and it is the thing worth keeping: with no tenant scope open, the app
    // role sees nothing of `tenant_users`. RLS is doing its job on the TABLE.
    $this->actingForTenant($this->makeTenant(), TenantRole::Owner);

    $mine = [enumerationFixture(), enumerationFixture(TenantRole::Staff)];

    // ⚠ A `count(*)` IS ALWAYS >= 0, so the first version of this case wrapped the read in
    // `withoutTenantScope` and asserted exactly that -- an assertion that could not fail, in
    // the file added to pin measured facts. The `§11` review of this diff caught it.
    //
    // The ground truth belongs on the OWNER connection, where it is a real number; and it
    // counts only THIS test's own rows, so the figure does not depend on what else is in a
    // shared table. (A threshold over the whole table was the second version, and it went
    // red once when run beside another file.)
    $truth = withoutTenantScope(
        'tests: ground truth for this fixture\'s own memberships',
        fn (): int => (int) DB::connection('pgsql_owner')->table('tenant_users')
            ->whereIn('user_id', $mine)->count(),
    );

    expect($truth)->toBe(2,
        'the fixture did not create its two memberships, so "the app role sees none of them" '
        .'is not saying anything',
    );

    $unscoped = (int) DB::selectOne('select count(*) as c from tenant_users')->c;

    expect($unscoped)->toBe(0, 'the app role read tenant_users with no tenant scope open');
});

it('CAN enumerate every tenant\'s memberships by joining over user_memberships, and that is not bounded by the database', function (): void {
    // The same role, the same session, one statement apart from the baseline above.
    $this->actingForTenant($this->makeTenant(), TenantRole::Owner);

    enumerationFixture();
    enumerationFixture(TenantRole::Staff);

    $unscoped = (int) DB::selectOne('select count(*) as c from tenant_users')->c;

    expect($unscoped)->toBe(0, 'the baseline changed -- read the other case in this file first');

    // THE JOIN. `users` is global and the app role can read its id column, so this needs no
    // input the caller does not already have.
    $enumerated = DB::selectOne(
        'select count(*) as rows, count(distinct m.tenant_id) as tenants
           from users u, public.user_memberships(u.id) m',
    );

    // Ground truth, read as the owner with the bypass, so the comparison is against reality
    // rather than against another guess.
    $truth = withoutTenantScope('tests: ground truth for the membership enumeration', fn () => DB::connection('pgsql_owner')
        ->selectOne('select count(*) as rows, count(distinct tenant_id) as tenants from tenant_users'));

    expect((int) $enumerated->rows)->toBe((int) $truth->rows,
        'the lateral join no longer returns every membership -- if a bound was added, update '
        .'Memberships\' docblock, PLAN.md §2.8 and R-108, because the prose now UNDERSTATES '
        .'the control',
    );

    expect((int) $enumerated->tenants)->toBe((int) $truth->tenants)
        ->and((int) $enumerated->tenants)->toBeGreaterThan(1,
            'the fixture made fewer than two tenants, so this case is not showing cross-tenant reach',
        );
});

it('CAN read every account\'s two-factor ciphertext through user_two_factor, although the column grant withholds it', function (): void {
    /*
     * The sibling seam, and the sharper one: `2026_10_28_130000_create_two_factor_reader`
     * REFUSES to grant the app role SELECT on `two_factor_secret`, in those words, because
     * doing so "re-opens the exact shape `R-006` is about". `2026_10_28_140000` then dropped
     * the same column from `authenticate_user` for the same reason. The function created
     * alongside them hands it over one join at a time.
     */
    $this->actingForTenant($this->makeTenant(), TenantRole::Owner);

    enumerationFixture();
    enumerationFixture();

    // The grant really is withheld -- this is the assertion the narrowing migration rests on.
    expect($this->sqlStateOf(fn () => DB::select('select two_factor_secret from users')))
        ->toBe('42501', 'the app role can read users.two_factor_secret directly');

    // And the function returns it anyway, for every row, with no email and no id needed.
    $rows = DB::select(
        'select u.id, t.two_factor_secret
           from users u, public.user_two_factor(u.id) t
          where t.two_factor_secret is not null',
    );

    expect(count($rows))->toBeGreaterThanOrEqual(2,
        'user_two_factor stopped returning other accounts\' secrets -- if a bound was added, '
        .'R-108 and the migration headers need updating, because the control is now stronger '
        .'than the prose',
    );

    // It is the CIPHERTEXT, which is the one mitigation that is real: decrypting it needs
    // APP_KEY, which is not in the database.
    foreach ($rows as $row) {
        expect((string) $row->two_factor_secret)->not->toBe('ENUMERATIONSECRET',
            'user_two_factor returned a PLAINTEXT secret',
        );
    }
});

it('CAN read every account\'s bcrypt password hash through authenticate_user, which is the strongest of the three seams', function (): void {
    /*
     * ⚠ THE THIRD SEAM, AND THE WORST ASSET — found by the SECOND `§11` review of the fix
     * diff, after the first version of this file pinned two and stopped.
     *
     * `public.authenticate_user(varchar)` is `SECURITY DEFINER` and returns `password_hash`.
     * `R-006`'s entire closure is that `qrpager_app` holds no SELECT on `users.password`, and
     * `2026_10_28_140000` dropped `two_factor_secret` from this very function for exactly
     * that reason — while leaving the password hash in it.
     *
     * Why this is the worst of the three: a membership map is metadata and
     * `user_two_factor`'s secret comes back as CIPHERTEXT needing `APP_KEY`, which is not in
     * the database. **A bcrypt hash is an offline-crackable credential on its own.** The
     * reviewer measured eleven of fourteen at cost 4 on the dev database (a test-fixture
     * cost, not production's 12 — but the shape is the point).
     *
     * Same reachability class as the other two: it needs arbitrary SQL as the app role, so
     * SQL injection or a foothold is the precondition, and no route takes a user id or an
     * email from input into this function (`UserCredentials::verify` passes a lower-cased
     * email from a validated `LoginRequest` and reads three columns). `R-108` owns all three.
     */
    $this->actingForTenant($this->makeTenant(), TenantRole::Owner);

    $mine = [enumerationFixture(), enumerationFixture()];

    // The grant is withheld -- this is what R-006 was closed with.
    expect($this->sqlStateOf(fn () => DB::select('select password from users')))
        ->toBe('42501', 'the app role can read users.password directly');

    // And the function hands it over for every readable email, in one statement.
    $hashes = DB::select(
        'select u.id, a.password_hash
           from users u, public.authenticate_user(u.email) a
          where a.password_hash is not null and a.password_hash <> \'\'',
    );

    expect(count($hashes))->toBeGreaterThanOrEqual(count($mine),
        'authenticate_user stopped returning other accounts\' password hashes -- if a bound '
        .'was added, R-108 and the migration headers need updating, because the control is '
        .'now stronger than the prose',
    );

    // They are real bcrypt hashes and not a placeholder, which is what makes this a
    // credential rather than a shape.
    $looksLikeBcrypt = array_filter(
        $hashes,
        fn (object $row): bool => str_starts_with((string) $row->password_hash, '$2y$'),
    );

    expect(count($looksLikeBcrypt))->toBeGreaterThanOrEqual(count($mine),
        'the hashes returned are not bcrypt, so this case is not measuring what it claims',
    );
});
