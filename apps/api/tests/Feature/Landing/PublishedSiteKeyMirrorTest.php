<?php

declare(strict_types=1);

use App\Domain\Contracts\SiteKeyMirror;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\PublishFailed;

/*
 * ================================================================================
 * THE PHP HALF OF A TWO-ENGINE MEASUREMENT — Task 11 (QRPAGER-18), ADR-005
 * ================================================================================
 *
 * `docs/contracts/hostnames.json` holds the cases and their verdicts. This file drives them
 * through `PublishedSite::isSafeHostname()`; `packages/contracts/test/site-keys.test.ts`
 * drives the SAME cases through the generated TypeScript rule. Neither file decides a
 * verdict — the fixture does — so if PCRE and ECMAScript ever disagree about one, exactly
 * one of the two suites goes red and the case names itself.
 *
 * ⚠ **WRITING THE FIXTURE IS WHAT FOUND THE DEFECT IT WAS WRITTEN TO RULE OUT.** On the
 * first run `"example.test\n"` was **ACCEPTED here** and refused in TypeScript: PCRE's `$`
 * matches at the end of the subject *or immediately before a final newline*, ECMAScript's
 * only at the very end. `root()` would have built the key `sites/example.test\n`, a
 * different prefix from the host's own — the one thing that guard exists to prevent.
 * `isSafeHostname()` now applies the `D` modifier, chosen over `\z` so the pattern STRING
 * stays identical and therefore mirrorable. The case below is the regression.
 *
 * NO DATABASE AND NO BUCKET in this file: every subject is a pure function. That is a
 * property of `PublishedSite` worth keeping — the key layout is decided without state, which
 * is what lets a CDN replace this code entirely.
 */

