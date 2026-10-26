<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * "The repo guard and each named architecture guard fail on a deliberately bad file"
 * (PLAN.md §3, row 2). The three guards are:
 *
 *   1. domain logic imports no framework or DB code
 *   2. the platform layer imports no feature modules
 *   3. production code imports no test helpers
 *
 * A fourth rule rides along, from B5: a feature module may not be built on the
 * pre-Task-5 auth stub. It is not one of §3's named three -- it is the mechanical half
 * of "the stub surviving Task 5 is a CI failure rather than a reviewer's job" -- and it
 * is proven the same way, because a rule nobody has seen fail is a rule nobody should
 * trust.
 *
 * Each case copies a fixture into the layer it violates, runs THE REAL deptrac.yaml,
 * and asserts deptrac reports the dependency and exits non-zero. The real config is
 * used on purpose: a fixture-only config would be a second copy of the ruleset, and a
 * second copy that agrees today diverges later -- which is the failure this project is
 * structured to prevent (§6.2, C7).
 */

/**
 * @param  array<string, string>  $fixtures  fixture file => path under app/, relative to the project root
 */
function deptracAgainst(array $fixtures): array
{
    $written = [];

    try {
        foreach ($fixtures as $fixture => $target) {
            $source = base_path("guard/fixtures/architecture/{$fixture}");
            $destination = base_path($target);

            expect(is_file($source))->toBeTrue("fixture [{$fixture}] is missing");
            expect(is_file($destination))->toBeFalse("[{$target}] already exists; a previous run leaked");

            @mkdir(dirname($destination), 0o755, true);
            copy($source, $destination);
            $written[] = $destination;
        }

        $result = Process::path(base_path())
            ->run('vendor/bin/deptrac analyse --no-progress --no-cache');

        return [$result->exitCode(), $result->output().$result->errorOutput()];
    } finally {
        // Loud rather than silent if this ever fails: a leaked file makes every later
        // deptrac run fail and names itself, where the assertion above refuses to run
        // on top of it (R-018's shape, inverted).
        //
        // The directories go too. Unlinking only the files left three empty
        // Deliberately/ directories behind, and the last test in this file caught it --
        // which is the whole reason that test exists rather than being assumed.
        foreach ($written as $path) {
            @unlink($path);
            @rmdir(dirname($path));
        }
    }
}

it('passes on the real tree, so a failure below means the fixture and not the ruleset', function (): void {
    $result = Process::path(base_path())->run('vendor/bin/deptrac analyse --no-progress --no-cache');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('Violations           0');
});

it('guard 1 fails when domain logic imports framework or DB code', function (): void {
    [$exit, $output] = deptracAgainst([
        'domain-imports-framework.php.fixture' => 'app/Domain/Deliberately/BadDomainDependency.php',
    ]);

    expect($exit)->not->toBe(0, 'deptrac accepted a Domain class that imports Illuminate')
        ->and($output)->toContain('App\Domain\Deliberately\BadDomainDependency must not depend on')
        ->and($output)->toContain('Illuminate\Support\Facades\DB');
});

it('guard 2 fails when the platform layer imports a feature module', function (): void {
    [$exit, $output] = deptracAgainst([
        'platform-imports-feature-target.php.fixture' => 'app/Features/Deliberately/SomeFeatureService.php',
        'platform-imports-feature.php.fixture' => 'app/Platform/Deliberately/BadPlatformDependency.php',
    ]);

    expect($exit)->not->toBe(0, 'deptrac accepted a Platform class that imports a Feature')
        ->and($output)->toContain('App\Platform\Deliberately\BadPlatformDependency must not depend on')
        ->and($output)->toContain('App\Features\Deliberately\SomeFeatureService');
});

it('guard 3 fails when production code imports a test helper', function (): void {
    [$exit, $output] = deptracAgainst([
        'production-imports-test-helper.php.fixture' => 'app/Platform/Deliberately/BadTestHelperDependency.php',
    ]);

    expect($exit)->not->toBe(0, 'deptrac accepted production code importing Tests\\Support')
        ->and($output)->toContain('App\Platform\Deliberately\BadTestHelperDependency must not depend on')
        ->and($output)->toContain('Tests\Support\Composition');
});

it('fails when a feature module is built on the pre-Task-5 auth stub — B5', function (): void {
    [$exit, $output] = deptracAgainst([
        'feature-imports-auth-stub.php.fixture' => 'app/Features/Deliberately/FeatureBuiltOnTheAuthStub.php',
    ]);

    expect($exit)->not->toBe(0, 'deptrac accepted a Feature built on the auth stub')
        ->and($output)->toContain('App\Features\Deliberately\FeatureBuiltOnTheAuthStub must not depend on')
        ->and($output)->toContain('App\Platform\Auth\HarnessTenantResolver');
});

it('leaves no fixture behind in app/', function (): void {
    // The §6 "fresh clone" clause in miniature: the previous tests must not have
    // changed the tree they ran against.
    expect(glob(base_path('app/*/Deliberately')) ?: [])->toBe([])
        ->and(is_dir(base_path('app/Features/Deliberately')))->toBeFalse();
});
