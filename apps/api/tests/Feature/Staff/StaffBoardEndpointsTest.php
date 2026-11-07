<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * THE BOARD AND THE FOUR TRANSITIONS, AS THE POLLED STAFF CLIENT MEETS THEM
 * ================================================================================
 *
 * `StaffContractTest`'s reasoning, applied to 10b's surface: a client reader and a server
 * response are a contract across two languages, and the only honest place to assert one is
 * against the real response. Every key `apps/web/src/staff/board.ts` and `api.ts` read is
 * pinned here, at its path, with the function that reads it named.
 *
 * It also carries the two SERVER halves of 10b's two ruled `§5` rows:
 *
 *   `R-222`  `/v1/auth/me` publishes the restaurant's NAME, so the chooser can label by it;
 *   `R-224`  a 429 on the board carries `Retry-After`, which is the backoff hint the client
 *            honours. The CLIENT half of `R-224` -- the distinct `throttled` kind, and the
 *            copy -- is TypeScript's and is asserted in `apps/web/test/`.
 *
 * ⚠ TWO CASE NAMES HERE ARE DECLARED CONTROLS of `bin/board-race-revert-proof` and must not
 * be renamed without editing that script: *"marks one order ready for a single client"* and
 * *"refuses a transition on another tenant"*. They are the sequential halves, which removing
 * the row lock cannot change -- which is what makes them a control for a concurrency revert.
 */

it('lists the restaurant\'s waiting and ready pagers, newest first, with the keys the board reads', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> fetchBoard()
    //   data.sessions[].id  .order_reference  .status  .created_at  .ready_at
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Staff);

    // Three ages, inserted out of order so "newest first" is a real assertion rather than
    // insertion order happening to agree with it.
    $middle = $this->makeSession($tenant, ['order_reference' => 'B-200']);
    $oldest = $this->makeSession($tenant, ['order_reference' => 'C-300']);
    $newest = $this->makeSession($tenant, ['order_reference' => 'A-100']);

    withTenant($tenant, function () use ($oldest, $middle, $newest): void {
        $at = ['%s' => null];
        unset($at);

        foreach ([[$oldest, '-30 minutes'], [$middle, '-10 minutes'], [$newest, '-1 minute']] as [$id, $ago]) {
            DB::connection('pgsql_owner')->table('pager_sessions')->where('id', $id)
                ->update(['created_at' => now()->modify($ago)]);
        }
    }, 'pgsql_owner');

    // A terminal one, which must NOT appear: the board is what is still live.
    $done = $this->makeSession($tenant, ['status' => PagerStatus::Completed->value]);

    $response = $this->getJson('/v1/pager-sessions')->assertOk();

    $ids = array_column((array) $response->json('sessions'), 'id');

    // EXACT ORDER AND EXACT MEMBERSHIP, never a `toContain`: a board that returned the
    // right rows in the wrong order is a kitchen reading the oldest order as the newest.
    expect($ids)->toBe([$newest, $middle, $oldest])
        ->and($ids)->not->toContain($done);

    $response->assertJsonPath('sessions.0.order_reference', 'A-100')
        ->assertJsonPath('sessions.0.status', PagerStatus::Waiting->value)
        ->assertJsonPath('sessions.0.ready_at', null);

    /*
     * ⚠ `Cache-Control: private, no-store`, AS AN EXACT HEADER — `§11` SECURITY M-2. The
     * board had none and relied on Symfony's `no-cache, private` default, which forbids a
     * SHARED cache and permits the DEVICE's own to store the body: a shared kitchen tablet
     * would keep one restaurant's order board on disk across the lock and the next person's
     * sign-in. `§4 › Realtime` asks a polling endpoint for short-TTL cache headers, and this
     * is the route polled every five seconds by every screen in the room.
     *
     * ⚠ BOTH DIRECTIVES, EACH ASSERTED, RATHER THAN THE WHOLE STRING. The first version of
     * this pinned the literal `private, no-store` and failed against a correct response:
     * Symfony NORMALISES and reorders the header, so what arrives is `no-store, private`.
     * Pinning the order would make the assertion about Symfony's sort rather than about the
     * posture. `StaffShellEndpointsTest` already asserts the same pair this way for
     * `/v1/tenant`, which is the shape followed here.
     *
     * `no-store` is the load-bearing one and is asserted explicitly: the framework default
     * is `no-cache, private`, which also contains `private`, so a `private`-only assertion
     * would have passed against the very default this fix replaces.
     */
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    // ⚠ AND THE SESSION'S TOKEN IS NOWHERE IN THE DOCUMENT, asserted rather than assumed.
    // `token_hash` is not in `board()`'s select list and `qrpager_app` cannot read it at
    // all (§2.7 makes it write-only) -- but the assertion is about the RESPONSE, which is
    // the thing a client and a log see.
    expect($response->getContent())->not->toContain('token');
});

