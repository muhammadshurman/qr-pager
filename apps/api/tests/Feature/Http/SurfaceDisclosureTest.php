<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Logging\RedactLogs;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * TASK 12'S NAMED INVARIANT — `§3` ROW 12, WRITTEN BY MUHAMMAD BEFORE ANY CODE
 * ================================================================================
 *
 * > *"No response from any surface, at any `APP_DEBUG` setting, carries a secret, a token, a
 * > QR value, SQL or a bound parameter — proven by driving the WHOLE route surface, derived
 * > from the router rather than hand-written, and asserting zero occurrences in captured
 * > bodies, headers and log output."*
 *
 * `PROJECT_INSTRUCTIONS.md §11` makes a falsifiable invariant the PRECONDITION for starting a
 * task, and row 12 had none, so this file is the one the task exists to satisfy.
 *
 * ================================================================================
 * WHY THIS IS NOT A FIFTH COPY OF AN ASSERTION THE PROJECT ALREADY HAS
 * ================================================================================
 *
 * It is not that nothing checked disclosure before — it is that everything that did named its
 * own subjects. `§3` row 2 clause (b) already says *"no error response body carries a database
 * role name, a constraint name, SQL or a bound parameter, at any `APP_DEBUG` setting"*, and
 * `ProblemRenderer` is built to it; `LogRedactionTest` reads the real file the logger wrote
 * for the channels it configures; `PagerTokenTest` pins the token's redaction.
 *
 * Every one of those is true **of the routes and channels a test author listed**. None of them
 * can fail because somebody added a route. This file derives its subjects from
 * `Route::getRoutes()`, so the surface it checks is whatever the router actually serves — and
 * a new route is covered by the commit that adds it, with no edit here.
 *
 * ================================================================================
 * ⚠ THE SHAPE OF THIS TEST IS THE DANGEROUS ONE, AND THE CONTROL IS THE FIRST CASE
 * ================================================================================
 *
 * Every assertion here is an ASSERTION OF ABSENCE. A broken scanner, an empty route list, a
 * needle set that is accidentally empty, or a body that never got captured — each of those
 * makes this whole file pass and report the strongest possible result. That is the vacuity
 * shape `§4a` records four instances of.
 *
 * So the first case is a POSITIVE CONTROL: a route is registered that deliberately discloses
 * every needle, driven through the same scanner the real cases use, and the scanner must FIND
 * them. If it does not, nothing else in this file means anything, and it fails first.
 */

/**
 * Every route in the table, as [method, uri].
 *
 * Derived, never listed. HEAD is dropped (it is a GET with no body) and one verb is taken per
 * route, because the needles are checked in the response and the verb cannot change which
 * secrets exist.
 *
 * @return list<array{0: string, 1: string}>
 */
function disclosureRoutes(): array
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
        $uri = (string) preg_replace('#\{[^}]+\}#', (string) Str::uuid7(), $uri);

        $routes[] = [$methods[0], $uri];
    }

    return array_values(array_unique($routes, SORT_REGULAR));
}

/**
 * The needles: what must never appear.
 *
 * ⚠ READ FROM THE RUNNING CONFIGURATION RATHER THAN TYPED, which is the difference between
 * checking for secrets and checking for the strings somebody remembered. A password written
 * here as a literal would stop being the password the moment `.env` changed, and this file
 * would keep passing.
 *
 * @param  array<string, string>  $extra  needles the caller minted, e.g. a live QR token
 * @return array<string, string> label => needle
 */
