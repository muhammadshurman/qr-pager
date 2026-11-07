<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE FUZZ SUITE — `§3` ROW 12: *"the fuzz suite finds no 5xx"*
 * ================================================================================
 *
 * Every route in the table, driven with hostile input in its body, its path parameters and
 * its headers. The clause is a 5xx, and the reason it is the right clause rather than a weak
 * one is that a 5xx is the only status that means *we did not consider this*: a 400, a 401, a
 * 403, a 404, a 422 and a 429 are all the application deciding something, and a 500 is the
 * application being surprised. `§4 › Edge & ops` also makes an unauthenticated caller's
 * ability to generate 500s an error-budget question, which `R-038`'s own measurement of two
 * anonymous 500s from vendor routes already demonstrated.
 *
 * ================================================================================
 * SEEDED, SO A FAILURE IS A BUG REPORT AND NOT A RUMOUR
 * ================================================================================
 *
 * A fuzz run that cannot be repeated produces exactly the defect shape `§4a` calls this
 * project's most expensive: an unexplained red. Every payload here is generated from a FIXED
 * SEED, the seed is printed in every failure message, and `FUZZ_SEED` in the environment
 * overrides it — so a red run is reproducible by one variable, and a broader sweep is one
 * variable away without editing this file.
 *
 * ================================================================================
 * WHAT IS *NOT* CLAIMED
 * ================================================================================
 *
 * This is a bounded, deterministic corpus over the real surface, not a coverage-guided
 * fuzzer. It drives every route with every payload in the corpus; it does not search. What it
 * is good for is the clause it is written against and one more thing: it is the only test in
 * this project that sends an array where a string is expected, a 64 kB field, a lone
 * surrogate and a NUL byte to EVERY route, which is where unguarded `(string)` casts and
 * `json_decode` assumptions live.
 */

/** The seed, overridable, so a red run can be reproduced and a wider sweep needs no edit. */
function fuzzSeed(): int
{
    $seed = (int) env('FUZZ_SEED', 20261107);

    return $seed > 0 ? $seed : 20261107;
}

/**
 * The hostile corpus: label => value, each a different way of being wrong.
 *
 * ⚠ TYPE CONFUSION IS FIRST AND IS THE ONE THAT MATTERS MOST. Laravel's validator refuses an
 * array where a string is expected with a 422 — but a controller that reads
 * `$request->input('x')` BEFORE validating, or a form request that validates a sibling field,
 * gets an `array` into a `(string)` cast and PHP 8.4 raises a TypeError, which is a 500.
 *
 * @return array<string, mixed>
 */
function fuzzCorpus(int $seed): array
{
    mt_srand($seed);

    $random = '';

    for ($i = 0; $i < 48; $i++) {
        $random .= chr(mt_rand(1, 255));
    }

    return [
        'an array where a scalar belongs' => ['nested' => ['deeper' => 'x']],
        'a list where a scalar belongs' => ['a', 'b', 'c'],
        'an integer where a string belongs' => 7,
        'a float' => 1.5e30,
        'a boolean' => true,
        'null' => null,
        'the empty string' => '',
        'a 64 kB field' => str_repeat('A', 65_536),
        'a NUL byte' => "before\0after",
        'control characters' => "\x01\x02\x03\x07\x08\x0b\x0c\x1b",
        'an invalid UTF-8 sequence' => "\xC3\x28\xA0\xA1",
        'a lone surrogate, as escaped JSON would carry it' => '\\ud800',
        'a right-to-left override' => "admin\u{202E}txt.exe",
        'a combining-mark pile' => 'e'.str_repeat("\u{0301}", 200),
        'SQL metacharacters' => "' or 1=1 --",
        'a quoted identifier and a cast' => '"users"::text',
        'a Postgres array literal' => '{1,2,3}',
        'a script tag' => '<script>alert(1)</script>',
        'a javascript: URL' => 'javascript:alert(1)',
        'an event handler attribute' => '" onmouseover="alert(1)',
        'path traversal' => '../../../../etc/passwd',
        'an encoded traversal' => '%2e%2e%2f%2e%2e%2fetc%2fpasswd',
        'a template expression' => '{{ 7*7 }}',
        'a PHP serialised object' => 'O:8:"stdClass":1:{s:1:"a";i:1;}',
        'a format string' => '%s%n%x',
        'a very large integer' => PHP_INT_MAX,
        'a negative integer' => PHP_INT_MIN,
        'a mail header injection' => "a@b.test\r\nBcc: victim@c.test",
        'a CRLF for a response header' => "x\r\nX-Injected: 1",
        'random bytes, from the seed' => $random,
    ];
}

