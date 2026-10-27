<?php

declare(strict_types=1);

use App\Platform\Auth\Http\AuthController;
use App\Platform\Auth\Http\PasskeyController;
use App\Platform\Auth\Http\TwoFactorController;
use App\Platform\Http\Middleware\RateLimit;
use Illuminate\Support\Facades\Route;

/*
 * THE AUTH SURFACE. The third of this project's three route sets, and the reason it is a
 * third rather than part of either existing one is circularity.
 *
 * `routes/v1.php` is on the `api` group, whose last entry is `RequireTenantScope`:
 * deny-by-default, 401 when no tenant resolves. A LOGIN cannot be on that group, because
 * the tenant is derived from the credential the login is about to establish — requiring a
 * resolved tenant in order to authenticate is a loop with no entry point.
 *
 * `routes/customer.php` is the QR-token surface and has no account behind it at all
 * (`§4 › Tenancy`, two authentication surfaces never confused). These routes are the
 * staff/admin half of that split, at the moment before a principal exists.
 *
 * SO: same `/v1` prefix, because the versioning policy is about the published path;
 * own `auth` middleware group, which carries the session stack and CSRF but NOT
 * `RequireTenantScope` and NOT `RequireAbility`.
 *
 * `RoleMatrixTest` lists every name here in its `$auth` set, so these routes are
 * accounted for by the same closed-world check that covers the other two surfaces — a
 * route added here without being listed fails the suite rather than quietly joining a set
 * that requires nothing of it.
 *
 * EVERY ROUTE HERE IS RATE-LIMITED PER IP AND NOT PER TENANT, for the same reason the
 * customer token route is (`R-063`): there is no tenant until the credential resolves, so
 * the per-tenant half cannot apply before the lookup, and resolving one in order to
 * rate-limit the request that asks for it is work an unauthenticated caller can make this
 * process do.
 */

Route::prefix('auth')->group(function (): void {
    /*
     * Exchange credentials for a session.
     *
     * The tightest bucket on this surface: a password check is a bcrypt verification, which
     * is deliberately expensive, so an unbounded loop here is a CPU denial of service as
     * well as a guessing attempt.
     */
    Route::post('login', [AuthController::class, 'login'])
        ->middleware([RateLimit::class.':auth-login'])
        ->name('auth.login');

    /*
     * End the session.
     *
     * Deliberately NOT rate-limited beyond the group default and deliberately answering
     * the same way whether or not a session existed: a logout that reports "you were not
     * logged in" is an oracle, and one that can be exhausted leaves people unable to end
     * their own session.
     */
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');

    /*
     * Choose which restaurant this session is acting in.
     *
     * Needed because a user may hold memberships in several. It is an explicit, verified
     * action rather than a per-request header: `PagerIdorTest` drives five caller-supplied
     * tenant shapes at the staff surface and requires 404 from each, and the pre-Task-5
     * resolver's docblock calls a header-driven tenant "the most dangerous shape this
     * application could ship". The selection is written into the server-side session only
     * after the membership is verified, and the resolver re-verifies it on every later
     * request anyway.
     */
    Route::post('tenant', [AuthController::class, 'selectTenant'])
        ->middleware([RateLimit::class.':auth-login'])
        ->name('auth.tenant');

    /*
     * Who the caller is, and which restaurants they may act in.
     *
     * The one endpoint here that needs an established session. It exists so a client does
     * not have to guess: a user with one membership is already resolved, and a user with
     * several has to be shown the choice.
     */
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');

    /*
     * TWO-FACTOR AUTHENTICATION.
     *
     * Enrolment is two steps and the second one is what arms it: `config/fortify.php` sets
     * `confirm: true`, so a secret exists from the moment `enable` is called and is not in
     * force until a code from it has been shown to work. Gating login on the secret alone
     * would lock somebody out of their own account between those two steps.
     *
     * The challenge is the only one of these that is reachable WITHOUT a session, because it
     * is the step that completes one. It reads the pending user id from the server-side
     * session, so a caller cannot nominate whose challenge they are completing.
     */
    Route::prefix('two-factor')->group(function (): void {
        Route::post('enable', [TwoFactorController::class, 'enable'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.two-factor.enable');

        Route::post('confirm', [TwoFactorController::class, 'confirm'])
            ->middleware([RateLimit::class.':auth-2fa'])
            ->name('auth.two-factor.confirm');

        Route::delete('/', [TwoFactorController::class, 'disable'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.two-factor.disable');

        /*
         * The tightest bucket of the three: this one is guessable. Six digits is a million
         * possibilities and a thirty-second window, so an unbounded loop is a real attack
         * rather than a theoretical one -- and a recovery code is longer but equally worth
         * bounding.
         */
        Route::post('challenge', [TwoFactorController::class, 'challenge'])
            ->middleware([RateLimit::class.':auth-2fa'])
            ->name('auth.two-factor.challenge');
    });

    /*
     * PASSKEYS -- WebAuthn, through laravel/passkeys over web-auth/webauthn-lib.
     *
     * Each ceremony is two round trips: options out, credential back. The challenge issued
     * by the first is kept SERVER-SIDE and pulled (single use) by the second, which is why
     * these routes are on this group and could not be on `api` -- a challenge the client
     * hands back to us is not a challenge.
     *
     * The two `options` routes are GETs that WRITE to the session, which is unusual enough
     * to say out loud: they are not cacheable and they are not idempotent in the session's
     * terms, because each one replaces the pending challenge. That is the ceremony's shape
     * rather than a choice.
     */
    Route::prefix('passkeys')->group(function (): void {
        Route::get('options', [PasskeyController::class, 'registrationOptions'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.passkeys.options');

        Route::post('/', [PasskeyController::class, 'register'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.passkeys.register');

        Route::get('login/options', [PasskeyController::class, 'loginOptions'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.passkeys.login-options');

        Route::post('login', [PasskeyController::class, 'login'])
            ->middleware([RateLimit::class.':auth-login'])
            ->name('auth.passkeys.login');
    });
});
