<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `public.user_memberships(uuid)` RETURNS THE RESTAURANT'S **NAME** — `R-222`, ruled by
 * Muhammad on 2026-11-02 and recorded in `PLAN.md §1`.
 *
 * ================================================================================
 * WHY, IN ONE PARAGRAPH
 * ================================================================================
 *
 * The staff restaurant chooser runs BEFORE a tenant is selected, so it labels each
 * membership with the only datum available at that moment. Until now that was the `slug` —
 * `al-baik-jeddah · Owner` — which is a DNS label with a role word beside it, and `§12`'s
 * zero-technical-background bar forbids an unexplained identifier on a screen. The honest
 * label is the restaurant's own name.
 *
 * ================================================================================
 * WHAT THIS DOES **NOT** DO, which is the part a reviewer should check hardest
 * ================================================================================
 *
 * **It adds no cross-tenant PATH.** `PROJECT_INSTRUCTIONS.md §4 › Tenancy` names *"a user's
 * own memberships"* as one of exactly four, and this function IS that path. One more column
 * of a row the function already reads — the `tenants` join was already here for `status` and
 * `slug` — is inside that path by any reading. `PLAN.md §2.8`'s named list stays at **four**
 * and **no rank-2 edit was needed or taken**.
 *
 * **It does not widen the bound.** `§2.8`'s two rules both still hold, and they are the test
 * of whether a widening is safe:
 *
 *   rule 1  `RETURNS TABLE` with NAMED columns, never `RETURNS SETOF tenants` — which would
 *           hand `qrpager_app` every column of that table, including whatever a later task
 *           adds to it, and would silently undo `§2.7`;
 *   rule 2  ONE exact-match uuid argument, compared with `=`. It still answers one question
 *           and still cannot be used to enumerate.
 *
 * **And it is not a reversal of 10a's decide-and-record**, which chose `GET /v1/tenant` over
 * widening this function. That reason was that the name is readable INSIDE the resolved
 * tenant's scope, where RLS leaves exactly one row — so the SHELL's header needs no
 * cross-tenant read, and it still does not have one. The chooser is on the other side of
 * tenant selection: there is no scope to read inside, and the alternative is one tenant
 * scope per membership in a single request, which breaks *one tenant scope per request* for
 * a display string. Both decisions follow the same rule and land differently because the two
 * screens sit on opposite sides of the same boundary.
 *
 * ================================================================================
 * WHY `DROP` + `CREATE` AND NOT `CREATE OR REPLACE` — verified against the server
 * ================================================================================
 *
 * Postgres refuses to change a function's return type in place: a `RETURNS TABLE` column
 * list that grows raises `42P13 cannot change return type of existing function`. The
 * ARGUMENT list is unchanged, so `DROP FUNCTION public.user_memberships(uuid)` names one
 * function unambiguously and cannot take a different overload with it.
 *
 * ⚠ **THE GRANTS ARE RE-MADE, BECAUSE A GRANT DOES NOT SURVIVE ITS OBJECT.** Dropping the
 * function drops its ACL with it, so `REVOKE ALL … FROM PUBLIC` and the single
 * `GRANT EXECUTE … TO qrpager_app` run again in the same statement block — and the `REVOKE`
 * is load-bearing rather than tidy: a `SECURITY DEFINER` function is executable by `PUBLIC`
 * by default, so without it every role on the cluster could read every user's memberships
 * under the tenant bypass. `MembershipFunctionGrantTest` reads `pg_proc.proacl` back as an
 * exact two-entry set, so a third grantee fails the suite rather than passing quietly.
 *
 * The body is otherwise **byte-identical to the one it replaces**, including the bypass
 * restore on the error path — which exists because the transaction-scoped third argument
 * binds to the OUTERMOST transaction (`PLAN.md §2.10`), so a throw without the restore would
 * leave the bypass on for the rest of the caller's transaction. It is copied rather than
 * improved: this migration changes one line of a `SELECT` list and nothing else.
 */
return new class extends Migration
{
    public function up(): void
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
        SQL);
    }

    /**
     * Back to the four-column form, grants and all.
     *
     * `§6` makes a clean rollback a gate clause, and a `down()` that dropped the function
     * without restoring the previous one would leave the application unable to authenticate
     * anybody — every `/v1` request resolves a tenant through this function. So the rollback
     * re-creates the version `2026_10_28_120000_create_auth_contract.php` installed, rather
     * than merely undoing the addition.
     */
    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.user_memberships(uuid);

            CREATE FUNCTION public.user_memberships(p_user_id uuid)
            RETURNS TABLE (
                tenant_id     uuid,
                role          public.tenant_role,
                tenant_status public.tenant_status,
                tenant_slug   character varying
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
                    SELECT tu.tenant_id, tu.role, t.status, t.slug
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
        SQL);
    }
};
