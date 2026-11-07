<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Pager\PagerStatus;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * THE KITCHEN DISPLAY's SERVER SIDE — 10c (`QRPAGER-27`)
 * ================================================================================
 *
 * 10c adds **no route**. The screen reads `GET /v1/pager-sessions`, which has existed since
 * Task 5 precisely so that a device token would have something to read — `PagerSessions::board()`
 * says so in its own docblock. So this file is not a route test; it is the three properties of
 * that route **as a bearer-token caller meets it** that no existing file asserts.
 *
 * ================================================================================
 * WHAT `RoleMatrixTest` ALREADY PROVES, SO THIS FILE DOES NOT REPEAT IT
 * ================================================================================
 *
 * `RoleMatrixTest` drives `device × every staff route × own tenant` and `× another tenant`,
 * both derived from the router. A device token's **403 on every transition** is therefore
 * already proven there, and re-driving it here would be a second copy of a sweep that is
 * already mechanical. What that file asserts about the board's own cell is a SUBSTRING: *"the
 * device token read a board that did not contain its own tenant's session"*.
 *
 * Three things that leaves open, and each is a case below:
 *
 *   **(1) THE CROSS-TENANT QUESTION IS ABOUT THE BODY, NOT THE STATUS — and the router-derived
 *   sweep structurally cannot reach it.** That sweep substitutes a FOREIGN id into a path
 *   parameter and asserts the refusal. `GET /v1/pager-sessions` **takes no input at all** — no
 *   body, no query, no path parameter — so there is nothing foreign to substitute, and a
 *   device token in tenant B answers **200** whatever is in tenant A. The only honest
 *   assertion is over the CONTENT of that 200, and no existing file makes it. This is the one
 *   bug class 10c could introduce that nothing else would catch.
 *
 *   **(2) THE DISPATCH COUNT, which is 10c's invariant and is not a status code.**
 *   `QRPAGER-27 §0`: *"re-sends NO notification … counting dispatches on the server before and
 *   after."* A 403 is strong evidence that nothing was sent and it is not the assertion: a
 *   refusal that had already written an outbox row before refusing would answer 403 too. Since
 *   the worker is the only sender (**C5**), the outbox is where a dispatch is visible, so that
 *   is where it is counted — the same oracle `tools/gate/bin/kds-resync-proof` uses across a
 *   real browser's reconnect.
 *
 *   **(3) THE KEYS THE CLIENT READS**, pinned against the real response under a bearer token.
 *   `StaffContractTest`'s reasoning: a client reader and a server shape are a contract across
 *   two languages, and the only honest place to assert one is against the real response.
 *   `apps/web/src/kds/api.ts` reads four keys and ignores `ready_at`, which the staff board
 *   does read — so the two clients are two contracts over one response.
 */

/** Every outbox row for one session. The dispatch count, at the only place a dispatch exists. */
function kdsDispatchCount(string $tenantId, string $sessionId): int
{
    return withTenant(
        $tenantId,
        fn (): int => DB::table('outbox')->where('pager_session_id', $sessionId)->count(),
    );
}

/* -------------------------------------------------------------------------------- */

it('serves the kitchen screen its own restaurant\'s board, with the four keys that client reads', function (): void {
    // READ BY: apps/web/src/kds/api.ts -> fetchKdsBoard()
    //   data.sessions[].id  .order_reference  .status  .created_at
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant, ['order_reference' => 'KDS-1']);
    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $response = $this->getJson('/v1/pager-sessions', $headers)->assertOk();

    $rows = $response->json('sessions');

    expect($rows)->toBeArray()->toHaveCount(1);

    $row = $rows[0];

    // Each key by name, because each one is a line in that client's reader -- and a row
    // missing any of the four is DROPPED by it rather than defaulted, so a rename here would
    // present a kitchen with an empty screen rather than an error.
    expect($row['id'])->toBe($session);
    expect($row['order_reference'])->toBe('KDS-1');
    expect($row['status'])->toBe(PagerStatus::Waiting->value);
    expect($row['created_at'])->toBeString()->not->toBe('');

    /*
     * ⚠ AND THE RAW TOKEN'S HASH IS NOT IN THE RESPONSE, asserted over the whole body rather
     * than by listing keys. `token_hash` is not in `board()`'s select list and `qrpager_app`
     * cannot read the column at all, so this is the third line of defence — and it is the one
     * that holds if somebody widens the select.
     */
    expect($response->getContent())->toBeString();
    expect(str_contains((string) $response->getContent(), 'token'))->toBeFalse(
        'the board response mentions a token',
    );
});

