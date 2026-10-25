<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENABLE and FORCE row level security on every tenant table, with the policy
 * matrix in PLAN.md §2.6. No exceptions (C4).
 *
 * Written out per table, with the role names as literals, because a migration is
 * a snapshot: generated DDL would change meaning for a fresh database the moment
 * config changed, and not for one that had already migrated. The identical shape
 * across the six tables is held by RlsCoverageTest, which reads pg_catalog rather
 * than this file, and RoleNamingTest checks that config still names these roles.
 *
 * Both policies use NULLIF. Verified on 16.14: a placeholder GUC that has never
 * been set reads NULL, but after any transaction has set it the connection reads
 * an empty string -- and ''::uuid raises 22P02 instead of matching nothing.
 * NULLIF is what turns that into "0 rows, no error" (PLAN.md §2.2).
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'tenants', 'tenant_users', 'pager_sessions', 'push_subscriptions', 'outbox', 'audit_log',
    ];

    public function up(): void
    {
        DatabaseRoles::assertRolesExist(DB::connection());

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tenants FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_tenants ON tenants
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_tenants ON tenants
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE tenant_users ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tenant_users FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_tenant_users ON tenant_users
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_tenant_users ON tenant_users
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE pager_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE pager_sessions FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_pager_sessions ON pager_sessions
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_pager_sessions ON pager_sessions
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE push_subscriptions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE push_subscriptions FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_push_subscriptions ON push_subscriptions
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_push_subscriptions ON push_subscriptions
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE outbox ENABLE ROW LEVEL SECURITY;
            ALTER TABLE outbox FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_outbox ON outbox
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_outbox ON outbox
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_log FORCE ROW LEVEL SECURITY;

            -- tenant_id is nullable here: a NULL is a platform-scope row, which this
            -- policy makes invisible to every tenant (PLAN.md §2.5).
            CREATE POLICY tenant_isolation_audit_log ON audit_log
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_audit_log ON audit_log
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');
        SQL);

        // The only cross-tenant policies that exist, one per named path in
        // PROJECT_INSTRUCTIONS.md §4 › Tenancy. qrpager_app gets none of them.
        DB::unprepared(<<<'SQL'
            -- the outbox queue
            CREATE POLICY worker_drains_outbox ON outbox
                FOR SELECT TO qrpager_worker USING (true);

            CREATE POLICY worker_updates_outbox ON outbox
                FOR UPDATE TO qrpager_worker USING (true) WITH CHECK (true);

            -- the expiry job. Bounded by TIME and not by status on purpose: verified on
            -- 16.14, an UPDATE that reads any column re-checks the NEW row against the
            -- SELECT policies, so a policy that hid expired rows would make the expiry
            -- job fail with 42501 every time, with or without RETURNING (PLAN.md §2.12).
            CREATE POLICY worker_reads_expired_sessions ON pager_sessions
                FOR SELECT TO qrpager_worker USING (expires_at <= now());

            -- WITH CHECK pins the only transition the worker may make without tenant
            -- context: expiring a session whose TTL has already passed.
            CREATE POLICY worker_expires_sessions ON pager_sessions
                FOR UPDATE TO qrpager_worker
                USING (expires_at <= now())
                WITH CHECK (status = 'expired');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS worker_expires_sessions ON pager_sessions;
            DROP POLICY IF EXISTS worker_reads_expired_sessions ON pager_sessions;
            DROP POLICY IF EXISTS worker_updates_outbox ON outbox;
            DROP POLICY IF EXISTS worker_drains_outbox ON outbox;
        SQL);

        foreach (self::TENANT_TABLES as $table) {
            // Identifiers from a private const in this file, never from input.
            DB::statement("DROP POLICY IF EXISTS owner_bypass_{$table} ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
