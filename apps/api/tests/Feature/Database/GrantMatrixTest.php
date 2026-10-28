<?php

declare(strict_types=1);

use Tests\Support\Schema;

/*
 * The column-grant matrix in PLAN.md §2.7, checked against
 * information_schema rather than against the migration that wrote it.
 *
 * The point of column grants is that over-broad SQL cannot leak what the role
 * was never given, so these are asserted as exact sets: a grant that widens
 * later has to widen this table too, in the diff, on purpose.
 */

it('keeps token_hash write-only for the app role and unreadable for the worker', function (): void {
    expect(Schema::grantedColumns('qrpager_app', 'pager_sessions', 'SELECT'))->not->toContain('token_hash')
        ->and(Schema::grantedColumns('qrpager_app', 'pager_sessions', 'INSERT'))->toContain('token_hash')
        ->and(Schema::grantedColumns('qrpager_worker', 'pager_sessions', 'SELECT'))->not->toContain('token_hash')
        ->and(Schema::grantedColumns('qrpager_worker', 'pager_sessions', 'INSERT'))->toBe([]);
});

it('grants UPDATE on no tenant_id column anywhere', function (): void {
    foreach (Schema::tenantScopedTables() as $table) {
        $column = $table === 'tenants' ? 'id' : 'tenant_id';

        foreach (['qrpager_app', 'qrpager_worker'] as $role) {
            // in_array() AND NOT `->not->toContain($column, "message")`, which is what
            // this line was and which asserted NOTHING. Pest's toContain takes
            // ...$needles, so a message passed as a second argument is read as a SECOND
            // NEEDLE -- and under ->not the whole thing becomes "does not contain BOTH",
            // which a message that is never present makes trivially true. Measured
            // 2026-10-27: expect('hello app_limit')->not->toContain('app_limit') fails as
            // it should, and expect('hello app_limit')->not->toContain('app_limit',
            // 'msg') PASSES. So this control -- no role may update a tenant key, which is
            // what makes moving a row between tenants impossible -- was green and inert.
            // Found at Task 4; the same defect was in PagerTokenTest and in
            // OpenApiSpecTest's R-052 sweep, and ExpectationApiTest now forbids the shape.
            expect(in_array($column, Schema::grantedColumns($role, $table, 'UPDATE'), true))
                ->toBeFalse("{$role} can update {$table}.{$column} and move a row between tenants");
        }
    }
});

it('grants the app exactly the pager_sessions updates the state machine needs', function (): void {
    expect(Schema::grantedColumns('qrpager_app', 'pager_sessions', 'UPDATE'))->toBe([
        'cancelled_at', 'completed_at', 'last_notified_at', 'ready_at', 'renotify_count', 'status', 'updated_at',
    ]);
});

it('grants the worker only the expiry columns on pager_sessions', function (): void {
    expect(Schema::grantedColumns('qrpager_worker', 'pager_sessions', 'UPDATE'))
        ->toBe(['expired_at', 'status', 'updated_at']);
});

it('lets the app insert an outbox row only as pending', function (): void {
    // No INSERT grant on status, so the column default decides: an app cannot
    // write a row that is already 'sent'.
    expect(Schema::grantedColumns('qrpager_app', 'outbox', 'INSERT'))->not->toContain('status')
        ->and(Schema::grantedColumns('qrpager_worker', 'outbox', 'UPDATE'))->toContain('status');
});

it('gives the worker no insert or delete on the outbox', function (): void {
    expect(Schema::hasTablePrivilege('qrpager_worker', 'outbox', 'INSERT'))->toBeFalse()
        ->and(Schema::hasTablePrivilege('qrpager_worker', 'outbox', 'DELETE'))->toBeFalse();
});

it('gives the app no delete on sessions or the outbox', function (): void {
    // Sessions are cancelled or they expire; they are not deleted (PLAN.md §2.7).
    expect(Schema::hasTablePrivilege('qrpager_app', 'pager_sessions', 'DELETE'))->toBeFalse()
        ->and(Schema::hasTablePrivilege('qrpager_app', 'outbox', 'DELETE'))->toBeFalse();
});

it('lets the worker prune dead push subscriptions', function (): void {
    expect(Schema::hasTablePrivilege('qrpager_worker', 'push_subscriptions', 'DELETE'))->toBeTrue();
});

it('keeps failed_jobs away from the app role', function (): void {
    // A serialized job payload can carry tenant data and the table has no RLS.
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        expect(Schema::hasTablePrivilege('qrpager_app', 'failed_jobs', $privilege))
            ->toBeFalse("the app role holds {$privilege} on failed_jobs");
    }

    expect(Schema::hasTablePrivilege('qrpager_worker', 'failed_jobs', 'SELECT'))->toBeTrue();
});

it('keeps the migrations table away from both runtime roles', function (): void {
    foreach (['qrpager_app', 'qrpager_worker'] as $role) {
        expect(Schema::hasTablePrivilege($role, 'migrations', 'SELECT'))->toBeFalse();
    }
});

it('reaches exactly the tables in the matrix and no others', function (): void {
    // Deny by default, asserted as an exact set: a table added without a grant is
    // invisible to the application and fails loudly, and a grant that widens the
    // reach has to widen this list too, in the diff, on purpose.
    expect(Schema::reachableTables('qrpager_app'))->toBe([
        // passkeys and personal_access_tokens joined at Task 5. Both are global and
        // RLS-free, and RlsCoverageTest's pinned allowlist carries the argument for each.
        'audit_log', 'outbox', 'pager_sessions', 'passkeys', 'password_reset_tokens',
        'personal_access_tokens', 'push_subscriptions', 'tenant_users', 'tenants', 'users',
    ]);

    expect(Schema::reachableTables('qrpager_worker'))->toBe([
        'audit_log', 'failed_jobs', 'outbox', 'pager_sessions',
        'push_subscriptions', 'tenant_users', 'tenants', 'users',
    ]);
});

