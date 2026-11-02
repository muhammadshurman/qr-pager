<?php

declare(strict_types=1);

use App\Platform\Auth\PasskeyOrigins;

/*
 * ================================================================================
 * THE PASSKEY ORIGIN ALLOW-LIST — asserted on the RESOLVED CONFIG, not on a file
 * ================================================================================
 *
 * `App\Platform\Auth\PasskeyOrigins` carries the whole argument. Two things are asserted here
 * and the FIRST is the one that matters, because it is the one a published config file would
 * fail:
 *
 *   1. `config('passkeys.allowed_origins')` really is what this application computed. The
 *      first attempt at this feature published `config/passkeys.php`, and on
 *      `laravel/framework v13.38.0` the PACKAGE's value won for every key the two files share
 *      -- silently. A test that read the file, or that asserted `PasskeyOrigins::allowed()`
 *      alone, would have passed against a completely inert change.
 *   2. The validation narrows rather than widens. A bad entry is DROPPED, which is the
 *      direction that matters: the origin check is the only thing between a credential minted
 *      for this application and a page on somebody else's.
 */

it('is what THIS application computed, not what the package registered', function (): void {
    // ⚠ THE CASE THE PUBLISHED-CONFIG VERSION WOULD HAVE FAILED. `AppServiceProvider::boot()`
    // sets this AFTER every `register()`, which is the only point at which the application can
    // have the last word over `mergeConfigFrom`.
    $resolved = config('passkeys.allowed_origins');

    expect($resolved)->toBe(PasskeyOrigins::allowed());

    // `APP_URL` is unconditionally first, so a mistyped variable costs the extra origins and
    // never the working one.
    expect($resolved[0] ?? null)->toBe((string) config('app.url'));
});

it('derives the relying party id from APP_URL\'s host, which is a SUFFIX match and not an origin', function (): void {
    // Stated as an assertion because the distinction is what the measured defect turned on:
    // the relying party id matched across two ports and the ORIGIN did not. A reader who
    // conflates them concludes the harness was misconfigured rather than under-configured.
    expect(config('passkeys.relying_party_id'))
        ->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
});

it('accepts an origin and nothing that merely looks like one', function (): void {
    foreach ([
        'https://staff.example.com',
        'http://localhost:3098',
        'https://localhost',
        'http://127.0.0.1:8000',
    ] as $good) {
        expect(PasskeyOrigins::isOrigin($good))->toBeTrue("rejected a valid origin: {$good}");
    }

    foreach ([
        // Each with the reason it is refused, because a list of rejected strings with no
        // reasons is a list somebody later shortens.
        '' => 'empty',
        '   ' => 'whitespace only',
        'staff.example.com' => 'no scheme, so not an origin',
        'https://*.example.com' => 'a wildcard, which would make the list decorative',
        'https://example.com/path' => 'carries a path',
        'https://example.com?x=1' => 'carries a query',
        'https://example.com#f' => 'carries a fragment',
        'https://user:pw@example.com' => 'carries credentials',
        'ftp://example.com' => 'not an http scheme',
        'javascript:alert(1)' => 'not an http scheme',
        '//example.com' => 'scheme-relative, so not an origin',
    ] as $bad => $why) {
        expect(PasskeyOrigins::isOrigin((string) $bad))->toBeFalse("accepted {$bad} — {$why}");
    }
});

it('is a closed list with no wildcard in it, whatever the environment says', function (): void {
    // The property a reviewer should check directly, independent of the validator: no entry in
    // the list the library is actually given may contain a star.
    foreach (config('passkeys.allowed_origins') as $origin) {
        expect(str_contains((string) $origin, '*'))->toBeFalse("a wildcard reached the allow-list: {$origin}");
        expect(PasskeyOrigins::isOrigin((string) $origin))->toBeTrue("a non-origin reached the allow-list: {$origin}");
    }

    // Not vacuous: the list is non-empty, so the loop above examined something.
    expect(config('passkeys.allowed_origins'))->not->toBe([]);
});
