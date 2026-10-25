<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tenant schema (PLAN.md §2.3, §2.5).
 *
 * Enum values are written out literally rather than read from the PHP enums.
 * A migration that reads today's enum is not a snapshot: it would silently mean
 * one thing on a fresh database and another on a migrated one. EnumContractTest
 * is what keeps the two sides from drifting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TYPE pager_status     AS ENUM ('waiting', 'ready', 'completed', 'cancelled', 'expired');
            CREATE TYPE outbox_status    AS ENUM ('pending', 'sent', 'dead');
            CREATE TYPE tenant_status    AS ENUM ('active', 'suspended');
            CREATE TYPE tenant_role      AS ENUM ('owner', 'manager', 'staff');
            CREATE TYPE audit_actor_type AS ENUM ('user', 'system', 'platform');

            CREATE TABLE tenants (
                id         uuid          PRIMARY KEY,
                name       varchar(120)  NOT NULL,
                slug       varchar(63)   NOT NULL,
                status     tenant_status NOT NULL DEFAULT 'active',
                timezone   varchar(64)   NOT NULL DEFAULT 'UTC',
                created_at timestamptz   NOT NULL DEFAULT now(),
                updated_at timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT tenants_slug_unique UNIQUE (slug),
                CONSTRAINT tenants_slug_is_dns_label CHECK (slug ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$')
            );

            CREATE TABLE tenant_users (
                id         uuid        PRIMARY KEY,
                tenant_id  uuid        NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                user_id    uuid        NOT NULL REFERENCES users (id)   ON DELETE CASCADE,
                role       tenant_role NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT tenant_users_tenant_user_unique UNIQUE (tenant_id, user_id)
            );

            CREATE INDEX tenant_users_user_id_index ON tenant_users (user_id);

            CREATE TABLE pager_sessions (
                id                 uuid         PRIMARY KEY,
                tenant_id          uuid         NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                token_hash         bytea        NOT NULL,
                order_reference    varchar(32)  NOT NULL,
                status             pager_status NOT NULL,
                expires_at         timestamptz  NOT NULL,
                ready_at           timestamptz,
                completed_at       timestamptz,
                cancelled_at       timestamptz,
                expired_at         timestamptz,
                last_notified_at   timestamptz,
                renotify_count     smallint     NOT NULL DEFAULT 0,
                created_by_user_id uuid         REFERENCES users (id) ON DELETE SET NULL,
                created_at         timestamptz  NOT NULL DEFAULT now(),
                updated_at         timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT pager_sessions_token_hash_unique UNIQUE (token_hash),
                -- The composite FK target. This is what makes a cross-tenant child row
                -- structurally impossible rather than policy-dependent (PLAN.md §2.5).
                CONSTRAINT pager_sessions_tenant_id_id_unique UNIQUE (tenant_id, id),
                CONSTRAINT pager_sessions_renotify_count_non_negative CHECK (renotify_count >= 0)
            );

            CREATE INDEX pager_sessions_board_index
                ON pager_sessions (tenant_id, status, created_at DESC);

            -- The expiry job's only index. Partial, so it holds live sessions only.
            CREATE INDEX pager_sessions_expiry_index
                ON pager_sessions (expires_at)
                WHERE status IN ('waiting', 'ready');

            CREATE TABLE push_subscriptions (
                id               uuid         PRIMARY KEY,
                tenant_id        uuid         NOT NULL,
                pager_session_id uuid         NOT NULL,
                endpoint         text         NOT NULL,
                p256dh           varchar(255) NOT NULL,
                auth             varchar(255) NOT NULL,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                updated_at       timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT push_subscriptions_session_fk
                    FOREIGN KEY (tenant_id, pager_session_id)
                    REFERENCES pager_sessions (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT push_subscriptions_session_endpoint_unique UNIQUE (pager_session_id, endpoint),
                CONSTRAINT push_subscriptions_endpoint_is_https
                    CHECK (endpoint LIKE 'https://%' AND length(endpoint) <= 2048)
            );

            CREATE INDEX push_subscriptions_tenant_id_index ON push_subscriptions (tenant_id);

            CREATE TABLE outbox (
                id               uuid          PRIMARY KEY,
                tenant_id        uuid          NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                pager_session_id uuid,
                type             varchar(64)   NOT NULL,
                dedupe_key       varchar(160)  NOT NULL,
                payload          jsonb         NOT NULL DEFAULT '{}'::jsonb,
                status           outbox_status NOT NULL DEFAULT 'pending',
                attempts         smallint      NOT NULL DEFAULT 0,
                available_at     timestamptz   NOT NULL DEFAULT now(),
                sent_at          timestamptz,
                dead_at          timestamptz,
                last_error       text,
                created_at       timestamptz   NOT NULL DEFAULT now(),
                updated_at       timestamptz   NOT NULL DEFAULT now(),
                CONSTRAINT outbox_session_fk
                    FOREIGN KEY (tenant_id, pager_session_id)
                    REFERENCES pager_sessions (tenant_id, id) ON DELETE CASCADE,
                -- Task 3's idempotency mechanism: one row per logical event, enforced by
                -- the database under true concurrency, never by a read-then-write check.
                CONSTRAINT outbox_tenant_dedupe_key_unique UNIQUE (tenant_id, dedupe_key),
                CONSTRAINT outbox_attempts_non_negative CHECK (attempts >= 0)
            );

            CREATE INDEX outbox_claim_index
                ON outbox (available_at, id)
                WHERE status = 'pending';

            CREATE TABLE audit_log (
                id            bigint           GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                tenant_id     uuid,
                actor_type    audit_actor_type NOT NULL,
                actor_user_id uuid,
                action        varchar(64)      NOT NULL,
                subject_type  varchar(64),
                subject_id    uuid,
                context       jsonb            NOT NULL DEFAULT '{}'::jsonb,
                ip_address    inet,
                created_at    timestamptz      NOT NULL DEFAULT now()
            );

            -- No foreign keys on purpose: ON DELETE SET NULL is an UPDATE, which the
            -- append-only trigger blocks, so it would make deleting a user impossible.
            -- The log keeps ids, not references (PLAN.md §2.5).

            CREATE INDEX audit_log_tenant_index ON audit_log (tenant_id, created_at DESC);
            CREATE INDEX audit_log_action_index ON audit_log (action, created_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS audit_log;
            DROP TABLE IF EXISTS outbox;
            DROP TABLE IF EXISTS push_subscriptions;
            DROP TABLE IF EXISTS pager_sessions;
            DROP TABLE IF EXISTS tenant_users;
            DROP TABLE IF EXISTS tenants;

            DROP TYPE IF EXISTS audit_actor_type;
            DROP TYPE IF EXISTS tenant_role;
            DROP TYPE IF EXISTS tenant_status;
            DROP TYPE IF EXISTS outbox_status;
            DROP TYPE IF EXISTS pager_status;
        SQL);
    }
};
