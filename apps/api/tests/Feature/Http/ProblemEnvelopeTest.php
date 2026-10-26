<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    $this->postJson('/v1/probe-method')
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