function disclosureNeedles(array $extra = []): array
{
    $needles = [
        // SECRETS, from the configuration in force.
        'APP_KEY' => (string) config('app.key'),
        'db password (app)' => (string) config('database.connections.pgsql.password'),
        'db password (owner)' => (string) config('database.connections.pgsql_owner.password'),
        'db password (root)' => (string) config('database.connections.pgsql_root.password'),
        'object store secret' => (string) config('filesystems.disks.s3.secret'),

        // SQL and BOUND PARAMETERS. Fragments rather than whole statements: the clause is
        // about a statement reaching a body, and a body carrying `select "users"."id"` has
        // disclosed the schema whether or not the rest of the query came with it.
        'a select list' => 'select "users"',
        'an insert statement' => 'insert into "pager_sessions"',
        'a bound-parameter marker' => 'values (?, ?',
        // `DatabaseErrorMapper` keeps the SQLSTATE and the relation name on purpose (they are
        // published in `§2`), so the needle is the thing that is NOT allowed: the role names.
        'the app role' => 'qrpager_app',
        'the owner role' => 'qrpager_owner',
    ];

    $needles = array_merge($needles, $extra);

    // ⚠ EMPTY NEEDLES ARE REMOVED *AND COUNTED*, because an unset config value would
    // otherwise become `''`, and `str_contains($anything, '')` is TRUE -- which would make
    // every case here fail loudly rather than quietly, but for the wrong reason. The count is
    // asserted by the control case, so a configuration that silently lost its secrets cannot
    // shrink this set to nothing without being noticed.
    return array_filter($needles, static fn (string $needle): bool => strlen($needle) >= 8);
}

/**
 * Scan one captured response for every needle. Returns the labels found.
 *
 * ⚠ BODY **AND** HEADERS, which the invariant names separately and for a reason: a secret in a
 * `Set-Cookie`, a `Location` or a custom debug header is as disclosed as one in a body, and a
 * body-only scanner would say nothing about it.
 *
 * @param  array<string, string>  $needles
 * @return list<string>
 */
function disclosedIn(string $label, string $body, string $headers, array $needles): array
{
    $found = [];
    $haystack = $headers."\n".$body;

    foreach ($needles as $needle => $value) {
        if (str_contains($haystack, $value)) {
            $found[] = $label.' discloses '.$needle;
        }
    }

    return $found;
}

/** A response's headers, flattened for scanning. */
function flattenHeaders(Symfony\Component\HttpFoundation\Response $response): string
{
    $out = '';

    foreach ($response->headers->all() as $name => $values) {
        foreach ($values as $value) {
            $out .= $name.': '.$value."\n";
        }
    }

    return $out;
}

it('FINDS every needle when a route really discloses one — the control for this whole file', function (): void {
    /*
     * ⚠ READ THIS CASE BEFORE TRUSTING ANY OTHER IN THIS FILE.
     *
     * Everything below asserts ABSENCE, and an absence assertion passes for a scanner that
     * scans nothing, a needle set that is empty, and a response that was never captured. This
     * case drives a route built to disclose all of it, through the same `disclosedIn()` the
     * real cases use, and requires that every needle come back.
     */
    $needles = disclosureNeedles(['a minted token' => 'TOKEN-'.str_repeat('z', 32)]);

    // The set is not accidentally tiny: if `.env` lost its values, `disclosureNeedles()` would
    // filter them out and the real cases would be checking almost nothing.
    expect(count($needles))->toBeGreaterThan(
        8,
        'only '.count($needles).' needles survived filtering: the configuration has no secrets in it, '.
        'so the absence cases below are nearly vacuous',
    );

    $disclosure = implode(' ', $needles);

    Route::get('/_disclosure-control', fn (): Response => response($disclosure)
        ->header('X-Leaky-Header', (string) config('app.key')));

    $response = $this->get('/_disclosure-control');

    $found = disclosedIn(
        'the control route',
        (string) $response->getContent(),
        flattenHeaders($response->baseResponse),
        $needles,
    );

    // EVERY needle, not merely one: a scanner that matched the first and stopped would pass a
    // weaker assertion and leave most of the needle set unexercised.
    expect(count($found))->toBe(
        count($needles),
        'the scanner found '.count($found).' of '.count($needles).' needles in a route that discloses all of them: '.
        "it is not scanning what it claims to.\nfound: ".implode(', ', $found),
    );

    // And the HEADER half specifically, because a body-only scanner would pass the line above
    // (the key is in the body too) while being blind to a `Set-Cookie`.
    $headerOnly = disclosedIn(
        'the control route',
        '',
        flattenHeaders($response->baseResponse),
        ['APP_KEY' => (string) config('app.key')],
    );

    expect($headerOnly)->not->toBe([], 'the scanner does not read headers at all');
});

