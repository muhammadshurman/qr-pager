<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\Http\PagerSessionController;
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
