<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The QR token lookup: §4 › Tenancy's named cross-tenant read, as §2.8 prescribes it.
 *
 * WHY A FUNCTION AND NOT A QUERY, and this is the measurement that settles it rather than
 * a preference. §2.7 calls token_hash "write-only for app", and the consequence is
 * stronger than that phrase: measured through the real stack, qrpager_app cannot
 * REFERENCE the column at all. `select token_hash ...` raises 42501 and so does
 * `where token_hash = decode(?, 'hex')` -- a column-level SELECT grant is required to
 * name a column in a predicate, not only in a select list. So the application role cannot
 * perform this lookup by any query it is able to write. A SECURITY DEFINER function is
 * the only mechanism available, which is what §2.8 said and is now also what the cluster
 * says.
 *
 * The §2.8 recipe, every clause of which is in the body below:
 *
 *   - owned by qrpager_owner, which it is by construction: migrations run as the owner;
 *   - SET search_path, so a planted object cannot be resolved in place of a real one.
 *     pg_catalog, pg_temp with pg_temp LAST and every reference schema-qualified, which
 *     is the form the Postgres manual prescribes for a SECURITY DEFINER function;
 *   - turns on app.bypass_tenant for the duration of its own body and restores the
 *     previous value before returning -- including on the error path, which is what the
 *     inner BEGIN ... EXCEPTION block is for. set_config(..., true) binds to the
 *     outermost transaction (§2.10), so a throw without the restore would leave the
 *     bypass on for the rest of the caller's transaction;
 *   - EXECUTE to qrpager_app alone. REVOKE FROM PUBLIC first, because Postgres grants
 *     EXECUTE to PUBLIC by default on a new function -- the revoke is the control and the
 *     grant is only what is left. Measured: qrpager_worker gets "permission denied for
 *     function resolve_pager_token";
 *   - NEVER `RETURNS SETOF pager_sessions`. §2.8 rule 1, learned by running it: column
 *     grants are not applied to a function's result set, so a SETOF resolver would hand
 *     token_hash straight back to qrpager_app and quietly undo §2.7. This declares a
 *     narrow RETURNS TABLE naming seven columns, and token_hash is not one of them;
 *   - one exact-match argument, so it answers one question and cannot enumerate. With
 *     128 bits of entropy behind the hash, guessing is the only way in and there is no
 *     cheaper one.
 *
 * Measured end to end as qrpager_app with NO tenant context: one row, the right tenant,
 * no token_hash column in the result, app.bypass_tenant reading empty afterwards, the
 * caller's own direct pager_sessions query still returning 0 rows, and a random hash
 * returning nothing.
 *
 * IT DOES NOT FILTER BY TTL OR STATUS, which is a deliberate choice against a tempting
 * one. Filtering would make an expired session indistinguishable from an unknown token --
 * and §4.1 and Task 9's gate both require the PWA to handle "expired-token and
 * invalid-token paths" as different things, so the product needs to tell them apart. The
 * token holder is the legitimate customer; telling them their session expired is not a
 * disclosure. It also keeps the function's result independent of the clock, which a test
 * can pin.
 *
 * The scope guard permits the call because the statement names no tenant table, which is
 * correct -- this is a named context-free path -- but incidental, so
 * PagerTokenLookupTest pins it: in one armed request the function call is permitted and a
 * bare pager_sessions statement on the same connection is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.resolve_pager_token(presented_hash bytea)
            RETURNS TABLE (
                session_id        uuid,
                tenant_id         uuid,
                status            public.pager_status,
                order_reference   character varying,
                expires_at        timestamptz,
                ready_at          timestamptz,
                last_notified_at  timestamptz
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
                    SELECT s.id, s.tenant_id, s.status, s.order_reference,
                           s.expires_at, s.ready_at, s.last_notified_at
                      FROM public.pager_sessions s
                     WHERE s.token_hash = presented_hash;
                EXCEPTION WHEN OTHERS THEN
                    PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                    RAISE;
                END;

                PERFORM set_config('app.bypass_tenant', coalesce(previous_bypass, ''), true);
                RETURN;
            END;
            $fn$;

            REVOKE ALL ON FUNCTION public.resolve_pager_token(bytea) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.resolve_pager_token(bytea) TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.resolve_pager_token(bytea);');
    }
};
