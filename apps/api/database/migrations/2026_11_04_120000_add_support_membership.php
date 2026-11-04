<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `tenant_users.expires_at` — support access as a membership that expires. `QRPAGER-22`.
 *
 * `§4 › Tenancy`, the clause Muhammad added 2026-11-04: *"Support access is a temporary,
 * audited membership, never a cross-tenant read. A platform admin may enter exactly one
 * explicitly chosen tenant's context as a membership that expires … A platform admin never
 * holds more than one at a time, and an expired or released membership leaves no residual
 * ability. Its lifetime is fixed at 60 minutes and is not chosen by the taker."*
 *
 * ================================================================================
 * ONE COLUMN, AND A SUPPORT MEMBERSHIP IS DEFINED BY IT
 * ================================================================================
 *
 * **A `tenant_users` row with a non-null `expires_at` IS a platform-support membership, and
 * nothing else ever sets one.** That is the single source: the principal resolution, the
 * one-at-a-time index and the owner's "platform support" marker all read this column and
 * there is no second flag to disagree with it. `MemberDirectory::add()` does not write it,
 * and `SupportMembershipTest` asserts that rather than trusting this sentence.
 *
 * ⚠ **AND `tenant_role` GAINS NO FOURTH VALUE, WHICH IS THE ALTERNATIVE THAT WAS REJECTED.**
 * A `support` role would have to sit on `TenantRole`'s seniority ladder — `rank()` is total
 * and `MemberSeniorityTest` requires every case to have a unique rank — and it would appear
 * in `StoreMemberRequest`'s `in:` rule, which validates against `TenantRole::cases()`. That
 * is a role a manager could then GRANT. With no such value there is nothing to grant: the
 * abilities come from `Principal::Support`, derived from this column, and the row stores the
 * least-privileged role so that a mis-resolution fails closed rather than open.
 *
 * ================================================================================
 * ONE AT A TIME, ENFORCED BY THE DATABASE AND NOT BY THE SERVICE
 * ================================================================================
 *
 * `UNIQUE (user_id) WHERE expires_at IS NOT NULL`. A partial unique index, so an ordinary
 * membership is untouched by it — a person may belong to several restaurants — while a second
 * *support* membership for the same admin raises `23505` whatever path asks for it.
 *
 * ⚠ **THIS IS WHY RELEASE AND EXPIRY DELETE THE ROW RATHER THAN MARKING IT.** The predicate
 * cannot be `WHERE expires_at > now()`: `now()` is not immutable and Postgres refuses it in
 * an index. So a spent row left in place would block the next take forever. Deleting is also
 * the strongest possible reading of *"leaves no residual ability"* — there is no row to
 * mis-read.
 *
 * ================================================================================
 * NO GRANT CHANGES, AND THAT WAS CHECKED RATHER THAN ASSUMED
 * ================================================================================
 *
 * `2026_10_25_150300` already gives `qrpager_app` `SELECT, INSERT, DELETE` on `tenant_users`
 * table-wide, so taking (INSERT) and releasing (DELETE) need nothing new. `UPDATE` stays
 * narrowed to `(role, updated_at)` and this column is deliberately NOT added to it: a support
 * membership's lifetime is fixed at 60 minutes by `§4`, so there is no legitimate write that
 * extends one, and the app role cannot perform the write that would.
 *
 * ================================================================================
 * THE READER FILTERS EXPIRED ROWS, IN SQL, FOR EVERY CALLER AT ONCE
 * ================================================================================
 *
 * `public.user_memberships(uuid)` is `§4 › Tenancy`'s second named path and the one place
 * every membership read goes through. It gains `expires_at` AND the predicate
 * `(tu.expires_at IS NULL OR tu.expires_at > now())`, so an expired support membership is
 * invisible to the application the instant it lapses — before any sweeper runs, and without
 * each caller remembering to check. *"No residual ability"* becomes a property of the
 * database rather than of the code that reads it.
 *
 * ⚠ DROP + CREATE, NOT `CREATE OR REPLACE`: a `RETURNS TABLE` column list that grows raises
 * `42P13 cannot change return type of existing function`. And **the grants are re-made**,
 * because an ACL does not survive its object — both lessons are recorded verbatim in
 * `2026_11_02_120000`'s docblock, and `MembershipFunctionGrantTest` reads `proacl` back as an
 * exact two-entry set, so a third grantee fails the suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            ALTER TABLE tenant_users
                ADD COLUMN expires_at timestamptz NULL;

            CREATE UNIQUE INDEX tenant_users_one_support_membership
                ON tenant_users (user_id)
             WHERE expires_at IS NOT NULL;

            DROP FUNCTION IF EXISTS public.user_memberships(uuid);

            CREATE FUNCTION public.user_memberships(p_user_id uuid)
            RETURNS TABLE (
                tenant_id     uuid,
                role          public.tenant_role,
                tenant_status public.tenant_status,
                tenant_slug   character varying,
                tenant_name   character varying,
                expires_at    timestamptz
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            DECLARE
                previous_bypass text := current_setting('app.bypass_tenant', true);
            BEGIN
                PERFORM set_config('app.bypass_tenant', 'on', true);

                BEGIN
                    RETURN QUERY
                    SELECT tu.tenant_id, tu.role, t.status, t.slug, t.name, tu.expires_at
                      FROM public.tenant_users tu
                      JOIN public.tenants t ON t.id = tu.tenant_id
                     WHERE tu.user_id = p_user_id
                       AND (tu.expires_at IS NULL OR tu.expires_at > now());
                EXCEPTION WHEN OTHERS THEN
                    PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                    RAISE;
                END;

                PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                RETURN;
            END;
            $fn$;

            REVOKE ALL ON FUNCTION public.user_memberships(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.user_memberships(uuid) TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.user_memberships(uuid);

            CREATE FUNCTION public.user_memberships(p_user_id uuid)
            RETURNS TABLE (
                tenant_id     uuid,
                role          public.tenant_role,
                tenant_status public.tenant_status,
                tenant_slug   character varying,
                tenant_name   character varying
            )
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            DECLARE
                previous_bypass text := current_setting('app.bypass_tenant', true);
            BEGIN
                PERFORM set_config('app.bypass_tenant', 'on', true);

                BEGIN
                    RETURN QUERY
                    SELECT tu.tenant_id, tu.role, t.status, t.slug, t.name
                      FROM public.tenant_users tu
                      JOIN public.tenants t ON t.id = tu.tenant_id
                     WHERE tu.user_id = p_user_id;
                EXCEPTION WHEN OTHERS THEN
                    PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                    RAISE;
                END;

                PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                RETURN;
            END;
            $fn$;

            REVOKE ALL ON FUNCTION public.user_memberships(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.user_memberships(uuid) TO qrpager_app;

            DROP INDEX IF EXISTS tenant_users_one_support_membership;

            ALTER TABLE tenant_users DROP COLUMN expires_at;
        SQL);
    }
};
