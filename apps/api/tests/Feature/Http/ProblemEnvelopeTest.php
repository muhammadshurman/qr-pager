<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerSessionNotFound;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerTransitionDenied;
use App\Platform\Database\UnsafeDatabaseRoleException;
use App\Platform\Http\ProblemRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/*
 * Gate clause (b), approved on 2026-10-26: "no error response body carries a database
 * role name, a constraint name, SQL or a bound parameter, proven against captured
 * response bodies." Plus R-007's Task 2 half and R-021.
 *
 * Every case asserts against the CAPTURED BODY, not against the mapper's return value:
 * what was broken at 7f1c8f6 was the rendering, and a test of the mapper alone would
 * have passed against the leak.
 *
 * APP_DEBUG is forced true in each case. It is the configuration .env.example ships,
 * it is what made R-021 reachable, and the §4 amendment says "at any APP_DEBUG
 * setting" -- so testing only the false case would test the easy half.
 */

beforeEach(function (): void {
    // Every /v1 route is deny-by-default behind RequireTenantScope, so a request with
    // no resolved tenant is a 401 that never reaches the route under test. The tenant
    // comes from the harness binding and never from the request (B5).
    $this->actingForTenant();

    config(['app.debug' => true]);
});

/** A route that exists only for this file, so no production surface is added to throw from. */
function throwingRoute(string $uri, Closure $thrower): void
{
    Route::middleware('api')->prefix('v1')->get($uri, $thrower);
}

it('renders a problem document, not Laravel debug output, for an unexpected fault', function (): void {
    throwingRoute('probe-server-error', fn () => throw new RuntimeException('internal detail'));

    $response = $this->getJson('/v1/probe-server-error');

    $response->assertStatus(500)
        ->assertJsonPath('type', ProblemType::ServerError->value)
        ->assertJsonPath('title', ProblemType::ServerError->title())
        ->assertJsonPath('status', 500)
        ->assertJsonPath('instance', '/v1/probe-server-error');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->getContent())->not->toContain('internal detail')
        ->and($response->getContent())->not->toContain('RuntimeException');
});

it('leaks no SQL and no bound parameter from a real driver failure', function (): void {
    $bound = (string) Str::uuid7();

    throwingRoute('probe-query', fn () => DB::select(
        'select * from no_such_table_for_the_envelope_test where id = ?', [$bound],
    ));

    $body = (string) $this->getJson('/v1/probe-query')->getContent();

    // All four of these were in the body at 7f1c8f6, measured.
    expect($body)
        ->not->toContain('no_such_table_for_the_envelope_test')
        ->not->toContain($bound)
        ->not->toContain('select')
        ->not->toContain('SQLSTATE');
});

it('maps 23514 without returning the constraint name or the SQL — R-007', function (): void {
    // The real constraint, from the real schema: users_email_is_lowercase. R-007's
    // condition is this exact one, and the name itself discloses a table and a column.
    throwingRoute('probe-check', fn () => DB::connection('pgsql_owner')->table('users')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'Check Violation',
        'email' => 'NOT-LOWER-CASED@example.test',
    ]));

    $response = $this->getJson('/v1/probe-check');
    $body = (string) $response->getContent();

    $response->assertStatus(422)->assertJsonPath('type', ProblemType::Validation->value);

    expect($body)
        ->not->toContain('users_email_is_lowercase')
        ->not->toContain('check constraint')
        ->not->toContain('NOT-LOWER-CASED')
        ->not->toContain('insert into');
});

it('maps 42501 to a forbidden problem that reveals nothing about the other tenant', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $session = $this->makeSession($alpha);

    throwingRoute('probe-rls', fn () => withTenant($alpha, fn () => DB::table('pager_sessions')
        ->where('id', $session)
        ->update(['tenant_id' => $beta])));

    $response = $this->getJson('/v1/probe-rls');

    $response->assertStatus(403)->assertJsonPath('type', ProblemType::Forbidden->value);

    expect((string) $response->getContent())
        ->not->toContain($beta)
        ->not->toContain('pager_sessions')
        ->not->toContain('policy');
});

