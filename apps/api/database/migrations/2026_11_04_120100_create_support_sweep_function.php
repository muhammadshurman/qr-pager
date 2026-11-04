<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `public.sweep_support_memberships()` — the one statement `support:sweep` may run.
 * `QRPAGER-22`.
 *
 * ================================================================================
 * ⚠ WHY A FUNCTION AND NOT A GRANT, MEASURED
 * ================================================================================
 *
 * The sweep was written as a plain `DELETE` on the worker connection and the database refused
 * it: **`SQLSTATE[42501]: permission denied for table tenant_users`**. `qrpager_worker` holds
 * `SELECT` on that table and nothing else (`2026_10_25_150300`).
 *
 * The obvious fix — `GRANT DELETE ON tenant_users TO qrpager_worker` — was rejected.
 * `DELETE` has no column-level form in Postgres, so it is all-or-nothing: a background role
 * would gain the ability to remove **any** membership, and a predicate that was ever wrong
 * would take real people out of real restaurants. `§2.7`'s whole discipline is the narrowest
 * grant that works.
 *
 * So the worker gains no table privilege at all. It gains `EXECUTE` on one function whose
 * body IS the bounded statement — `§2.8`'s recipe for a named path with no tenant context,
 * the same shape as `resolve_pager_token` and `user_memberships`. The bound lives in the
 * database, where a caller cannot mistype it.
 *
 * ================================================================================
 * THE BOUND, AND WHY IT IS TWO CONDITIONS
 * ================================================================================
 *
 * `expires_at IS NOT NULL AND expires_at <= now()`. The first is the one that makes a mistake
 * survivable: an ordinary membership has no expiry, so no change to the second condition can
 * reach one. Stated separately rather than left implied, because `NULL <= now()` is NULL and
 * therefore already excluded — the explicit check is defence against a later edit, not
 * against today's semantics.
 *
 * ⚠ IT RETURNS A COUNT AND NOTHING ELSE. `§2.8` rule 1 is about never handing a caller a
 * table's columns; a count is the strongest possible form of that. The command prints it, and
 * `SupportAccessSweepTest` asserts it.
 *
 * ⚠ AND IT IS NOT `STABLE`. It writes, so it is `VOLATILE` (the default) — a `STABLE`
 * function that writes is undefined behaviour in Postgres, not merely mislabelled.
 *
 * ⚠ **AND IT TURNS THE BYPASS ON FOR ITS OWN BODY, WHICH THE FIRST VERSION DID NOT — MEASURED
 * AS A SWEEP OF ZERO ROWS.** `tenant_users` carries `ENABLE` + `FORCE` RLS, and `FORCE`
 * applies to the owner as well, so a `SECURITY DEFINER` body with no tenant scope sees an
 * empty table and reports success. The restore-before-raise shape is `user_memberships()`'s,
 * so a failure inside cannot leave the bypass on for the caller's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            CREATE FUNCTION public.sweep_support_memberships()
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            DECLARE
                swept integer;
                previous_bypass text := current_setting('app.bypass_tenant', true);
            BEGIN
                -- ⚠ THE BYPASS IS NOT OPTIONAL AND ITS ABSENCE WAS MEASURED AS A SWEEP OF
                -- ZERO ROWS. `tenant_users` carries ENABLE + FORCE ROW LEVEL SECURITY
                -- (§2.5), and FORCE applies to the table's OWNER too -- so a SECURITY
                -- DEFINER function running as `qrpager_owner` with no `app.tenant_id` set
                -- sees nothing at all and deletes nothing, reporting success. That is the
                -- reassuring-direction failure: a sweep that silently does nothing forever.
                -- `DataMigrationPatternTest` records the same property for migrations.
                PERFORM set_config('app.bypass_tenant', 'on', true);

                BEGIN
                    DELETE FROM public.tenant_users
                     WHERE expires_at IS NOT NULL
                       AND expires_at <= now();

                    GET DIAGNOSTICS swept = ROW_COUNT;
                EXCEPTION WHEN OTHERS THEN
                    -- Restored before re-raising, so a failure cannot leave the bypass on
                    -- for the caller's transaction -- `user_memberships()`'s own shape.
                    PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                    RAISE;
                END;

                PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);

                RETURN swept;
            END;
            $fn$;

            REVOKE ALL ON FUNCTION public.sweep_support_memberships() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.sweep_support_memberships() TO qrpager_worker;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.sweep_support_memberships();
        SQL);
    }
};
