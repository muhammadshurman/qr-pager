<?php

declare(strict_types=1);

/*
 * ================================================================================
 * `pint.json` EXCLUDES ONE UNTRACKED SCRATCH FILE, AND NOTHING ELSE — Task 12
 * ================================================================================
 *
 * ⚠ WHY THIS FILE EXISTS, which is a defect of mine rather than a design.
 * `git add -A apps/api` swept Muhammad's untracked `seed_restaurant.php` into `c0aa76f`. It
 * is his own demo seeder with Arabic commentary, paired with
 * `docs/PROJECT_DOCUMENTATION_AR.md`, and it was never part of any ticket. It has been
 * rewritten out of this branch's history and added to `apps/api/.gitignore`.
 *
 * ⚠ AND `.gitignore` DOES NOT KEEP PINT OFF IT. Measured: with the ignore entry in place,
 * `composer pint` still reported `{"path":"seed_restaurant.php","fixers":[…]}` and exited 1.
 * `vendor/bin/pint` walks the tree, not the index. That is load-bearing rather than trivia:
 * an unformatted untracked file at `apps/api` root made `composer pint` — the FIRST step of
 * `bin/gate` — red before any of Task 12's work existed, and formatting his file was the only
 * way to get a gate run at all. Reverting that formatting (his ruling) would have put the
 * gate back to red, so the exclusion has to live where pint reads it.
 *
 * ⚠ THE RISK THIS GUARDS, AND IT IS THE WHOLE REASON FOR A TEST RATHER THAN A COMMENT.
 * `pint.json` did not exist before this; `composer pint` is `pint --test` on the default
 * `laravel` preset. A config file with a `notPath` is one line away from being the place
 * somebody silences a formatting failure in `app/` instead of fixing it — and `composer pint`
 * is the gate's first step, so a quietly widened exclusion makes the cheapest check in the
 * project stop meaning anything.
 */

/** @return array<string, mixed> */
function pintConfig(): array
{
    $path = base_path('pint.json');

    expect(is_file($path))->toBeTrue('pint.json is gone, so its notPath exclusion is unpinned');

    /** @var array<string, mixed> $config */
    $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $config;
}

it('excludes exactly one path, and it is the untracked scratch file', function (): void {
    $config = pintConfig();

    // An EXACT list, not a "contains". The point is that nothing else is excluded.
    expect($config['notPath'] ?? null)->toBe(['seed_restaurant.php'],
        "pint.json's notPath changed. composer pint is bin/gate's FIRST step, so anything "
        .'added here stops being formatted and stops failing -- which is how a formatting '
        .'exclusion becomes a silencer. Say what the new path is and why pint must not read it.',
    );

    // And no other exclusion mechanism has appeared beside it. Pint has several, and a test
    // that pinned only `notPath` would be satisfied while `exclude` hid a whole directory.
    foreach (['exclude', 'notName'] as $key) {
        expect(array_key_exists($key, $config))->toBeFalse(
            "pint.json gained a [{$key}] exclusion, which this test does not cover -- read it",
        );
    }
});

it('keeps the preset pinned to the one it had before pint.json existed', function (): void {
    // ⚠ CREATING `pint.json` CHANGES PINT'S DEFAULTS IF THE PRESET IS OMITTED, so it is
    // written down. `laravel` is what `pint --test` used when there was no config at all, and
    // the proof that pinning it changed nothing is that `composer pint` passed over the whole
    // tree immediately afterwards with no file reported.
    expect(pintConfig()['preset'] ?? null)->toBe('laravel');
});

it('still formats the directories that matter, so the exclusion is narrow in fact', function (): void {
    // ⚠ THE NON-VACUITY HALF. The two cases above read a JSON file; neither proves pint still
    // LOOKS at anything. A `notPath` of `['seed_restaurant.php']` would pass them while an
    // `exclude` somewhere else, a renamed preset or a broken binary left `app/` unformatted.
    //
    // So this drives the real binary against a deliberately misformatted file in `app/` and
    // requires it to be reported. Written into a temporary file and removed in a `finally`,
    // because a misformatted file left behind would redden the gate for everyone else.
    $probe = base_path('app/Platform/PintScopeProbe.php');

    file_put_contents($probe, "<?php\n\nnamespace App\\Platform;\n\nclass PintScopeProbe{\npublic function a(){return    1;}\n}\n");

    try {
        $result = shell_exec(escapeshellcmd(base_path('vendor/bin/pint')).' --test --format=json 2>&1');
    } finally {
        @unlink($probe);
    }

    expect($result)->toBeString();
    expect(str_contains((string) $result, 'PintScopeProbe'))->toBeTrue(
        'pint did not report a deliberately misformatted file in app/Platform, so its scope '
        ."is not what pint.json says it is. Output: {$result}",
    );
});

it('does NOT report the excluded file, which is the exclusion working rather than configured', function (): void {
    // The other direction, and the one the exclusion exists for. Driven rather than read:
    // `§8` does not accept a configuration as a control, and this one was added because a
    // DIFFERENT configuration (`.gitignore`) turned out not to be one.
    $scratch = base_path('seed_restaurant.php');

    if (! is_file($scratch)) {
        // His file is not in the repository, so a clone legitimately does not have it. Assert
        // the configuration instead of skipping -- a skip here would make this case silent in
        // CI, which is precisely where it would matter.
        expect(pintConfig()['notPath'] ?? null)->toBe(['seed_restaurant.php']);

        return;
    }

    $result = (string) shell_exec(escapeshellcmd(base_path('vendor/bin/pint')).' --test --format=json 2>&1');

    expect(str_contains($result, 'seed_restaurant.php'))->toBeFalse(
        "pint is reading the excluded scratch file again, so composer pint -- bin/gate's first "
        ."step -- depends on the formatting of a file that is not in the repository. Output: {$result}",
    );
});
