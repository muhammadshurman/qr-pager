<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Column-level grants for the two runtime roles (PLAN.md §2.7).
 *
 * Role names are literals here for the same reason as in the policy migration: a
 * migration is a snapshot. GrantMatrixTest checks the result against
 * information_schema, and RoleNamingTest checks that config still names them.
 *
 * Grants are deny-by-default: nothing is granted on a table that is not named
 * here, so a table added without a grant is invisible to the application and
 * fails loudly instead of inheriting access.
 *
 * The point of doing this per column is that over-broad SQL cannot leak what the
 * role was never given. token_hash is the clearest case: app can INSERT it and
 * cannot SELECT it, so the hash cannot come back through a careless SELECT *.
 */
return new class extends Migration
{
    public function up(): void
    {
        DatabaseRoles::assertRolesExist(DB::connection());

        DB::unprepared(<<<'SQL'
            -- global tables -------------------------------------------------------
            -- Stated per column rather than table-wide, so the widest grants in the
            -- schema are visible in the diff and pinned as exact sets by
            -- GrantMatrixTest (F2, security review of task-1). The app still reaches
            -- password and remember_token: moving authentication behind a SECURITY
            -- DEFINER function and dropping both is Task 5's gate clause
            -- (QRPAGER-12, PLAN.md §2.8), carried until then as an accepted risk.
            GRANT SELECT (id, name, email, email_verified_at, password, remember_token,
                          created_at, updated_at) ON users TO qrpager_app;
            GRANT INSERT (id, name, email, email_verified_at, password, remember_token,
                          created_at, updated_at) ON users TO qrpager_app;
            -- No id and no created_at: a uuid primary key and a creation timestamp are
            -- never rewritten by the application.
            GRANT UPDATE (name, email, email_verified_at, password, remember_token,
                          updated_at) ON users TO qrpager_app;

            -- The worker authenticates nobody. It needs a name and an address to send
            -- a notification; credentials are not its business.
            GRANT SELECT (id, name, email) ON users TO qrpager_worker;

            GRANT SELECT (email, token, created_at) ON password_reset_tokens TO qrpager_app;
            GRANT INSERT (email, token, created_at) ON password_reset_tokens TO qrpager_app;
            -- DELETE has no column form in Postgres: a reset token is consumed whole.
            GRANT DELETE ON password_reset_tokens TO qrpager_app;

            -- worker only: a serialized job payload can carry tenant data, and a
            -- global table has no row level security behind it (PLAN.md §2.4).
            GRANT SELECT, INSERT, DELETE ON failed_jobs TO qrpager_worker;

            -- tenants -------------------------------------------------------------
            GRANT SELECT ON tenants TO qrpager_app, qrpager_worker;
            GRANT INSERT (id, name, slug, status, timezone, created_at, updated_at) ON tenants TO qrpager_app;
            GRANT UPDATE (name, status, timezone, updated_at) ON tenants TO qrpager_app;

            -- tenant_users --------------------------------------------------------
            GRANT SELECT, INSERT, DELETE ON tenant_users TO qrpager_app;
            GRANT UPDATE (role, updated_at) ON tenant_users TO qrpager_app;
            GRANT SELECT ON tenant_users TO qrpager_worker;

            -- pager_sessions ------------------------------------------------------
            GRANT SELECT (id, tenant_id, order_reference, status, expires_at, ready_at,
                          completed_at, cancelled_at, expired_at, last_notified_at,
                          renotify_count, created_by_user_id, created_at, updated_at)
                ON pager_sessions TO qrpager_app, qrpager_worker;
            GRANT INSERT (id, tenant_id, token_hash, order_reference, status, expires_at,
                          created_by_user_id, created_at, updated_at) ON pager_sessions TO qrpager_app;
            GRANT UPDATE (status, ready_at, completed_at, cancelled_at, last_notified_at,
                          renotify_count, updated_at) ON pager_sessions TO qrpager_app;
            GRANT UPDATE (status, expired_at, updated_at) ON pager_sessions TO qrpager_worker;

            -- push_subscriptions --------------------------------------------------
            GRANT SELECT, INSERT, DELETE ON push_subscriptions TO qrpager_app;
            GRANT UPDATE (p256dh, auth, updated_at) ON push_subscriptions TO qrpager_app;
            -- the worker prunes on 404/410 (PROJECT_INSTRUCTIONS.md §4 › Realtime)
            GRANT SELECT, DELETE ON push_subscriptions TO qrpager_worker;

            -- outbox --------------------------------------------------------------
            -- No INSERT grant on status, so the app cannot write a row that is
            -- already 'sent': it gets the column default, 'pending'.
            GRANT SELECT ON outbox TO qrpager_app, qrpager_worker;
            GRANT INSERT (id, tenant_id, pager_session_id, type, dedupe_key, payload,
                          available_at, created_at, updated_at) ON outbox TO qrpager_app;
            GRANT UPDATE (status, attempts, available_at, sent_at, dead_at, last_error,
                          updated_at) ON outbox TO qrpager_worker;

            -- audit_log -----------------------------------------------------------
            -- INSERT and SELECT only, for everyone. No UPDATE, no DELETE, no TRUNCATE:
            -- the trigger is the backstop behind the missing grant, not the only control.
            GRANT SELECT ON audit_log TO qrpager_app, qrpager_worker;
            GRANT INSERT (tenant_id, actor_type, actor_user_id, action, subject_type,
                          subject_id, context, ip_address) ON audit_log TO qrpager_app, qrpager_worker;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM qrpager_app, qrpager_worker;
        SQL);
    }
};