it('marks one order ready for a single client', function (): void {
    // A DECLARED CONTROL of bin/board-race-revert-proof -- do not rename without editing it.
    //
    // READ BY: apps/web/src/staff/api.ts -> markSessionReady()
    //   data.session.id  .status  .order_reference  .renotify_count  .changed
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Staff);

    $session = $this->makeSession($tenant);

    $this->postJson("/v1/pager-sessions/{$session}/ready")
        ->assertOk()
        ->assertJsonPath('session.id', $session)
        ->assertJsonPath('session.status', PagerStatus::Ready->value)
        ->assertJsonPath('session.changed', true);

    // The repeat, from the same client: still 200, `changed` false. This is what the panel
    // reads to decide whether to refresh the board immediately.
    $this->postJson("/v1/pager-sessions/{$session}/ready")
        ->assertOk()
        ->assertJsonPath('session.changed', false);
});

it('refuses a transition on another tenant', function (): void {
    // A DECLARED CONTROL of bin/board-race-revert-proof -- do not rename without editing it.
    //
    // 404 and never 403: a 403 confirms the id exists, which is the IDOR distinction
    // `§11.2` asks to be proven by a test rather than read off the code.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $theirs = $this->makeSession($beta);

    $this->actingForTenant($alpha, TenantRole::Owner);

    foreach (['ready', 'renotify', 'complete', 'cancel'] as $verb) {
        $this->postJson("/v1/pager-sessions/{$theirs}/{$verb}")->assertStatus(404);
    }

    // And beta's session is untouched -- a refusal that had side effects would pass the
    // status assertions above.
    expect(withTenant($beta, fn (): string => (string) DB::table('pager_sessions')
        ->where('id', $theirs)->value('status')))->toBe(PagerStatus::Waiting->value);
});

it('cancels and completes, and answers 409 rather than 404 for an illegal move', function (): void {
    // READ BY: apps/web/src/staff/api.ts -> cancelSession() / completeSession()
    //
    // The panel's `limit`, `expired` and `failed` states all come off a 409, and its `gone`
    // state off a 404, so the two must be distinguishable on a session that EXISTS.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $cancelled = $this->makeSession($tenant);
    $this->postJson("/v1/pager-sessions/{$cancelled}/cancel")
        ->assertOk()->assertJsonPath('session.status', PagerStatus::Cancelled->value);

    // Ready on a cancelled session is a 409: the session is real and the move is not.
    $this->postJson("/v1/pager-sessions/{$cancelled}/ready")->assertStatus(409);

    $completed = $this->makeSession($tenant);
    app(PagerSessions::class)->markReady($tenant, $completed);

    $this->postJson("/v1/pager-sessions/{$completed}/complete")
        ->assertOk()->assertJsonPath('session.status', PagerStatus::Completed->value);
});

