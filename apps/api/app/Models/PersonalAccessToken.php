<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as BaseToken;

/**
 * Sanctum's token model, with a uuid key.
 *
 * THE SECOND INSTANCE OF ONE MISMATCH, and worth naming as a pattern rather than fixing
 * twice in silence: both `laravel/passkeys` and `laravel/sanctum` ship a migration using
 * `$table->id()`, so both of their models assume an auto-incrementing integer key. This
 * project's ids are uuids (`PLAN.md §2.5`) and its migrations are its own, so the column has
 * no default and an insert through the vendor model sends no id.
 *
 * Measured rather than predicted -- the first device-token issue answered 500 with:
 *
 *     SQLSTATE[23502]: null value in column "id" of relation "personal_access_tokens"
 *     violates not-null constraint
 *
 * which is the loud failure direction, and is why this is a test catching a wiring gap
 * rather than a column quietly filling with something unintended.
 *
 * `HasUuids` is the whole of the difference. Registered through
 * `Sanctum::usePersonalAccessTokenModel()` in AppServiceProvider, the package's own seam.
 *
 * Nothing else changes. The parent's `$hidden` keeps `token` out of serialisation, its
 * `abilities` cast stays json, and `can()`/`cant()` -- which is what bounds a device
 * token's capability -- are inherited untouched.
 */
class PersonalAccessToken extends BaseToken
{
    use HasUuids;

    /**
     * Find a token by its presented value, refusing a malformed id before the query.
     *
     * ================================================================================
     * WHY THIS OVERRIDE EXISTS: AN AUTHENTICATION FAILURE WAS ANSWERING 422
     * ================================================================================
     *
     * The parent splits `<id>|<plaintext>` and calls `static::find($id)`. The key is a uuid
     * here, so an attacker-chosen id went straight into `where id = ?` as a non-uuid and
     * Postgres raised `22P02`, which `DatabaseErrorMapper` maps to `ProblemType::Validation`.
     * Both `§11` reviews of the fix diff measured the consequences, and the status was the
     * smaller of them:
     *
     *   `Authorization: Bearer zzz|q` -> **422** on an AUTHENTICATION failure, on every
     *   surface that resolves a token, plus **one failed Postgres statement** and **337 bytes
     *   of `local.ERROR`** per request -- from a caller with no credential at all. The log
     *   line echoes the attacker's own string (`invalid input syntax for type uuid: "zzz"`).
     *
     * That is the same unbounded-ERROR flood this task downgraded `RefuseDeviceToken` from
     * `report()` to `Log::warning` to avoid, on the path that needs no credential rather than
     * the one that needs a stolen screen -- so fixing it there and not here would have been
     * the inconsistency the second review pointed out.
     *
     * A SHAPE CHECK IS THE WHOLE FIX. A token id that is not a uuid cannot match any row, so
     * refusing it here is not a policy decision -- it is declining to ask the database a
     * question whose answer is already known. The caller gets the ordinary bodiless 401 that
     * every other unauthenticated request gets, nothing is logged, and no statement is sent.
     *
     * `Str::isUuid` and not a regex, because it is what every other identifier in this
     * project is checked with (`Memberships`, `RequestPrincipal`, `TenantPin`).
     *
     * The no-pipe branch is left to the parent: it hashes the whole string and matches on
     * `token`, a `varchar`, so there is no cast to fail.
     */
    public static function findToken($token)
    {
        // No is_string() guard: the parent documents the parameter as a string and the
        // analyser treats that as certain, so the check was flagged as always true.
        if (str_contains((string) $token, '|')) {
            [$id] = explode('|', $token, 2);

            if (! Str::isUuid($id)) {
                return null;
            }
        }

        return parent::findToken($token);
    }
}
