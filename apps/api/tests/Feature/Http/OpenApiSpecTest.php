<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
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

    // Updated at Task 3, which is what the previous version of this comment asked for:
    // "Task 2 serves the two probes and nothing else ... When Task 3 adds one, this list
    // changes in the same commit as the route -- which is the property, not the list."
    // The five pager routes are that change. The two probes stay unversioned on purpose
    // (a load balancer's health-check path must not change because the API's major
    // version did) and everything else is under /v1.
    expect($documented)->toBe([
        '/healthz',
        '/readyz',
        '/v1/pager-sessions',
        '/v1/pager-sessions/{session}/cancel',
        '/v1/pager-sessions/{session}/complete',
        '/v1/pager-sessions/{session}/ready',
        '/v1/pager-sessions/{session}/renotify',
    ]);

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
    // to add `probe` back, so the removed names are pinned here too.
    //
    // WHAT THIS DOES AND DOES NOT COVER -- R-051, recorded here rather than left to be
    // rediscovered. These loops read the TOP level. At cb584c3 only `probe` and
    // `freshness_budget_seconds` were top-level; `app_backends`, `app_limit` and
    // `saturation` were nested inside `probe`, so those three assertions cannot fail
    // while the `probe` one holds -- it is the `probe` assertion that carries this.
    // And `postgres_up`, `redis_up` and `measured_at` are absent from the list, so a
    // flat `redis_up` added to the body passes every case in this file. The breadth fix
    // is an exact assertion on the property set and it belongs to Task 12 with R-051;
    // what stops a quiet reintroduction today is `composer openapi:check`, which fails
    // on any change to the generated document.
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

/*
 * R-033: "The error envelope's claimed generated-contract staleness check does not exist."
 *
 * ProblemType's docblock asserted that Scramble generates
 * #/components/schemas/ProblemType "with every case as an OpenAPI enum ... which is what
 * makes 'one source' mechanical rather than a convention", and QRPAGER-9 §6.2 listed it as
 * one of three contracts with a CI staleness check. Measured 2026-10-26: the committed
 * spec contained ZERO occurrences of ProblemType and components.schemas was EMPTY, because
 * Scramble emits a component schema only where a route's declared response references it
 * -- and at Task 2 no route returned a problem document, because routes/v1.php was empty
 * by design. So the claim was true of the research spike and false of the repository.
 *
 * The review-by: "the first route that can return a problem document declares it, and
 * docs/openapi.json is then asserted to carry #/components/schemas/ProblemType with every
 * case -- which turns the claim into the control it says it is". That is this block.
 *
 * EVERY CASE, DRIVEN FROM THE ENUM. The assertion iterates ProblemType::cases(), so adding
 * a case without regenerating the spec fails here AND fails `composer openapi:check`. A
 * hand-written list of ten URIs would have been a second source -- the exact thing §6.2
 * forbids.
 */

it('publishes the error envelope’s type vocabulary, with every case — R-033', function (): void {
    $spec = committedSpec();
    $schemas = (array) ($spec['components']['schemas'] ?? []);

    // No failure message on either of these: Pest's toHaveKey() reads a second argument
    // as the expected VALUE and toContain() reads every argument as another needle, so a
    // message string there becomes an assertion that the message itself is present.
    expect(array_keys($schemas))->toContain('ProblemType');

    $published = (array) ($schemas['ProblemType']['enum'] ?? []);
    sort($published);

    $cases = array_map(static fn (ProblemType $case): string => $case->value, ProblemType::cases());
    sort($cases);

    // Both directions: nothing missing and nothing extra.
    expect($published)->toBe($cases)
        ->and($published)->toHaveCount(count(ProblemType::cases()));

    // And it is REFERENCED, not merely present. A component nothing points at is a
    // weaker contract: a client reading the 404 response has to find the vocabulary to
    // know what `type` can be.
    expect($schemas['ProblemDocument']['properties']['type'] ?? [])
        ->toBe(['$ref' => '#/components/schemas/ProblemType']);
});