it('shows a kitchen screen NOTHING of another restaurant, which no path parameter can reach', function (): void {
    /*
     * ⚠ 10c's TENANT-ISOLATION CASE, AND IT IS A CONTENT ASSERTION BECAUSE THE STATUS CANNOT
     * CARRY IT.
     *
     * `StaffSurfaceIsolationTest`'s router-derived sweep substitutes a foreign id into a path
     * parameter and asserts the refusal. This route takes **no input at all**, so there is
     * nothing to substitute: a device token pinned to beta answers **200** whatever alpha
     * contains, and the only question is what is in that 200.
     *
     * Both directions are driven, because an isolation case that only looks one way passes
     * against a scope that is inverted rather than absent.
     */
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $alphaSession = $this->makeSession($alpha, ['order_reference' => 'ALPHA-ONLY']);
    $betaSession = $this->makeSession($beta, ['order_reference' => 'BETA-ONLY']);

    // Not vacuous: both rows exist, read back inside their own scopes. An isolation assertion
    // over a fixture that was never created is the shape that passes for free.
    expect(kdsDispatchCount($alpha, $alphaSession))->toBeGreaterThanOrEqual(0);
    expect(withTenant($alpha, fn () => DB::table('pager_sessions')->count()))->toBe(1);
    expect(withTenant($beta, fn () => DB::table('pager_sessions')->count()))->toBe(1);

    $betaHeaders = ['Authorization' => 'Bearer '.kdsTokenFor($this, $beta)];
    $betaBody = (string) $this->getJson('/v1/pager-sessions', $betaHeaders)->assertOk()->getContent();

    expect(str_contains($betaBody, 'BETA-ONLY'))->toBeTrue('beta\'s screen cannot see beta\'s own order');
    expect(str_contains($betaBody, 'ALPHA-ONLY'))->toBeFalse('beta\'s screen rendered alpha\'s order');
    expect(str_contains($betaBody, $alphaSession))->toBeFalse('beta\'s screen carries alpha\'s session id');

    $alphaHeaders = ['Authorization' => 'Bearer '.kdsTokenFor($this, $alpha)];
    $alphaBody = (string) $this->getJson('/v1/pager-sessions', $alphaHeaders)->assertOk()->getContent();

    expect(str_contains($alphaBody, 'ALPHA-ONLY'))->toBeTrue('alpha\'s screen cannot see alpha\'s own order');
    expect(str_contains($alphaBody, 'BETA-ONLY'))->toBeFalse('alpha\'s screen rendered beta\'s order');
    expect(str_contains($alphaBody, $betaSession))->toBeFalse('alpha\'s screen carries beta\'s session id');
});

it('dispatches NOTHING when a kitchen screen attempts every transition — the count, not the status', function (): void {
    /*
     * ⚠ 10c's INVARIANT AT THE OUTBOX, WHICH IS WHERE A DISPATCH IS VISIBLE (**C5**: the
     * worker is the only sender).
     *
     * `RoleMatrixTest` already asserts the 403 on each of these. What it does not assert — and
     * what `QRPAGER-27 §0` actually names — is that **no dispatch was written**. The two are
     * different claims: a handler that enqueued and then refused would answer 403 and leave a
     * row, and a customer's phone would buzz for an order no kitchen screen was allowed to
     * ready.
     *
     * The count is taken before and after, and compared. That is the same oracle and the same
     * comparison `tools/gate/bin/kds-resync-proof` makes across a real browser's reconnect;
     * this is the cheap, hermetic half of it.
     */
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $before = kdsDispatchCount($tenant, $session);

    foreach (['ready', 'renotify', 'complete', 'cancel'] as $verb) {
        $this->postJson("/v1/pager-sessions/{$session}/{$verb}", [], $headers)
            ->assertStatus(403);
    }

    // Creating one is the fifth write on this surface and is `pager:create`, which a device
    // also does not hold. Included because a screen that could OPEN a session could buzz a
    // customer by opening and readying one.
    $this->postJson('/v1/pager-sessions', ['order_reference' => 'KDS-NEW'], $headers)
        ->assertStatus(403);

    expect(kdsDispatchCount($tenant, $session))->toBe(
        $before,
        'a kitchen screen\'s refused transition still wrote a dispatch',
    );

    // And no session was created either, so the `pager:create` refusal is a refusal rather
    // than a 403 after the fact.
    expect(withTenant($tenant, fn () => DB::table('pager_sessions')->count()))->toBe(1);
});

it('forbids a shared kitchen device from caching the board on disk', function (): void {
    /*
     * `§11` SECURITY M-2's clause, driven on the surface it was written for. 10b added
     * `private, no-store` to this route and `StaffShellEndpointsTest` asserts the same header
     * on `/v1/tenant` with the words *"Nothing shared may cache a restaurant's data off a
     * shared kitchen device."*
     *
     * ⚠ THE DEVICE SURFACE IS WHERE THAT SENTENCE IS LITERALLY TRUE, which is why it is
     * re-asserted here rather than left to the staff-session case: this screen runs for thirty
     * days on a tablet nobody logs out of, and `no-store` is what stops the order board
     * surviving on its disk after the code is revoked.
     */
    $tenant = $this->makeTenant();
    $this->makeSession($tenant);
    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $this->getJson('/v1/pager-sessions', $headers)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses a kitchen screen the restaurant\'s own name, which is why that screen shows none', function (): void {
    /*
     * ⚠ THIS IS A POSITIVE ASSERTION OF AN ABSENCE, AND IT IS HERE BECAUSE `KdsBoard.tsx`
     * CITES IT AS A DESIGN REASON.
     *
     * That screen renders no restaurant name, and its docblock gives the reason:
     * `Ability::TenantRead` is deliberately not held by `Principal::Device`, and that
     * ability's own docblock calls granting it *"a vacuous grant reading as a capability"*.
     * A later change that widened `issuableToDevice()` would make the screen's stated reason
     * false while every other case in this file still passed — so the reason is pinned.
     *
     * `RoleMatrixTest` drives this cell as part of its sweep. What is added here is the LINK:
     * this case names the screen that depends on the refusal.
     */
    $tenant = $this->makeTenant();
    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $this->getJson('/v1/tenant', $headers)->assertStatus(403);

    // And the closed list is still closed, which is the structural half: a device cannot be
    // ISSUED anything else, so the refusal above cannot be routed around by asking for a
    // better token.
    expect(Ability::issuableToDevice())->toBe([Ability::PagerRead]);
});
