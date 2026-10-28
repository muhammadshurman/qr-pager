<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * THREE NARROWINGS THE `§11` REVIEWS FOUND, ALL OF THEM GRANTS OR A FUNCTION SIGNATURE
 * ================================================================================
 *
 * Task 5's own migration argued carefully for what the app role may read and write on
 * `users`, and then handed it three things nothing asks for. None was reachable when it was
 * found -- that is why these are narrowings rather than an incident -- and each would have
 * become reachable at a named later task.
 *
 * 1. `INSERT (is_platform_admin)` ON `users`. The sibling migration's comment argues, at
 *    length and correctly, that there is no `UPDATE` grant on that column, because "granting
 *    a tenant-facing role the ability to write the platform-admin flag would make privilege
 *    escalation a single UPDATE". It then granted INSERT on it in the list above, which is
 *    the same escalation one row earlier. Nothing inserts a user today: Fortify's
 *    registration routes are not mounted (`Fortify::$registersRoutes = false`) and
 *    `MemberDirectory` only attaches users that already exist. **Task 6 is tenant
 *    provisioning**, and the first thing it does is create users.
 *
 * 2. `DELETE ON passkeys`, table-wide, on an RLS-FREE table. No route deletes a passkey --
 *    confirmed against the production route table, not the source -- so the grant is
 *    unused, and `passkeys` has no RLS to stand behind it, so the first delete path anybody
 *    adds would have no database backstop at all under a missing `user_id` predicate. The
 *    grant comes back with the route that needs it, and that route arrives with a
 *    cross-user test.
 *
 * 3. `public.authenticate_user(varchar)` RETURNED `two_factor_secret`. This is the one worth
 *    reading twice. `2026_10_28_130000_create_two_factor_reader.php` REFUSES to grant the
 *    app role SELECT on that column, in those words, because doing so "re-opens the exact
 *    shape `R-006` is about, one task after closing it" -- and the function next to it
 *    handed the same column to the same role through a `SECURITY DEFINER` seam. Measured as
 *    `qrpager_app`: `select two_factor_secret from users` → `42501`, while
 *    `select two_factor_secret from public.authenticate_user('…')` → **OK**, one readable
 *    email at a time. The login path never read it (`UserCredentials::verify` selects
 *    `user_id, password_hash, two_factor_confirmed_at`) and `public.user_two_factor(uuid)`
 *    already exists for the code that does need it, so the column bought nothing.
 *
 * `GrantMatrixTest` and `AuthContractTest` pin all three, so none can come back by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            -- 1. The platform-admin flag is no longer writable at INSERT time either.
            --    Column-level REVOKE, so the rest of the INSERT grant is untouched.
            REVOKE INSERT (is_platform_admin) ON users FROM qrpager_app;

            -- 2. Removed until a route needs it.
            REVOKE DELETE ON passkeys FROM qrpager_app;

            -- 3. The function's result type changes, so it is dropped and recreated:
            --    CREATE OR REPLACE cannot alter a RETURNS TABLE signature.
            DROP FUNCTION IF EXISTS public.authenticate_user(character varying);

            CREATE FUNCTION public.authenticate_user(presented_email character varying)
            RETURNS TABLE (
                user_id                 uuid,
                password_hash           text,
                is_platform_admin       boolean,
                two_factor_confirmed_at timestamptz,
                email_verified_at       timestamptz
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
                SELECT u.id, u.password, u.is_platform_admin,
                       u.two_factor_confirmed_at, u.email_verified_at
                  FROM public.users u
                 WHERE u.email = lower(presented_email);
            $fn$;

            REVOKE ALL ON FUNCTION public.authenticate_user(character varying) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.authenticate_user(character varying) TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        // Restores exactly what 2026_10_28_120000 created, so that a rollback leaves the
        // schema this migration found rather than a third state. The rollback proof checks
        // re-application is byte-identical, which is what makes that worth getting right.
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            GRANT INSERT (is_platform_admin) ON users TO qrpager_app;
            GRANT DELETE ON passkeys TO qrpager_app;

            DROP FUNCTION IF EXISTS public.authenticate_user(character varying);

            CREATE FUNCTION public.authenticate_user(presented_email character varying)
            RETURNS TABLE (
                user_id                 uuid,
                password_hash           text,
                is_platform_admin       boolean,
                two_factor_secret       text,
                two_factor_confirmed_at timestamptz,
                email_verified_at       timestamptz
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
                SELECT u.id, u.password, u.is_platform_admin, u.two_factor_secret,
                       u.two_factor_confirmed_at, u.email_verified_at
                  FROM public.users u
                 WHERE u.email = lower(presented_email);
            $fn$;

            REVOKE ALL ON FUNCTION public.authenticate_user(character varying) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.authenticate_user(character varying) TO qrpager_app;
        SQL);
    }
};
