<?php

declare(strict_types=1);

/*
 * ================================================================================
 * EVERY CI ACTION AND THE RUNNER IMAGE ARE PINNED EXACT — Task 12
 * ================================================================================
 *
 * His instruction: *"`actions/cache@v4` is being forced off deprecated Node 20 onto Node 24,
 * and `ubuntu-latest` has already migrated. Pin and update the actions so a runner image
 * change cannot redden a release."*
 *
 * ⚠ A FLOATING REF IS THE SAME DEFECT CLASS AS A STALE GATE SHA, which is why this is a test
 * and not a one-time edit. `@v5` and `ubuntu-latest` mean *whatever that label points at on
 * the day the job runs* — so a release can go red for a change nobody in this repository made,
 * and the diff that caused it is in somebody else's repository. `§4a` records the wrong-sha
 * and stale-build families; this is the same thing with the moving part outside the clone.
 *
 * MEASURED 2026-11-07, each action's `using:` read out of its own `action.yml` at the ref this
 * workflow pins — `cache@v4` was the only `node20` action in the file, and `checkout@v5` and
 * `setup-node@v5` were already `node24`. So only `cache` was UPDATED; the rest were only
 * PINNED. Two unverifiable major bumps (v5 → v7) would serve neither the deprecation nor the
 * pin, and CI is blocked on an account billing condition, so a red could not have been read.
 */

/** The workflow, as text. */
function ciWorkflow(): string
{
    $path = base_path('../../.github/workflows/ci.yml');

    expect(is_file($path))->toBeTrue('.github/workflows/ci.yml is missing');

    return (string) file_get_contents($path);
}

it('pins every `uses:` to an exact version, with no floating major anywhere', function (): void {
    preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)\s*$/m', ciWorkflow(), $matches);

    // NON-VACUITY FIRST. A regex that stopped matching would make every assertion below pass
    // over an empty list, which is the shape `§4a` records four instances of. The live file
    // has 11 `uses:` lines.
    expect(count($matches[1]))->toBeGreaterThan(
        8,
        'only '.count($matches[1]).' `uses:` lines were found: the pattern is broken, not the workflow',
    );

    $floating = [];

    foreach (array_unique($matches[1]) as $ref) {
        // An exact pin is `owner/action@<major>.<minor>.<patch>` or a 40-character commit sha.
        // A bare major (`@v5`) and a bare tag with no patch (`@v5.1`) both move.
        $exact = preg_match('/@v?\d+\.\d+\.\d+$/', $ref) === 1
            || preg_match('/@[0-9a-f]{40}$/', $ref) === 1;

        if (! $exact) {
            $floating[] = $ref;
        }
    }

    expect($floating)->toBe([],
        "these CI actions float, so a tag moved in somebody else's repository can redden a "
        ."release here:\n  ".implode("\n  ", $floating),
    );
});

it('pins the runner image, because `ubuntu-latest` has already moved once', function (): void {
    $workflow = ciWorkflow();

    expect(str_contains($workflow, 'runs-on: ubuntu-latest'))
        ->toBeFalse('a job is back on the floating `ubuntu-latest` label');

    preg_match_all('/^\s*runs-on:\s*(\S+)\s*$/m', $workflow, $matches);

    expect(count($matches[1]))->toBeGreaterThan(2, 'the runs-on pattern matched almost nothing');

    foreach (array_unique($matches[1]) as $image) {
        // An explicit image, e.g. `ubuntu-26.04`. The `-latest` suffix is the floating form on
        // every platform GitHub offers.
        expect(str_ends_with($image, '-latest'))
            ->toBeFalse("[{$image}] is a floating runner label");

        expect(preg_match('/^[a-z]+-\d+\.\d+$/', $image))
            ->toBe(1, "[{$image}] is not an explicit runner image");
    }
});

it('runs no action on the deprecated Node 20, which is the thing being forced', function (): void {
    // ⚠ ASSERTED AS A VERSION FLOOR RATHER THAN BY FETCHING `action.yml`, deliberately: this
    // test must not need the network, and `§6`'s fresh-clone clause means it must pass with no
    // GitHub access at all. The mapping was MEASURED once and is recorded here with its
    // evidence, which is what makes the floor meaningful:
    //
    //   actions/cache@v4      node20   <- the deprecated runtime
    //   actions/cache@v5.1.0  node24
    //
    // So `cache` must be at v5 or above. If a future bump needs a different floor, the
    // measurement to repeat is reading `using:` out of that ref's own `action.yml`.
    // ⚠ ONLY `uses:` LINES, AND THE FIRST DRAFT READ THE WHOLE FILE — which failed, because
    // `ci.yml`'s own comment block QUOTES `actions/cache@v4` as the measurement that justifies
    // the bump. A guard that reads documentation fails on documentation, and the fix is not to
    // stop documenting. Same discipline as the `uses:` scan above.
    preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)\s*$/m', ciWorkflow(), $lines);

    $matches = [1 => []];

    foreach ($lines[1] as $ref) {
        if (preg_match('#^actions/cache@v?(\d+)#', $ref, $m) === 1) {
            $matches[1][] = $m[1];
        }
    }

    expect($matches[1])->not->toBe([], 'actions/cache is not used at all, so this floor is vacuous');

    foreach ($matches[1] as $major) {
        expect((int) $major)->toBeGreaterThanOrEqual(
            5,
            'actions/cache@v'.$major.' runs on node20, which GitHub is forcing off. v5.1.0 is node24.',
        );
    }
});

it('still names the four jobs, so pinning did not quietly drop one', function (): void {
    // The pin touched `runs-on:` on every job, which is exactly the edit that could delete one
    // by accident. CI run `42372017224` ran four jobs; all four must still be declared.
    $workflow = ciWorkflow();

    foreach ([
        'Pint, PHPStan, Pest',
        'Frontend workspace',
        'Secret scan',
        'FrankenPHP runtime',
    ] as $job) {
        expect(str_contains($workflow, $job))->toBeTrue("the [{$job}] job is gone from ci.yml");
    }
});
