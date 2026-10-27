<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\Http\PagerSessionController;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Middleware\ShedLoad;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The five routes this task adds, as a surface: versioning, deny-by-default, validation,
 * and the status a caller actually receives.
 *
 * R-042 is here too -- "every unmapped 4xx renders as 403 Forbidden, so a request-format
 * error is answered with an authorization verdict". The row's own note says "the first
 * reachable instance arrives with Task 3's first endpoint", and the malformed-JSON case
 * below is that instance.
 */

it('serves every pager route under /v1 and by POST only', function (): void {
    $pager = [];

    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'pager-sessions')) {
            $pager[$route->uri()] = $route->methods();
        }
    }

    expect($pager)->toHaveCount(5);

    foreach ($pager as $uri => $methods) {
        // "Every public route is under /v1" (§3 row 2). A published path is never changed
        // in place, so this list is the contract rather than an inventory.
        expect($uri)->toStartWith('v1/pager-sessions');

        // POST only. These are not idempotent in the HTTP sense of GET, and the four
        // transitions change state; HEAD comes free with GET and neither should exist here.
        expect(array_values(array_diff($methods, ['POST'])))->toBe([], "{$uri} answers more than POST");
    }
});

it('denies every pager route when no tenant resolves, with no detail about why', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    // No actingForTenant(): the harness binds nothing, so the resolver answers null and
    // RequireTenantScope refuses. This is Task 2's deny-by-default, and §4 requires every
    // route built in Tasks 2-4 to be either on the customer token path or behind it.
    foreach ([
        ['POST', '/v1/pager-sessions', ['order_reference' => 'A-1']],
        ['POST', "/v1/pager-sessions/{$session}/ready", []],
        ['POST', "/v1/pager-sessions/{$session}/renotify", []],
        ['POST', "/v1/pager-sessions/{$session}/complete", []],
        ['POST', "/v1/pager-sessions/{$session}/cancel", []],
    ] as [$method, $route, $body]) {
        $response = $this->json($method, $route, $body);

        expect($response->status())->toBe(401, "{$route} was reachable with no tenant");

        // The 401 carries no detail: "no tenant could be resolved" and "that tenant is
        // not yours" are the same answer from outside, and a different one would tell a
        // caller which tenant ids exist.
        $response->assertJsonPath('type', ProblemType::Unauthenticated->value)
            ->assertJsonPath('title', ProblemType::Unauthenticated->title());

        expect((array) $response->json())->not->toHaveKey('errors');
    }

    // And nothing was written by any of them.
    $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('status'));

    expect($status)->toBe(PagerStatus::Waiting->value);
});

