<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * R-081's OTHER HALF -- what a SUSPENDED tenant means for the CUSTOMER
 * ================================================================================
 *
 * `QRPAGER-12 §7`, third clause: *"The customer surface's behaviour for a suspended tenant
 * is decided deliberately and written down — an existing session is not silently broken
 * without a stated answer."*
 *
 * THE DECISION: **a live customer session keeps working, and the surface needs no code at
 * all.** Written down here and in `PLAN.md §5`'s `R-081` row, with the reasoning, because
 * "we did nothing" and "we decided to do nothing" look identical in a diff and only one of
 * them is a decision.
 *
 * WHY, in the order the argument actually runs:
 *
 *   1. **Suspension is an action against the RESTAURANT, not against the diner.** It is a
 *      billing or compliance state (`§2.3`: `tenant_status` is `active · suspended`). A
 *      customer sitting at a table with a pager has already ordered and is waiting for
 *      food, and the pager is the only thing that will tell them it is ready. Refusing them
 *      makes the restaurant's billing problem into the diner's missing lunch.
 *
 *   2. **The surface drains by itself, so "keep serving" is bounded and not open-ended.**
 *      Creating a session is a STAFF action (`pager.sessions.store`), and the staff surface
 *      refuses a suspended tenant at the resolver — so no NEW session can be opened the
 *      moment a tenant is suspended. The sessions that exist carry a 2-hour TTL
 *      (`§4 › Data`), after which they accept no transition at all. So the customer surface
 *      empties within two hours without anyone doing anything, which is the property that
 *      makes this answer safe rather than merely kind.
 *
 *   3. **And the staff cannot serve them anyway**, which bounds what "keeps working"
 *      delivers: `markReady` is refused, so an existing session can still be POLLED but can
 *      no longer be marked ready. The customer sees a live, honest "still waiting" rather
 *      than a dead end — which is what `§3` row 4's "the customer must not see a dead end"
 *      asks for on the Redis-outage path, applied to this one.
 *
 * THE ALTERNATIVE REJECTED: refusing the customer surface too, for symmetry with staff.
 * It is one line in the token resolver. It was rejected because symmetry is not a reason,
 * and the cost is borne by the wrong person — and because the drain in (2) means the
 * difference between the two answers lasts at most two hours.
 *
 * This file is what stops the decision being reversed silently: if somebody later adds a
 * status check to the customer path, these cases go red and they have to come here first.
 */

/**
 * The raw token out of a QR payload.
 *
 * `§3` row 3 puts it in the URL **fragment** -- RFC 3986 §3.5 has the client resolve it, so
 * it reaches no access log, no `Referer` header and no CDN cache key. A camera reads the
 * whole URL; this reads the part after the `#`, which is the same thing the PWA will do.
 */
function tokenFromQrPayload(string $payload): ?string
{
    $fragment = parse_url($payload, PHP_URL_FRAGMENT);

    return is_string($fragment) && $fragment !== '' ? $fragment : null;
}

/**
 * Suspend a tenant, then cross a request boundary.
 *
 * ⚠ THE FLUSH IS LOAD-BEARING IN TESTS AND IS NOT PAPERING OVER ANYTHING -- it is
 * simulating something production has and the test harness does not.
 *
 * `Memberships` is bound `scoped()` and memoises one user's membership set for the life of
 * the request (`QRPAGER-12 §10.5`). Octane calls `forgetScopedInstances()` at every request
 * boundary, so in production the set is re-read on the next request and a suspension takes
 * effect immediately. Laravel's TEST client does not: the container persists across
 * `$this->postJson()` calls, so a membership read during an earlier request is still
 * memoised -- with `status = active` -- when a later request asks.
 *
 * Measured: without this flush, the third case below marked a suspended tenant's session
 * ready and got 200. That is a TEST-ENVIRONMENT divergence and not a production defect, and
 * it is called out here rather than hidden behind a passing assertion, because the thing
 * standing between the memo and a stale-membership cross-tenant read IS the Octane flush.
 * `RoleMatrixTest`'s suspended case does not need this only because it makes no HTTP request
 * before suspending.
 */
function suspend(string $tenantId): void
{
    withTenant($tenantId, fn () => DB::connection('pgsql_owner')
        ->table('tenants')->where('id', $tenantId)
        ->update(['status' => TenantStatus::Suspended->value]), 'pgsql_owner');

    app()->forgetScopedInstances();
}

it('keeps serving a live customer session after its tenant is suspended', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // A real session, opened while the tenant was active, through the real staff route.
    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-7']);
    $created->assertStatus(201);

    // THE TOKEN IS IN THE QR PAYLOAD'S FRAGMENT and in no body member of its own -- §3
    // row 3, as amended from security S-1: it reaches no access log, no Referer and no CDN
    // cache key, so the create response publishes a URL rather than a field. Parsed here
    // the way a camera would read it.
    $token = tokenFromQrPayload((string) $created->json('qr_payload'));

    expect($token)->not->toBeNull('the create response carried no token to present');

    // Now suspend the tenant.
    suspend($tenant);

    // THE DECISION, driven: the customer's token still resolves.
    $this->postJson('/v1/pager/session', ['token' => $token])->assertOk();

    // And the staff surface is refused at the same moment, which is the asymmetry being
    // asserted rather than assumed.
    $this->getJson('/v1/pager-sessions')->assertStatus(401);
});

it('lets no NEW session be opened once the tenant is suspended, which is what bounds the decision', function (): void {
    // Point 2 of the decision. Without this, "a live session keeps working" would be
    // open-ended -- a suspended restaurant could go on handing out pagers indefinitely.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    suspend($tenant);

    $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-8'])->assertStatus(401);

    // Nothing was written, so the refusal is before the insert and not after it.
    $count = withTenant($tenant, fn () => DB::table('pager_sessions')->count());

    expect($count)->toBe(0, 'a suspended tenant opened a new session');
});

it('refuses to mark an existing session ready while suspended, so the customer sees waiting and not a dead end', function (): void {
    // Point 3. The session stays pollable and stays WAITING, which is an honest answer
    // rather than a broken one.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'A-9']);
    $created->assertStatus(201);

    $sessionId = $created->json('session.id');
    $token = tokenFromQrPayload((string) $created->json('qr_payload'));

    suspend($tenant);

    $this->postJson("/v1/pager-sessions/{$sessionId}/ready")->assertStatus(401);

    $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $sessionId)->value('status'));

    expect($status)->toBe('waiting');

    // The customer can still ask, and still gets a truthful answer.
    $resolved = $this->postJson('/v1/pager/session', ['token' => $token]);

    $resolved->assertOk();

    expect((string) $resolved->getContent())->not->toContain('suspend');
});