/** @return list<array{hostname: string, safe: bool, why: string}> */
function hostnameCases(): array
{
    /** @var array{cases: list<array{hostname: string, safe: bool, why: string}>} $fixture */
    $fixture = json_decode(
        (string) file_get_contents(dirname(base_path(), 2).'/docs/contracts/hostnames.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $fixture['cases'];
}

/** @return list<array{label: string, pattern: string, safe: bool, why: string}> */
function hostnameLengthCases(): array
{
    /** @var array{lengthCases: list<array{label: string, pattern: string, safe: bool, why: string}>} $fixture */
    $fixture = json_decode(
        (string) file_get_contents(dirname(base_path(), 2).'/docs/contracts/hostnames.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $fixture['lengthCases'];
}

it('reads a fixture with enough cases to be worth calling a measurement', function (): void {
    // Not vacuous: a fixture that failed to load would make the sweep below pass by
    // iterating nothing, which is the one way this file could be green and useless.
    $cases = hostnameCases();

    expect(count($cases))->toBeGreaterThanOrEqual(30);
    expect(count(array_filter($cases, fn (array $c): bool => $c['safe'])))->toBeGreaterThanOrEqual(5);
    expect(count(array_filter($cases, fn (array $c): bool => ! $c['safe'])))->toBeGreaterThanOrEqual(20);
    expect(hostnameLengthCases())->toHaveCount(2);
});

it('agrees with every recorded verdict', function (): void {
    foreach (hostnameCases() as $case) {
        expect(PublishedSite::isSafeHostname($case['hostname']))->toBe(
            $case['safe'],
            json_encode($case['hostname']).' — '.$case['why'],
        );
    }
});

it('agrees on both sides of the length boundary', function (): void {
    foreach (hostnameLengthCases() as $case) {
        preg_match('/^a\{(\d+)\}(.*)$/', $case['pattern'], $matches);

        $hostname = str_repeat('a', (int) $matches[1]).$matches[2];

        expect(PublishedSite::isSafeHostname($hostname))->toBe(
            $case['safe'],
            $case['label'].': '.strlen($hostname).' characters — '.$case['why'],
        );
    }
});

it('refuses a trailing newline, which is the case the two engines disagreed about', function (): void {
    /*
     * ⚠ THE REGRESSION, AND IT IS WRITTEN AS A PAIR SO A REVERT CANNOT PASS HALF OF IT.
     * Measured 2026-11-03 on PHP 8.4: without `D`, `preg_match` returned 1 for the second
     * line; with `D` it returns 0, and the JavaScript regex returned false either way.
     */
    expect(PublishedSite::isSafeHostname('example.test'))->toBeTrue();
    expect(PublishedSite::isSafeHostname("example.test\n"))->toBeFalse();

    // And the key builder refuses it rather than building a key outside the prefix.
    expect(fn () => PublishedSite::root("example.test\n"))
        ->toThrow(PublishFailed::class);
});

it('keeps the pattern in the dialect both engines read', function (): void {
    /*
     * The mirror can carry the same CHARACTERS to TypeScript; it cannot carry the meaning of
     * a PCRE-only construct. So the pattern is held to the intersection — and this is the
     * assertion that stops a later edit from reaching for `\z`, `(?i)`, a possessive
     * quantifier or a named group, each of which would be silently wrong on the other side.
     */
    expect(PublishedSite::HOSTNAME_PATTERN)->toStartWith('^');
    expect(PublishedSite::HOSTNAME_PATTERN)->toEndWith('$');

    $pcreOnly = ['\\z', '\\A', '\\Z', '(?i)', '(?<', '(?P<', '*+', '++', '?+', '(?>', '\\h', '\\R'];

    foreach ($pcreOnly as $construct) {
        expect(str_contains(PublishedSite::HOSTNAME_PATTERN, $construct))->toBeFalse(
            'the pattern uses '.$construct.', which ECMAScript does not read the same way — '
            .'the mirror in packages/contracts would hold the same characters and a different rule'
        );
    }
});

it('puts every object for one host under that host’s own prefix', function (): void {
    expect(PublishedSite::root('alpha.test'))->toBe('sites/alpha.test');
    expect(PublishedSite::pointer('alpha.test'))->toBe('sites/alpha.test/index.html');
    expect(PublishedSite::manifest('alpha.test'))->toBe('sites/alpha.test/manifest.json');
    expect(PublishedSite::snapshot('alpha.test', 'abc123'))->toBe('sites/alpha.test/abc123.html');
    expect(PublishedSite::asset('alpha.test', 'media/x/deadbeef'))->toBe('sites/alpha.test/assets/deadbeef');
});

it('never builds one host’s key from another host’s name', function (): void {
    /*
     * `§3` row 11's invariant at the level this class can carry it: the key is a pure
     * function of the hostname, so two hosts cannot produce one key and one host cannot
     * produce another's. Driven over the fixture's own legal hosts rather than over a pair
     * chosen to pass — the same property the TypeScript half asserts, on the same inputs.
     */
    $legal = array_values(array_map(
        fn (array $c): string => $c['hostname'],
        array_filter(hostnameCases(), fn (array $c): bool => $c['safe']),
    ));

    expect(count($legal))->toBeGreaterThanOrEqual(5);

    $keys = array_map(fn (string $h): string => PublishedSite::pointer($h), $legal);

    expect(count(array_unique($keys)))->toBe(count($legal), 'two hosts share a pointer key');

    foreach ($legal as $index => $hostname) {
        expect($keys[$index])->toBe('sites/'.$hostname.'/index.html');

        foreach ($legal as $other) {
            if ($other === $hostname) {
                continue;
            }

            expect(str_contains($keys[$index], '/'.$other.'/'))->toBeFalse(
                $hostname.'’s key mentions '.$other
            );
        }
    }
});

it('emits a mirror that is the committed one, byte for byte', function (): void {
    // The gate runs `contracts:export-site-keys --check`, which is the same comparison from
    // the other side. This asserts it from inside the suite too, so a stale mirror is a red
    // test and not only a red gate step — the difference matters when somebody runs the
    // suite and not the wrapper.
    $committed = (string) file_get_contents(dirname(base_path(), 2).'/'.SiteKeyMirror::RELATIVE_PATH);

    expect(SiteKeyMirror::encode())->toBe(
        $committed,
        'packages/contracts/src/generated/site-keys.ts is stale — run `php artisan contracts:export-site-keys`'
    );
});

it('emits a mirror that carries the rule rather than a description of it', function (): void {
    $mirror = SiteKeyMirror::encode();

    // The pattern and the cap reach TypeScript as VALUES. A mirror that said "lower-case DNS
    // labels" in prose would be a second source written in English.
    expect($mirror)->toContain(PublishedSite::HOSTNAME_PATTERN);
    expect($mirror)->toContain((string) PublishedSite::HOSTNAME_MAX_LENGTH);
    expect($mirror)->toContain("'".PublishedSite::PREFIX."'");

    // Deterministic: the gate byte-compares, so a timestamp would make staleness unprovable.
    expect($mirror)->toBe(SiteKeyMirror::encode());
    expect($mirror)->not->toContain((string) date('Y-m-d'));
});