it('renders a generic 500 for UnsafeDatabaseRoleException and names the role nowhere — R-021', function (): void {
    throwingRoute('probe-unsafe-role', fn () => throw new UnsafeDatabaseRoleException(
        'qrpager_app',
        ['it is a SUPERUSER, which ignores FORCE row level security entirely'],
    ));

    $response = $this->getJson('/v1/probe-unsafe-role');
    $body = (string) $response->getContent();

    $response->assertStatus(500)->assertJsonPath('type', ProblemType::ServerError->value);

    // Both were present in the body at 7f1c8f6, with APP_DEBUG=true. Measured, not assumed.
    expect($body)
        ->not->toContain('qrpager_app')
        ->not->toContain('SUPERUSER')
        ->not->toContain('UnsafeDatabaseRoleException');
});

it('renders validation failures with our own messages and nothing else', function (): void {
    throwingRoute('probe-validation', fn () => throw ValidationException::withMessages([
        'order_reference' => ['The order reference is required.'],
    ]));

    $this->getJson('/v1/probe-validation')
        ->assertStatus(422)
        ->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('errors.order_reference.0', 'The order reference is required.');
});

it('renders 404 and 405 as problem documents', function (): void {
    throwingRoute('probe-method', fn () => response()->noContent());

    $notFound = $this->getJson('/v1/no-such-endpoint-at-all');
    $notFound->assertStatus(404)->assertJsonPath('type', ProblemType::NotFound->value);
    expect($notFound->headers->get('Content-Type'))->toContain('application/problem+json');

    // A WRONG VERB IS NOW 404, not 405, and that is deliberate: the fallback is
    // registered for every verb the router dispatches (security #1), so it sits in the
    // POST bucket and matches before checkForAlternateVerbs is reached. This case used
    // to assert 405 here, and it is the one place in the suite that observed the
    // router's method handling -- so it is also where the trade is recorded.
    $this->postJson('/v1/probe-method')
        ->assertStatus(404)
        ->assertJsonPath('type', ProblemType::NotFound->value);

    // ...and the 405 document still renders, which is what keeps the enum case honest
    // now that routing cannot produce it. ProblemRenderer maps the exception, and a
    // package or a controller can still raise it.
    throwingRoute('probe-method-thrown', fn () => throw new MethodNotAllowedHttpException(['GET']));

    $this->getJson('/v1/probe-method-thrown')
        ->assertStatus(405)
        ->assertJsonPath('type', ProblemType::MethodNotAllowed->value);
});

it('renders the same document for a browser request, not an HTML debug page', function (): void {
    // The 7f1c8f6 HTML debug page for this exception was 862,104 bytes and carried the
    // statement, the bindings and the source of every frame.
    throwingRoute('probe-html', fn () => DB::select('select * from no_such_table_html where id = ?', ['bound-value']));

    $response = $this->get('/v1/probe-html');
    $body = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and(strlen($body))->toBeLessThan(500)
        ->and($body)->not->toContain('no_such_table_html')
        ->and($body)->not->toContain('bound-value');
});

/*
 * R-042: "every unmapped 4xx renders as 403 Forbidden, so a request-format error is
 * answered with an authorization verdict."
 *
 * Asserted the way QA found it -- by throwing each status through the real renderer and
 * reading the rendered `status`, which is the review-by's own wording. Not through a
 * route: R-042's claim that Laravel throws 400 for a malformed JSON body is FALSE on
 * Laravel 13 (measured: Request::json() returns an empty array and throws nothing), so a
 * route-level case would test the FormRequest rather than the renderer. The defect the
 * row names is in the renderer and that is where it is pinned.
 */

