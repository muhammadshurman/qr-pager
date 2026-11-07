<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THIS APPLICATION'S WEBAUTHN SETTINGS. One key, and the FILE NAME is deliberate.
 * ================================================================================
 *
 * `App\Platform\Auth\PasskeyOrigins` carries the whole argument for why the passkey origin
 * allow-list has to be computed by this application rather than taken from the library's
 * default. Two constraints decided the shape, and both are measured rather than assumed:
 *
 * ⚠ **IT CANNOT BE `config/passkeys.php`.** Measured on `laravel/framework v13.38.0`: a
 * published `config/passkeys.php` IS read -- a key the vendor file lacks appears in
 * `config('passkeys')` -- and for every key the two files SHARE, the package's value wins.
 * `timeout` set to `12345` read back as the vendor's `60000`; a two-entry `allowed_origins`
 * read back as the vendor's one. So a file with that name looks authoritative and is ignored
 * for exactly the keys anyone would edit it for. `AppServiceProvider::boot()` sets
 * `passkeys.allowed_origins` instead, which is the first point the application can win.
 *
 * ⚠ **AND THE VALUE CANNOT COME FROM `env()` IN A CLASS.** `larastan`'s
 * `noEnvCallsOutsideOfConfig` caught the first version doing exactly that, and it is a real
 * bug rather than a style rule: `env()` returns **null** once `php artisan config:cache` has
 * run, so a cached production deployment would have silently dropped every extra origin --
 * and the symptom is a passkey login that fails with nothing in the response naming an
 * origin, which is the failure this whole feature exists to prevent.
 *
 * A config file is the one place `env()` is correct, because this file is evaluated before
 * the cache is written and its RESULT is what gets cached.
 */

return [

    /*
     * Extra PAGE origins allowed to complete a WebAuthn ceremony, as a list.
     *
     * `PASSKEY_EXTRA_ORIGINS` is comma-separated and empty by default. Each entry is
     * VALIDATED by `PasskeyOrigins::isOrigin()` before it reaches the library -- no wildcard,
     * no path, no credentials, no scheme but http(s) -- and a bad entry is DROPPED rather
     * than passed through, so a typo narrows the allow-list instead of widening it.
     *
     * Split here and validated there, deliberately: this file turns one string into a list
     * and makes no judgements, so the judgement lives in one testable place.
     */
    'extra_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PASSKEY_EXTRA_ORIGINS', ''))),
        static fn (string $origin): bool => $origin !== '',
    )),

];
