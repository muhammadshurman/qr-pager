<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use Illuminate\Support\Facades\Log;

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
    /**
     * Where the extra origins are read from.
     *
     * ⚠ A CONFIG KEY AND NOT `env()`, AND `larastan` CAUGHT THE FIRST VERSION DOING THE LATTER
     * -- correctly, and it is a real bug rather than a style rule. `env()` returns **null**
     * once `php artisan config:cache` has run, so a cached production deployment would have
     * silently dropped every extra origin, and the symptom is a passkey login failing with
     * nothing in the response naming an origin: the exact failure this class exists to
     * prevent, reintroduced by the mechanism meant to fix it.
     *
     * `config/webauthn.php` reads the variable, which is the one place `env()` is correct.
     */
    public const CONFIG_KEY = 'webauthn.extra_origins';

    /**
     * `APP_URL`'s origin first and always, then every usable extra origin, deduplicated.
     *
     * ⚠ `APP_URL` IS UNCONDITIONAL **AND IS NOW NORMALISED AND CHECKED** — `§11` SECURITY L-6,
     * and the finding was right about both halves.
     *
     * It used to be `(string) config('app.url')`, prepended raw. Two consequences, neither
     * visible from anywhere:
     *
     *   - `APP_URL=https://api.example.com/` — one trailing slash, which is how half the
     *     world writes a base URL and which `.env.example` happens not to — put
     *     `https://api.example.com/` in the allow-list. The library compares the page's
     *     origin to that string EXACTLY, and a page's origin never carries a trailing slash,
     *     so the application's own origin silently matched nothing. The same for a value with
     *     a path, which a deployment behind a path prefix would have.
     *   - a value with no origin at all got into the list unexamined, where it cannot match
     *     anything and cannot be noticed.
     *
     * So the value is reduced to its origin, and a value with no usable origin is **logged as
     * an error and omitted**. Omitted rather than passed through, because an entry that no
     * page's origin can ever equal is not protection — it is one more string in a list
     * somebody later reads as deliberate.
     *
     * ⚠ AND A DROPPED EXTRA ORIGIN NOW SAYS SO. The old `array_filter` discarded bad entries
     * in silence, which is the same silence the whole class exists to end: an operator who
     * mistypes `PASSKEY_EXTRA_ORIGINS` gets a passkey login that fails with nothing naming an
     * origin, which is exactly the symptom `§11` found this class fixing one layer up and
     * reintroducing here. Origins are not secrets — they are public by construction, being
     * what a browser puts in `clientDataJSON` — so the rejected value is logged in full. No
     * token, no credential: `isOrigin()` refuses a URL carrying either.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $origins = [];
        $appUrl = (string) config('app.url');
        $own = self::originOf($appUrl);

        if ($own === null) {
            Log::error('passkeys: APP_URL carries no usable origin, so this application\'s own origin is NOT in the WebAuthn allow-list and no passkey ceremony served from it can complete', [
                'app_url' => $appUrl,
            ]);
        } else {
            $origins[] = $own;
        }

        $declared = config(self::CONFIG_KEY, []);

        /** @var mixed $entry */
        foreach (is_array($declared) ? $declared : [] as $entry) {
            if (! is_string($entry)) {
                // The old code ran `strval()` over this, which THROWS for an array entry --
                // a config mistake answered with a 500 at boot. Named and skipped instead.
                Log::warning('passkeys: dropped a non-string entry from the WebAuthn extra-origin list', [
                    'type' => get_debug_type($entry),
                ]);

                continue;
            }

            $origin = self::originOf($entry);

            if ($origin === null) {
                Log::warning('passkeys: dropped an unusable entry from the WebAuthn extra-origin list', [
                    'entry' => $entry,
                ]);

                continue;
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /**
     * This string's ORIGIN — scheme, host and port — or `null` if it has none.
     *
     * ⚠ IT NORMALISES, WHICH IS A WIDENING OF WHAT `allowed()` ACCEPTS, AND IT IS SAID OUT
     * LOUD. `https://staff.example.com/` and `https://staff.example.com/app` were both
     * refused before and are both accepted now, as `https://staff.example.com`. Every refusal
     * that carries a security argument is unchanged: no wildcard, no query, no fragment, no
     * credentials, no scheme but http or https. What changed is that the PATH is dropped
     * rather than treated as a refusal — which is what a browser does to reach the origin it
     * sends, so the normalised entry is the string a real ceremony is compared against. The
     * allow-list therefore still holds origins only, and `isOrigin()` over the resolved list
     * is what re-checks that.
     *
     * `isOrigin()` keeps the strict question ("is this ALREADY an origin?") and is what the
     * list the library receives is asserted against.
     */
    public static function originOf(string $candidate): ?string
    {
        if (! self::isCandidate($candidate)) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
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
        // ⚠ EXACT EQUALITY WITH THE NORMALISED FORM, so this stays the STRICT question it was:
        // `https://x/` has an origin and is not one. Everything the old body refused, this
        // refuses -- a trailing slash included -- and the shared refusals live in one place
        // rather than in two bodies that can drift.
        return self::originOf($candidate) === $candidate;
    }

    /**
     * The refusals that carry the security argument, applied before anything is rebuilt.
     *
     * Separate from `originOf()`'s reassembly so that a reader can see the closed list in one
     * place: no wildcard (which would make the allow-list decorative), no query or fragment,
     * no credentials in the URL, and no scheme but http(s).
     *
     * ⚠ A PATH IS NOT ON THAT LIST, AND THE LINE IS DELIBERATE. `originOf()` DISCARDS the
     * path, because a path is not part of an origin: a page served from
     * `https://api.example.com/app` sends `https://api.example.com` in `clientDataJSON`, so
     * deriving that is agreeing with the browser rather than widening anything. What is
     * refused here is a value of the wrong KIND — credentials, a query, a wildcard, a
     * `javascript:` scheme — where the mistake is not cosmetic and guessing at an origin would
     * be guessing at an intention.
     */
    private static function isCandidate(string $candidate): bool
    {
        if (trim($candidate) === '') {
            return false;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }

        if (array_intersect_key($parts, array_flip(['query', 'fragment', 'user', 'pass'])) !== []) {
            return false;
        }

        return ! str_contains($parts['host'], '*');
    }
}
