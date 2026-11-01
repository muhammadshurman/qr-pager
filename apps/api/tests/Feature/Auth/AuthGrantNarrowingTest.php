<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE GRANTS AND THE FUNCTION SIGNATURE, DRIVEN AS STATEMENTS
 * ================================================================================
 *
 * `GrantMatrixTest` reads `information_schema` and pins the grant SETS, which is the right
 * shape for a catalog fact and is not the same thing as a refusal. This file drives the
 * statements and reads the SQLSTATE, because three of Task 5's `§11` findings were about
 * exactly the gap between those two:
 *
 *   - `R-006`'s own `§5` cell dictates a demonstration -- "proven by a test that the role
 *     gets `42501` on `select password from users`" -- and `PLAN.md` credited
 *     `PlatformAdminIsNotABypassTest` with it, which drives
 *     `update users set is_platform_admin`: a different verb on a different column. The
 *     statement the row names was never run by anything. (QA M-5.)
 *   - `public.authenticate_user(varchar)` returned `two_factor_secret` to the app role
 *     through a `SECURITY DEFINER` seam, one migration after a sibling migration refused to
 *     grant SELECT on that column in those words. (QA M-6.)
 *   - `INSERT (is_platform_admin)` existed while `UPDATE` deliberately did not. (SEC L-1.)
 *   - `DELETE ON passkeys` was table-wide, on an RLS-free table, and unused. (SEC L-2.)
 *
 * Every case below is as `qrpager_app`, the role the application actually runs as.
 */

it('refuses the app role every withheld users column, as the statements R-006 names', function (): void {
    // R-006's CLOSURE CLAUSE, driven verbatim at last. The row says `select password from
    // users`; that is the first assertion, and the rest are the same control on the columns
    // the same migration withheld.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $withheld = [
        'select password from users' => 'users.password',
        'select remember_token from users' => 'users.remember_token',
        'select two_factor_secret from users' => 'users.two_factor_secret',
        'select two_factor_recovery_codes from users' => 'users.two_factor_recovery_codes',
        // `select *` is the one that matters most in practice, because it is Eloquent's
        // DEFAULT. R-103 is the §5 row about the ways to emit it by accident.
        'select * from users' => 'every users column at once',
    ];

    foreach ($withheld as $statement => $what) {
        expect($this->sqlStateOf(fn () => DB::select($statement)))
            ->toBe('42501', "the app role can read {$what}");
    }

    // THE POSITIVE CONTROL. Without it, five 42501s are indistinguishable from a role that
    // cannot read `users` at all -- which would be a different (and broken) application.
    //
    // ⚠ `toBeArray()` WAS NOT A CONTROL: DB::select() always returns an array, so the
    // assertion held whatever the grants were. The §11 review of the fix diff caught it.
    // What the control has to say is that the statement SUCCEEDS and returns the acting
    // user's own row, so it is written that way.
    expect($this->sqlStateOf(fn () => DB::select(
        'select id, email, name, is_platform_admin, two_factor_confirmed_at from users limit 1',
    )))->toBeNull('the app role cannot read the users columns it IS granted');

    $mine = DB::selectOne('select id, email from users where id = ?', [(string) auth()->id()]);

    expect($mine)->not->toBeNull('the app role could not read its own user row')
        ->and((string) $mine->id)->toBe((string) auth()->id());
});

it('refuses the app role the platform-admin flag at INSERT as well as at UPDATE', function (): void {
    // SEC L-1. The UPDATE half was already driven by PlatformAdminIsNotABypassTest; the
    // INSERT half was granted, and is the same escalation one row earlier. Unreachable at
    // Task 5 -- nothing inserts a user -- and Task 6 is the task that creates them.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $id = (string) Str::uuid7();

    $state = $this->sqlStateOf(fn () => DB::insert(
        'insert into users (id, name, email, password, is_platform_admin) values (?, ?, ?, ?, true)',
        [$id, 'Escalated', 'escalated-'.str_replace('-', '', $id).'@example.test', Hash::make('x')],
    ));

    expect($state)->toBe('42501', 'the app role can write users.is_platform_admin at INSERT time');

    // No row landed, so the refusal is not a partial write with the flag dropped.
    expect(DB::connection('pgsql_owner')->table('users')->where('id', $id)->exists())->toBeFalse();

    // AND THE SAME INSERT WITHOUT THE FLAG SUCCEEDS, which is what makes the refusal about
    // the column rather than about the statement.
    $allowed = (string) Str::uuid7();

    DB::insert(
        'insert into users (id, name, email, password) values (?, ?, ?, ?)',
        [$allowed, 'Ordinary', 'ordinary-'.str_replace('-', '', $allowed).'@example.test', Hash::make('x')],
    );

    expect(DB::connection('pgsql_owner')->table('users')->where('id', $allowed)->exists())->toBeTrue();
});

it('holds no DELETE on passkeys, because no route deletes one yet', function (): void {
    // SEC L-2. Table-wide DELETE on an RLS-FREE table, unused: `passkeys` has no policy to
    // stand behind it, so the first delete path added without its own `user_id` predicate
    // would have no database backstop. The grant returns with the route that needs it.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    expect($this->sqlStateOf(fn () => DB::delete('delete from passkeys where id = ?', [(string) Str::uuid7()])))
        ->toBe('42501', 'the app role can delete passkeys rows');

    // The reads and writes the registration ceremony needs are untouched, so this is a
    // narrowing and not a lockout.
    expect($this->sqlStateOf(fn () => DB::select('select id, user_id, name from passkeys limit 1')))
        ->toBeNull();
});