/**
 * Is this a 5xx the clause is about, or the one designed answer on this surface?
 *
 * ⚠ ONE EXCEPTION, NAMED AND REASONED, AND IT IS THE ONLY 5xx THE WHOLE CORPUS PRODUCED.
 * `GET /readyz` answers **503** when it has no readiness snapshot, and that is the documented
 * posture rather than a surprise: `config/ratelimit.php`'s own note on fail-open says
 * *"anything carrying TENANT DATA fails closed instead, which is why readiness answers 503
 * when it cannot read its key rather than guessing"*, `RouteSurfaceTest` asserts that exact
 * answer, and `TestCase::setUp()` CLEARS the key before every test on purpose (*"an absent key
 * is the safe default"*). So in this suite `/readyz` answers 503 by construction.
 *
 * Measured at `FUZZ_SEED=20261107`: **58 responses of 500 or more across the three cases, and
 * all 58 were this pair.** That is the result worth recording — the clause is met, and it is
 * met with one designed answer rather than with a loosened criterion.
 *
 * ⚠ IT IS A PATH **AND** A STATUS, not a path. `/readyz` answering 500 is still a failure,
 * which is what keeps the exception from being a hole in the one route it names. And it is
 * asserted non-vacuous below: if `/readyz` ever stops answering 503 here, the exception has
 * gone stale and the case says so instead of quietly covering nothing.
 */
function fuzzIsRealServerError(string $path, int $status): bool
{
    if ($status < 500) {
        return false;
    }

    return ! ($status === 503 && rtrim($path, '/') === '/readyz');
}

/**
 * Every route, as [method, uri template, parameter names].
 *
 * @return list<array{0: string, 1: string, 2: list<string>}>
 */
function fuzzRoutes(): array
{
    $routes = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $m): bool => $m !== 'HEAD',
        ));

        if ($methods === []) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');

        preg_match_all('#\{([^}?]+)\??\}#', $uri, $names);

        $routes[] = [$methods[0], $uri, array_values($names[1])];
    }

    return array_values(array_unique($routes, SORT_REGULAR));
}

/** Substitute one value into every parameter slot. */
function fuzzUri(string $template, string $value): string
{
    return (string) preg_replace('#\{[^}]+\}#', rawurlencode($value), $template);
}

beforeEach(function (): void {
    // The limiter is not what is under test here, and a surface that answers 429 to everything
    // would make this file pass while driving nothing (`R-063`'s clause read backwards). The
    // buckets are the suite's own per-IP counters and are cleared between tests anyway; this
    // clears them again between rounds inside one test.
    $this->flushRateLimits = function (): void {
        try {
            $store = (string) config('cache.stores.redis.connection', 'cache');
            $prefix = (string) config('cache.prefix', '');
            $connection = app('redis')->connection($store);
            $keys = $connection->keys($prefix.'ratelimit:*');

            if (is_array($keys) && $keys !== []) {
                $bare = array_map(
                    static fn (mixed $k): string => (string) $k,
                    $keys,
                );
                $clientPrefix = (string) config('database.redis.options.prefix', '');
                $connection->del(...array_map(
                    static fn (string $k): string => $clientPrefix !== '' && str_starts_with($k, $clientPrefix)
                        ? substr($k, strlen($clientPrefix))
                        : $k,
                    $bare,
                ));
            }
        } catch (Throwable) {
            // A Redis that cannot be reached has no counters to clear.
        }
    };
});