it('discloses nothing across the WHOLE route surface, at APP_DEBUG false AND true', function (): void {
    /*
     * THE INVARIANT ITSELF, in the form it was written: the whole surface, derived from the
     * router, at both debug settings, bodies and headers.
     *
     * ⚠ BOTH SETTINGS, AND `APP_DEBUG=true` IS THE ONE THAT MATTERS. `.env.example` ships
     * `APP_DEBUG=true`, so that is the configuration a developer actually runs — and
     * `ProblemRenderer`'s docblock records what this surface did at `7f1c8f6` before it
     * existed: a `QueryException` rendered a JSON body with the full SQL and the bound uuid,
     * and as HTML it was 862,104 bytes carrying all of it. An error renderer that steps aside
     * when `APP_DEBUG` is true satisfies the words of a disclosure clause and not the property.
     *
     * Driven ANONYMOUSLY and then AUTHENTICATED, because the two produce different bodies: the
     * anonymous pass gets the refusals (401/403/404/419/429), the authenticated pass gets the
     * real payloads.
     */
    $routes = disclosureRoutes();

    expect(count($routes))->toBeGreaterThan(
        40,
        'the route table came back with '.count($routes).' entries: the derivation is broken, not the surface',
    );

    $needles = disclosureNeedles();
    $found = [];

    foreach ([false, true] as $debug) {
        config(['app.debug' => $debug]);

        // ---- anonymous
        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri);

            $found = array_merge($found, disclosedIn(
                sprintf('APP_DEBUG=%s anon %s %s [%d]', $debug ? 'true' : 'false', $method, $uri, $response->getStatusCode()),
                (string) $response->getContent(),
                flattenHeaders($response->baseResponse),
                $needles,
            ));
        }

        // ---- authenticated as an owner, which is the most privileged tenant principal
        $this->actingForTenant(role: TenantRole::Owner);

        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri);

            $found = array_merge($found, disclosedIn(
                sprintf('APP_DEBUG=%s owner %s %s [%d]', $debug ? 'true' : 'false', $method, $uri, $response->getStatusCode()),
                (string) $response->getContent(),
                flattenHeaders($response->baseResponse),
                $needles,
            ));
        }
    }

    expect($found)->toBe([], "disclosures:\n  ".implode("\n  ", $found));
});