it('publishes the restaurant NAME on /v1/auth/me, which is what the chooser labels by', function (): void {
    // R-222, ruled by Muhammad 2026-11-02. READ BY:
    //   apps/web/src/staff/api.ts -> fetchIdentity()  ->  data.memberships[].name
    //   apps/web/src/screens/staff/StaffSignIn.tsx    ->  chooserLabel()
    //
    // ⚠ THE NAME IS COMPARED AGAINST THE ROW, not against a literal: a test that asserted a
    // hard-coded string would pass against a response that published the SLUG if the two
    // ever happened to agree, and `makeTenant` builds both from the same uuid.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Manager);

    $row = withTenant($tenant, fn () => DB::selectOne('select name, slug from tenants'));

    $response = $this->getJson('/v1/auth/me')->assertOk();

    // `§11` SECURITY M-2, on the payload `R-222` made worth more: this answer now carries the
    // name of every restaurant the caller is staff of.
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $response->assertJsonPath('memberships.0.name', $row->name)
        // `slug` STAYS: `§6` says a published path is never changed in place, so the member
        // is still answered and it is the CLIENT that stops using it as a label.
        ->assertJsonPath('memberships.0.slug', $row->slug);

    // And the two really are different values, so the assertion above is not satisfied by
    // the name and the slug being the same string.
    expect($row->name)->not->toBe($row->slug);
});

it('carries Retry-After on a 429 for the board, which is the hint the client backs off on', function (): void {
    // R-224's SERVER half. The board is POLLED as of 10b, so a 429 here is ordinary rather
    // than exceptional -- and a refusal with no hint leaves the client doubling blindly.
    //
    // READ BY: apps/web/src/staff/api.ts -> call()  ->  { kind: 'throttled', retryAfter }
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Staff);

    // DRIVEN rather than configured, which is `R-063`'s clause: the bound is proven by
    // being exceeded. The per-IP allowance is read from config rather than written here, so
    // a change to the bucket changes this test's effort and not its meaning.
    $perIp = (int) config('ratelimit.buckets.api.per_ip.attempts');

    expect($perIp)->toBeGreaterThan(0, 'the api bucket has no per-IP allowance, so this test would be vacuous');

    $refused = null;

    foreach (range(1, $perIp + 1) as $ignored) {
        $response = $this->getJson('/v1/pager-sessions');

        if ($response->getStatusCode() === 429) {
            $refused = $response;

            break;
        }
    }

    expect($refused)->not->toBeNull("the board never answered 429 in {$perIp} + 1 requests");

    $refused->assertStatus(429)->assertHeader('Retry-After');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('lets a kitchen device token read the board and refuses it every transition', function (): void {
    // The split 10c inherits, asserted here because it is this surface's own property: a
    // kitchen screen watches and cannot act.
    //
    // `Ability::issuableToDevice()` is a closed list of ONE, so this is structural rather
    // than a policy -- but structural is what a test proves or fails to.
    expect(Ability::issuableToDevice())->toBe([Ability::PagerRead]);

    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $session = $this->makeSession($tenant);

    // Minted through the real route, which is `RoleMatrixTest`'s own pattern: a token
    // fabricated by calling the service directly would not prove that the issuing surface
    // can only issue this one ability.
    $issued = $this->postJson('/v1/devices', [
        'name' => 'The pass',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 60,
    ])->assertStatus(201);

    // The session is dropped before the token is presented, which is what a kitchen screen
    // actually does -- and without it the cookie would authorise every call below and the
    // 403s would never arrive.
    auth()->forgetGuards();
    app('auth')->guard('web')->logout();

    $headers = ['Authorization' => 'Bearer '.$issued->json('device.token')];

    $this->getJson('/v1/pager-sessions', $headers)->assertOk();

    foreach (['ready', 'renotify', 'complete', 'cancel'] as $verb) {
        // postJson takes headers THIRD and getJson takes them second, which is why these
        // two calls are written out rather than looped together.
        $this->postJson("/v1/pager-sessions/{$session}/{$verb}", [], $headers)->assertStatus(403);
    }
});