it('finds no 5xx anywhere on the surface, with a hostile BODY on every route', function (): void {
    $seed = fuzzSeed();
    $corpus = fuzzCorpus($seed);
    $routes = fuzzRoutes();

    // Not vacuous: an empty route list or an empty corpus would make this the strongest
    // possible pass. Both floors are well below the live figures (~59 routes, 30 payloads).
    expect(count($routes))->toBeGreaterThan(40, 'the route derivation is broken, not the surface')
        ->and(count($corpus))->toBeGreaterThan(20, 'the corpus is nearly empty');

    // Driven as an authenticated owner, deliberately: an anonymous caller is refused by
    // `RequireTenantScope` before most controllers run, so an anonymous-only fuzz would be a
    // fuzz of the middleware stack. The hostile input has to REACH the validators and the
    // controllers to be worth sending.
    $this->actingForTenant(role: TenantRole::Owner);

    $failures = [];
    $statuses = [];
    $driven = 0;

    foreach ($corpus as $label => $value) {
        ($this->flushRateLimits)();

        foreach ($routes as [$method, $template, $parameters]) {
            // Every known field name on this surface gets the payload at once, plus two names
            // no route declares -- mass-assignment bait.
            $body = [
                'email' => $value,
                'role' => $value,
                'name' => $value,
                'token' => $value,
                'order_reference' => $value,
                'endpoint' => $value,
                'keys' => $value,
                'ttl_minutes' => $value,
                'blocks' => $value,
                'theme' => $value,
                'template' => $value,
                'language' => $value,
                'domain' => $value,
                'id' => $value,
                'tenant_id' => $value,
                'is_platform_admin' => $value,
            ];

            $uri = $parameters === [] ? $template : fuzzUri($template, (string) Str::uuid7());

            $response = $this->json($method, $uri, $body);
            $status = $response->getStatusCode();

            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $driven++;

            if (fuzzIsRealServerError($uri, $status)) {
                $failures[] = sprintf('[%d] %s %s  with %s  (FUZZ_SEED=%d)', $status, $method, $uri, $label, $seed);
            }
        }
    }

    // Reported as one list: a failure names every offender at once rather than the first.
    expect($failures)->toBe([], count($failures)." 5xx response(s):\n  ".implode("\n  ", $failures));

    // ⚠ AND THE RUN REALLY HAPPENED, which an all-green absence assertion cannot say for
    // itself. Both halves matter: enough requests to be a sweep, and not so many 429s that the
    // surface was simply refusing everything.
    $throttled = $statuses[429] ?? 0;

    expect($driven)->toBeGreaterThan(1000, "only {$driven} requests were sent")
        ->and($throttled / max(1, $driven))->toBeLessThan(
            0.5,
            sprintf('%d of %d responses were 429: the limiter answered most of this run, not the application', $throttled, $driven),
        );

    // And the surface answered with VARIETY rather than one status, which is what says the
    // payloads reached different code paths instead of one early refusal.
    expect(count($statuses))->toBeGreaterThan(
        2,
        'every fuzzed request got the same status ('.implode(', ', array_keys($statuses)).'): nothing was exercised',
    );
});

it('finds no 5xx with a hostile value in every PATH PARAMETER', function (): void {
    // A separate case because it is a different reader: a path parameter is bound by the
    // router, reaches `Route::parameter()` and is usually fed straight to a lookup, with no
    // form request between it and the controller. `PagerIdorTest` covers the cross-tenant
    // property of these ids; this covers the parsing of them.
    $seed = fuzzSeed();
    $routes = array_values(array_filter(fuzzRoutes(), static fn (array $r): bool => $r[2] !== []));

    expect($routes)->not->toBe([], 'no parameterised routes found at all');

    $this->actingForTenant(role: TenantRole::Owner);

    $failures = [];
    $driven = 0;

    foreach (fuzzCorpus($seed) as $label => $value) {
        // Only scalars can go in a path; an array has no spelling there.
        if (! is_scalar($value) && $value !== null) {
            continue;
        }

        ($this->flushRateLimits)();

        foreach ($routes as [$method, $template]) {
            // Truncated: a 64 kB path is a web-server limit rather than an application
            // property, and Symfony's test client builds the URI in memory where the real
            // limit would never be reached. 2 kB is past every real id and inside what a
            // server would accept.
            $scalar = substr((string) $value, 0, 2048);

            $response = $this->json($method, fuzzUri($template, $scalar));
            $driven++;

            if (fuzzIsRealServerError($template, $response->getStatusCode())) {
                $failures[] = sprintf(
                    '[%d] %s %s  with %s  (FUZZ_SEED=%d)',
                    $response->getStatusCode(), $method, $template, $label, $seed,
                );
            }
        }
    }

    expect($failures)->toBe([], count($failures)." 5xx response(s):\n  ".implode("\n  ", $failures))
        ->and($driven)->toBeGreaterThan(50, "only {$driven} parameterised requests were sent");
});