it('creates a session and returns a QR payload carrying the token in its fragment', function (): void {
    config(['pager.landing_url' => 'https://qr.example.test/p']);

    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $response = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-77']);

    expect($response->status())->toBe(201);

    $response->assertJsonPath('session.status', PagerStatus::Waiting->value)
        ->assertJsonPath('session.order_reference', 'A-77')
        ->assertJsonPath('session.renotify_count', 0)
        ->assertJsonPath('session.changed', true);

    $payload = (string) $response->json('qr_payload');
    [$visible, $fragment] = explode('#', $payload, 2);

    expect($visible)->toBe('https://qr.example.test/p')
        ->and($fragment)->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and(strlen($fragment))->toBe(PagerToken::LENGTH);

    // The session really exists, with the hash of that very token and a 2 h TTL.
    $row = $this->asRoot()->selectOne(
        'select encode(token_hash, \'hex\') as hash,
                extract(epoch from (expires_at - created_at)) as ttl_seconds
           from pager_sessions where id = ?',
        [(string) $response->json('session.id')],
    );

    expect((string) $row->hash)->toBe(hash('sha256', $fragment))
        ->and((int) round((float) $row->ttl_seconds))->toBe(7200);

    // Never cached: the one response in the API that carries a credential. The header
    // reads `no-store, private` -- the framework appends `private` -- so the assertion is
    // on the directive and not on the whole string.
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('validates the order reference against the column it is stored in', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    foreach ([
        'missing' => [],
        'empty' => ['order_reference' => ''],
        'too long' => ['order_reference' => str_repeat('A', 33)],
        'control character' => ['order_reference' => "A-1\n2"],
        'not a string' => ['order_reference' => ['A-1']],
    ] as $case => $body) {
        $response = $this->postJson('/v1/pager-sessions', $body);

        // 422 with the field named, rather than a 23514 or a 22001 from Postgres rendered
        // through the envelope -- which would tell the caller nothing about what to fix.
        expect($response->status())->toBe(422, "[{$case}] was accepted");

        $response->assertJsonPath('type', ProblemType::Validation->value)
            ->assertJsonPath('status', 422);

        expect((array) $response->json('errors'))->toHaveKey('order_reference');
    }

    // A 32-character reference is the boundary and is accepted.
    expect($this->postJson('/v1/pager-sessions', ['order_reference' => str_repeat('A', 32)])->status())->toBe(201);
});

it('answers a malformed JSON body as a 422 naming the field, not an authorization verdict — R-042', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $response = $this->call(
        'POST',
        '/v1/pager-sessions',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{"order_reference": "A-1"',   // deliberately unterminated
    );

    // R-042'S REACHABILITY CLAIM IS FALSE ON LARAVEL 13, and this case is where it was
    // measured. The row says "Laravel throws 400 for malformed JSON, so the first Task 3
    // endpoint that accepts a body answers 'That is not permitted.' to a caller who sent
    // broken JSON". Measured 2026-10-27: Request::json() on an unterminated body returns
    // an EMPTY ARRAY and throws nothing -- so does Symfony's toArray() -- so no 400 is
    // raised anywhere, the FormRequest sees a missing field, and the answer is a 422 that
    // names `order_reference`. Which is a better answer than the row hoped for: the
    // caller is told what to fix.
    //
    // The DEFECT the row names was still real and is still fixed -- `default =>
    // Forbidden` mis-rendered every unmapped 4xx, measured through the renderer by QA and
    // re-measured in ProblemEnvelopeTest. What is wrong is only the route by which it
    // becomes reachable. PLAN.md's R-042 row is corrected in place rather than carried.
    expect($response->status())->toBe(422);

    $response->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('status', 422);

    expect((array) $response->json('errors'))->toHaveKey('order_reference');

    // And nothing from the parser or the driver reaches the body at any APP_DEBUG.
    expect((string) json_encode($response->json()))
        ->not->toContain('Syntax')
        ->not->toContain('json_decode')
        ->not->toContain('SQLSTATE');
});

it('runs the four transitions end to end through HTTP', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $create = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-88']);
    $session = (string) $create->json('session.id');

    $ready = $this->postJson("/v1/pager-sessions/{$session}/ready");
    expect($ready->status())->toBe(200);
    $ready->assertJsonPath('session.status', PagerStatus::Ready->value)
        ->assertJsonPath('session.changed', true);

    $renotify = $this->postJson("/v1/pager-sessions/{$session}/renotify");
    expect($renotify->status())->toBe(200);
    $renotify->assertJsonPath('session.renotify_count', 1);

    // A repeat of ready is 200 and changed=false -- idempotent, not an error, so a
    // dropped response is safe to retry.
    $again = $this->postJson("/v1/pager-sessions/{$session}/ready");
    expect($again->status())->toBe(200);
    $again->assertJsonPath('session.changed', false);

    $complete = $this->postJson("/v1/pager-sessions/{$session}/complete");
    expect($complete->status())->toBe(200);
    $complete->assertJsonPath('session.status', PagerStatus::Completed->value);

    // Terminal: cancelling a completed session is a 409, because the request is
    // well-formed and the STATE is what makes it impossible.
    $cancel = $this->postJson("/v1/pager-sessions/{$session}/cancel");
    expect($cancel->status())->toBe(409);
    $cancel->assertJsonPath('type', ProblemType::Conflict->value)
        ->assertJsonPath('title', ProblemType::Conflict->title());
});

it('keeps the refusal body free of status values, driver text and SQL', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $create = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-99']);
    $session = (string) $create->json('session.id');

    $this->postJson("/v1/pager-sessions/{$session}/cancel");

    $body = (string) json_encode($this->postJson("/v1/pager-sessions/{$session}/ready")->json());

    // PagerTransitionDenied's message names status values, which is safe for the LOG --
    // and it still must not reach the body: Conflict's title is a fixed phrase, which is
    // the whole point of the envelope (§4 › Edge & ops, amended for response bodies).
    expect($body)->not->toContain('cannot move to')
        ->not->toContain('Allowed from here')
        ->not->toContain('pager_sessions')
        ->not->toContain('SQLSTATE')
        ->not->toContain('qrpager_');
});

it('answers 404 for a session id that is not a uuid, through the unknown-path fallback', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    // whereUuid() makes a non-uuid id a routing miss rather than a uuid cast error inside
    // a query, and it keeps the tighter unknown-path rate limiter in front of garbage ids
    // instead of the api bucket.
    $response = $this->postJson('/v1/pager-sessions/not-a-uuid/ready');

    expect($response->status())->toBe(404);
    $response->assertJsonPath('type', ProblemType::NotFound->value);
});

