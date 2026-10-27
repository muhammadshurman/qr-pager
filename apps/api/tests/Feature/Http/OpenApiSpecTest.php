<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\File;
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
    // THE MODIFIED SPEC IS A COPY. NOTHING HERE WRITES docs/openapi.json (QA L-2).
    //
    // This case used to overwrite the committed contract and restore it in a `finally` --
    // and R-018 is the §5 row that says exactly why that is not good enough: "each finally
    // is correct and none of them survives a kill -9", an OOM or a CI timeout. The answer
    // there was to stop touching the shared object; this is the same answer. bin/openapi-check
    // takes OPENAPI_SPEC_PATH, so the check runs against a scratch file and the repository's
    // own contract is never opened for writing by any test.
    $scratch = tempnam(sys_get_temp_dir(), 'qrpager-spec-').'.json';
    $committed = (string) file_get_contents(base_path('../../docs/openapi.json'));

    try {
        // A single changed byte, which is the realistic failure: a route or a return type
        // changes and nobody regenerates.
        file_put_contents($scratch, str_replace('QR Pager API', 'QR Pager API (stale)', $committed));

        $result = Process::path(base_path())
            ->env(['OPENAPI_SPEC_PATH' => $scratch])
            ->run('composer openapi:check');

        expect($result->exitCode())->not->toBe(0, 'the staleness check accepted a modified spec')
            ->and($result->output().$result->errorOutput())->toContain('STALE');

        // ...and the SAME mechanism passes on an unmodified copy, so the failure above is
        // the modification and not the override path being broken.
        file_put_contents($scratch, $committed);

        $restored = Process::path(base_path())
            ->env(['OPENAPI_SPEC_PATH' => $scratch])
            ->run('composer openapi:check');

        expect($restored->exitCode())->toBe(0, $restored->output().$restored->errorOutput());
    } finally {
        @unlink($scratch);
    }

    // And the committed contract is byte-for-byte what it was when this case started --
    // asserted rather than assumed, because that is the property R-018 is about.
    expect(file_get_contents(base_path('../../docs/openapi.json')))->toBe($committed);
});

it('has no test that writes to a committed artefact — R-018 generalised, QA L-2', function (): void {
    // THE GUARD, not just the fix. §7: "Never fix the same bug twice. If it recurs, the
    // regression test was missing." R-018 was one test mutating a shared ROLE; L-2 was
    // another mutating a shared FILE. The shape is "a test modifies something the
    // repository owns and relies on a finally", and it has now appeared twice.
    //
    // Scanned: every test file, for a write call whose target is a path under the
    // repository's own docs/ or a committed spec. A test that needs a modified artefact
    // makes a copy -- which is what the case above now does.
    $writers = ['file_put_contents', 'fwrite', 'unlink', 'rename', 'copy'];
    $offenders = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $number => $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            foreach ($writers as $writer) {
                // The target has to be a committed path: base_path('../../docs/...') or a
                // literal docs/ path. A scratch file from tempnam/sys_get_temp_dir is fine
                // and is what this rule is steering people towards.
                if (str_contains($line, $writer.'(')
                    && (str_contains($line, '../../docs/') || str_contains($line, "base_path('../../docs"))) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1).'  '.trim($line);
                }
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, array_merge(
        ['a test writes to a committed artefact. R-018: every finally is correct and none of'],
        ['them survives a kill -9. Export to a scratch path and point the check at it instead.'],
        [''],
        $offenders,
    )));
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
            // str_contains() AND NOT `->not->toContain($forbidden, "message")`, which is
            // what this was -- and it is why `0.9 x app_limit` is in the committed spec
            // today with a test that claims to forbid it. Pest's toContain takes
            // ...$needles, so the message was read as a second needle and ->not became
            // "does not contain BOTH", which a message that never appears makes trivially
            // true. Measured 2026-10-27, found at Task 4. R-052 is an accepted row owned
            // by Task 12; what was NOT known is that its compensating control had never
            // worked.
            expect(str_contains($description, $forbidden))->toBeFalse(
                "a published description carries [{$forbidden}]: ".substr($description, 0, 120),
            );
        }
    }
});

