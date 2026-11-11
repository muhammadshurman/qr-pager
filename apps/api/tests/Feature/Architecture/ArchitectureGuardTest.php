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
 *
 * EVERY RUN PINS --formatter=json, AND THAT IS NOT A STYLE CHOICE. deptrac chooses its
 * formatter from the ENVIRONMENT: AnalyseCommand.php:99 reads
 *
 *     false !== (new Env())->get('GITHUB_ACTIONS')
 *         ? GithubActionsOutputFormatter::getName()
 *         : TableOutputFormatter::getName()
 *
 * so under GitHub Actions it emits `::error file=...` workflow commands instead of the
 * table -- and with NO violations the github-actions formatter emits NOTHING AT ALL.
 * Measured on this machine, same clean tree, same exit code 0:
 *
 *     $ vendor/bin/deptrac analyse --no-progress --no-cache
 *       stdout 292 bytes (the Report table) · stderr 0 · exit 0
 *     $ GITHUB_ACTIONS=true vendor/bin/deptrac analyse --no-progress --no-cache
 *       stdout   0 bytes · stderr 0 · exit 0
 *
 * The first version of the case below asserted that output() contained
 * "Violations           0", so in CI it was asserting against an EMPTY STRING while
 * deptrac was perfectly happy -- a test that fails only in the environment the gate
 * runs in, which is §6's fresh-clone clause inverted: a pass that depended on something
 * only present on the builder's machine, namely the absence of GITHUB_ACTIONS.
 *
 * The assertions are therefore on the PROPERTY -- the exit code and a parsed violation
 * count -- and not on rendered text. Pinning the formatter also removes the GitHub
 * annotations deptrac would otherwise produce for these fixture runs, which is the
 * right trade: they would be annotations about files this test creates and deletes.
 */

/**
 * Run the real deptrac and return its exit code and decoded report.
 *
 * BOTH STREAMS are concatenated before decoding, deliberately. Process::run captures
 * stderr into errorOutput(), which Pest never prints, so "the report went to stderr"
 * and "deptrac aborted before writing anything" are indistinguishable from a failure
 * message built on output() alone -- both show an empty actual. Concatenating removes
 * the stream as a variable, and a payload that will not decode fails with BOTH streams
 * and the exit code in the message, which is the discrimination that was missing.
 *
 * @return array{int, array{Report: array<string, int>, files?: array<string, array{messages: list<array{message: string, line: int, type: string}>, violations: int}>}}
 */
function deptracJson(): array
{
    /*
     * ⚠ AN EXPLICIT DEADLINE, FOR `R-301`'s REASON AND AS ITS SECOND MEASURED INSTANCE.
     * `Illuminate\Process`'s default is 60 seconds and nobody chose it; `bin/fresh-clone-proof`
     * went red HERE on a loaded host — `ProcessTimedOutException` from `Process.php:1247`,
     * 1 failed / 1511 passed — exactly as it had gone red on `OpenApiSpecTest` an hour
     * earlier, which is what that row predicted: *"the default applies to every
     * `Process::run()` in this suite and nothing says which of them chose it."* deptrac
     * analysing 471 files with no cache is a minute's work on an idle machine and several
     * on one that has been running gates all day. An absent or broken binary still fails at
     * once, with the exit code and both streams, so the deadline covers load and nothing else.
     */
    $result = Process::path(base_path())
        ->timeout(300)
        ->run('vendor/bin/deptrac analyse --no-progress --no-cache --formatter=json');

    $raw = $result->output().$result->errorOutput();
    $decoded = json_decode($raw, true);

    expect($decoded)->toBeArray(
        "deptrac did not return a JSON report.\nexit: {$result->exitCode()}"
        ."\nstdout: [{$result->output()}]\nstderr: [{$result->errorOutput()}]",
    );

    expect($decoded)->toHaveKey('Report');

    /** @var array{Report: array<string, int>} $decoded */
    return [$result->exitCode(), $decoded];
}

/** The violations deptrac reported against one file, by its path under the project root. */
function deptracViolationsFor(array $report, string $relativePath): array
{
    $absolute = base_path($relativePath);

    /** @var array<string, array{messages: list<array{message: string}>, violations: int}> $files */
    $files = $report['files'] ?? [];

    return $files[$absolute] ?? ['messages' => [], 'violations' => 0];
}

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

        return deptracJson();
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
    [$exit, $report] = deptracJson();

    // The exit code and a NUMBER, not a rendered row. See the file docblock: the
    // "Violations           0" needle this used to look for does not exist under the
    // formatter deptrac picks when GITHUB_ACTIONS is set, which is every CI run.
    expect($exit)->toBe(0, json_encode($report))
        ->and($report['Report']['Violations'])->toBe(0)
        ->and($report['Report']['Errors'])->toBe(0)
        // Uncovered is 0 by configuration rather than by accident: every layer in
        // deptrac.yaml is reachable from a ruleset entry, so a class that matches no
        // layer is a hole in the configuration and not a neutral fact.
        ->and($report['Report']['Uncovered'])->toBe(0)
        // And the ruleset is doing work rather than passing vacuously: an empty layer
        // set would also report zero violations.
        ->and($report['Report']['Allowed'])->toBeGreaterThan(0);
});