it('refuses to run a pager action with no tenant bound, so a route off the api group fails loudly', function (): void {
    // UNREACHABLE THROUGH ROUTING, which is the point of asserting it rather than
    // trusting it: RequireTenantScope answers 401 before any route runs when no tenant
    // resolves, so this guard exists for a FUTURE route mounted without that middleware.
    // §4 asks for that direction -- fail loudly rather than run unscoped -- and a guard
    // nothing exercises is a guard nobody notices breaking.
    //
    // The controller is resolved and called directly, with nothing bound, which is
    // exactly the state such a route would produce.
    $controller = app(PagerSessionController::class);

    expect(fn () => $controller->ready(Request::create('/v1/pager-sessions/x/ready', 'POST'), 'x'))
        ->toThrow(RuntimeException::class, 'No tenant is bound for this request');
});

/*
 * QA L-7. §11's failure checklist is timeout, retry, duplicate, crash mid-way. Retry is
 * PagerIdempotencyTest, duplicate is its unique-index case, crash mid-way is
 * PagerTransactionTest -- and TIMEOUT had no test on this task at all.
 *
 * The app role carries lock_timeout = 2s (§2.1) and `select ... for update` in
 * PagerSessions is the only statement in the project that waits on another transaction,
 * so this is the one path that can reach it. Measured at review time: a competing holder
 * made markReady fail after 2.02s with SQLSTATE 55P03 and the envelope answered
 * **500 ServerError**, because only 57014 was mapped. Both are timeouts the role sets on
 * purpose, so two spellings of "we waited and gave up" answered differently. 55P03 now
 * joins 57014 on Unavailable.
 */
