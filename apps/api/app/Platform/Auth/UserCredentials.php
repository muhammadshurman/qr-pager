<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Password verification, through the one function the app role is allowed to read a hash
 * with.
 *
 * ================================================================================
 * THIS IS WHAT `R-006` IS FOR
 * ================================================================================
 *
 * `R-006`'s review-by cell dictates the clause this class implements:
 *
 *   > `qrpager_app` holds no `SELECT` on `users.password` or `users.remember_token`;
 *   > authentication reads the hash only through a named `SECURITY DEFINER` function
 *   > granted to `qrpager_app` alone, proven by a test that the role gets `42501` on
 *   > `select password from users`
 *
 * So there is no Eloquent user provider behind the login, and `App\Models\User` cannot
 * even ask for the column — it pins an explicit select list precisely because `select *`
 * now raises `42501`. Measured as the app role after this task's migration:
 *
 *     select password from users     -> 42501
 *     select * from users            -> 42501
 *     where password = 'x'           -> 42501   (a predicate needs the grant too)
 *
 * ================================================================================
 * WHY IT RETURNS THE HASH RATHER THAN VERIFYING IN SQL
 * ================================================================================
 *
 * bcrypt verification needs the hash in the process doing the comparison. A verify-in-SQL
 * variant would need `pgcrypto` and would put the PLAINTEXT on the wire to Postgres, where
 * `R-004`'s whole history is about statement text reaching a log nobody redacts. So the
 * hash crosses the boundary and the plaintext does not.
 *
 * The function takes **one exact-match argument** (`§2.8` rule 2), so it answers one
 * question and cannot enumerate, and it is `RETURNS TABLE` with six named columns
 * (`§2.8` rule 1) rather than `SETOF users`, which would have handed back the two 2FA
 * secrets and undone the revoke.
 *
 * ================================================================================
 * TIMING
 * ================================================================================
 *
 * An unknown address must cost about what a known one does, or the response time is the
 * enumeration oracle the single error message exists to close. So a miss still performs a
 * hash comparison, against a fixed dummy hash, rather than returning early.
 */
final class UserCredentials
{
    /**
     * A real bcrypt hash of a value nothing knows, used to spend the same time on a miss
     * as on a hit. Generated once at build time rather than per call: hashing a random
     * string on every miss would cost MORE than the hit path, which is its own signal.
     */
    private const DUMMY_HASH = '$2y$12$C.0SMV9MYJRU7OOSRvRCSuw0eEtSJLXj5JtJgnQrJQBRhPpEWKKBS';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Hasher $hasher,
    ) {}

    /**
     * The id of the user those credentials belong to, or null.
     *
     * One answer for "no such address" and "wrong password", because the caller must not be
     * able to tell them apart -- `users` is global, so an enumeration here reads every
     * tenant's staff at once.
     */
    public function verify(string $email, string $password): ?string
    {
        $row = $this->connection->selectOne(
            'select user_id, password_hash, two_factor_confirmed_at
               from public.authenticate_user(?)',
            [Str::lower($email)],
        );

        $hash = is_object($row) && isset($row->password_hash) ? (string) $row->password_hash : null;

        // THE TIMING ARM. A miss, and an account with no password at all (a passkey-only
        // user -- §2.4 says the column is nullable for exactly that), both land here and
        // both pay for one comparison.
        if ($hash === null || $hash === '') {
            $this->hasher->check($password, self::DUMMY_HASH);

            return null;
        }

        if (! $this->hasher->check($password, $hash)) {
            return null;
        }

        // No is_object() here: the null arm above has already returned, so $row is an
        // object by this point and PHPStan says so. isset() is still needed -- the column
        // is named in the function's RETURNS TABLE but a renamed one would be a property
        // error in the middle of a login.
        $userId = isset($row->user_id) ? (string) $row->user_id : null;

        // Shape-checked on the way out, like every identifier in this project that becomes
        // a tenant scope or a Redis namespace downstream.
        return is_string($userId) && Str::isUuid($userId) ? $userId : null;
    }
}