it('describes the problem body with the keys a real body actually has — R-033', function (): void {
    $schemas = (array) (committedSpec()['components']['schemas'] ?? []);

    // The defect found while closing R-033, pinned so it cannot come back. Pointing
    // Scramble at Problem published the RENDERER'S INPUTS rather than the wire's output:
    // `type, instance, errors, retryAfter` -- advertising a member no body carries
    // (retryAfter is the Retry-After HEADER) and omitting two that every body carries
    // (title, status).
    //
    // Compared against a REAL RENDERED BODY rather than against a list written beside it,
    // which is the whole point: the spec and the body cannot both be wrong in the same way.
    $plain = array_keys((new Problem(ProblemType::NotFound, '/v1/x'))->toArray());
    $validation = array_keys((new Problem(ProblemType::Validation, '/v1/x', ['field' => ['bad']]))->toArray());

    sort($plain);
    sort($validation);

    $documentedPlain = array_keys((array) ($schemas['ProblemDocument']['properties'] ?? []));
    $documentedValidation = array_keys((array) ($schemas['ValidationProblemDocument']['properties'] ?? []));

    sort($documentedPlain);
    sort($documentedValidation);

    expect($documentedPlain)->toBe($plain)
        ->and($documentedValidation)->toBe($validation)
        // retryAfter is a header and must not be published as a body member.
        ->and($documentedPlain)->not->toContain('retryAfter')
        ->and($documentedValidation)->not->toContain('retryAfter');

    // Every documented property is required, and the two schemas differ only by `errors`
    // -- which is why there are two rather than one with an optional member: Scramble
    // marks every property required, measured both as a promoted constructor parameter
    // with a default and as a plain property with an initialiser.
    expect(array_values(array_diff($documentedValidation, $documentedPlain)))->toBe(['errors']);

    foreach (['ProblemDocument', 'ValidationProblemDocument'] as $name) {
        $required = (array) ($schemas[$name]['required'] ?? []);
        sort($required);
        $properties = array_keys((array) ($schemas[$name]['properties'] ?? []));
        sort($properties);

        expect($required)->toBe($properties, "{$name} documents an optional member it always emits");
    }
});

it('keeps internal rationale out of every published description — R-052, R-056', function (): void {
    $spec = committedSpec();

    // R-052 and R-056 are both the same shape: a docblock shipping as spec text and
    // carrying something never meant to be published. Found again while closing R-033 --
    // ProblemType's whole docblock, including the sentence R-033 measured false, was being
    // published as the schema's description, and separating the halves inside one docblock
    // with a horizontal rule published both halves.
    //
    // So this sweeps EVERY description in the document rather than the one sentence
    // R-052 named, which is what R-056 asks for ("one assertion list covers both rows, so
    // the edit that satisfies R-052 satisfies this if it is written for the general case").
    $descriptions = [];

    array_walk_recursive($spec, function (mixed $value, string|int $key) use (&$descriptions): void {
        if ($key === 'description' && is_string($value)) {
            $descriptions[] = $value;
        }
    });

    expect($descriptions)->not->toBeEmpty();

    foreach ($descriptions as $description) {
        foreach ([
            // internal identifiers and ledger references
            'R-0', 'PLAN.md', 'QRPAGER-', '§',
            // capacity and infrastructure figures (R-052's own instance)
            'app_limit', 'app_backends', 'saturation', 'qrpager_',
            // the artefact of a docblock leaking its own comment markers
            ' * ',
            // database and framework internals
            'SQLSTATE', 'pg_', 'deptrac', 'Scramble',
        ] as $forbidden) {
            expect($description)->not->toContain(
                $forbidden,
                "a published description carries [{$forbidden}]: ".substr($description, 0, 120),
            );
        }
    }
});
