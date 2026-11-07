<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform schema — Task 6 (PLAN.md §2.4, QRPAGER-13 §0 and §1).
 *
 * THREE PLATFORM TABLES, AND NOT ONE OF THEM CARRIES RLS. That is the ruling this task
 * is built on, settled 2026-10-28: platform tables are not tenant tables, the platform
 * admin panel operates only on tables belonging to no tenant, and a panel query that
 * reaches a tenant table is a rejected design. These three hold the platform's own
 * records ABOUT a restaurant — what was asked for, how it went, aggregates, and who did
 * what — and no tenant content, which is why there is no scope for RLS to narrow.
 *
 * ⚠ THE ONE THING A REVIEWER SHOULD PUSH ON: NONE OF THEM HAS A COLUMN NAMED `tenant_id`.
 *
 * That is deliberate and it interacts with a live mechanism, so it is stated here rather
 * than left to be found. C4's meta-test classifies a table as tenant-scoped by exactly two
 * rules read from pg_catalog -- `relname = 'tenants'`, or a column named `tenant_id` -- so
 * a platform table carrying that column name would be classified tenant-scoped, and both
 * `it('has every tenant-scoped table under ENABLE and FORCE')` and `it('keeps the global
 * allowlist honest: nothing on it carries a tenant_id')` would fail unless it were given
 * RLS, which the ruling forbids.
 *
 * IT IS NOT A RENAME TO ESCAPE THE GUARD. Each of these is ONE ROW PER TENANT, so the
 * tenant's id is the row's IDENTITY and not a scope column over many rows:
 * `tenant_provisionings.id` IS the id the tenant will carry, and `tenant_statistics.id`
 * IS the id of the tenant it describes. That is ordinary 1:1 modelling and it is the exact
 * mirror of the property TenantTables already documents for `tenants` itself -- "its own id
 * IS the tenant scope". The difference, which is the whole of the ruling, is WHICH SIDE OWNS
 * THE ROW: `tenants.id` scopes tenant data; these ids reference a tenant from a platform
 * record that contains none.
 *
 * REJECTED: (a) naming the column `tenant_id` and giving these tables RLS -- then the
 * platform admin, who holds no tenant context, reads nothing, which is exactly the state
 * Task 5 shipped and this task exists to fix without widening it. (b) loosening the
 * meta-test's classifier to a hand-maintained list -- that trades the one mechanical control
 * stopping a NEW tenant table escaping isolation for a label on three tables, which is the
 * wrong direction.
 *
 * SO THE GUARD IS OWED A REPLACEMENT, and PlatformTableContractTest is it: every column set
 * here is pinned exactly against information_schema, so neither a `tenant_id` column nor a
 * row-level-detail column can join one of these tables without the diff showing it.
 *
 * Enum values are written out literally rather than read from the PHP enum, and the
 * per-status counter columns on tenant_statistics are written out too. A migration that
 * reads today's enum is not a snapshot: it would silently mean one thing on a fresh
 * database and another on a migrated one. EnumContractTest keeps `provisioning_status`
 * from drifting, and PlatformTableContractTest asserts one counter column per PagerStatus
 * case -- so a sixth pager status fails the build here rather than leaving a counter
 * missing in silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TYPE provisioning_status AS ENUM ('pending', 'running', 'completed', 'failed');

            -- The platform's record of a restaurant it was asked to create, and the
            -- registry the panel lists: the ruling's "can see that a restaurant exists".
            --
            -- `id` IS the tenant id this row provisions, allocated by the panel BEFORE the
            -- tenant exists. There is deliberately NO foreign key to tenants(id): the row
            -- is written first, which is the point of it, and an FK would invert the order
            -- and make the failure path -- a provisioning record for a tenant that was
            -- never created -- unrepresentable. That record is the only evidence a failed
            -- attempt leaves.
            CREATE TABLE tenant_provisionings (
                id                   uuid                PRIMARY KEY,
                name                 varchar(120)        NOT NULL,
                slug                 varchar(63)         NOT NULL,
                owner_email          varchar(255)        NOT NULL,
                status               provisioning_status NOT NULL DEFAULT 'pending',
                attempts             smallint            NOT NULL DEFAULT 0,
                failure_reason       text,
                requested_by_user_id uuid                REFERENCES users (id) ON DELETE SET NULL,
                completed_at         timestamptz,
                created_at           timestamptz         NOT NULL DEFAULT now(),
                updated_at           timestamptz         NOT NULL DEFAULT now(),
                CONSTRAINT tenant_provisionings_slug_unique UNIQUE (slug),
                -- The same expression as tenants_slug_is_dns_label, so a slug that would be
                -- refused by the tenant table is refused here first, on the platform side,
                -- before any job runs. The application validates the same shape; neither
                -- side trusts the other.
                CONSTRAINT tenant_provisionings_slug_is_dns_label
                    CHECK (slug ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$'),
                -- Stored lower-cased, as users.email is (PLAN.md §2.4, R-007).
                CONSTRAINT tenant_provisionings_owner_email_is_lower CHECK (owner_email = lower(owner_email)),
                CONSTRAINT tenant_provisionings_attempts_non_negative CHECK (attempts >= 0),
                -- A terminal row says why. `failed` carries a reason and `completed` carries
                -- a timestamp, and neither is optional -- a failed provisioning with no
                -- reason is the state this table exists to make impossible.
                CONSTRAINT tenant_provisionings_failed_has_reason
                    CHECK (status <> 'failed' OR failure_reason IS NOT NULL),
                CONSTRAINT tenant_provisionings_completed_has_timestamp
                    CHECK (status <> 'completed' OR completed_at IS NOT NULL)
            );

            CREATE INDEX tenant_provisionings_status_created_at_index
                ON tenant_provisionings (status, created_at DESC);

            -- AGGREGATES ONLY, one row per tenant, written by a job from INSIDE that
            -- tenant's own context and read by the panel from out here.
            --
            -- "An aggregate carries no row-level detail: no order reference, no token, no
            -- session identifier." That is the ruling, and PlatformTableContractTest turns
            -- it into a property by pinning this column set and asserting none of the
            -- forbidden names is in it.
            --
            -- `id` IS the tenant id these aggregates describe. No FK, for the same reason
            -- as above plus one more: a statistics row outliving a tenant is a fact about
            -- the platform's history, not a dangling reference.
            CREATE TABLE tenant_statistics (
                id                  uuid        PRIMARY KEY,
                sessions_total      integer     NOT NULL DEFAULT 0,
                sessions_waiting    integer     NOT NULL DEFAULT 0,
                sessions_ready      integer     NOT NULL DEFAULT 0,
                sessions_completed  integer     NOT NULL DEFAULT 0,
                sessions_cancelled  integer     NOT NULL DEFAULT 0,
                sessions_expired    integer     NOT NULL DEFAULT 0,
                staff_count         integer     NOT NULL DEFAULT 0,
                median_wait_seconds integer,
                p95_wait_seconds    integer,
                window_start        timestamptz,
                window_end          timestamptz,
                computed_at         timestamptz NOT NULL DEFAULT now(),
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT tenant_statistics_counts_non_negative CHECK (
                    sessions_total     >= 0 AND sessions_waiting   >= 0 AND
                    sessions_ready     >= 0 AND sessions_completed >= 0 AND
                    sessions_cancelled >= 0 AND sessions_expired   >= 0 AND
                    staff_count        >= 0
                ),
                CONSTRAINT tenant_statistics_waits_non_negative CHECK (
                    (median_wait_seconds IS NULL OR median_wait_seconds >= 0) AND
                    (p95_wait_seconds    IS NULL OR p95_wait_seconds    >= 0)
                ),
                CONSTRAINT tenant_statistics_window_ordered CHECK (
                    window_start IS NULL OR window_end IS NULL OR window_start <= window_end
                )
            );

            -- What a platform admin did to a platform record.
            --
            -- THIS TABLE EXISTS BECAUSE audit_log CANNOT SERVE IT, which was measured
            -- rather than assumed (QRPAGER-13 §0 SR-3). audit_log is a tenant table under
            -- FORCE RLS whose isolation policy compares `tenant_id = NULLIF(current_setting
            -- ('app.tenant_id', true), '')::uuid`, and `NULL = anything` is NULL rather
            -- than true -- so a platform-scope row (tenant_id NULL, which §2.5 documents as
            -- "platform scope") satisfies no policy in either direction. Measured at Task
            -- 6's Phase 0: insert refused 42501 for qrpager_app with AND without a context
            -- and for qrpager_owner without the bypass; a committed row reads back as 0 rows
            -- for the app role in either state. Only qrpager_owner with app.bypass_tenant =
            -- 'on' can write or read one, which is a migration-time posture, not a
            -- request-time one. So "platform admin actions all land in audit_log" was never
            -- reachable by a serving process, and this is where they land instead.
            --
            -- `actor_user_id` carries NO foreign key, for audit_log's own reason: an
            -- ON DELETE SET NULL is an UPDATE, which the append-only trigger below blocks,
            -- so deleting a user would become impossible. The log keeps ids, not references.
            CREATE TABLE platform_audit_log (
                id            bigint       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                actor_user_id uuid,
                action        varchar(64)  NOT NULL,
                subject_type  varchar(64),
                subject_id    uuid,
                context       jsonb        NOT NULL DEFAULT '{}'::jsonb,
                ip_address    inet,
                created_at    timestamptz  NOT NULL DEFAULT now()
            );

            CREATE INDEX platform_audit_log_created_at_index ON platform_audit_log (created_at DESC);
            CREATE INDEX platform_audit_log_action_created_at_index ON platform_audit_log (action, created_at DESC);
        SQL);

        // Append-only, the same construction as audit_log (§2.9) and for the same two
        // reasons, both verified on 16.14 at Task 1 and re-driven for this table:
        //
        //  - ENABLE ALWAYS. A superuser that sets session_replication_role = replica
        //    deletes rows straight through an ordinary trigger. Without this keyword the
        //    protection is decoration.
        //  - FOR EACH STATEMENT. It fires even when the statement would match zero rows,
        //    so "DELETE FROM platform_audit_log WHERE false" is refused too.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION platform_audit_log_is_append_only() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog
                AS $$
            BEGIN
                RAISE EXCEPTION 'platform_audit_log is append-only: % is not permitted', TG_OP
                    USING ERRCODE = '42501';
            END;
            $$;

            CREATE TRIGGER platform_audit_log_no_mutation
                BEFORE UPDATE OR DELETE ON platform_audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION platform_audit_log_is_append_only();

            CREATE TRIGGER platform_audit_log_no_truncate
                BEFORE TRUNCATE ON platform_audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION platform_audit_log_is_append_only();

            ALTER TABLE platform_audit_log ENABLE ALWAYS TRIGGER platform_audit_log_no_mutation;
            ALTER TABLE platform_audit_log ENABLE ALWAYS TRIGGER platform_audit_log_no_truncate;
        SQL);

        // Column grants, per role, stated per column so the widest grant in the schema is
        // visible in the diff and pinned as an exact set by GrantMatrixTest (§2.7).
        //
        // NOT ONE GRANT ON A TENANT TABLE IS WIDENED BY THIS TASK. The provisioning job
        // writes `tenants` and `tenant_users` with grants qrpager_app already held since
        // Task 1, and the aggregate job reads pager_sessions and tenant_users with grants
        // qrpager_worker already held.
        DB::unprepared(<<<'SQL'
            -- tenant_provisionings -------------------------------------------------
            -- The panel reads the registry and writes a request; the provisioning job
            -- moves the row through its statuses. Neither touches `slug` or `name` after
            -- the insert: a restaurant's identity is not rewritten by a retry.
            GRANT SELECT ON tenant_provisionings TO qrpager_app;
            GRANT INSERT (id, name, slug, owner_email, status, requested_by_user_id,
                          created_at, updated_at) ON tenant_provisionings TO qrpager_app;
            GRANT UPDATE (status, attempts, failure_reason, completed_at, updated_at)
                ON tenant_provisionings TO qrpager_app;

            -- The worker enumerates provisioned tenants to aggregate them and needs
            -- nothing else from this table -- not the owner's address, not who requested
            -- it, not why an attempt failed.
            GRANT SELECT (id, slug, status) ON tenant_provisionings TO qrpager_worker;

            -- tenant_statistics ----------------------------------------------------
            -- The panel READS ONLY. The aggregate job is the only writer, which is the
            -- ruling's "aggregates written to a platform table by a job" expressed as a
            -- grant rather than as a convention.
            GRANT SELECT ON tenant_statistics TO qrpager_app;
            GRANT SELECT, INSERT ON tenant_statistics TO qrpager_worker;
            GRANT UPDATE (sessions_total, sessions_waiting, sessions_ready,
                          sessions_completed, sessions_cancelled, sessions_expired,
                          staff_count, median_wait_seconds, p95_wait_seconds,
                          window_start, window_end, computed_at, updated_at)
                ON tenant_statistics TO qrpager_worker;

            -- platform_audit_log ---------------------------------------------------
            -- No UPDATE and no DELETE to any role: the trigger makes it impossible and the
            -- absent grant makes it unreachable, which is §2.9's belt and braces.
            GRANT SELECT ON platform_audit_log TO qrpager_app;
            GRANT INSERT (actor_user_id, action, subject_type, subject_id, context, ip_address)
                ON platform_audit_log TO qrpager_app, qrpager_worker;
        SQL);
    }

    public function down(): void
    {
        // Reverse order, and the triggers and function go with their table. Proven clean
        // by bin/migration-rollback-proof, which rolls back to zero and re-applies
        // byte-identically.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS platform_audit_log_no_truncate ON platform_audit_log;
            DROP TRIGGER IF EXISTS platform_audit_log_no_mutation ON platform_audit_log;
            DROP FUNCTION IF EXISTS platform_audit_log_is_append_only();

            DROP TABLE IF EXISTS platform_audit_log;
            DROP TABLE IF EXISTS tenant_statistics;
            DROP TABLE IF EXISTS tenant_provisionings;

            DROP TYPE IF EXISTS provisioning_status;
        SQL);
    }
};
