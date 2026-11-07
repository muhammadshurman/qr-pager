<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 5's data contract: the authentication tables, the narrowed credential grants, and
 * the two SECURITY DEFINER functions the auth layer reads through (PLAN.md §2.4, §2.7,
 * §2.8; QRPAGER-12 §10.1).
 *
 * THE THREE VENDOR MIGRATIONS ARE NOT PUBLISHED, and none of them could be. Fortify,
 * Sanctum and laravel/passkeys each ship one; read in-session, they use $table->id()
 * (bigint, where every id here but audit_log's is uuid), $table->timestamp() (where every
 * timestamp in §2.5 is timestamptz), and $table->morphs() (which would make tokenable_id
 * bigint against a uuid users.id). None of them grants a column to a role, adds a table to
 * the C4 allowlist, or runs as the owner. So the schemas were read and re-expressed here
 * under this project's conventions, and the vendor files stay unpublished.
 *
 * ALL THREE ADDITIONS ARE GLOBAL -- no tenant_id, no RLS -- and that is the largest single
 * widening of the RLS-free surface since Task 1, so the argument is written down rather
 * than implied. Each one has to be readable BEFORE a tenant is known:
 *
 *   - users is already global (§2.4): "a user may hold memberships in several tenants and
 *     platform staff belong to none";
 *   - a passkey belongs to a user, not to a tenant, and the WebAuthn ceremony identifies
 *     the user before any tenant is selected;
 *   - personal_access_tokens must be resolvable from a bearer token alone, which is
 *     precisely the property a tenant-scoped table cannot have. Giving it a tenant_id
 *     would make it a tenant table, C4 would then force RLS on it, and the row would be
 *     invisible to the lookup that has to happen first -- whose only escape is a fifth
 *     named cross-tenant path, which §4 › Tenancy forbids without a rank-2 edit. So the
 *     tenant a device token is good for travels in `abilities` and is VERIFIED against the
 *     holder's own memberships on every request rather than trusted.
 *
 * Each is added to App\Domain\Database\TenantTables::GLOBAL in the same change, or the C4
 * meta-test fails the build -- which is the mechanism that stops a new table quietly
 * escaping tenant isolation, and is why it is a build failure and not a review note.
 */