it('maps every status it is given to a type that renders that same status — R-042', function (int $thrown, ProblemType $expected): void {
    $problem = app(ProblemRenderer::class)->render(
        new HttpException($thrown, 'deliberate'),
        Request::create('/v1/probe-status', 'POST'),
    );

    expect($problem->type)->toBe($expected, "a thrown {$thrown} rendered as {$problem->type->value}");

    // The rendered STATUS, which is what a client sees and the half R-042 is about: before
    // the fix a thrown 400, 402, 406, 410, 415 and 418 all came out as 403.
    expect($problem->toResponse()->getStatusCode())->toBe($expected->status());
})->with([
    // Mapped exactly: the status the client sees is the status that was thrown.
    'bad request' => [400, ProblemType::BadRequest],
    'unauthenticated' => [401, ProblemType::Unauthenticated],
    'forbidden' => [403, ProblemType::Forbidden],
    'not found' => [404, ProblemType::NotFound],
    'method not allowed' => [405, ProblemType::MethodNotAllowed],
    'conflict' => [409, ProblemType::Conflict],
    'unprocessable' => [422, ProblemType::Validation],
    'rate limited' => [429, ProblemType::RateLimited],
    'unavailable' => [503, ProblemType::Unavailable],

    // Deliberately collapsed, as R-042 allows ("mapped or deliberately collapsed").
    'not acceptable -> bad request' => [406, ProblemType::BadRequest],
    'unsupported media -> bad request' => [415, ProblemType::BadRequest],
    'gone -> not found' => [410, ProblemType::NotFound],

    // Not modelled, so they take the default -- which is now ServerError and NOT
    // Forbidden. Answering "that is not permitted" to these asserted something about
    // authorization the renderer cannot know.
    'payment required -> server error' => [402, ProblemType::ServerError],
    'teapot -> server error' => [418, ProblemType::ServerError],
    'too early -> server error' => [425, ProblemType::ServerError],

    // 5xx was already right and must stay right.
    'internal -> server error' => [500, ProblemType::ServerError],
    'bad gateway -> server error' => [502, ProblemType::ServerError],
]);

it('never answers Forbidden for a status that is not about authorization — R-042', function (): void {
    $renderer = app(ProblemRenderer::class);
    $request = Request::create('/v1/probe-status', 'POST');

    // The property behind the data set above, stated once: across every 4xx and 5xx there
    // is exactly ONE status that may render as Forbidden, and it is 403.
    for ($status = 400; $status <= 599; $status++) {
        $type = $renderer->render(new HttpException($status, 'deliberate'), $request)->type;

        if ($type === ProblemType::Forbidden) {
            expect($status)->toBe(403, "a thrown {$status} rendered as Forbidden");
        }
    }
});

it('renders the two pager domain refusals as 404 and 409, never as a 500', function (): void {
    $renderer = app(ProblemRenderer::class);
    $request = Request::create('/v1/pager-sessions/x/ready', 'POST');

    // Both exceptions live in App\Domain rather than App\Features precisely so they can be
    // mapped here: deptrac forbids Platform from importing Features, so a feature-layer
    // exception could not be reached by this renderer and would fall through to a 500.
    expect($renderer->render(PagerSessionNotFound::create(), $request)->type)
        ->toBe(ProblemType::NotFound)
        ->and($renderer->render(
            PagerTransitionDenied::between(PagerStatus::Completed, PagerStatus::Ready),
            $request,
        )->type)->toBe(ProblemType::Conflict);
});

it('maps the three framework exceptions it matches by class, not by status', function (): void {
    $renderer = app(ProblemRenderer::class);
    $request = Request::create('/v1/x', 'GET');

    // Matched by CLASS rather than by status, which the renderer does deliberately
    // ("these two ARE HttpExceptions, and matching them by class rather than by status
    // keeps the mapping readable"). Covered here because a branch nothing exercises is a
    // branch nobody notices breaking.
    expect($renderer->render(new ModelNotFoundException, $request)->type)
        ->toBe(ProblemType::NotFound)
        ->and($renderer->render(new AuthenticationException, $request)->type)
        ->toBe(ProblemType::Unauthenticated)
        ->and($renderer->render(new AuthorizationException('nope'), $request)->type)
        ->toBe(ProblemType::Forbidden);
});
