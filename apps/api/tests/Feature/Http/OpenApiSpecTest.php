<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use App\Providers\AppServiceProvider;
use Dedoc\Scramble\Contracts\RouteProvider;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\OperationBuilder;
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
        // Task 5's AUTH surface -- the third route set and the third middleware group. It
        // cannot be on `api`, whose last entry is RequireTenantScope: the tenant is derived
        // from the credential a login is about to establish, so requiring one in order to
        // authenticate is a loop with no entry point (routes/auth.php).
        '/v1/auth/login',
        '/v1/auth/logout',
        '/v1/auth/me',
        '/v1/auth/passkeys',
        '/v1/auth/passkeys/login',
        '/v1/auth/passkeys/login/options',
        '/v1/auth/passkeys/options',
        '/v1/auth/tenant',
        '/v1/auth/two-factor',
        '/v1/auth/two-factor/challenge',
        '/v1/auth/two-factor/confirm',
        '/v1/auth/two-factor/enable',
        // Task 5's own staff routes. Device tokens are credentials for a kitchen screen and
        // the member routes are R-007's half -- the only surface where the role matrix
        // gives staff and manager different answers to the same request.
        '/v1/devices',
        '/v1/devices/{device}',
        '/v1/members',
        '/v1/members/{member}',
        '/v1/pager-sessions',
        '/v1/pager-sessions/{session}/cancel',
        '/v1/pager-sessions/{session}/complete',
        '/v1/pager-sessions/{session}/ready',
        '/v1/pager-sessions/{session}/renotify',
        // Task 4's customer surface. Under the same /v1 prefix -- the versioning policy is
        // about the public path, not about which file a route lives in -- and on a
        // different middleware group, because `api`'s last entry is RequireTenantScope and
        // a customer has no account for a tenant to be resolved from (§4 › Tenancy, two
        // authentication surfaces).
        '/v1/pager/session',
        '/v1/pager/status',
        '/v1/pager/subscription',
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
    $paced = array_keys((new Problem(ProblemType::Stale, '/v1/x', pollAfter: 7))->toArray());

    sort($plain);
    sort($validation);
    sort($paced);

    $documentedPlain = array_keys((array) ($schemas['ProblemDocument']['properties'] ?? []));
    $documentedValidation = array_keys((array) ($schemas['ValidationProblemDocument']['properties'] ?? []));
    $documentedPaced = array_keys((array) ($schemas['PacedProblemDocument']['properties'] ?? []));

    sort($documentedPlain);
    sort($documentedValidation);
    sort($documentedPaced);

    expect($documentedPlain)->toBe($plain)
        ->and($documentedValidation)->toBe($validation)
        ->and($documentedPaced)->toBe($paced)
        // retryAfter is a header and must not be published as a body member -- and
        // `poll_after_seconds` IS one, which is the distinction PacedProblemDocument
        // exists to keep (QA M-6, 2026-10-27). The polling endpoint's 503 sends both, so
        // this is the one place the two could be confused.
        ->and($documentedPlain)->not->toContain('retryAfter')
        ->and($documentedValidation)->not->toContain('retryAfter')
        ->and($documentedPaced)->not->toContain('retryAfter');

    // Every documented property is required, and each specialised schema differs from the
    // plain one by exactly its own member -- which is why there are three rather than one
    // with two optional members: Scramble marks every property required, measured both as
    // a promoted constructor parameter with a default and as a plain property with an
    // initialiser.
    expect(array_values(array_diff($documentedValidation, $documentedPlain)))->toBe(['errors'])
        ->and(array_values(array_diff($documentedPaced, $documentedPlain)))->toBe(['poll_after_seconds']);

    foreach (['ProblemDocument', 'ValidationProblemDocument', 'PacedProblemDocument'] as $name) {
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

    // THE TOKENLESS SET IS TWO SURFACES, and this assertion is why the generator builds them
    // separately. Until Task 4 arrived it was "exactly the two probes"; the customer routes
    // are tokenless for a different reason -- the QR token is in the request BODY, which
    // OpenAPI cannot express as a scheme -- so they publish an empty requirement too. When
    // this case said PUBLIC_PATHS alone, the rebase published all four customer routes as
    // needing a STAFF BEARER TOKEN and this assertion was what caught it.
    $probes = AppServiceProvider::PUBLIC_PATHS;
    $customer = ['/v1/pager/session', '/v1/pager/status', '/v1/pager/subscription'];

    // THE THIRD TOKENLESS SURFACE, added at Task 5. Tokenless in the OpenAPI sense even
    // though `auth.me` needs a session: a session COOKIE is no more expressible as a
    // security scheme than the customer's QR-token-in-the-body, and declaring the bearer
    // scheme on a login would name a credential that login has not issued yet. The
    // generator reads the `auth-surface` middleware group rather than a second list, for
    // the same reason it reads `customer` -- the failure mode of forgetting to edit a list
    // is a route published as needing a credential it does not use, which is exactly what
    // happened here before this set existed.
    // In ROUTER order, which is the order the spec's paths come out in, not alphabetical:
    // login, logout, tenant, me, as routes/auth.php declares them.
    $auth = [
        '/v1/auth/login', '/v1/auth/logout', '/v1/auth/tenant', '/v1/auth/me',
        '/v1/auth/two-factor/enable', '/v1/auth/two-factor/confirm', '/v1/auth/two-factor',
        '/v1/auth/two-factor/challenge',
        '/v1/auth/passkeys/options', '/v1/auth/passkeys',
        '/v1/auth/passkeys/login/options', '/v1/auth/passkeys/login',
    ];

    // ORDER IS NOT PINNED, and that is a deliberate change made at Task 5 rather than a
    // weakening. The spec's `paths` ordering is Scramble's own insertion order, and with
    // three tokenless surfaces it interleaves them -- the committed artifact lists
    // `/healthz` between the passkey routes and `/readyz`. Pinning that order asserts a
    // property of the GENERATOR's internals; what this case is for is WHICH paths are
    // tokenless, which is a property of this application. So the set is compared as a set.
    // The failure this assertion exists to catch -- a customer or auth route published as
    // needing a staff bearer token, which is what the Task 4 rebase did -- is a membership
    // failure and is caught either way.
    expect(array_values(array_unique($public)))
        ->toEqualCanonicalizing([...$auth, ...$customer, ...$probes]);

    // ...and every staff route is in the other set. TWELVE as of Task 5 -- it was five
    // until this task added the board read, four member routes and two device-token routes
    // -- counted and named, so a route that quietly became public, or a customer route that
    // quietly claimed a staff credential, is a failure here rather than a reading of the
    // diff.
    expect($authenticated)->toHaveCount(12)
        ->and($authenticated)->toContain('POST /v1/pager-sessions')
        ->and($authenticated)->toContain('GET /v1/pager-sessions')
        ->and($authenticated)->toContain('GET /v1/members')
        ->and($authenticated)->toContain('POST /v1/members')
        ->and($authenticated)->toContain('PATCH /v1/members/{member}')
        ->and($authenticated)->toContain('DELETE /v1/members/{member}')
        ->and($authenticated)->toContain('POST /v1/devices')
        ->and($authenticated)->toContain('DELETE /v1/devices/{device}');
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
    $authSurface = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (! in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $label = strtoupper($verb).' '.$path;
            $responses = $operation['responses'] ?? [];

            if (($operation['security'] ?? null) === [[]]) {
                // THE AUTH SURFACE IS TOKENLESS AND CAN STILL ANSWER 401, which this case's
                // original premise did not allow for -- and the premise, not the surface,
                // is what was wrong.
                //
                // When this assertion was written, "tokenless" meant two things that never
                // refuse for want of a credential: the probes, and the customer surface,
                // whose refusals are 404 Stale and 409 rather than 401. Task 5 added a
                // third, where 401 is the WHOLE POINT of the endpoint: a login refuses bad
                // credentials with it, and `auth.me` and the tenant selection refuse an
                // absent session with it.
                //
                // So the auth surface is carved out and held to a STRONGER rule instead,
                // asserted below: the three routes that can refuse must document a 401, and
                // `logout` -- which answers 204 whether or not there was a session, so that
                // it is neither an oracle nor exhaustible -- must not.
                if (str_starts_with($path, '/v1/auth/')) {
                    $authSurface[$label] = array_key_exists('401', $responses);

                    continue;
                }

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

    // The auth surface, named operation by operation rather than counted, so that adding a
    // route to routes/auth.php without deciding whether it can refuse fails here.
    // Keyed by operation, so order is irrelevant and the KEY SET is part of the assertion:
    // an auth route added without deciding whether it can answer 401 fails here, which is
    // the point. (toEqual rather than toBe for the same reason the set above is
    // canonicalized -- the spec's path order is Scramble's, not ours.)
    expect($authSurface)->toEqual([
        'POST /v1/auth/login' => true,
        'POST /v1/auth/logout' => false,
        'POST /v1/auth/tenant' => true,
        'GET /v1/auth/me' => true,
        // Two-factor: everything but the CHALLENGE needs a session, so each documents the
        // 401 it can give. The challenge documents one too -- "there is no sign-in waiting
        // for a second factor" -- which is a different 401 from "no session" and is the one
        // answer this surface has that a client must be able to tell apart from a 422.
        'POST /v1/auth/two-factor/enable' => true,
        'POST /v1/auth/two-factor/confirm' => true,
        'DELETE /v1/auth/two-factor' => true,
        'POST /v1/auth/two-factor/challenge' => true,
        // Passkeys: registration needs a session and documents a 401; the LOGIN ceremony
        // does not, because it is what establishes one. A 401 on either login route would be
        // a documented answer it can never give.
        'GET /v1/auth/passkeys/options' => true,
        'POST /v1/auth/passkeys' => true,
        'GET /v1/auth/passkeys/login/options' => false,
        'POST /v1/auth/passkeys/login' => false,
    ]);
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
    $problemSchemas = [
        '#/components/schemas/ProblemDocument',
        '#/components/schemas/ValidationProblemDocument',
        '#/components/schemas/PacedProblemDocument',
    ];
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
    // 65 as of Task 5, over twenty-eight routes. It was 26 = the five staff routes' 14, plus the customer surface's 12
    // (three each on session, subscription POST and subscription DELETE, and three on
    // status, whose 503 is the Redis-unavailable problem document rather than a readiness
    // body). Task 5 adds 21: the board read, four member routes and two device-token
    // routes, each declaring the 401 that RequireTenantScope answers and the 403 that
    // RequireAbility answers, plus 404s on the three keyed routes and 422s on the two that
    // take a body; then the auth surface's -- login's 401/422/429, the tenant selection's
    // 401/404/422, `me`'s 401, the four two-factor routes' 401s and 422s (enable also
    // declares a 500, for the case where the secret it just wrote cannot be read back), and
    // the passkey ceremony's 401s and 422s. The 403 is new to this project's published
    // contract: before Task 5 nothing could answer it, because nothing authorised anything.
    expect($seen)->toBe(65, 'the spec publishes '.$seen.' problem responses, not the 65 the twenty-eight routes declare')
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

it('produces the security and media-type decisions in the GENERATOR, not only in the committed file', function (): void {
    // Everything above reads docs/openapi.json, which is the right subject for a staleness
    // check and the wrong one for the two post-processing steps in AppServiceProvider: the
    // committed file is their OUTPUT, so a test that only reads it passes whether the steps
    // ran today or shipped once and broke later. `composer openapi:check` does regenerate
    // and diff, but it is a separate process, so nothing in the suite ever EXECUTED those
    // closures: AppServiceProvider measured **17.6%** of lines before this case and **96.1%**
    // with it, the remaining two being the defensive `continue`s for a Reference response and
    // a response with no `application/json` content, neither of which this document contains.
    //
    // So this generates the document in process, the way `scramble:export` does, and asserts
    // the decisions as decisions. Proven able to fail by mutating the SOURCE rather than the
    // artefact: with the media-type step disabled it names all 14 offenders, and with the
    // per-operation security call removed the public/authenticated split collapses.
    $generator = new Generator(app(OperationBuilder::class), app(RouteProvider::class));
    $config = Scramble::getGeneratorConfig('default');   // the same api name scramble:export defaults to

    $spec = $generator->generate($config)->spec($config->openApiVersion());

    expect($spec['components']['securitySchemes'] ?? [])->toHaveKey(AppServiceProvider::STAFF_SECURITY_SCHEME);

    $public = [];
    $authenticated = [];
    $problemResponses = [];
    $jsonProblems = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (! in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            expect($operation)->toHaveKey('security');

            // ONE EMPTY REQUIREMENT means "no authentication required". Its PHP shape
            // differs between the two readings, which is itself a reason this case exists:
            // in process an empty requirement is a `stdClass`, and the committed file's
            // `{}` decodes to an empty ARRAY. A test that only ever reads the file would
            // never meet the first shape.
            $requirements = $operation['security'];

            if (count($requirements) === 1 && (array) $requirements[0] === []) {
                $public[] = $path;
            } else {
                $authenticated[] = strtoupper($verb).' '.$path;
            }

            foreach ($operation['responses'] ?? [] as $code => $response) {
                foreach ($response['content'] ?? [] as $mediaType => $media) {
                    $isProblem = str_contains($media['schema']['$ref'] ?? '', 'ProblemDocument');

                    if ($isProblem && $mediaType === Problem::CONTENT_TYPE) {
                        $problemResponses[] = $path.' '.$code;
                    }

                    // The step moved nothing, or moved it and left the original behind.
                    if ($isProblem && $mediaType === 'application/json') {
                        $jsonProblems[] = $path.' '.$code;
                    }
                }
            }
        }
    }

    // As a SET, for the reason the committed-spec case above gives.
    expect(array_values(array_unique($public)))->toEqualCanonicalizing([
        // The auth surface -- the third tokenless surface, added at Task 5. See the
        // committed-spec case above for why a login publishes no scheme.
        '/v1/auth/login', '/v1/auth/logout', '/v1/auth/tenant', '/v1/auth/me',
        '/v1/auth/two-factor/enable', '/v1/auth/two-factor/confirm', '/v1/auth/two-factor',
        '/v1/auth/two-factor/challenge',
        '/v1/auth/passkeys/options', '/v1/auth/passkeys',
        '/v1/auth/passkeys/login/options', '/v1/auth/passkeys/login',
        '/v1/pager/session', '/v1/pager/status', '/v1/pager/subscription',
        ...AppServiceProvider::PUBLIC_PATHS,
    ])
        // Twelve and 65 as of Task 5 -- the in-process generator and the committed artifact
        // must agree, which is this case's whole purpose, so both numbers move together
        // with the ones in the committed-spec cases above.
        ->and($authenticated)->toHaveCount(12)
        ->and($jsonProblems)->toBe([], 'a problem document is still published as application/json: '.implode(', ', $jsonProblems))
        ->and($problemResponses)->toHaveCount(65);
});