it('publishes a security requirement on EVERY operation, and the staff routes are not public — security M-1', function (): void {
    // THE DEFECT: the committed spec had no `components.securitySchemes` and no `security`
    // member on any operation, so all five state-changing staff endpoints were published as
    // requiring no authentication. §11.3 asks that public routes be "explicitly marked and
    // justified", and in that document an authenticated route with no scheme and a
    // deliberately public one were indistinguishable -- while the spec is what Tasks 8-9
    // generate clients from.
    //
    // EVERY OPERATION, including the probes. OpenAPI's inheritance would let the
    // document-level requirement cover the staff routes with the probes overriding it, and
    // that is one fewer thing written down: a reader would need the inheritance rule to see
    // that /healthz is public on purpose. Explicit everywhere means THIS CASE CAN REQUIRE
    // THE MEMBER, so a route added without one fails rather than silently inheriting.
    $spec = committedSpec();
    $scheme = AppServiceProvider::STAFF_SECURITY_SCHEME;

    expect($spec['components']['securitySchemes'] ?? [])->toHaveKey($scheme);

    $declared = $spec['components']['securitySchemes'][$scheme];

    expect($declared['type'] ?? null)->toBe('http')
        ->and($declared['scheme'] ?? null)->toBe('bearer')
        // The description says what the credential is and that Task 5 issues it, so a
        // reader is not left to infer that "bearer" means something exists today.
        ->and($declared['description'] ?? '')->toContain('Task 5');

    $missing = [];
    $public = [];
    $authenticated = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (! in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $label = strtoupper($verb).' '.$path;

            if (! array_key_exists('security', $operation)) {
                $missing[] = $label;

                continue;
            }

            // `[{}]` is OpenAPI's spelling for "no authentication required". Anything else
            // names a scheme.
            if ($operation['security'] === [[]] || $operation['security'] === []) {
                $public[] = $path;
            } else {
                $authenticated[] = $label;
                expect($operation['security'])->toBe([[$scheme => []]], "{$label} names an unexpected scheme");
            }
        }
    }

    expect($missing)->toBe([], 'these operations publish no security member at all: '.implode(', ', $missing));

    // The public set is EXACTLY the two probes, from the same constant the generator reads,
    // so adding a third public route means editing that constant deliberately.
    expect(array_values(array_unique($public)))->toBe(AppServiceProvider::PUBLIC_PATHS);

    // ...and every staff route is in the other set. Five, named, so a route that quietly
    // became public is a failure here rather than a reading of the diff.
    expect($authenticated)->toHaveCount(5)
        ->and($authenticated)->toContain('POST /v1/pager-sessions');
});

it('publishes the 401 that every unauthenticated request actually receives — QA L-5', function (): void {
    // RequireTenantScope answers 401 before any controller method runs, and that is the most
    // common answer this surface gives -- yet the spec documented 201/422 and 200/404/409 and
    // never 401. §6's clause ("any REST endpoint added is in the generated OpenAPI spec") was
    // met; the published contract was incomplete.
    //
    // Required on the AUTHENTICATED routes only: the probes cannot answer 401, because they
    // carry no tenant middleware at all, and requiring it of them would document an answer
    // they never give.
    // COLLECTED, THEN ASSERTED ONCE, and not with a message handed to toHaveKey: that
    // matcher's second parameter is the expected VALUE and its message is the THIRD
    // (`toHaveKey($key, $value, $message)`), so `toHaveKey('401', 'some message')` asserts
    // that the 401 response EQUALS that sentence. It fails loudly here -- that is how this
    // was found -- but negated it can never fail at all. Measured on 2026-10-27, inside
    // Pest: `expect(['a' => 1])->not->toHaveKey('a', 'a message')` PASSES while the key is
    // present. Collecting first needs no message slot and names every offender at once.
    $spec = committedSpec();
    $missing = [];
    $wrongShape = [];
    $spurious = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (! in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $label = strtoupper($verb).' '.$path;
            $responses = $operation['responses'] ?? [];

            if (($operation['security'] ?? null) === [[]]) {
                if (array_key_exists('401', $responses)) {
                    $spurious[] = $label;
                }

                continue;
            }

            if (! array_key_exists('401', $responses)) {
                $missing[] = $label;

                continue;
            }

            // And it is the project's own problem document, not an ad-hoc shape.
            $ref = $responses['401']['content']['application/problem+json']['schema']['$ref'] ?? '';

            if (! str_contains($ref, 'ProblemDocument')) {
                $wrongShape[] = $label.' -> '.($ref === '' ? 'no problem+json schema' : $ref);
            }
        }
    }

    expect($missing)->toBe([], 'authenticated, and documents no 401: '.implode(', ', $missing))
        ->and($wrongShape)->toBe([], 'a 401 that is not the problem document: '.implode(', ', $wrongShape))
        ->and($spurious)->toBe([], 'public, and documents a 401 it can never give: '.implode(', ', $spurious));
});

