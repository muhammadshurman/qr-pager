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
        //
        // platform_audit_log, tenant_provisionings and tenant_statistics joined at Task 6.
        // All three are PLATFORM tables, which is what makes the admin panel possible
        // without a cross-tenant read: the panel reads and writes these and nothing else.
        // tenant_statistics is SELECT-only for this role -- the aggregate job is its only
        // writer -- which the per-column case below pins.
        //
        // landing_pages, media_assets and tenant_domains joined at Task 7. All three are
        // TENANT tables, and they are reachable by this role and by NO OTHER -- see the
        // worker's list below, which does not grow.
        'audit_log', 'landing_pages', 'media_assets', 'outbox', 'pager_sessions',
        'passkeys', 'password_reset_tokens', 'personal_access_tokens',
        'platform_audit_log', 'push_subscriptions', 'tenant_domains',
        'tenant_provisionings', 'tenant_statistics', 'tenant_users', 'tenants', 'users',
    ]);

    // UNCHANGED BY TASK 7, AND THAT IS THE ASSERTION. QRPAGER-14 §6.1 granted the worker
    // SELECT on tenant_domains and landing_pages, justified by "the verification and
    // issuance polling commands run as the worker". A worker sweeping every tenant's
    // domains is a fifth cross-tenant read and §4 › Tenancy names four, so the sweep was
    // never built and the grant it implied was never made (QRPAGER-14 §0 SR-3, R-130).
    // This list is where that stays true: adding the grant later fails here.
    expect(Schema::reachableTables('qrpager_worker'))->toBe([
        'audit_log', 'failed_jobs', 'outbox', 'pager_sessions', 'platform_audit_log',
        'push_subscriptions', 'tenant_provisionings', 'tenant_statistics',
        'tenant_users', 'tenants', 'users',
    ]);
});

it('states Task 7 grants as exact column sets, with the three absences that are the design', function (): void {
    // NO DELETE ON landing_pages FOR ANYONE. A tenant has one page row and every other
    // row points at it; emptying the page is an UPDATE to 'draft', not a delete.
    foreach (['qrpager_app', 'qrpager_worker', 'qrpager_owner'] as $role) {
        expect(Schema::hasTablePrivilege($role, 'landing_pages', 'DELETE'))->toBeFalse();
    }

    // NO UPDATE ON media_assets FOR EITHER ROLE THAT SERVES TRAFFIC. Every column
    // describes bytes already in the object store, so an update leaves a row that no
    // longer describes its object.
    //
    // ⚠ THE OWNER KEEPS IT, AND THAT IS A NARROWED CLAIM RATHER THAN AN EXCEPTION.
    // Revoking UPDATE from the owner was tried and taken back out: deleting a `tenants`
    // row makes Postgres take `SELECT … FROM ONLY media_assets … FOR KEY SHARE`, that
    // lock requires the UPDATE privilege and not merely SELECT, and the delete answered
    // 42501 from the owner connection. Measured, and recorded in the migration. The
    // assertion below is the honest form: the roles that cannot run DDL hold none.
    foreach (['qrpager_app', 'qrpager_worker'] as $role) {
        expect(Schema::grantedColumns($role, 'media_assets', 'UPDATE'))->toBe([]);
    }

    expect(Schema::hasTablePrivilege('qrpager_owner', 'media_assets', 'UPDATE'))
        ->toBeTrue('the owner needs UPDATE for the FOR KEY SHARE lock referential integrity takes');

    // NOTHING AT ALL FOR THE WORKER on any of the three -- SR-3 again, per column this
    // time, because the table-level list above would still pass if a single column were
    // granted without the table becoming "reachable" in some future definition of it.
    foreach (['landing_pages', 'media_assets', 'tenant_domains'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            expect(Schema::grantedColumns('qrpager_worker', $table, $privilege))
                ->toBe([], "qrpager_worker holds {$privilege} on {$table}; SR-3 says it holds nothing");
        }

        expect(Schema::hasTablePrivilege('qrpager_worker', $table, 'DELETE'))->toBeFalse();
    }

    // The app's own sets, stated exactly. tenant_id is insertable and never updatable on
    // any of the three: a row does not change tenant.
    expect(Schema::grantedColumns('qrpager_app', 'landing_pages', 'UPDATE'))->toBe([
        'draft_document', 'draft_updated_at', 'published_at', 'published_checksum',
        'published_document', 'published_hostname', 'status', 'updated_at',
    ]);

    expect(Schema::grantedColumns('qrpager_app', 'tenant_domains', 'INSERT'))->toBe([
        'created_at', 'hostname', 'id', 'status', 'tenant_id', 'updated_at',
        'verification_token',
    ]);

    // Every lifecycle column is UPDATE-only: a domain is always created with nothing
    // earned, and dns_verified_at, the certificate pair and activated_at can only ever
    // arrive through an update the transition table and the CHECKs both bound.
    expect(Schema::grantedColumns('qrpager_app', 'tenant_domains', 'UPDATE'))->toBe([
        'activated_at', 'certificate_identifier', 'certificate_issued_at', 'checked_at',
        'dns_verified_at', 'last_error', 'redirects_to_domain_id', 'status', 'updated_at',
    ]);
});

it('keeps the panel out of the aggregate table as a writer, and the worker out of the registry', function (): void {
    // THE RULING AS A GRANT RATHER THAN A CONVENTION (QRPAGER-13 §0). "Aggregates written
    // to a platform table by a job, and the panel reads only that platform row" is only
    // true if the panel cannot write one, so the app role holds no INSERT and no UPDATE on
    // tenant_statistics at all.
    expect(Schema::grantedColumns('qrpager_app', 'tenant_statistics', 'INSERT'))->toBe([])
        ->and(Schema::grantedColumns('qrpager_app', 'tenant_statistics', 'UPDATE'))->toBe([]);

    // And the worker, which enumerates tenants to aggregate them, gets three columns of
    // the registry and not the owner's address, who requested it, or why one failed.
    expect(Schema::grantedColumns('qrpager_worker', 'tenant_provisionings', 'SELECT'))
        ->toBe(['id', 'slug', 'status'])
        ->and(Schema::grantedColumns('qrpager_worker', 'tenant_provisionings', 'INSERT'))->toBe([])
        ->and(Schema::grantedColumns('qrpager_worker', 'tenant_provisionings', 'UPDATE'))->toBe([]);

    // platform_audit_log is append-only by grant as well as by trigger: no role holds
    // UPDATE or DELETE on it. §2.9's belt and braces, for the platform half.
    foreach (['qrpager_app', 'qrpager_worker'] as $role) {
        expect(Schema::grantedColumns($role, 'platform_audit_log', 'UPDATE'))->toBe([])
            ->and(Schema::hasTablePrivilege($role, 'platform_audit_log', 'DELETE'))->toBeFalse();
    }

    // The registry's identity columns are never rewritten: a retry does not rename a
    // restaurant or move it to another slug.
    expect(Schema::grantedColumns('qrpager_app', 'tenant_provisionings', 'UPDATE'))
        ->toBe(['attempts', 'completed_at', 'failure_reason', 'status', 'updated_at']);
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
