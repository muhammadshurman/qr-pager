<?php

declare(strict_types=1);

/*
 * ================================================================================
 * `bin/gate` SAYS WHAT IT DOES NOT RUN — Task 12
 * ================================================================================
 *
 * His instruction: *"THE PHP JOB IS RED IN CI AND WAS GREEN LOCALLY ON FOUR CONSECUTIVE RUNS.
 * That gap is the same class as the wrong-sha gates and the stale build. Say exactly what CI
 * runs that your local gate does not, and close it at the mechanism."*
 *
 * ⚠ THE INSTANCE, MEASURED. CI run `42372017224`'s job is named *"Pint, PHPStan, Pest"* and
 * all three of those passed inside it — the red was `pnpm run gate:e2e`, which `bin/gate` does
 * not run. The failure was a `Permissions-Policy` denying `navigator.credentials.get()` on
 * `/passkey-harness`, and `routes/harness.php` exists precisely because *"the credential
 * ceremony lives in `navigator.credentials` and cannot be driven from HTTP"* — so **no number
 * of PHP tests could have caught it**, and five green `composer gate` runs did not. The same
 * CI run's frontend job failed at `records:check`, a step of `pnpm gate`, with three of four
 * complaints against `§0` in this repository.
 *
 * ⚠ WHY THE CLOSURE IS A NOTICE RATHER THAN MORE STEPS. Adding `gate:e2e` to `bin/gate` would
 * put ~13 minutes of browser matrix on every local run, which under lean mode is the cost
 * `§0.0e` exists to avoid — and a gate people stop running is worse than one with a stated
 * scope. So the green line is followed by the list of what was NOT run, and this file is what
 * stops that list rotting into a comfortable lie.
 */

/** `bin/gate`, as text. */
function gateScript(): string
{
    $path = base_path('bin/gate');

    expect(is_file($path))->toBeTrue('apps/api/bin/gate is missing');

    return (string) file_get_contents($path);
}

it('prints what it does NOT run, right after the green line', function (): void {
    $script = gateScript();

    // The green line and the notice, in that order: a notice printed before the result would
    // be read as part of the preamble and skipped.
    $green = strpos($script, 'steps green. Full output:');
    $notice = strpos($script, 'THIS IS apps/api\'s GATE ONLY');

    expect($green)->not->toBeFalse('the gate no longer prints a green line')
        ->and($notice)->not->toBeFalse(
            'bin/gate no longer says what it does not run. CI run 42372017224 was red in a job '
            .'named "Pint, PHPStan, Pest" while all three passed, because that job also runs '
            .'pnpm run gate:e2e. A green local gate is not a prediction of CI and must not read '
            .'as one.',
        )
        ->and($notice)->toBeGreaterThan((int) $green, 'the notice is printed before the result it qualifies');

    // And it refuses to claim it IS a prediction, in so many words.
    expect($script)->toContain('It is not a prediction of CI.');
});

it('names only commands that actually exist, so the notice cannot cite a ghost', function (): void {
    /*
     * ⚠ A CITATION TO SOMETHING THAT IS NOT THERE IS THE `R-136` SHAPE, and this task has
     * already paid for it twice: a `config/cors.php` docblock naming a `CorsPolicyTest` that
     * did not exist (caught by `RepoGuardTest`), and an `.gitignore` comment claiming pint
     * honours `.gitignore` when it does not. A notice listing a step CI no longer runs would
     * be worse than no notice, because it would be read as an inventory.
     */
    $script = gateScript();
    $repo = realpath(base_path('../..'));

    expect($repo)->not->toBeFalse();

    // `pnpm gate` and `pnpm run gate:e2e` must be real package scripts.
    /** @var array{scripts?: array<string, string>} $package */
    $package = json_decode((string) file_get_contents($repo.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = $package['scripts'] ?? [];

    foreach (['gate', 'gate:e2e'] as $name) {
        expect(array_key_exists($name, $scripts))->toBeTrue(
            "bin/gate's notice names `pnpm run {$name}` and package.json has no such script",
        );
    }

    // And the one path it names must be a real executable in this app.
    expect(is_file(base_path('bin/fresh-clone-proof')))->toBeTrue(
        "bin/gate's notice names bin/fresh-clone-proof and it is not there",
    );

    // The notice really does name those three, rather than this case asserting about nothing.
    foreach (['pnpm gate', 'pnpm run gate:e2e', 'bin/fresh-clone-proof'] as $named) {
        expect($script)->toContain($named);
    }
});

it('names the browser-only enforcement that is invisible to every step it DOES run', function (): void {
    // The specific lesson, pinned rather than left to the reader: the regression that got
    // through was enforced by a browser and by nothing else. A notice that said only "CI runs
    // more steps" would not have told anybody why five green runs meant nothing here.
    $script = gateScript();

    expect($script)->toContain('Permissions-Policy');

    foreach (['CSP', 'WebAuthn'] as $mechanism) {
        expect($script)->toContain($mechanism);
    }
});

it('still runs the three §6 commands itself, so the notice is not an excuse', function (): void {
    // ⚠ THE OTHER DIRECTION. A scope notice could become a reason to drop steps from this
    // script — "CI runs it" — which would leave the cheapest feedback loop in the project
    // weaker than it was. `PROJECT_INSTRUCTIONS.md §6`'s three commands must still be here,
    // and so must the steps the tasks since have added.
    $script = gateScript();

    foreach ([
        'composer pint',
        'composer stan',
        'php artisan test',
        'composer guard',
        'composer deptrac',
        'composer openapi:check',
        'composer assets',
        'composer deps',
    ] as $step) {
        expect($script)->toContain('"'.$step.'"');
    }
});