it('publishes every problem document under the media type the API really sends, and under one only', function (): void {
    // THE DEFECT: Problem::toResponse() has always sent `application/problem+json` -- six
    // test files assert that header against real responses -- while the committed spec
    // published all four error codes under `application/json`. A client generated from the
    // document is therefore told the wrong media type for every error this API can answer
    // with, which §6's "any REST endpoint added is in the generated OpenAPI spec" does not
    // catch: the endpoint WAS in the spec, described wrongly.
    //
    // MEASURED, NOT ASSUMED. The expected value is read off a real refused request rather
    // than compared to the constant both sides already share -- a constant equal to itself
    // would prove nothing about what goes over the wire.
    Route::middleware('api')->prefix('v1')->get('media-type-probe', fn () => response()->json(['unreachable' => true]));

    $refused = $this->getJson('/v1/media-type-probe');

    $refused->assertStatus(401);

    $measured = trim(explode(';', (string) $refused->headers->get('Content-Type'))[0]);

    expect($measured)->toBe(Problem::CONTENT_TYPE, 'a real error response no longer sends '.Problem::CONTENT_TYPE);

    // ONE MEDIA TYPE PER RESPONSE, which is the part that regressed while this was being
    // written: Scramble infers a 422 from the FormRequest and a `#[Response]` attribute
    // adds its own, so the operation published BOTH `application/json` (Laravel's default
    // validation body, a shape this API never sends) and the problem document. Two
    // alternatives, one of them fictional, is worse than one wrong one.
    $problemSchemas = ['#/components/schemas/ProblemDocument', '#/components/schemas/ValidationProblemDocument'];
    $wrongType = [];
    $alternatives = [];
    $seen = 0;

    foreach (committedSpec()['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (! in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            foreach ($operation['responses'] ?? [] as $code => $response) {
                $content = $response['content'] ?? [];

                $isProblem = array_filter(
                    $content,
                    fn (array $media) => in_array($media['schema']['$ref'] ?? '', $problemSchemas, true),
                );

                if ($isProblem === []) {
                    continue;
                }

                $seen++;
                $label = strtoupper($verb).' '.$path.' '.$code;

                if (count($content) > 1) {
                    $alternatives[] = $label.' -> '.implode(' + ', array_keys($content));
                }

                if (array_keys($content) !== [$measured]) {
                    $wrongType[] = $label.' -> '.implode(' + ', array_keys($content));
                }
            }
        }
    }

    // The count is asserted so that a document which stopped publishing problem documents
    // altogether cannot pass this by having nothing left to check -- R-029's shape.
    expect($seen)->toBe(14, 'the spec publishes '.$seen.' problem responses, not the 14 the five staff routes declare')
        ->and($alternatives)->toBe([], 'published under more than one media type: '.implode(', ', $alternatives))
        ->and($wrongType)->toBe([], 'published under a media type no response sends: '.implode(', ', $wrongType));
});

it('leaves a body that is not a problem document as application/json — /readyz 503', function (): void {
    // The counter-case, and the reason the step above keys off the SCHEMA and not the
    // status code: /readyz answers 503 with a readiness body, not an error envelope, and
    // moving it would have been the same defect in the other direction.
    $spec = committedSpec();

    expect(array_keys($spec['paths']['/readyz']['get']['responses']['503']['content']))->toBe(['application/json'])
        ->and(array_keys($spec['paths']['/readyz']['get']['responses']['200']['content']))->toBe(['application/json']);
});