it('lets the app role persist the WebAuthn signature counter, which login could not', function (): void {
    /*
     * ⛔ THE STATEMENT THAT RETURNED 403 FOR EVERY PASSKEY LOGIN, and nothing in this suite
     * ran it before. `VerifyPasskey::updatePasskey()` writes `credential` and `last_used_at`
     * after every successful assertion, and `2026_10_28_120000_create_auth_contract.php`
     * granted UPDATE on `name, last_used_at, updated_at` only -- so the UPDATE raised
     * `42501` and `DatabaseErrorMapper` turned it into `ProblemType::Forbidden`.
     *
     * It was unreachable from here because the counter is written only after a real
     * authenticator signs a real challenge. Task 9's `browser/passkey.spec.ts` -- a virtual
     * authenticator over CDP against the real API -- found it, which is what `R-100` asked
     * that toolchain for. This case is the half that does not need a browser.
     *
     * ⚠ ONE STATEMENT, AND IT IS THE VENDOR'S OWN. Not a reduced stand-in: the SET list is
     * the two columns that method writes plus the `updated_at` Eloquent adds, so a grant that
     * covered the test's statement and not the application's would fail here.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    expect($this->sqlStateOf(fn () => DB::update(
        'update passkeys set credential = ?, last_used_at = now(), updated_at = now() where id = ?',
        [json_encode(['counter' => 1]), (string) Str::uuid7()]
    )))->toBeNull('the app role cannot persist a used credential, so passkey login 403s');

    // The POSITIVE CONTROL's mirror: the columns that must stay unwritable. A credential's
    // identity never changes -- a changed `credential_id` is a different key -- and
    // re-pointing a key at another account is the escalation `narrow_auth_grants` refuses.
    foreach (['credential_id' => "'x'", 'user_id' => "'".Str::uuid7()."'"] as $column => $value) {
        expect($this->sqlStateOf(fn () => DB::update(
            "update passkeys set {$column} = {$value} where id = ?",
            [(string) Str::uuid7()]
        )))->toBe('42501', "the app role can move passkeys.{$column}");
    }
});

it('grants the app role UPDATE on exactly four passkeys columns, and no more', function (): void {
    // The catalog half, EXACT rather than "contains": the fix above is one column, and a
    // later widening -- to `credential_id`, to `user_id`, or table-wide -- fails here instead
    // of passing quietly. `GrantMatrixTest` pins reachability; this pins the verb.
    $rows = DB::connection('pgsql_root')->select(
        "select column_name
           from information_schema.column_privileges
          where table_name = 'passkeys'
            and grantee = 'qrpager_app'
            and privilege_type = 'UPDATE'
          order by column_name",
    );

    expect(array_map(fn (object $r): string => (string) $r->column_name, $rows))
        ->toBe(['credential', 'last_used_at', 'name', 'updated_at']);
});

it('returns no secret from authenticate_user, and exactly the columns the login path reads', function (): void {
    /*
     * QA M-6, and the reason it is worth a test of its own: the control that was supposed
     * to hold here is a GRANT, and a `SECURITY DEFINER` function is a hole straight through
     * one. `2026_10_28_130000_create_two_factor_reader.php` refuses to grant the app role
     * SELECT on `two_factor_secret` because doing so "re-opens the exact shape R-006 is
     * about, one task after closing it" -- and the function beside it returned that column
     * to that role, one readable email at a time.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // Lower-cased: users_email_is_lowercase is a CHECK constraint, and Str::random() is
    // mixed case -- a 23514 from a test fixture is R-007's own lesson.
    $email = 'authfn-'.Str::lower(Str::random(10)).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'Function Probe',
        'email' => $email,
        'password' => Hash::make('correct-horse-battery-staple'),
        'two_factor_secret' => encrypt('SHOULDNOTBEREADABLE'),
    ]);

    // THE REFUSAL, driven through the function rather than through the table.
    expect($this->sqlStateOf(fn () => DB::select(
        'select two_factor_secret from public.authenticate_user(?)', [$email],
    )))->toBe('42703', 'authenticate_user still returns two_factor_secret');

    // AND THE COLUMN LIST IS PINNED, so a later edit cannot widen it quietly. Read from
    // pg_catalog rather than by selecting, because a missing column is the thing under test.
    $columns = DB::connection('pgsql_owner')->select(
        "select unnest(p.proargnames) as name, unnest(p.proargmodes) as mode
           from pg_proc p
           join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'authenticate_user'",
    );

    $returned = array_values(array_map(
        fn (object $row): string => (string) $row->name,
        array_filter($columns, fn (object $row): bool => (string) $row->mode === 't'),
    ));

    sort($returned);

    expect($returned)->toBe([
        'email_verified_at', 'is_platform_admin', 'password_hash',
        'two_factor_confirmed_at', 'user_id',
    ], 'authenticate_user\'s result columns changed -- widening this set re-opens R-006');

    // THE POSITIVE CONTROL: the three columns UserCredentials::verify actually selects
    // still come back, and the login the function exists for still works end to end.
    $row = DB::selectOne(
        'select user_id, password_hash, two_factor_confirmed_at from public.authenticate_user(?)',
        [$email],
    );

    expect($row)->not->toBeNull()
        ->and((string) $row->password_hash)->not->toBeEmpty();

    expect(Hash::check('correct-horse-battery-staple', (string) $row->password_hash))->toBeTrue();
});
