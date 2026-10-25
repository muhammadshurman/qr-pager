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
            expect(Schema::grantedColumns($role, $table, 'UPDATE'))
                ->not->toContain($column, "{$role} can update {$table}.{$column} and move a row between tenants");
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
        'audit_log', 'outbox', 'pager_sessions', 'password_reset_tokens',
        'push_subscriptions', 'tenant_users', 'tenants', 'users',
    ]);

    expect(Schema::reachableTables('qrpager_worker'))->toBe([
        'audit_log', 'failed_jobs', 'outbox', 'pager_sessions',
        'push_subscriptions', 'tenant_users', 'tenants', 'users',
    ]);
});