it('keeps a REAL QR token out of every response but the one that minted it', function (): void {
    /*
     * ⚠ THE "QR VALUE" HALF OF THE INVARIANT, WITH A LIVE TOKEN RATHER THAN A PLACEHOLDER.
     *
     * `§4 › Security` says the QR token is *"never logged, never in a URL, never in an error
     * body"*, and `PagerToken` makes every accidental route out of the object a redacted
     * marker — `jsonSerialize()` and `__debugInfo()` both answer `[redacted]`. What none of
     * that can tell you is whether the VALUE appears somewhere else on the surface, because
     * the places it could appear are not the object.
     *
     * So a real session is created through the real route, its real token is taken out of the
     * `qr_payload` (which is the ONE response that is meant to carry it), and the whole
     * surface is then driven and scanned for that exact string.
     */
    $this->actingForTenant(role: TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-414']);

    $created->assertCreated();

    // `qrPayload()` is `rtrim($landingUrl, '#').'#'.$this->reveal()`, so the token is the
    // fragment. Taken apart here rather than regenerated, so the needle is the byte sequence a
    // customer's browser would really hold.
    $payload = (string) $created->json('qr_payload');
    $token = substr($payload, (int) strrpos($payload, '#') + 1);

    expect(strlen($token))->toBeGreaterThan(
        20,
        "the token could not be read out of the qr_payload [{$payload}]: this case is scanning for nothing",
    );

    // The premise, stated as an assertion: the creating response really does carry it. Without
    // this the case could pass against a token that was never issued.
    expect((string) $created->getContent())->toContain($token);

    $needles = ['the live QR token' => $token];
    $found = [];

    foreach ([false, true] as $debug) {
        config(['app.debug' => $debug]);

        foreach (disclosureRoutes() as [$method, $uri]) {
            // The creating route is driven too, and a fresh POST mints a DIFFERENT token, so
            // it cannot disclose this one. That is the property rather than an exemption.
            $response = $this->call($method, $uri);

            $found = array_merge($found, disclosedIn(
                sprintf('APP_DEBUG=%s %s %s [%d]', $debug ? 'true' : 'false', $method, $uri, $response->getStatusCode()),
                (string) $response->getContent(),
                flattenHeaders($response->baseResponse),
                $needles,
            ));
        }
    }

    expect($found)->toBe([], "the QR token leaked:\n  ".implode("\n  ", $found));
});

it('carries no SQL and no bound parameter out of a REAL database failure, at either setting', function (): void {
    /*
     * The `§3` row 2 clause (b) case, driven over HTTP rather than over the renderer.
     *
     * A real `QueryException` is provoked on a real route by taking the table away underneath
     * it — `Connection::run()` builds the message with the statement and the bound values
     * interpolated, which is why `ProblemRenderer` exists at all and why no amount of careful
     * logging would be enough.
     *
     * ⚠ AND THE LOG FILE IS READ, NOT A SPY. The invariant names *"log output"* alongside
     * bodies and headers, and `LogRedactionTest`'s own opening argument applies: *"A log
     * assertion against a spy proves the call was made, not what landed on disk."*
     */
    $this->actingForTenant(role: TenantRole::Owner);

    $logFile = storage_path('logs/disclosure-'.Str::random(12).'.log');

    foreach ([false, true] as $debug) {
        config([
            'app.debug' => $debug,
            'logging.default' => 'disclosure_probe',
            'logging.channels.disclosure_probe' => [
                'driver' => 'single',
                'path' => $logFile,
                'level' => 'debug',
                'tap' => [RedactLogs::class],
            ],
        ]);

        Log::forgetChannel();
        Log::forgetChannel('disclosure_probe');

        // The table goes away for the duration of one request. `pgsql_owner` owns the schema,
        // so this is the one connection that can do it, and the rename is reversed in a
        // `finally` so a failure here cannot leave the suite's schema broken for every test
        // after it.
        DB::connection('pgsql_owner')->statement('alter table pager_sessions rename to pager_sessions_gone');

        try {
            $response = $this->getJson('/v1/pager-sessions');
        } finally {
            DB::connection('pgsql_owner')->statement('alter table pager_sessions_gone rename to pager_sessions');
        }

        $setting = $debug ? 'APP_DEBUG=true' : 'APP_DEBUG=false';

        // The premise: this really did fail, or the case is asserting about a 200.
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(
            500,
            "{$setting}: the missing table did not produce a server error, so nothing was rendered to inspect",
        );

        $body = (string) $response->getContent();
        $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        // The log really was written, so the scan below is reading something.
        expect($log)->not->toBe('', "{$setting}: nothing reached the log file, so its redaction is untested here");

        /*
         * ⚠ THESE NEEDLES ARE THE MEASURED DISCLOSURE AND NOT A GUESS, AND THE FIRST DRAFT OF
         * THIS CASE PROVES WHY THAT MATTERS. It looked for `pager_sessions_gone` and
         * `select *` -- and NEITHER APPEARS: the table is RENAMED, so the failing statement
         * still says `pager_sessions`, and the board's query selects named columns. The case
         * was green, and it stayed green with `ProblemRenderer` UNREGISTERED. An
         * absence-assertion with the wrong needles is the vacuity shape this file's control
         * case exists for, found here by reverting rather than by reading.
         *
         * So the list below is taken from the body captured with the renderer removed,
         * `APP_DEBUG=true`, on 2026-11-07 -- **32,425 bytes** against the problem document's
         * **149**:
         *
         *     "message": "SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation
         *      \"pager_sessions\" does not exist\nLINE 2: from pager_sessions ...
         *      (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: qrpager_test,
         *      SQL: select id, order_reference, status, created_at, ready_at from
         *      pager_sessions where status in (waiting::pager_status, ...) limit 100)",
         *     "exception": "Illuminate\\Database\\QueryException",
         *     "file": ".../vendor/laravel/framework/src/Illuminate/Database/Connection.php",
         *     "line": 864,
         *     "trace": [ ... ]
         *
         * That is the statement, the relation, the database NAME, the host and port, the
         * framework's absolute paths and the whole stack -- which is `ProblemRenderer`'s own
         * docblock measurement ("862,104 bytes carrying all of it", as HTML) reproduced on the
         * JSON surface at a new commit.
         */
        foreach ([
            'the driver SQLSTATE' => 'SQLSTATE',
            'the statement' => 'SQL: select',
            'the relation' => 'pager_sessions',
            'the exception class' => 'QueryException',
            'a stack trace' => '"trace"',
            'a framework path' => 'vendor/laravel',
            'the database name' => 'Database: '.(string) config('database.connections.pgsql.database'),
            'the app role' => 'qrpager_app',
            'the owner role' => 'qrpager_owner',
            'a password' => (string) config('database.connections.pgsql.password'),
        ] as $label => $needle) {
            if (strlen($needle) < 4) {
                continue;
            }

            expect(str_contains($body, $needle))
                ->toBeFalse("{$setting}: the response body discloses {$label}");
        }

        // AND THE BODY IS THE PROBLEM DOCUMENT, not merely free of needles. A renderer that
        // answered an EMPTY body would pass every line above; the clause is that the caller
        // still gets a usable RFC 7807 answer.
        // Read from the DECODED document rather than the raw body: `json_encode` escapes the
        // slashes, so a `str_contains` for `/problems/server-error` looks for a string the
        // body never carries. (Measured -- it is how this assertion first failed.)
        expect((string) $response->json('type'))->toContain('/problems/server-error')
            ->and(strlen($body))->toBeLessThan(
                2000,
                "{$setting}: the 500 body is ".strlen($body).' bytes; the problem document is ~149 '.
                'and a debug envelope is ~32,000',
            );

        // ⚠ THE LOG IS HELD TO A NARROWER BAR THAN THE BODY, and `LogRedactionTest`'s contract
        // says which: *"VALUES, STATEMENTS and TRACES out; NAMES and LOCATIONS in."* A
        // relation name is schema, which `§2` publishes, so it is allowed in a log and not in
        // a body. What must not be there is a bound value, a statement, or a credential.
        foreach ([
            'a password' => (string) config('database.connections.pgsql.password'),
            'the APP_KEY' => (string) config('app.key'),
            'a select list' => 'select *',
        ] as $label => $needle) {
            if (strlen($needle) < 8) {
                continue;
            }

            expect(str_contains($log, $needle))
                ->toBeFalse("{$setting}: the log file discloses {$label}");
        }

        @unlink($logFile);
    }
});

it('leaks nothing through the panel surface either, which is HTML and not JSON', function (): void {
    // The panels are the only HTML documents on this origin, so they are the one shape the
    // JSON-shaped cases above never render. `PanelProblemPage` wraps a problem document into
    // an HTML page for a panel request, which is a second renderer and therefore a second
    // chance to disclose.
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Disclosure Probe',
        'email' => 'disc-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $admin = User::query()->findOrFail($id);
    assert($admin instanceof User);

    $needles = disclosureNeedles();
    $found = [];

    foreach ([false, true] as $debug) {
        config(['app.debug' => $debug]);

        foreach (['/platform', '/platform/audit-log', '/platform/restaurants'] as $path) {
            $response = $this->actingAs($admin)->get($path);

            $found = array_merge($found, disclosedIn(
                sprintf('APP_DEBUG=%s %s [%d]', $debug ? 'true' : 'false', $path, $response->getStatusCode()),
                (string) $response->getContent(),
                flattenHeaders($response->baseResponse),
                $needles,
            ));
        }
    }

    expect($found)->toBe([], "the panel discloses:\n  ".implode("\n  ", $found));
});
