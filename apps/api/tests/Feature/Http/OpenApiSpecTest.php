<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

/*
 * "The OpenAPI spec is generated from the routes, committed, and CI fails when it is
 * stale" (PLAN.md §3, row 2; §6.2's third shared contract).
 *
 * Generated, not written: the only way it can be wrong is by being stale, which is the
 * quietest kind of wrong -- nothing fails and the file looks plausible. The staleness
 * check is `composer openapi:check`, which is also a CI step; this file is what proves
 * the check is worth running.
 */

function committedSpec(): array
{
    $path = base_path('../../docs/openapi.json');

    expect(is_file($path))->toBeTrue('docs/openapi.json is not committed');

    /** @var array<string, mixed> */
    return (array) json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

it('is committed, is OpenAPI 3.1 and names this API', function (): void {
    $spec = committedSpec();

    expect($spec['openapi'] ?? null)->toStartWith('3.1')
        // Named rather than left to default to the framework's "Laravel": the spec is a
        // committed artefact that CI diffs, so what it calls the API is part of the diff.
        ->and($spec['info']['title'] ?? null)->toBe('QR Pager API');
});

it('describes exactly the routes that exist, and not the fallback', function (): void {
    $spec = committedSpec();
    $documented = array_keys((array) ($spec['paths'] ?? []));
    sort($documented);

    // Task 2 serves the two probes and nothing else: routes/v1.php is empty by design,
    // because every endpoint in it belongs to a later task. When Task 3 adds one, this
    // list changes in the same commit as the route -- which is the property, not the
    // list.
    expect($documented)->toBe(['/healthz', '/readyz']);

    // The fallback matches paths that do NOT exist. Documenting it would publish a route
    // for every unknown path at once.
    foreach ($documented as $path) {
        expect($path)->not->toContain('fallbackPlaceholder');
    }

    // And every documented path is a route that really exists.
    $real = [];

    foreach (Route::getRoutes() as $route) {
        $real[] = '/'.ltrim($route->uri(), '/');
    }

    foreach ($documented as $path) {
        expect($real)->toContain($path);
    }
});

it('describes both readiness outcomes with the real body shape', function (): void {
    $responses = committedSpec()['paths']['/readyz']['get']['responses'] ?? [];

    // Cast, because json_decode turns a numeric-string key into an int: array_keys()
    // here gives [503, 200] and not ['503', '200'].
    expect(array_map('strval', array_keys((array) $responses)))->toContain('200')->toContain('503');

    $schema = $responses['200']['content']['application/json']['schema'] ?? [];

    // The shape, not a string. With the body built in a private helper and the status in
    // a conditional, Scramble described this endpoint as {"type":"string"} -- a generated
    // spec that is wrong in the quietest possible way. §7's own caveat predicted it: a
    // controller has to return a declared shape.
    expect($schema['type'] ?? null)->toBe('object');

    // THREE FIELDS, and the absences are asserted as well as the presences (R-031).
    // The capacity figures were published to any anonymous caller on the one group
    // that carries no limiter, while ShedLoad withheld the same saturation figure from
    // its 503. Asserting only the three present fields would leave a later commit free
    // to add `probe` back, so the four removed names are pinned here too.
    foreach (['ready', 'reason', 'probe_age_seconds'] as $property) {
        expect($schema['properties'] ?? [])->toHaveKey($property);
    }

    foreach (['probe', 'app_backends', 'app_limit', 'saturation', 'freshness_budget_seconds'] as $removed) {
        expect($schema['properties'] ?? [])->not->toHaveKey($removed);
    }
});

it('fails loudly when the committed spec is stale', function (): void {
    $path = base_path('../../docs/openapi.json');
    $original = (string) file_get_contents($path);

    try {
        // A single changed byte, which is the realistic failure: a route or a return type
        // changes and nobody regenerates.
        file_put_contents($path, str_replace('QR Pager API', 'QR Pager API (stale)', $original));

        $result = Process::path(base_path())->run('composer openapi:check');

        expect($result->exitCode())->not->toBe(0, 'the staleness check accepted a modified spec')
            ->and($result->output().$result->errorOutput())->toContain('STALE');
    } finally {
        file_put_contents($path, $original);
    }

    // ...and passes again once it is restored, so the failure above is the modification
    // and not the check being broken.
    $restored = Process::path(base_path())->run('composer openapi:check');

    expect($restored->exitCode())->toBe(0, $restored->output().$restored->errorOutput());
});

it('exports the same bytes every time, so the diff means something', function (): void {
    // A check built on a diff needs a deterministic generator, or it fails at random and
    // is turned off. Three consecutive exports, byte for byte.
    $hashes = [];

    for ($i = 0; $i < 3; $i++) {
        $path = tempnam(sys_get_temp_dir(), 'qrpager-spec');
        Process::path(base_path())->run('php artisan scramble:export --path='.escapeshellarg((string) $path));
        $hashes[] = hash_file('sha256', (string) $path);
        @unlink((string) $path);
    }

    expect(array_unique($hashes))->toHaveCount(1);
});