it('finds no 5xx with hostile HEADERS, which no body fuzz reaches', function (): void {
    // ⚠ THE THIRD READER, AND THE ONE A BODY FUZZ CANNOT TOUCH. `TenantResolver` reads a host,
    // `ResolvePrincipal` reads an `Authorization`, content negotiation reads `Accept`, and
    // `trustedproxy` reads `X-Forwarded-*`. Every one of those parses attacker-controlled
    // bytes before any validator runs.
    $seed = fuzzSeed();
    $this->actingForTenant(role: TenantRole::Owner);

    $hostile = [
        'Authorization' => ['Bearer ', 'Bearer |', 'Bearer zzz|q', 'Basic !!!', str_repeat('A', 8192)],
        'Accept' => ['*/*;q=zzz', 'application/'.str_repeat('x', 2048), 'text/html, application/json;q='],
        'Content-Type' => ['application/json; charset=nope', 'application/problem+json', 'multipart/form-data'],
        'X-Forwarded-For' => ['999.999.999.999', '127.0.0.1, '.str_repeat('8.8.8.8, ', 200), '::1%eth0'],
        'X-Forwarded-Proto' => ['gopher', ''],
        'Origin' => ['null', 'https://'.str_repeat('a', 2048).'.test', 'file://'],
        'Host' => ['', 'a'.str_repeat('.b', 200).'.test', '127.0.0.1:99999'],
        'Accept-Encoding' => ['gzip;q=-1', str_repeat('br, ', 500)],
        'Accept-Language' => ['ar;q=zz', str_repeat('en-GB, ', 500)],
        'Cookie' => ['=', 'a='.str_repeat('b', 8192)],
    ];

    $paths = ['/healthz', '/readyz', '/v1/auth/me', '/v1/members', '/v1/pager-sessions', '/v1/tenant', '/platform'];

    $failures = [];
    $driven = 0;

    foreach ($hostile as $header => $values) {
        foreach ($values as $value) {
            ($this->flushRateLimits)();

            // `HTTP_` plus the upper-snake name is Symfony's server-var spelling; `Host` and
            // `Content-Type` are the two it reads from their own keys instead.
            $key = match ($header) {
                'Host' => 'HTTP_HOST',
                'Content-Type' => 'CONTENT_TYPE',
                default => 'HTTP_'.strtoupper(str_replace('-', '_', $header)),
            };

            foreach ($paths as $path) {
                $response = $this->call('GET', $path, [], [], [], [$key => $value]);
                $driven++;

                if (fuzzIsRealServerError($path, $response->getStatusCode())) {
                    $failures[] = sprintf(
                        '[%d] GET %s  with %s: %s  (FUZZ_SEED=%d)',
                        $response->getStatusCode(), $path, $header, substr($value, 0, 60), $seed,
                    );
                }
            }
        }
    }

    expect($failures)->toBe([], count($failures)." 5xx response(s):\n  ".implode("\n  ", $failures))
        ->and($driven)->toBeGreaterThan(100, "only {$driven} header variations were sent");
});

it('WOULD catch a 5xx — the control, because every case above asserts an absence', function (): void {
    /*
     * ⚠ THE CONTROL FOR THIS WHOLE FILE. Three cases assert that no 5xx exists, and an empty
     * route list, a broken request helper or a status read from the wrong place would make all
     * three pass and report the strongest possible result. That is the vacuity shape `§4a`
     * records four instances of.
     *
     * A route that throws is registered and driven through the same path, and it must come
     * back 500. If this case fails, nothing above means anything.
     */
    Route::get('/_fuzz-control', function (): void {
        throw new RuntimeException('the control route, and its message must not reach the body');
    });

    $response = $this->getJson('/_fuzz-control');

    expect($response->getStatusCode())->toBe(500, 'a throwing route did not answer 5xx: the fuzz cases cannot fail')
        // And while here: the invariant holds on this one too. A control route is still a
        // route, and the exception message must not be in the body.
        ->and(str_contains((string) $response->getContent(), 'the control route'))
        ->toBeFalse('the exception message reached the response body');

    // ⚠ THE EXCEPTION IS NOT A HOLE, ASSERTED BOTH WAYS. `fuzzIsRealServerError()` excuses
    // exactly one pair, and an exception that covers nothing is a stale exemption while one
    // that covers too much is a silencer.
    expect(fuzzIsRealServerError('/readyz', 503))->toBeFalse('the designed 503 is being reported as a defect')
        ->and(fuzzIsRealServerError('/readyz', 500))->toBeTrue('a 500 on /readyz would be excused')
        ->and(fuzzIsRealServerError('/healthz', 503))->toBeTrue('the exception is not pinned to /readyz')
        ->and(fuzzIsRealServerError('/v1/members', 503))->toBeTrue();

    // And it is still LIVE: `/readyz` really does answer 503 in this suite, so the exception
    // is covering a real answer rather than a historical one.
    expect($this->getJson('/readyz')->getStatusCode())
        ->toBe(503, 'the /readyz exception has gone stale: it no longer answers 503 here');
});
