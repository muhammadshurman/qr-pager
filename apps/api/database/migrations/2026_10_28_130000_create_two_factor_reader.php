<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `public.user_two_factor(uuid)` — the second-factor material, read the only way the app
 * role is allowed to read it (PLAN.md §2.7, §2.8).
 *
 * ================================================================================
 * WHY THIS EXISTS, and it was found by a test rather than by design
 * ================================================================================
 *
 * `2026_10_28_120000_create_auth_contract` withholds `SELECT` on `two_factor_secret` and
 * `two_factor_recovery_codes` from `qrpager_app`. `R-006`'s clause names only `password` and
 * `remember_token`, so that was a CHOICE rather than a requirement — made on the reasoning
 * that a 2FA secret is the same class of secret, and that a role which can
 * `select two_factor_secret from users` leaks every staff member's second factor in one
 * over-broad query.
 *
 * **The choice has a consequence the first draft did not see.** Fortify reads both columns
 * **off the model**: `TwoFactorAuthenticatable::twoFactorQrCodeSvg()` and `recoveryCodes()`
 * decrypt `$this->two_factor_secret` / `$this->two_factor_recovery_codes`, and
 * `App\Models\User::READABLE` does not contain either — so they come back null and
 * Fortify's decrypt throws. Measured, through the real endpoint:
 *
 *     POST /v1/auth/two-factor/enable  ->  500
 *     DecryptException: The payload is invalid.   (the payload was null)
 *
 * Three ways out, and the chosen one is the third:
 *
 *   1. **Grant `SELECT` on both columns.** Literally allowed — `R-006` does not name them —
 *      and rejected: it re-opens the exact shape `R-006` is about, one task after closing it.
 *   2. **Keep the secret in memory and never read it back.** Works for enrolment and fails
 *      for everything after it: confirming a code and answering a challenge both need the
 *      secret on a later request, and a recovery code has to be checked against the stored
 *      set.
 *   3. **Read it through a `SECURITY DEFINER` function**, exactly as the password hash is
 *      read through `authenticate_user()`. One exact-match argument, a narrow
 *      `RETURNS TABLE`, `EXECUTE` to `qrpager_app` alone. The read becomes a named,
 *      single-purpose, auditable call instead of a column grant.
 *
 * NOT a cross-tenant path: `users` is global and carries no RLS, so there is no tenant
 * boundary here and no `set_config` pair — same as `authenticate_user()`, and for the same
 * reason. What it crosses is a column grant.
 *
 * A SEPARATE MIGRATION rather than an edit to `..._120000_create_auth_contract`, which is
 * the rule `R-003`'s closure records: that file has already been applied to every database
 * on this branch, and editing an applied snapshot leaves them without the function while the
 * file claims otherwise. The rollback drops it, so `§6`'s clean-rollback clause still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        DatabaseRoles::assertRolesExist(DB::connection());

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.user_two_factor(p_user_id uuid)
            RETURNS TABLE (
                two_factor_secret         text,
                two_factor_recovery_codes text,
                two_factor_confirmed_at   timestamptz
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $fn$
                SELECT u.two_factor_secret, u.two_factor_recovery_codes, u.two_factor_confirmed_at
                  FROM public.users u
                 WHERE u.id = p_user_id;
            $fn$;

            REVOKE ALL ON FUNCTION public.user_two_factor(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.user_two_factor(uuid) TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.user_two_factor(uuid);');
    }
};