/*
 * F2 (security review of task-1): users and password_reset_tokens were the only
 * tables granted table-wide, and the only ones with no assertion here at all --
 * so the widest grants in the schema were the ones nothing would ever catch
 * widening. They are now stated per column in the migration and pinned here.
 */

it('states the app grants on users as exact column sets', function (): void {
    // NARROWED AT TASK 5 -- this is R-006 closing, and the four names that are ABSENT from
    // the SELECT set are the whole point of the row:
    //
    //     password  remember_token  two_factor_secret  two_factor_recovery_codes
    //
    // R-006's review-by cell required exactly this: "qrpager_app holds no SELECT on
    // users.password or users.remember_token; authentication reads the hash only through a
    // named SECURITY DEFINER function granted to qrpager_app alone". The two 2FA secrets
    // are added by the same migration and are never granted SELECT either -- same class of
    // secret, so granting them would have re-opened the row at the moment it closed.
    expect(Schema::grantedColumns('qrpager_app', 'users', 'SELECT'))->toBe([
        'created_at', 'email', 'email_verified_at', 'id', 'is_platform_admin', 'name',
        'two_factor_confirmed_at', 'updated_at',
    ]);

    // A WRITE WITHOUT A READ, which §2.7 already proves is possible with
    // pager_sessions.token_hash. Enrolling 2FA and rotating a password are writes; neither
    // needs to read the old value, because verification goes through the function.
    //
    // AND NO is_platform_admin HERE EITHER, which it had until Task 5's security review.
    // The UPDATE set below has carried that absence from the start, with a comment arguing
    // that writing the flag "would make privilege escalation a single UPDATE" -- and the
    // INSERT set granted the same column, which is the same escalation one row earlier.
    // Nothing inserted a user at Task 5 (Fortify's registration routes are not mounted and
    // MemberDirectory only attaches existing users), so it was unreachable rather than
    // exploited; Task 6 is tenant provisioning and creating users is the first thing it
    // does. Narrowed by 2026_10_28_140000_narrow_auth_grants.
    expect(Schema::grantedColumns('qrpager_app', 'users', 'INSERT'))->toBe([
        'created_at', 'email', 'email_verified_at', 'id', 'name',
        'password', 'remember_token', 'two_factor_confirmed_at', 'two_factor_recovery_codes',
        'two_factor_secret', 'updated_at',
    ]);

    // No id and no created_at: a uuid primary key and a creation timestamp are never
    // rewritten by the application. And NO is_platform_admin: granting a tenant-facing
    // role the ability to write the platform-admin flag would make privilege escalation a
    // single UPDATE. The absence of the grant is the control -- not a policy check that
    // could be wrong.
    expect(Schema::grantedColumns('qrpager_app', 'users', 'UPDATE'))->toBe([
        'email', 'email_verified_at', 'name', 'password', 'remember_token',
        'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret',
        'updated_at',
    ]);

    // Nobody deletes a user row; deactivation is a status change (PLAN.md §2.4).
    expect(Schema::hasTablePrivilege('qrpager_app', 'users', 'DELETE'))->toBeFalse();

    // And the grants are COLUMN grants, not a table-wide one that happens to cover
    // every column: has_table_privilege reports false for a table reachable only
    // through column grants, so this is what pins the shape rather than the set.
    foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
        expect(Schema::hasTablePrivilege('qrpager_app', 'users', $privilege))
            ->toBeFalse("users.{$privilege} is granted table-wide, not per column");
    }
});

it('keeps user credentials away from the worker role entirely', function (): void {
    // The worker authenticates nobody: a name and an address are all a notification
    // needs. password and remember_token are not reachable at all.
    expect(Schema::grantedColumns('qrpager_worker', 'users', 'SELECT'))->toBe(['email', 'id', 'name']);

    foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        expect(Schema::hasTablePrivilege('qrpager_worker', 'users', $privilege))
            ->toBeFalse("the worker role holds {$privilege} on users");
    }

    expect(Schema::hasTablePrivilege('qrpager_worker', 'users', 'SELECT'))
        ->toBeFalse('users.SELECT is granted to the worker table-wide, not per column');
});

it('states the password_reset_tokens grants as exact column sets', function (): void {
    $all = ['created_at', 'email', 'token'];

    expect(Schema::grantedColumns('qrpager_app', 'password_reset_tokens', 'SELECT'))->toBe($all)
        ->and(Schema::grantedColumns('qrpager_app', 'password_reset_tokens', 'INSERT'))->toBe($all)
        ->and(Schema::hasTablePrivilege('qrpager_app', 'password_reset_tokens', 'DELETE'))->toBeTrue()
        // A reset token is written once and consumed whole; it is never edited.
        ->and(Schema::hasTablePrivilege('qrpager_app', 'password_reset_tokens', 'UPDATE'))->toBeFalse();

    // SELECT and INSERT are column grants; only DELETE is table-wide, because
    // Postgres has no column form for it.
    foreach (['SELECT', 'INSERT'] as $privilege) {
        expect(Schema::hasTablePrivilege('qrpager_app', 'password_reset_tokens', $privilege))
            ->toBeFalse("password_reset_tokens.{$privilege} is granted table-wide, not per column");
    }

    // The worker has no part in password recovery.
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        expect(Schema::hasTablePrivilege('qrpager_worker', 'password_reset_tokens', $privilege))
            ->toBeFalse("the worker role holds {$privilege} on password_reset_tokens");
    }
});