it('guard 1 fails when domain logic imports framework or DB code', function (): void {
    $target = 'app/Domain/Deliberately/BadDomainDependency.php';

    [$exit, $report] = deptracAgainst([
        'domain-imports-framework.php.fixture' => $target,
    ]);

    $file = deptracViolationsFor($report, $target);

    expect($exit)->not->toBe(0, 'deptrac accepted a Domain class that imports Illuminate')
        ->and($report['Report']['Violations'])->toBeGreaterThan(0)
        // Attributed to the fixture, so a violation somewhere else cannot satisfy this.
        ->and($file['violations'])->toBeGreaterThan(0)
        // The offending dependency, from the report's own message field rather than from
        // a rendered table: the class and the token it reached for are the property,
        // while "DependsOnDisallowedLayer" and the column alignment are presentation.
        ->and(implode(' ', array_column($file['messages'], 'message')))
        ->toContain('Illuminate\Support\Facades\DB');
});

it('guard 2 fails when the platform layer imports a feature module', function (): void {
    [$exit, $report] = deptracAgainst([
        'platform-imports-feature-target.php.fixture' => 'app/Features/Deliberately/SomeFeatureService.php',
        'platform-imports-feature.php.fixture' => 'app/Platform/Deliberately/BadPlatformDependency.php',
    ]);

    $file = deptracViolationsFor($report, 'app/Platform/Deliberately/BadPlatformDependency.php');

    expect($exit)->not->toBe(0, 'deptrac accepted a Platform class that imports a Feature')
        ->and($report['Report']['Violations'])->toBeGreaterThan(0)
        ->and($file['violations'])->toBeGreaterThan(0)
        ->and(implode(' ', array_column($file['messages'], 'message')))
        ->toContain('App\Features\Deliberately\SomeFeatureService');
});

it('guard 3 fails when production code imports a test helper', function (): void {
    [$exit, $report] = deptracAgainst([
        'production-imports-test-helper.php.fixture' => 'app/Platform/Deliberately/BadTestHelperDependency.php',
    ]);

    $file = deptracViolationsFor($report, 'app/Platform/Deliberately/BadTestHelperDependency.php');

    expect($exit)->not->toBe(0, 'deptrac accepted production code importing Tests\\Support')
        ->and($report['Report']['Violations'])->toBeGreaterThan(0)
        ->and($file['violations'])->toBeGreaterThan(0)
        ->and(implode(' ', array_column($file['messages'], 'message')))
        ->toContain('Tests\Support\Composition');
});

it('fails when a feature module reaches into the auth layer — B5, repurposed at Task 5', function (): void {
    // The fixture used to import the pre-Task-5 stub. That class is deleted, so the rule
    // was either dead or had to be aimed at something real: QRPAGER-12 §6 required the
    // choice to be deliberate. It now proves that a Feature may not import
    // App\Platform\Auth at all -- the auth layer has one entry point, the TenantResolver
    // interface in Platform, and this is what keeps it that way.
    [$exit, $report] = deptracAgainst([
        'feature-imports-auth-layer.php.fixture' => 'app/Features/Deliberately/FeatureBuiltOnTheAuthStub.php',
    ]);

    $file = deptracViolationsFor($report, 'app/Features/Deliberately/FeatureBuiltOnTheAuthStub.php');

    expect($exit)->not->toBe(0, 'deptrac accepted a Feature reaching into the auth layer')
        ->and($report['Report']['Violations'])->toBeGreaterThan(0)
        ->and($file['violations'])->toBeGreaterThan(0)
        ->and(implode(' ', array_column($file['messages'], 'message')))
        ->toContain('App\Platform\Auth\AuthenticatedTenantResolver');
});

it('leaves no fixture behind in app/', function (): void {
    // The §6 "fresh clone" clause in miniature: the previous tests must not have
    // changed the tree they ran against.
    expect(glob(base_path('app/*/Deliberately')) ?: [])->toBe([])
        ->and(is_dir(base_path('app/Features/Deliberately')))->toBeFalse();
});
