<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Principal;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ================================================================================
 * THIS MODEL MAY NEVER EMIT `select *`, AND THAT IS NOT A STYLE RULE
 * ================================================================================
 *
 * `R-006` is closed by revoking `qrpager_app`'s `SELECT` on `users.password` and
 * `users.remember_token`, and by never granting it on the two 2FA secret columns this task
 * adds. Measured against the real cluster after that migration, as `qrpager_app`:
 *
 *     select password from users            -> 42501  permission denied for table users
 *     select * from users                   -> 42501  permission denied for table users
 *     where password = 'x'                  -> 42501  (a predicate needs the grant too)
 *     select id, name, email, …             -> OK
 *
 * `select *` expands to every column, so an ungranted column anywhere in the table makes
 * the whole statement fail — and Eloquent's default select IS `select *`. So the global
 * scope below pins an explicit column list on every query this model builds. Without it,
 * every single read of a user is a 42501, which is a *loud* failure rather than a leak;
 * but it is also every authentication in the application, so it is pinned here and
 * `UserColumnGrantTest` asserts both halves — that the model's reads succeed and that a
 * raw `select *` still does not.
 *
 * ================================================================================
 * REMEMBER-ME IS OFF, by construction rather than by configuration
 * ================================================================================
 *
 * Validating a "remember me" cookie requires READING `remember_token`, which this role can
 * no longer do. So the feature cannot work, and the honest thing is to make that explicit
 * rather than leave a login form with a checkbox that silently fails open or closed:
 * `getRememberToken()` answers `null` and `setRememberToken()` is a no-op, so Laravel's
 * `SessionGuard` never issues a recaller cookie and never tries to resolve one.
 * `SessionPolicyTest` asserts no recaller cookie is set on login.
 *
 * The trade is deliberate: a staff member re-authenticates when their session expires.
 * `R-006` called `UPDATE users SET password = …` "a direct authentication bypass", and the
 * same reasoning applies to a long-lived credential sitting in a column the application
 * cannot even read to audit.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Every column `qrpager_app` holds `SELECT` on, and therefore every column this model
     * may ask for.
     *
     * Kept as an explicit list rather than derived from the schema: the list IS the
     * assertion. A column added to `users` without a grant must break a test, not silently
     * join a `select *` that then fails at runtime in production.
     *
     * The four absent names are the point: `password`, `remember_token`,
     * `two_factor_secret`, `two_factor_recovery_codes`.
     *
     * @var list<string>
     */
    public const READABLE = [
        'id',
        'name',
        'email',
        'email_verified_at',
        'is_platform_admin',
        'two_factor_confirmed_at',
        'created_at',
        'updated_at',
    ];

    protected static function booted(): void
    {
        // Only when the caller has not chosen its own columns, so an explicit
        // `select('id')` and the aggregate queries Eloquent rewrites (`count()` clears the
        // select list and sets its own) are left alone.
        static::addGlobalScope('readableColumns', function (Builder $query): void {
            if ($query->getQuery()->columns === null) {
                $query->select(array_map(
                    static fn (string $column): string => 'users.'.$column,
                    self::READABLE,
                ));
            }
        });
    }

    /**
     * Re-read this user from the database.
     *
     * ================================================================================
     * OVERRIDDEN BECAUSE `refresh()` BYPASSES GLOBAL SCOPES, AND THAT WAS A MEASURED HOLE
     * ================================================================================
     *
     * `Model::refresh()` and `Model::fresh()` build their query with
     * `newQueryWithoutScopes()`, which is correct for their purpose and fatal for this
     * model: the global scope above is the only thing keeping `select *` out of a table
     * where four columns are ungranted, so both methods went straight to
     *
     *     SQLSTATE[42501] permission denied for table users
     *
     * Found by `TwoFactorTest`, not by reading: `TwoFactorController::enable()` called
     * `$user->refresh()` after enrolling, and the whole suite answered 403 on a request
     * that had nothing to do with authorisation. The caller was changed too, but a caller
     * fix alone would have left the next `refresh()` anywhere in the application to find
     * this again — so the model is where it is closed.
     *
     * The column list is applied explicitly rather than by re-adding the scope, because the
     * point of `withoutScopes` is that scopes do not apply; what this needs is not a scope
     * but a fact about which columns exist for this role.
     */
    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        // where()->firstOrFail() and not findOrFail(): find() accepts an array of keys and
        // is therefore typed as model-or-collection, which is not what a refresh returns.
        $fresh = static::query()
            ->select(array_map(static fn (string $c): string => 'users.'.$c, self::READABLE))
            ->where($this->getKeyName(), $this->getKey())
            ->firstOrFail();

        $this->setRawAttributes($fresh->getAttributes(), true);

        $this->load(collect($this->relations)->reject(
            fn ($relation) => $relation instanceof Pivot,
        )->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /**
     * A fresh instance of this user, read with the readable column list.
     *
     * Same reason as `refresh()` above: the inherited one uses `newQueryWithoutScopes()`.
     *
     * @param  array<int, string>|string  $with
     */
    public function fresh($with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        return static::query()
            ->with(is_string($with) ? func_get_args() : $with)
            ->select(array_map(static fn (string $c): string => 'users.'.$c, self::READABLE))
            ->where($this->getKeyName(), $this->getKey())
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
            // NO CAST ON EITHER 2FA COLUMN, and that is the correction rather than an
            // omission. Fortify encrypts and decrypts both ITSELF --
            // EnableTwoFactorAuthentication writes Fortify::currentEncrypter()->encrypt(...)
            // and TwoFactorAuthenticatable reads it back with ->decrypt(...) -- so an
            // `encrypted` cast here encrypts a second time on write and hands Fortify
            // ciphertext to decrypt on read.
            //
            // Measured: the first draft declared both casts and 2FA enrolment answered 500
            // with `DecryptException: The payload is invalid.` from inside
            // twoFactorQrCodeSvg(). Found by TwoFactorTest rather than by reading, which is
            // the useful direction for a defect whose symptom is three layers from its
            // cause.
            //
            // The columns are still kept out of serialisation by the #[Hidden] attribute on
            // this class, and out of the app role's SELECT grant entirely (§2.7, R-006), so
            // dropping the cast costs nothing in exposure.
        ];
    }

    /**
     * A platform admin, and NOT a tenant bypass.
     *
     * `QRPAGER-12 §5` and the rank-1 prompt both say so. This flag gates platform-scoped
     * things — provisioning a tenant, reading `audit_log` rows whose `tenant_id` is NULL —
     * and `RoleMatrix` grants the `PlatformAdmin` principal **no tenant ability at all**.
     * A platform admin holding no membership in a tenant resolves no tenant for it and is
     * refused by the ordinary 401, which `PlatformAdminIsNotABypassTest` drives through
     * HTTP rather than reading off this comment.
     *
     * The column carries no `UPDATE` grant for `qrpager_app` (§2.7, this task's
     * migration), so no request can set it — privilege escalation here is not a policy
     * check that could be wrong, it is a grant that does not exist.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->getAttribute('is_platform_admin');
    }

    /** The authorisation subject this user is, before any tenant is resolved. */
    public function platformPrincipal(): ?Principal
    {
        return $this->isPlatformAdmin() ? Principal::PlatformAdmin : null;
    }

    /*
     * passkeys(), hasPasskeysEnabled(), getPasskeyUserHandle(), getPasskeyDisplayName()
     * and getPasskeyUsername() come from PasskeyAuthenticatable and are NOT overridden
     * here. That is a decision rather than an omission.
     *
     * The first draft of this class implemented all five, and its getPasskeyUserHandle()
     * returned the raw user uuid. The trait's own implementation returns
     * hash_hmac('sha256', table|key, passkeys.user_handle_secret, binary: true) -- a
     * stable handle that reveals no PII. A WebAuthn user handle is stored BY THE
     * AUTHENTICATOR, which for a synced credential store means a third party, so handing
     * it a database identifier is a disclosure nothing in this application would have
     * flagged. The trait is better than the override was, so the override is gone and this
     * comment exists to stop it coming back.
     */

    /**
     * Remember-me is unavailable. See the class docblock.
     *
     * Returning null rather than throwing: `SessionGuard` calls this on every login and a
     * throw would turn an unavailable convenience into a failed authentication.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Deliberately nothing. The column exists and the role can write it; what it
        // cannot do is READ it back, so a value written here could never be validated and
        // would be a credential nobody can audit.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
