<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ PASSKEY LOGIN RETURNED `403` FOR EVERY USER, AND NO TEST IN THE SUITE SAW IT.
 *
 * `2026_10_28_120000_create_auth_contract.php` granted
 *
 *     GRANT UPDATE (name, last_used_at, updated_at) ON passkeys TO qrpager_app;
 *
 * and `Laravel\Passkeys\Actions\VerifyPasskey::updatePasskey()` writes `credential` AND
 * `last_used_at` on every successful assertion. `credential` is not in that list, so the
 * `UPDATE` raised `42501` — *permission denied for table passkeys* — which
 * `DatabaseErrorMapper` maps to `ProblemType::Forbidden`. The customer-visible effect was a
 * **`403` on `POST /v1/auth/passkeys/login` for a credential the browser's own authenticator
 * had just signed correctly**: registration succeeded, login never could.
 *
 * ⚠ AND THE COLUMN IS NOT OPTIONAL, which is why this is a grant and not a narrowing of the
 * write. The vendor's own comment on that method: *"The credential must be persisted after
 * each use to store the updated signature counter, which is used to detect cloned
 * authenticators."* So the missing grant did not merely break login — it would have left the
 * clone-detection counter frozen at its registration value for the lifetime of the key,
 * which is the control WebAuthn's counter exists to provide.
 *
 * ⚠ HOW IT SURVIVED 1,167 TESTS. Nothing in the suite drives the assertion ceremony as
 * `qrpager_app`: the counter is only written after a real authenticator signs a real
 * challenge, so the statement is unreachable without a browser. `R-100` is the `§5` row that
 * asked for exactly that toolchain, and Task 9's `browser/passkey.spec.ts` — a virtual
 * authenticator over CDP against the real API — is what finally ran it. **The harness found
 * it on its first full pass.** `AuthGrantNarrowingTest` now also drives the statement
 * directly, so the regression no longer needs a browser to be caught.
 *
 * WHAT THIS DOES NOT GRANT, deliberately: nothing table-wide, no `credential_id` UPDATE (a
 * credential's identity never changes; a changed id is a different key), and no `user_id`
 * UPDATE (re-pointing a key at another account is the escalation shape
 * `2026_10_28_140000_narrow_auth_grants.php` exists to refuse). The grant is one column,
 * named, and `AuthGrantNarrowingTest` pins the resulting set EXACTLY so a later widening
 * fails there rather than passing quietly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            GRANT UPDATE (credential) ON passkeys TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            REVOKE UPDATE (credential) ON passkeys FROM qrpager_app;
        SQL);
    }
};
