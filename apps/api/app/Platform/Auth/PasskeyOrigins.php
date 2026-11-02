<?php

declare(strict_types=1);

namespace App\Platform\Auth;

/**
 * ================================================================================
 * WHICH PAGE ORIGINS MAY COMPLETE A WEBAUTHN CEREMONY
 * ================================================================================
 *
 * `laravel/passkeys` ships `'allowed_origins' => [config('app.url')]`, and until Task 10a that
 * was right: the only page running a ceremony was `/passkey-harness`, which Laravel itself
 * serves, so the page's origin and `APP_URL` were the same string by construction.
 *
 * **Task 10a puts the login screen on `apps/web`.** `§4.2`'s *"Login (passkey-first)"* is a
 * Next.js route, and an assertion carries the PAGE's origin in `clientDataJSON`, which the
 * library compares **exactly** — not by domain suffix the way it compares the relying party
 * id. So a page served from any origin but `APP_URL` fails verification, and the failure
 * arrives as a rejected ceremony rather than as anything naming an origin.
 *
 * ⚠ MEASURED, NOT ANTICIPATED. 10a's first full `gate:e2e` run failed at *"the passkey sign-in
 * did not reach the shell"* with page errors `401`, `500`, `401`. The harness serves the staff
 * shell at `https://localhost:3098` and `APP_URL` at `https://localhost:3097` — two TLS
 * fronts, which `tools/browsers/bin/e2e` stands up for reasons of its own. Same host,
 * different port: the relying party id (`localhost`) matched and the ORIGIN did not.
 *
 * ⚠ AND IT IS A PRODUCTION CONCERN RATHER THAN A HARNESS ONE. `§4 › Edge & ops` puts
 * CloudFront in front of this product; the customer PWA, the staff shell and the API are three
 * origins a deployment may or may not unify, and Task 13 owns that. A one-origin allow-list
 * makes passkey login work only in the arrangement where they are unified, and fail with no
 * diagnostic in every other.
 *
 * ================================================================================
 * ⚠⚠ WHY THIS IS A CLASS AND NOT A PUBLISHED `config/passkeys.php` — MEASURED
 * ================================================================================
 *
 * The obvious fix is `php artisan vendor:publish` and edit one line. **It does not work on
 * this Laravel version, and it fails SILENTLY**, which is worse than not working.
 *
 * Measured on `laravel/framework v13.38.0` with a published `config/passkeys.php`:
 *
 *   - a key the vendor file does NOT have (`marker`) appeared in `config('passkeys')`, so the
 *     published file WAS being read;
 *   - `timeout` set to `12345` in the published file still read back as the vendor's `60000`;
 *   - `allowed_origins` with two entries still read back as the vendor's one.
 *
 * So for every key the two files share, **the package's value wins over the application's**.
 * `ServiceProvider::mergeConfigFrom()` is `array_merge(require $path, $config->get($key, []))`
 * — vendor first, app second, app should win — so the ordering alone does not explain it; the
 * observable behaviour is consistent with configuration being resolved lazily and the
 * provider's `register()`-time `set()` already holding the key by the time the file is read.
 *
 * The cause is the framework's business. What matters here is the consequence: **a published
 * config for this package is a file that looks authoritative and is ignored**, and anyone
 * changing passkey behaviour would edit it, see no effect, and start looking at WebAuthn. So
 * the file is deliberately NOT published, this class is the one place the list is computed,
 * and `AppServiceProvider::boot()` sets it — after the package has registered its defaults,
 * which is the only point at which the application can win.
 *
 * `tests/Feature/Staff/StaffPasskeyOriginTest.php` asserts the resolved config, which is the
 * only assertion that would have caught the published-file version being inert.
 */
final class PasskeyOrigins
{
    /** The environment variable, comma-separated. Empty in `.env.example` and in production. */
    public const VARIABLE = 'PASSKEY_EXTRA_ORIGINS';

    /**
     * `APP_URL` first and always, then every valid extra origin, deduplicated.
     *
     * ⚠ `APP_URL` IS UNCONDITIONAL, so removing or mistyping the variable cannot remove the
     * application's own origin — a mistake there costs the extra origins and never the
     * working one.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $extra = array_filter(
            array_map('trim', explode(',', (string) env(self::VARIABLE, ''))),
            self::isOrigin(...),
        );

        return array_values(array_unique([(string) config('app.url'), ...$extra]));
    }

    /**
     * Whether this string is a WebAuthn origin: scheme, host, optional port, and NOTHING else.
     *
     * ⚠ A BAD ENTRY IS DROPPED RATHER THAN PASSED THROUGH, which is the direction that
     * matters: the origin check is the only thing between a credential minted for this
     * application and a page on somebody else's, so a typo must NARROW the list. No wildcard,
     * no path, no query, no credentials in the URL, no scheme but http or https.
     */
    public static function isOrigin(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }

        $forbidden = array_flip(['path', 'query', 'fragment', 'user', 'pass']);

        if (array_intersect_key($parts, $forbidden) !== []) {
            return false;
        }

        return ! str_contains($parts['host'], '*');
    }
}