it('answers 503 and not 500 when the row lock times out — QA L-7', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $this->actingForTenant($tenant);

    $holder = proc_open(
        [PHP_BINARY, base_path('tests/Support/hold-pager-session-lock.php'), $tenant, $session, '6000'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($holder)->not->toBeFalse('could not start the lock holder');

    try {
        // BLOCK on the holder's own signal rather than sleeping: a fixed sleep either
        // makes the test slow or makes it flaky, and this line is how the script says
        // the lock is actually held.
        $signal = (string) fgets($pipes[1]);

        expect(trim($signal))->toBe('locked', "the lock holder did not acquire: [{$signal}]");

        $started = microtime(true);
        $response = $this->postJson("/v1/pager-sessions/{$session}/ready");
        $waited = microtime(true) - $started;

        expect($response->status())->toBe(503, 'a lock timeout did not render as Unavailable')
            ->and($response->json('type'))->toBe(ProblemType::Unavailable->value)
            ->and($response->json('title'))->toBe(ProblemType::Unavailable->title());

        // The LOWER bound only, which is F10's lesson and R-014's: the role's
        // lock_timeout is 2s, so a refusal that arrives sooner than that did not come
        // from the timeout and the test would be passing for the wrong reason. No upper
        // bound -- a loaded host turned a passing proof red at 27.6s on this branch.
        expect($waited)->toBeGreaterThan(1.5, 'the refusal arrived too early to be the lock timeout');

        // And the body carries nothing: no SQLSTATE, no SQL, no role name.
        $body = (string) $response->getContent();

        expect($body)->not->toContain('55P03')
            ->not->toContain('SQLSTATE')
            ->not->toContain('qrpager_')
            ->not->toContain('pager_sessions');
    } finally {
        if (is_resource($holder)) {
            proc_terminate($holder);
            proc_close($holder);
        }
    }
});

/**
 * Every middleware class that will actually run for a route, with groups expanded.
 *
 * Route::gatherMiddleware() returns the route's OWN middleware plus the NAMES of the
 * groups it is in -- so `RequireTenantScope::class` is not in that list for a staff route
 * at all: the string `api` is, and the class is inside it. The first version of the case
 * below compared against the unexpanded list and reported that `pager.sessions.store` was
 * NOT behind RequireTenantScope, which is the opposite of true. A test that reads the
 * route table has to resolve the table the way the kernel does.
 *
 * One level of expansion is enough and is asserted as such: bootstrap/app.php's two groups
 * (`api`, `customer`) contain classes only, never another group name.
 *
 * @return list<string>
 */
function effectiveMiddleware(Illuminate\Routing\Route $route): array
{
    // RESOLVE THE HTTP KERNEL FIRST, or the groups are not there yet. bootstrap/app.php
    // registers them inside withMiddleware(), which runs when the HTTP kernel is built --
    // so in a test that has made no request, getMiddlewareGroups() returns the framework's
    // defaults and `api` is absent. Measured: without this line the expansion below found
    // no group named `api`, returned the literal string, and reported that
    // pager.sessions.store was NOT behind RequireTenantScope. Worse, the two cases after
    // it still PASSED -- for the wrong reason, because the middleware they look for
    // happens to sit on the route rather than in the group. A vacuous pass is the failure
    // mode to design against here, so the assertion below makes an empty expansion fatal.
    app()->make(Kernel::class);

    $groups = app('router')->getMiddlewareGroups();

    expect($groups)->toHaveKeys(['api', 'customer', 'probe'],
        'the application middleware groups are not registered, so this expansion proves nothing');

    $resolved = [];

    foreach ($route->gatherMiddleware() as $entry) {
        if (is_string($entry) && isset($groups[$entry])) {
            foreach ($groups[$entry] as $inner) {
                expect(isset($groups[$inner]))->toBeFalse("the group [{$entry}] nests the group [{$inner}]");

                $resolved[] = (string) $inner;
            }

            continue;
        }

        $resolved[] = (string) $entry;
    }

    return $resolved;
}

it('mounts the customer surface off RequireTenantScope, and the staff surface on it', function (): void {
    // §4 › Tenancy, "two authentication surfaces, never confused", as a property of the
    // ROUTE TABLE rather than of a request. A feature test can show that a customer route
    // answers 200 without a tenant; this shows WHY, and it is the thing that would catch
    // somebody moving routes/customer.php into the api group -- after which every customer
    // request would be a 401 and this project's most important journey would be dead.
    $customer = [
        'pager.customer.session',
        'pager.customer.status',
        'pager.customer.subscribe',
        'pager.customer.unsubscribe',
    ];

    $staff = [
        'pager.sessions.store',
        'pager.sessions.ready',
        'pager.sessions.renotify',
        'pager.sessions.complete',
        'pager.sessions.cancel',
    ];

    foreach ($customer as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("the route {$name} does not exist");
        expect(in_array(RequireTenantScope::class, effectiveMiddleware($route), true))
            ->toBeFalse("{$name} is behind RequireTenantScope, so every real customer would get a 401");
    }

    foreach ($staff as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("the route {$name} does not exist");
        expect(in_array(RequireTenantScope::class, effectiveMiddleware($route), true))
            ->toBeTrue("{$name} is NOT behind RequireTenantScope, so a staff route is unauthenticated");
    }
});

it('sheds the live timer and nothing else on the customer surface', function (): void {
    // §4 › Edge & ops: "Load shedding drops nice-to-haves (live timer, analytics) BEFORE
    // alerts." The polling endpoint IS the live timer, by that clause's own words, and the
    // other three are how a customer comes to receive an alert at all -- so shedding them
    // would drop the alert first and the nice-to-have never.
    //
    // Asserted on the route table because it is an ASYMMETRY, and an asymmetry nobody
    // asserts is a line somebody tidies into consistency.
    $expected = [
        'pager.customer.status' => true,
        'pager.customer.session' => false,
        'pager.customer.subscribe' => false,
        'pager.customer.unsubscribe' => false,
    ];

    foreach ($expected as $name => $shouldShed) {
        $middleware = effectiveMiddleware(Route::getRoutes()->getByName($name));

        expect(in_array(ShedLoad::class, $middleware, true))->toBe(
            $shouldShed,
            $shouldShed
                ? "{$name} is the live timer and must shed first"
                : "{$name} is on the alert path and must not shed",
        );
    }
});

it('gives each customer route its own rate-limit bucket, and never the api default', function (): void {
    // Three routes, three buckets, three different sizes -- the Postgres-backed scan is
    // 30/minute while the Redis-only poll is 240. A group-level default would silently add
    // a fourth count to every request and make the three indistinguishable.
    $expected = [
        'pager.customer.session' => 'pager-token',
        'pager.customer.status' => 'pager-poll',
        'pager.customer.subscribe' => 'pager-subscribe',
        'pager.customer.unsubscribe' => 'pager-subscribe',
    ];

    foreach ($expected as $name => $bucket) {
        $middleware = effectiveMiddleware(Route::getRoutes()->getByName($name));

        expect($middleware)->toContain(RateLimit::class.':'.$bucket)
            ->and($middleware)->not->toContain(RateLimit::class);
    }
});

it('takes no route parameter anywhere on the customer surface, which is the IDOR answer', function (): void {
    // The session is whatever the token resolves to, so the parameter somebody would
    // tamper with does not exist. There is nothing to prove inaccessible across tenants
    // because there is nothing to ask for.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'pager.customer.')) {
            continue;
        }

        expect($route->parameterNames())->toBe(
            [],
            $route->getName().' takes a route parameter, which is an id an attacker can vary',
        );
    }
});
