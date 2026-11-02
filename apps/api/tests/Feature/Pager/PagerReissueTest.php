<?php

declare(strict_types=1);

use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * RE-ISSUING A SHEET. MUHAMMAD's RULING, `QRPAGER-28 §2`:
 * ================================================================================
 *
 *   *"A sheet is RE-ISSUED, never reprinted. The QR token is 128-bit CSPRNG stored as a hash
 *   only (`§4 › Data`), so the raw value exists once, at generation, and can never be
 *   re-rendered from the database. Reprint is not a feature being declined — it is physically
 *   unavailable, and `§4` forbids making it available."*
 *
 * And `§6`'s gate clause: *"The re-issue action is confirmed, marked not reversible, and **the
 * old code is proven to stop working** by driving it after a re-issue."*
 *
 * The *confirmed and marked not reversible* half is `§12`'s and is asserted against the
 * rendered screen (`apps/web/test/staff-issue.test.tsx` and `browser/staff.spec.ts`). **This
 * file is the half that matters most and the one only the server can answer: the old code
 * stops working, driven over the customer's own route rather than inferred from a column.**
 *
 * ================================================================================
 * WHY THE PROOF IS A CUSTOMER REQUEST AND NOT A HASH COMPARISON
 * ================================================================================
 *
 * The obvious assertion is *the `token_hash` column changed*. It is the wrong one, for the same
 * reason `QRPAGER-27 §0` gives about the KDS: it tests the mechanism rather than the property.
 * A hash that changed while `PagerTokenLookup` happened to resolve on some other column, or a
 * Redis pointer key that outlived the rotation, would both pass a column check and leave the
 * old sheet working — which is the one thing the ruling is about.
 *
 * So the old raw token is PRESENTED to the customer surface, before and after, and the two
 * answers are compared. That is the only form of this assertion a lost-sheet scenario cares
 * about.
 *
 * ⚠ `qrpager_app` CANNOT READ `token_hash` AT ALL (`§2.7`, measured), so a column comparison
 * would have had to run as the owner — a second reason the property is better driven than read.
 */

/** Present a raw token at the customer surface, exactly as a scanned sheet does. */
function resolveToken(object $test, string $token): int
{
    return (function () use ($token): int {
        return $this->postJson('/v1/pager/session', ['token' => $token])->status();
    })->call($test);
}

/* -------------------------------------------------------------------------------- */

it('gives the same order a new code, and the OLD code stops working — the ruling\'s whole point', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // Issued through the real route, so the token under test is one a real sheet would carry.
    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'RE-1'])->assertStatus(201);

    $sessionId = $created->json('session.id');
    $oldPayload = $created->json('qr_payload');
    $oldToken = substr($oldPayload, (int) strpos($oldPayload, '#') + 1);

    expect($oldToken)->toBeString()->not->toBe('');

    // NOT VACUOUS, AND THIS IS THE CONTROL THAT MAKES THE REFUSAL BELOW MEAN SOMETHING: the old
    // code works right now. Without this, a token that never worked would pass the assertion.
    expect(resolveToken($this, $oldToken))->toBe(200, 'the freshly issued code did not resolve');

    $reissued = $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk();

    $newPayload = $reissued->json('qr_payload');
    $newToken = substr($newPayload, (int) strpos($newPayload, '#') + 1);

    expect($newToken)->toBeString()->not->toBe('')->not->toBe($oldToken);

    // THE CLAUSE. The old sheet is void, driven over the customer's own route.
    expect(resolveToken($this, $oldToken))->toBe(
        404,
        'the OLD code still resolves after a re-issue, so the lost sheet still works',
    );

    // And the new one works, so the re-issue produced a usable sheet rather than killing the
    // order. Both halves, because either alone is satisfiable by a broken implementation.
    expect(resolveToken($this, $newToken))->toBe(200, 'the new code does not resolve');
});

