<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §3 gate, clause 4: "cross-tenant IDOR blocked through HTTP, not just at the DB".
 *
 * THROUGH HTTP IS THE POINT. §11 › Security requires "every route taking an ID is proven
 * inaccessible across tenants by a test, not by reading the code", and these FOUR routes
 * are the first in the project to take an id at all -- the four transitions. `store` is
 * the fifth pager route and takes no id, so it is not an IDOR surface and is not here.
 * (This said "five" until QA L-8; the file's own transitionRoutes() has always listed
 * four, so the comment disagreed with the code beside it.) So every assertion here goes through
 * a real request -- the middleware stack, the resolver, the tenant scope and the policies
 * -- and not through the service.
 *
 * AND THE ANSWER IS 404, NEVER 403. Inside tenant A's scope, tenant B's row is invisible
 * to row level security rather than refused, so there is nothing to forbid. A 403 would
 * confirm the id exists, which turns a blocked IDOR into an existence oracle: an attacker
 * who can tell 403 from 404 can enumerate other tenants' session ids. The two cases "no
 * such session" and "not your session" are therefore deliberately indistinguishable, and
 * these tests assert that they are.
 */

/** The four routes that take a session id. */
function transitionRoutes(string $sessionId): array
{
    return [
        'ready' => "/v1/pager-sessions/{$sessionId}/ready",
        'renotify' => "/v1/pager-sessions/{$sessionId}/renotify",
        'complete' => "/v1/pager-sessions/{$sessionId}/complete",
        'cancel' => "/v1/pager-sessions/{$sessionId}/cancel",
    ];
}

it('answers 404 on every id-taking route when the session belongs to another tenant', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    // Acting as alpha's staff, with beta's session id. This is the IDOR.
    $this->actingForTenant($alpha);

    foreach (transitionRoutes($betaSession) as $name => $route) {
        $response = $this->postJson($route);

        expect($response->status())->toBe(404, "{$name} did not answer 404 for another tenant's session");

        // The body is the envelope's NotFound and carries no hint that the id is real.
        $response->assertJsonPath('type', ProblemType::NotFound->value)
            ->assertJsonPath('title', ProblemType::NotFound->title())
            ->assertJsonPath('status', 404);
    }
});

it('answers the same 404 for an id that exists nowhere, so the two are indistinguishable', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);
    $nonexistent = (string) Str::uuid7();

    $this->actingForTenant($alpha);

    foreach (['ready', 'renotify', 'complete', 'cancel'] as $action) {
        $real = $this->postJson("/v1/pager-sessions/{$betaSession}/{$action}");
        $fake = $this->postJson("/v1/pager-sessions/{$nonexistent}/{$action}");

        // Byte-identical bodies except for `instance`, which is the path the caller
        // already knows. If these ever diverged, the difference would be the oracle.
        $realBody = (array) $real->json();
        $fakeBody = (array) $fake->json();
        unset($realBody['instance'], $fakeBody['instance']);

        expect($real->status())->toBe($fake->status())
            ->and($realBody)->toBe($fakeBody, "{$action} distinguishes a real session from an imaginary one");
    }
});

it('leaves the other tenant’s session completely untouched', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    $this->actingForTenant($alpha);

    foreach (transitionRoutes($betaSession) as $route) {
        $this->postJson($route);
    }

    // The refusal is not enough on its own: the question is whether anything was written.
    // The columns are NAMED and not `select *`, which is the column grant doing its job:
    // `select *` on pager_sessions as qrpager_app raises 42501 because token_hash is not
    // granted to it (§2.7). Measured here by writing this test the lazy way first.
    $row = withTenant($beta, fn () => DB::table('pager_sessions')
        ->where('id', $betaSession)
        ->first(['status', 'ready_at', 'completed_at', 'cancelled_at', 'renotify_count']));
    $outbox = withTenant($beta, fn (): int => DB::table('outbox')->where('pager_session_id', $betaSession)->count());
    $audit = withTenant($beta, fn (): int => DB::table('audit_log')->where('subject_id', $betaSession)->count());

    expect($row?->status)->toBe(PagerStatus::Waiting->value)
        ->and($row?->ready_at)->toBeNull()
        ->and($row?->cancelled_at)->toBeNull()
        ->and($row?->completed_at)->toBeNull()
        ->and($row?->renotify_count)->toBe(0)
        ->and($outbox)->toBe(0, "alpha's request wrote into beta's outbox")
        ->and($audit)->toBe(0, "alpha's request wrote into beta's audit log");
});

it('lets the owning tenant act on the very same session, so the 404 is isolation and not a broken route', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    // THE POSITIVE CONTROL for every assertion above. Without it, a route that answered
    // 404 for everyone would pass this whole file.
    $this->actingForTenant($alpha);
    expect($this->postJson("/v1/pager-sessions/{$betaSession}/ready")->status())->toBe(404);

    $this->actingForTenant($beta);
    $response = $this->postJson("/v1/pager-sessions/{$betaSession}/ready");

    expect($response->status())->toBe(200);
    $response->assertJsonPath('session.status', PagerStatus::Ready->value)
        ->assertJsonPath('session.changed', true);
});

it('does not let a tenant id be chosen by anything the caller sends', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    $this->actingForTenant($alpha);

    // Every plausible place a tenant-switching parameter could hide. The resolver reads a
    // container binding and nothing else (B5), so all of these have to be inert -- and a
    // route that honoured any of them would be an unauthenticated tenant switch that
    // looks like ordinary code.
    $attempts = [
        ['headers' => ['X-Tenant-Id' => $beta]],
        ['headers' => ['X-Tenant' => $beta]],
        ['query' => "?tenant_id={$beta}"],
        ['body' => ['tenant_id' => $beta]],
        ['body' => ['tenant' => $beta]],
    ];

    foreach ($attempts as $attempt) {
        $route = "/v1/pager-sessions/{$betaSession}/ready".($attempt['query'] ?? '');

        $response = $this->postJson($route, $attempt['body'] ?? [], $attempt['headers'] ?? []);

        expect($response->status())->toBe(404, 'a caller-supplied tenant hint changed which tenant the request ran as');
    }

    $status = withTenant($beta, fn () => DB::table('pager_sessions')->where('id', $betaSession)->value('status'));

    expect($status)->toBe(PagerStatus::Waiting->value);
});