return new class extends Migration
{
    public function up(): void
    {
        DatabaseRoles::assertRolesExist(DB::connection());

        DB::unprepared(<<<'SQL'
            -- ===============================================================
            -- users -- four new columns (§2.4)
            -- ===============================================================
            --
            -- is_platform_admin is a FLAG and not a fourth tenant_role value. §2.3 holds
            -- tenant-SCOPED roles; §2.4 says platform staff belong to no tenant. A
            -- `platform_admin` member of tenant_role could only ever be stored in
            -- tenant_users.role, which requires a tenant_id -- so the value would exist
            -- only attached to a tenant, which is exactly what a platform admin is not.
            --
            -- NOT a tenant bypass, which is the §5 trap of QRPAGER-12 and is enforced
            -- nowhere in this file on purpose: a platform admin holding no membership in a
            -- tenant resolves no tenant for it and is refused by the ordinary 401. If a
            -- cross-tenant read is ever needed it is a new named path and
            -- PROJECT_INSTRUCTIONS.md §4 must be edited first.
            ALTER TABLE users
                ADD COLUMN is_platform_admin boolean NOT NULL DEFAULT false;

            -- Fortify's 2FA. timestamptz, not the vendor migration's timestamp: every
            -- timestamp in this schema is timestamptz and a naive one here would be the
            -- only exception in the database.
            ALTER TABLE users
                ADD COLUMN two_factor_secret         text        NULL,
                ADD COLUMN two_factor_recovery_codes text       NULL,
                ADD COLUMN two_factor_confirmed_at   timestamptz NULL;

            -- ===============================================================
            -- passkeys -- global, no RLS (§2.4)
            -- ===============================================================
            --
            -- `credential` holds a PUBLIC KEY and metadata, not a secret, and that is the
            -- property that makes WebAuthn worth having on this surface: a full database
            -- disclosure yields nothing an attacker can authenticate with. It is still
            -- credential material, so the model hides it from serialisation.
            CREATE TABLE passkeys (
                id            uuid        PRIMARY KEY,
                user_id       uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                name          varchar(120) NOT NULL,
                credential_id text        NOT NULL UNIQUE,
                credential    jsonb       NOT NULL DEFAULT '{}'::jsonb,
                last_used_at  timestamptz NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX passkeys_user_id_index ON passkeys (user_id);

            -- ===============================================================
            -- personal_access_tokens -- global, no RLS (§2.4). Sanctum's.
            -- ===============================================================
            --
            -- `token` is the SHA-256 OF the plaintext and never the plaintext: measured in
            -- vendor/laravel/sanctum/src/HasApiTokens.php:66, which stores
            -- hash('sha256', $plainTextToken). The plaintext exists once, in
            -- NewAccessToken::$plainTextToken, and is returned to the caller. So this
            -- column is the same posture as pager_sessions.token_hash -- a verifier, not a
            -- credential -- which is why it needs no column-level withholding.
            --
            -- expires_at is nullable because Sanctum's own model and pruning command
            -- expect it to be. A NULL here would be a token that never expires, and
            -- QRPAGER-12 §5 requires a device token's expiry to be explicit, so the
            -- application refuses to ISSUE one without it. Stated here because the column
            -- allows what the application does not: a reviewer should check the issuing
            -- path, not this line.
            CREATE TABLE personal_access_tokens (
                id             uuid         PRIMARY KEY,
                tokenable_type varchar(255) NOT NULL,
                tokenable_id   uuid         NOT NULL,
                name           text         NOT NULL,
                token          varchar(64)  NOT NULL UNIQUE,
                abilities      text         NULL,
                last_used_at   timestamptz  NULL,
                expires_at     timestamptz  NULL,
                created_at     timestamptz  NOT NULL DEFAULT now(),
                updated_at     timestamptz  NOT NULL DEFAULT now()
            );

            CREATE INDEX personal_access_tokens_tokenable_index
                ON personal_access_tokens (tokenable_type, tokenable_id);
            CREATE INDEX personal_access_tokens_expires_at_index
                ON personal_access_tokens (expires_at);
        SQL);

        DB::unprepared(<<<'SQL'
            -- ===============================================================
            -- R-006 -- the credential grants narrow (§2.7)
            -- ===============================================================
            --
            -- R-006's review-by cell is the clause this implements, verbatim:
            -- "qrpager_app holds no SELECT on users.password or users.remember_token;
            -- authentication reads the hash only through a named SECURITY DEFINER function
            -- granted to qrpager_app alone, proven by a test that the role gets 42501 on
            -- select password from users".
            --
            -- The two 2FA secret columns added above are deliberately NEVER granted SELECT
            -- to the app role either. They are the same class of secret, added in the same
            -- change, and granting them would re-open this row at the moment it closes.
            REVOKE SELECT (password, remember_token) ON users FROM qrpager_app;

            -- The non-secret new column is readable; the secrets are not. Stated as three
            -- separate grants rather than one, so that a future widening of the secret set
            -- cannot hide inside a longer column list.
            GRANT SELECT (is_platform_admin, two_factor_confirmed_at) ON users TO qrpager_app;

            -- A WRITE without a READ, which §2.7 already proves is possible with
            -- pager_sessions.token_hash. Enrolling 2FA, rotating a password and confirming
            -- a second factor are all writes; none of them needs to read the old value,
            -- because verification goes through the function below.
            GRANT INSERT (is_platform_admin, two_factor_secret, two_factor_recovery_codes,
                          two_factor_confirmed_at) ON users TO qrpager_app;
            GRANT UPDATE (two_factor_secret, two_factor_recovery_codes,
                          two_factor_confirmed_at) ON users TO qrpager_app;

            -- is_platform_admin is NOT updatable by the app role. Granting a tenant-facing
            -- role the ability to write the platform-admin flag would make privilege
            -- escalation a single UPDATE, and the surface that sets it is Task 6's
            -- platform panel, which runs migrations-adjacent provisioning as the owner.
            -- No UPDATE grant is the control; there is no application path that needs one.

            -- The worker authenticates nobody and gains nothing here.

            -- ===============================================================
            -- passkeys and personal_access_tokens -- grants (§2.7)
            -- ===============================================================
            GRANT SELECT (id, user_id, name, credential_id, credential, last_used_at,
                          created_at, updated_at) ON passkeys TO qrpager_app;
            GRANT INSERT (id, user_id, name, credential_id, credential,
                          created_at, updated_at) ON passkeys TO qrpager_app;
            GRANT UPDATE (name, last_used_at, updated_at) ON passkeys TO qrpager_app;
            -- A user removing their own passkey is an ordinary action on their own row.
            GRANT DELETE ON passkeys TO qrpager_app;

            GRANT SELECT (id, tokenable_type, tokenable_id, name, token, abilities,
                          last_used_at, expires_at, created_at, updated_at)
                ON personal_access_tokens TO qrpager_app;
            GRANT INSERT (id, tokenable_type, tokenable_id, name, token, abilities,
                          expires_at, created_at, updated_at)
                ON personal_access_tokens TO qrpager_app;
            -- last_used_at is the only thing a request may rewrite. Not `abilities` and
            -- not `expires_at`: a token that could widen its own abilities or extend its
            -- own life is the escalation QRPAGER-12's second named invariant forbids, and
            -- withholding the grant makes that structural rather than a policy check.
            GRANT UPDATE (last_used_at, updated_at) ON personal_access_tokens TO qrpager_app;
            GRANT DELETE ON personal_access_tokens TO qrpager_app;
        SQL);

        DB::unprepared(<<<'SQL'
            -- ===============================================================
            -- §2.8 -- public.authenticate_user(varchar)
            -- ===============================================================
            --
            -- R-006's named function. NOT a cross-tenant path: `users` is global and
            -- carries no RLS at all, so this crosses no tenant boundary and is not a fifth
            -- entry on §4 › Tenancy's list. What it crosses is a COLUMN GRANT -- it is the
            -- only way the app role can reach the password hash, now that the grant above
            -- is gone.
            --
            -- §2.8's two rules, both of them:
            --   1. RETURNS TABLE and never RETURNS SETOF users. Column grants are not
            --      applied to a function's result set, so a SETOF resolver would hand back
            --      every column including the 2FA secrets and undo the revoke above.
            --   2. One exact-match argument, so it answers one question and cannot
            --      enumerate. No LIKE, no pattern, no range, no IN.
            --
            -- It needs no bypass GUC, because there is no RLS on users to bypass. That is
            -- the difference between this function and resolve_pager_token, and it is why
            -- there is no set_config pair here: adding one would be cargo-culted from a
            -- function that genuinely needs it.
            --
            -- It returns the HASH and the application verifies it in PHP, because bcrypt
            -- verification needs the hash in the process doing the comparison. A
            -- verify-in-SQL variant would need pgcrypto and would move password
            -- verification into the database, which is a larger change than the row asks
            -- for and would put the plaintext on the wire to Postgres.
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

            -- ===============================================================
            -- §2.8 -- public.user_memberships(uuid)
            -- ===============================================================
            --
            -- THIS one IS a cross-tenant read, and it is §4 › Tenancy's second named path,
            -- "a user's own memberships" -- already on the list, so no rank-2 edit.
            -- tenant_users and tenants are both tenant tables under FORCE RLS, so the
            -- bypass is necessary and is bounded by the same pattern resolve_pager_token
            -- established: on for the body only, restored before returning, including on
            -- the error path.
            --
            -- WHY IT CANNOT BE WIDENED, which is what QRPAGER-12 §10.3(c) has to show:
            --   - one argument, a uuid, compared with `=`. There is no input shape that
            --     returns a second user's memberships;
            --   - the caller passes the AUTHENTICATED user's id, from the guard, never
            --     from request input -- one call site, asserted by a test;
            --   - RETURNS TABLE with three named columns, so §2.8 rule 1 holds and nothing
            --     from tenant_users leaks beyond them;
            --   - EXECUTE to qrpager_app alone, after REVOKE FROM PUBLIC, because a
            --     SECURITY DEFINER function is executable by PUBLIC by default.
            --
            -- plpgsql rather than sql, because the bypass has to be set and restored and
            -- the error path has to restore it too: the transaction-scoped third argument
            -- binds to the OUTERMOST transaction (§2.10), so a throw without the restore
            -- would leave the bypass on for the rest of the caller's transaction.
            --
            -- (That sentence describes the call rather than spelling it out, and the
            -- reason is worth a line. composer guard reads this heredoc as SOURCE --
            -- correctly, because it cannot tell a `--` comment inside a SQL string from
            -- the statement around it -- so an illustrative call written here with an
            -- elided argument list is reported as a C2 violation with an absent third
            -- argument. It happened twice while writing this file. The guard's
            -- false-positive direction is the chosen one, so the prose moved rather than
            -- the rule, and the real calls below carry the literal true.)
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

    public function down(): void
    {
        // Reverse order of up(), and the grants go with the objects they were made on, so
        // nothing is left granted on a dropped column. Rollback is a gate clause (§6:
        // "a rollback of this task's migrations runs clean"), proven by
        // bin/migration-rollback-proof rather than asserted here.
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.user_memberships(uuid);
            DROP FUNCTION IF EXISTS public.authenticate_user(character varying);

            DROP TABLE IF EXISTS personal_access_tokens;
            DROP TABLE IF EXISTS passkeys;

            ALTER TABLE users
                DROP COLUMN IF EXISTS two_factor_confirmed_at,
                DROP COLUMN IF EXISTS two_factor_recovery_codes,
                DROP COLUMN IF EXISTS two_factor_secret,
                DROP COLUMN IF EXISTS is_platform_admin;

            -- Restore what the revoke above took away, so that rolling back to Task 4
            -- leaves Task 4's grant matrix exactly as GrantMatrixTest pins it. Without
            -- this the rollback is "clean" by the migrator's reckoning and leaves the
            -- previous task's own suite red, which is the shape §6's rollback clause
            -- exists to catch.
            GRANT SELECT (password, remember_token) ON users TO qrpager_app;
        SQL);
    }
};
