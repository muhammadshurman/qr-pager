<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * ================================================================================
 * A TEST FIXTURE'S "UNIQUE" EMAIL MUST ACTUALLY BE UNIQUE
 * ================================================================================
 *
 * ⚠ FOR THREE TASKS IT WAS NOT, AND THE SUITE WENT RED 15 TIMES WITH ONE IDENTITY.
 *
 * Nineteen call sites across eleven test files built an address as
 * `substr(str_replace('-', '', $uuid), 0, 12)`. A v7 UUID's first 48 bits are a
 * MILLISECOND TIMESTAMP and twelve hex characters are exactly those 48 bits — and
 * ramsey/uuid's v7 is monotonic, so inside one millisecond it keeps the timestamp and
 * increments a counter in the tail. Two UUIDs generated in the same millisecond therefore
 * share their first twelve characters exactly.
 *
 * Measured on 2026-10-31: over 200 consecutive `Str::uuid7()` calls that expression
 * produced **2 distinct emails and 198 collisions**. It survived Tasks 5, 6 and 7 only
 * because each test takes about 0.2s, so consecutive fixtures usually landed in different
 * milliseconds. On a loaded machine they bunch, and `users_email_unique` raises 23505:
 * 15 failures, every one of them that constraint on `acting-01a1932b11bf@example.test`,
 * whose prefix decodes to 18:13:55.775.
 *
 * `R-049`'s whole argument is that an unidentified flake is the most expensive defect shape
 * on this branch. This one is identified, so it gets a test rather than a row.
 *
 * WHAT THIS ASSERTS IS THE PROPERTY, NOT THE SPELLING. A future refactor may build the
 * address differently; what must stay true is that a burst of fixtures created inside one
 * millisecond get distinct addresses. The second case is the source scan that stops the
 * expression coming back — and it is a scan rather than a lint rule because this is test
 * infrastructure, where the §12 rule does not reach.
 */
it('derives a DISTINCT fixture email for every uuid, inside one millisecond', function (): void {
    // A tight loop is the condition: no sleep, so every iteration lands in the same
    // millisecond or the one after it. 200 is far more fixtures than any single test
    // creates, which is the headroom -- the old expression gave 2 distinct out of 200.
    $emails = [];
    for ($i = 0; $i < 200; $i++) {
        $id = (string) Str::uuid7();
        $emails[] = 'acting-'.str_replace('-', '', $id).'@example.test';
    }

    expect(array_unique($emails))->toHaveCount(200);

    // And the truncated form is asserted to COLLIDE, from the same loop, so this case
    // records the defect rather than only the fix. A reader who doubts the mechanism can
    // see both numbers side by side.
    $truncated = array_map(
        static fn (string $e): string => substr($e, 0, 7 + 12),
        $emails
    );
    expect(count(array_unique($truncated)))->toBeLessThan(200);

    // The CHECK constraint the helper's own comment names (R-007): every address is
    // lower-case, so a fixture cannot discover users_email_is_lowercase as a 23514.
    foreach ($emails as $email) {
        expect($email)->toBe(strtolower($email));
    }
});

it('leaves no truncated uuid prefix in any fixture address, across the whole suite', function (): void {
    // The 19 call sites, as a scan. A new test file copying the old expression from a
    // neighbour is how this would come back, and that is exactly how it reached nineteen.
    $offenders = [];
    $base = dirname(__DIR__);

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    $scanned = 0;

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        // ⚠ THIS FILE ITSELF IS SKIPPED, and it is skipped rather than comment-stripped
        // because the pattern lives in a double-quoted string, not a comment. Leaving it
        // in made the scan flag itself on its first run -- the FOURTH instance in this
        // task of a check reading its own text as evidence (the fixture's prose satisfying
        // the pipefail grep, the CI-parity test matching the workflow's own comment, and
        // the GNU-construct check matching the awk program's note about avoiding ENDFILE).
        if ($file->getFilename() === 'TestFixtureUniquenessTest.php') {
            continue;
        }

        $scanned++;
        $source = (string) file_get_contents((string) $file->getRealPath());

        // Any truncation of a de-hyphenated uuid, whatever the length and whatever the
        // variable is called. The length is not the property: `0, 32` would be the whole
        // thing and is still a truncation waiting to be shortened.
        if (preg_match("/substr\(\s*str_replace\(\s*'-'\s*,\s*''\s*,\s*\\\$\w+\s*\)\s*,\s*0\s*,/", $source) === 1) {
            $offenders[] = str_replace($base.'/', '', (string) $file->getRealPath());
        }
    }

    // A floor on what was examined, so a walk that found nothing cannot report no
    // offenders over no files -- the vacuity shape this repository has paid for repeatedly.
    expect($scanned)->toBeGreaterThan(100);
    expect($offenders)->toBe(
        [],
        'these files truncate a v7 uuid to build a "unique" value. The first 48 bits are a '
        .'millisecond timestamp and ramsey/uuid is monotonic within one, so two values '
        .'created in the same millisecond are IDENTICAL. Use the whole uuid.'
    );
});
