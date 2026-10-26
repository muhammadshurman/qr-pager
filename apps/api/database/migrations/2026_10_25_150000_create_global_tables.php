<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tables with no tenant scope column, and therefore no RLS (PLAN.md §2.4).
 *
 * Every table here has to be justified in PLAN.md §2.4 and listed in the
 * allowlist in RlsCoverageTest, or the meta-test fails the build. That is what
 * stops a tenant table from quietly arriving without isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id                uuid         PRIMARY KEY,
                name              varchar(120) NOT NULL,
                email             varchar(255) NOT NULL,
                email_verified_at timestamptz,
                password          varchar(255),
                remember_token    varchar(100),
                created_at        timestamptz  NOT NULL DEFAULT now(),
                updated_at        timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT users_email_unique UNIQUE (email),
                CONSTRAINT users_email_is_lowercase CHECK (email = lower(email))
            );

            CREATE TABLE password_reset_tokens (
                email      varchar(255) PRIMARY KEY,
                token      varchar(255) NOT NULL,
                created_at timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE failed_jobs (
                id         bigint       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid       varchar(255) NOT NULL,
                connection text         NOT NULL,
                queue      text         NOT NULL,
                payload    text         NOT NULL,
                exception  text         NOT NULL,
                failed_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS failed_jobs;
            DROP TABLE IF EXISTS password_reset_tokens;
            DROP TABLE IF EXISTS users;
        SQL);
    }
};
