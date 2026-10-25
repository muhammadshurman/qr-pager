<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * audit_log is append-only, enforced by trigger on UPDATE / DELETE / TRUNCATE --
 * superuser included (PROJECT_INSTRUCTIONS.md §4 › Data).
 *
 * Two details carry the whole guarantee, and both were verified on 16.14:
 *
 *  - ENABLE ALWAYS. A superuser that sets session_replication_role = replica
 *    deletes rows straight through an ordinary trigger; an ALWAYS trigger still
 *    fires and refuses. Without this keyword the protection is decoration.
 *  - FOR EACH STATEMENT. It fires even when the statement would match zero rows,
 *    so "DELETE FROM audit_log WHERE false" is refused too, and the cost does not
 *    scale with the number of rows a tamper attempt touches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION audit_log_is_append_only() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog
                AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is not permitted', TG_OP
                    USING ERRCODE = '42501';
            END;
            $$;

            CREATE TRIGGER audit_log_no_mutation
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION audit_log_is_append_only();

            CREATE TRIGGER audit_log_no_truncate
                BEFORE TRUNCATE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION audit_log_is_append_only();

            ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_log_no_mutation;
            ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_log_no_truncate;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_log_no_truncate ON audit_log;
            DROP TRIGGER IF EXISTS audit_log_no_mutation ON audit_log;
            DROP FUNCTION IF EXISTS audit_log_is_append_only();
        SQL);
    }
};
