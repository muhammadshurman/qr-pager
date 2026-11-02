<?php

declare(strict_types=1);

use App\Platform\Auth\PasskeyOrigins;
use Illuminate\Support\Facades\Log;

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

    // `APP_URL`'s ORIGIN is unconditionally first, so a mistyped extra variable costs the
    // extra origins and never the working one.
    expect($resolved[0] ?? null)->toBe(PasskeyOrigins::originOf((string) config('app.url')));
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

it('reads its extra origins from CONFIG, so `config:cache` cannot silently empty them', function (): void {
    // ⚠ THE BUG `larastan` CAUGHT, PINNED. The first version read `env()` from inside
    // `PasskeyOrigins`, and `env()` answers null once `php artisan config:cache` has run -- so
    // a cached production deployment would have dropped every extra origin, and the symptom
    // is a passkey login failing with nothing naming an origin. That is the exact failure this
    // class exists to prevent, reintroduced by the mechanism meant to fix it.
    expect(PasskeyOrigins::CONFIG_KEY)->toBe('webauthn.extra_origins');
    expect(config('webauthn.extra_origins'))->toBeArray();

    /*
     * Driven: a value set in config reaches the allow-list, an unusable one does not, and a
     * path-carrying one is NORMALISED.
     *
     * ⚠ THAT MIDDLE ENTRY USED TO BE DROPPED AND IS NOW KEPT AS ITS ORIGIN -- `§11` SECURITY
     * L-6, and this case is amended rather than left reading as if nothing changed.
     * `https://bad.test/path` was refused outright; `PasskeyOrigins::originOf()` now returns
     * `https://bad.test` for it, which is the origin a browser serving that page actually
     * sends. The entry that is still dropped is the one with no origin to find at all.
     */
    config(['webauthn.extra_origins' => ['https://staff.example.com', 'https://bad.test/path', 'ftp://bad.test', '']]);

    expect(PasskeyOrigins::allowed())->toBe([
        PasskeyOrigins::originOf((string) config('app.url')),
        'https://staff.example.com',
        'https://bad.test',
    ]);
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

/*
 * ================================================================================
 * `§11` SECURITY L-6 — a dropped origin used to be dropped in SILENCE, and `APP_URL`
 * reached the allow-list unexamined
 * ================================================================================
 *
 * Both halves of that finding are a defect of the same shape as the one this class exists to
 * fix: a passkey login that fails with nothing anywhere naming an origin. The cases below
 * drive the two mechanisms rather than describing them.
 */

it('reduces APP_URL to its origin, so one trailing slash cannot silently match nothing', function (): void {
    /*
     * ⚠ THE DEFECT, DRIVEN. `APP_URL=https://api.example.com/` is how a great many
     * deployments write it and how `.env.example` happens not to. The library compares the
     * page's origin to this string EXACTLY, and a page's origin never carries a trailing
     * slash — so the raw value put an entry in the allow-list that no ceremony could ever
     * match, including one served from the application's own origin.
     */
    config(['app.url' => 'https://api.example.com/', PasskeyOrigins::CONFIG_KEY => []]);

    expect(PasskeyOrigins::allowed())->toBe(['https://api.example.com']);

    // A path prefix, which a deployment behind one has, is refused rather than truncated: the
    // origin of `https://api.example.com/app` IS `https://api.example.com`, and that is what
    // a browser would send, so normalising it is correct and silently broadening it is not.
    config(['app.url' => 'https://api.example.com/app']);

    expect(PasskeyOrigins::allowed())->toBe(['https://api.example.com']);

    // And the port survives, because an origin is scheme + host + PORT and 10a's own measured
    // failure was two ports on one host.
    config(['app.url' => 'https://localhost:3097/']);

    expect(PasskeyOrigins::allowed())->toBe(['https://localhost:3097']);
});

it('says so in the log when APP_URL has no usable origin, and omits it rather than passing it through', function (): void {
    Log::spy();

    config(['app.url' => 'not-a-url', PasskeyOrigins::CONFIG_KEY => ['https://staff.example.com']]);

    // Omitted, not passed through: an entry no page's origin can equal is not protection, and
    // leaving it in a list is how it gets read as deliberate later.
    expect(PasskeyOrigins::allowed())->toBe(['https://staff.example.com']);

    /*
     * ⚠ `withArgs()` BEFORE `once()`, which is not a style choice. Mockery applies the count
     * to the METHOD, so `->once()->withArgs(...)` asserts *"warning was called once, and by
     * the way it matched these arguments"* -- and it fails against three different warnings
     * even when each one is correct. Written this way it asserts *"exactly one call matched
     * these arguments"*, which is the claim.
     */
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'APP_URL')
            && $context['app_url'] === 'not-a-url')
        ->once();
});

it('names every extra origin it drops, instead of filtering in silence', function (): void {
    Log::spy();

    config([PasskeyOrigins::CONFIG_KEY => [
        'https://staff.example.com',
        // Three with no origin to find: a wildcard would make the list decorative, a
        // non-http scheme cannot be a page's origin, and an empty entry is a typo in the
        // comma-separated variable.
        'https://*.wildcard.test',
        'ftp://staff.example.com',
        '',
    ]]);

    expect(PasskeyOrigins::allowed())->toBe([
        PasskeyOrigins::originOf((string) config('app.url')),
        'https://staff.example.com',
    ]);

    // ⚠ EXACTLY THREE, AND EACH NAMED. A count alone would pass if one warning were logged
    // three times, and `§11`'s point was that an operator cannot act on a silence.
    Log::shouldHaveReceived('warning')->times(3);

    foreach (['https://*.wildcard.test', 'ftp://staff.example.com', ''] as $dropped) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'unusable')
                && $context['entry'] === $dropped)
            ->once();
    }
});

it('survives a non-string entry instead of throwing at boot', function (): void {
    // The old body ran `strval()` over every entry, which THROWS for an array one -- a
    // configuration mistake answered with a 500 before the application finished booting.
    Log::spy();

    config([PasskeyOrigins::CONFIG_KEY => [['https://staff.example.com'], 42, null]]);

    expect(PasskeyOrigins::allowed())->toBe([
        PasskeyOrigins::originOf((string) config('app.url')),
    ]);

    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'non-string')
            && $context['type'] === 'array')
        ->once();
});

it('accepts a trailing slash on an EXTRA origin and stores the normalised form', function (): void {
    // ⚠ THIS IS A WIDENING OF WHAT IS ACCEPTED AND IT IS SAID OUT LOUD. `isOrigin()` still
    // answers false for `https://staff.example.com/` -- it is not an origin -- but `allowed()`
    // now normalises it instead of discarding it, because a trailing slash is a typing
    // convention and not a different origin. What reaches the library is the normalised form,
    // so the list still holds origins only, which the closed-list case below re-checks.
    expect(PasskeyOrigins::isOrigin('https://staff.example.com/'))->toBeFalse();

    config([PasskeyOrigins::CONFIG_KEY => ['https://staff.example.com/']]);

    expect(PasskeyOrigins::allowed())->toBe([
        PasskeyOrigins::originOf((string) config('app.url')),
        'https://staff.example.com',
    ]);
});