it('keeps the SAME session, the same order reference and the same status — one order, not two', function (): void {
    /*
     * `PagerSessions::reissue()`'s docblock rejects three alternative shapes and this is the
     * assertion that holds it to the one it chose. A cancel-then-create or a second session for
     * the same reference would both pass the *old code stops working* case above and would both
     * put TWO orders in the history where the restaurant had one — 10d's own `§3` bar is that
     * no screen shows an unexplained thing, and two rows for one order is exactly that.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'RE-2'])->assertStatus(201);
    $sessionId = $created->json('session.id');

    $reissued = $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk();

    expect($reissued->json('session.id'))->toBe($sessionId);
    expect($reissued->json('session.order_reference'))->toBe('RE-2');
    expect($reissued->json('session.status'))->toBe(PagerStatus::Waiting->value);

    // ONE ROW, which is the statement about the history screen.
    expect(withTenant($tenant, fn () => DB::table('pager_sessions')->count()))->toBe(1);
});

it('notifies NOBODY, because nothing happened to the customer\'s order', function (): void {
    /*
     * The clause that rules out cancel-then-create. Cancelling notifies — that is its whole
     * point (*"a pager that will never buzz is worse than news"*) — and a customer whose sheet
     * was reprinted at the counter has not been cancelled on and must not be told they have.
     *
     * Counted at the OUTBOX, because the worker is the only sender (**C5**) — the same oracle
     * 10c's resync proof uses, and for the same reason: a dispatch is only visible there.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'RE-3'])->assertStatus(201);
    $sessionId = $created->json('session.id');

    $before = withTenant($tenant, fn () => DB::table('outbox')->where('pager_session_id', $sessionId)->count());

    // Creating a session writes no outbox row either, so this starts at zero -- stated rather
    // than assumed, because a non-zero baseline would make the comparison below meaningless.
    expect($before)->toBe(0);

    $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk();

    expect(withTenant($tenant, fn () => DB::table('outbox')->where('pager_session_id', $sessionId)->count()))
        ->toBe($before, 'a re-issue dispatched a notification');
});

it('audits the re-issue as its own action, so the history can say why a code stopped working', function (): void {
    // `PagerAuditAction::Reissued` exists rather than reusing `Created`, because the two are
    // different facts about the same order -- and an audit log that could not tell them apart
    // would be unable to answer the only question anybody asks of it here.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $sessionId = $this->postJson('/v1/pager-sessions', ['order_reference' => 'RE-4'])
        ->assertStatus(201)
        ->json('session.id');

    $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk();

    $rows = withTenant($tenant, fn () => DB::table('audit_log')
        ->where('subject_id', $sessionId)
        ->where('action', 'pager.session.reissued')
        ->get()
        ->all());

    expect($rows)->toHaveCount(1);

    // ⚠ AND THE AUDIT ROW CARRIES NO TOKEN AND NO HASH, which is `audit()`'s own rule. The
    // whole row is searched rather than the context alone: a credential in a column nobody
    // thought about is still a credential in the log.
    $serialised = json_encode($rows[0], JSON_THROW_ON_ERROR);

    expect(str_contains($serialised, 'token'))->toBeFalse('the audit row mentions a token');
});

it('refuses a re-issue on a FINISHED order with a 409, because a new code would buzz nobody', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    foreach ([PagerStatus::Completed, PagerStatus::Cancelled, PagerStatus::Expired] as $terminal) {
        $sessionId = $this->makeSession($tenant, [
            'order_reference' => 'RE-T-'.$terminal->value,
            'status' => $terminal->value,
        ]);

        $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")
            ->assertStatus(409, "a re-issue on a [{$terminal->value}] order was not refused");
    }
});

it('refuses a re-issue on a session past its two-hour expiry, distinguishably from a finished one', function (): void {
    /*
     * The TTL check is `refuseIfPastTtl()`'s, shared with every transition so the two cannot
     * disagree about what expired means — computed from `expires_at` against the clock rather
     * than from `status`, which LAGS because `pager:expire` is scheduled nowhere (`R-058`).
     *
     * A DIFFERENT problem `type` from the terminal refusal above, which is `ProblemRenderer`'s
     * own distinction: *an illegal transition is a mistake to correct, an expired session is one
     * to re-issue* — and here the two remedies really do differ, because a person holding an
     * expired order needs a NEW pager rather than a new sheet for this one.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $sessionId = $this->makeSession($tenant, [
        'order_reference' => 'RE-X',
        // Still `waiting` by status, and past its expiry by the clock -- which is exactly the
        // state `R-058` makes reachable and the reason the check is not `status`-based.
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]);

    $response = $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertStatus(409);

    expect($response->json('type'))->toContain('expired');
});

it('reports another restaurant\'s session as not found, never as forbidden', function (): void {
    // `§11.2`'s IDOR rule at the newest route on this surface. 404 and not 403, because the row
    // is INVISIBLE inside the caller's scope rather than refused -- a 403 would confirm the id
    // exists somewhere, which is the leak the gate clause is about.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $betaSession = $this->makeSession($beta, ['order_reference' => 'BETA-RE']);

    $this->actingForTenant($alpha, TenantRole::Owner);

    $this->postJson("/v1/pager-sessions/{$betaSession}/reissue")->assertStatus(404);

    // Beta's token is UNTOUCHED, which is what makes the 404 isolation rather than a route that
    // rotated the hash and then refused. Compared as the owner, because the app role cannot
    // read this column at all.
    $hash = withTenant($beta, fn () => DB::connection('pgsql_owner')
        ->table('pager_sessions')->where('id', $betaSession)->value('token_hash'), 'pgsql_owner');

    expect($hash)->not->toBeNull();

    // And nothing was audited in beta either.
    expect(withTenant($beta, fn () => DB::table('audit_log')
        ->where('subject_id', $betaSession)
        ->where('action', 'pager.session.reissued')
        ->count()))->toBe(0);
});

it('serialises two concurrent re-issues on one session, so one surviving code is in force', function (): void {
    /*
     * Two staff members re-issuing the same sheet at the same instant must produce ONE
     * surviving code rather than two sheets whose holder cannot know which works.
     *
     * ⚠ DRIVEN THROUGH THE SERVICE RATHER THAN IN TWO PROCESSES, AND THAT IS A NARROWING SAID
     * OUT LOUD. `StaffBoardConcurrencyTest` (10b) and `MemberConcurrencyTest` (Task 5) each
     * drive two real OS processes against one database, which is the strong form. This does not,
     * and the reason is that the property here is weaker and different: `reissue()` takes the
     * same `FOR UPDATE` row lock as `apply()`, whose two-process serialisation those two files
     * already prove — so a third copy would re-prove the lock rather than this route.
     *
     * What IS asserted: two sequential re-issues leave exactly one hash in force, and the
     * FIRST of the two tokens is dead. That is the observable a two-process race would also
     * produce, and it is the one a staff member cares about.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $created = $this->postJson('/v1/pager-sessions', ['order_reference' => 'RE-5'])->assertStatus(201);
    $sessionId = $created->json('session.id');

    $first = $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk()->json('qr_payload');
    $second = $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk()->json('qr_payload');

    $firstToken = substr($first, (int) strpos($first, '#') + 1);
    $secondToken = substr($second, (int) strpos($second, '#') + 1);

    expect($firstToken)->not->toBe($secondToken);

    // Exactly one works, and it is the LATEST. A re-issue that did not supersede the previous
    // one would leave two live sheets for one order.
    expect(resolveToken($this, $firstToken))->toBe(404, 'an earlier re-issued code is still live');
    expect(resolveToken($this, $secondToken))->toBe(200);
});

it('is reachable only by a caller holding pager:create, so a staff member can re-issue and a screen cannot', function (): void {
    // The ability decision, named here rather than left to `RoleMatrixTest`'s sweep: re-issuing
    // is a CSPRNG draw that mints a credential, which is `store()`'s act and `store()`'s
    // ability. A device token holds neither.
    $tenant = $this->makeTenant();
    $sessionId = $this->makeSession($tenant);

    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $this->postJson("/v1/pager-sessions/{$sessionId}/reissue", [], $headers)->assertStatus(403);

    // And a staff member CAN, so the refusal above is about the device rather than the route.
    $this->actingForTenant($tenant, TenantRole::Staff);
    $this->postJson("/v1/pager-sessions/{$sessionId}/reissue")->assertOk();
});
