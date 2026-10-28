<?php

declare(strict_types=1);

use App\Domain\Auth\Ability;
use App\Domain\Auth\TenantPin;
use App\Domain\Tenancy\TenantRole;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * "DEVICE TOKEN SCOPE CANNOT ESCALATE" -- PLAN.md §3.1 row 5's second invariant
 * ================================================================================
 *
 * A KDS device token lives on a shared screen in a kitchen, which is the least defensible
 * place a credential can live: no login, no lock, and whoever walks past. So the question
 * this file answers is not "is it authenticated" but "what is the WORST a stolen one can
 * do", and every case below is driven rather than read off a config.
 *
 * THE FOUR BOUNDS, and each is tested by driving the token rather than by inspecting it:
 *
 *   1. ONE CAPABILITY. It reads the board and is refused on every other staff route --
 *      including, specifically, issuing another device token.
 *   2. ONE TENANT. It cannot act in another tenant even when its own ISSUER is a member of
 *      that other tenant, which is the case a membership-derived principal would get wrong.
 *   3. AN EXPLICIT EXPIRY. It cannot be minted without one, and it stops working at it.
 *   4. THE MATRIX IS THE CEILING. Even a token whose stored abilities are tampered with --
 *      written directly into the database, which is a stronger attacker than HTTP allows --
 *      cannot exceed what `Principal::Device` holds in `RoleMatrix`.
 */

/** Issue a device token through the real endpoint and return its plaintext. */
function issueDeviceToken(string $tenantId, int $ttlMinutes = 60): array
{
    $response = test()->postJson('/v1/devices', [
        'name' => 'pass',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => $ttlMinutes,
    ]);

    $response->assertStatus(201);

    return $response->json('device');
}

it('reads the board with its one capability, and is refused on every other staff route', function (): void {
    // BOUND 1. The issuer is an OWNER, so every refusal below is the token's own scope
    // rather than a weak issuer: if the principal came from the membership, this token
    // would hold everything an owner holds.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);
    $session = $this->makeSession($tenant);

    $device = issueDeviceToken($tenant);

    // Drop the session and present the token instead, which is what a kitchen screen does.
    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $headers = ['Authorization' => 'Bearer '.$device['token']];

    // ITS ONE ABILITY WORKS, which is the positive control. Without it every refusal below
    // would pass for a token that simply does not authenticate at all.
    //
    // ⚠ AND IT ASSERTS THE BOARD'S CONTENT, not just the status. A status-only control is
    // how this passed for a board that returns nothing: Task 5's QA review inserted
    // `return [];` at the top of `PagerSessions::board()` and all 205 tests in
    // tests/Feature/Auth and tests/Feature/Pager stayed green. A device token whose one
    // capability returns an empty board is not a token whose capability works.
    $board = $this->getJson('/v1/pager-sessions', $headers);

    $board->assertStatus(200);

    expect(str_contains((string) $board->getContent(), $session))->toBeTrue(
        "the board did not contain this tenant's own session, so a board returning nothing "
        .'would satisfy this case',
    );

    // And everything else is refused.
    $refused = [
        ['postJson', '/v1/pager-sessions', ['order_reference' => 'A-2']],
        ['postJson', "/v1/pager-sessions/{$session}/ready", []],
        ['postJson', "/v1/pager-sessions/{$session}/renotify", []],
        ['postJson', "/v1/pager-sessions/{$session}/complete", []],
        ['postJson', "/v1/pager-sessions/{$session}/cancel", []],
        ['getJson', '/v1/members', []],
        ['postJson', '/v1/members', ['email' => 'x@example.test', 'role' => 'staff']],
        ['patchJson', '/v1/members/'.Str::uuid7(), ['role' => 'owner']],
        ['deleteJson', '/v1/members/'.Str::uuid7(), []],
        // THE ONE THAT MATTERS MOST: a device token minting another device token. Refused
        // because `devices:write` is absent from both RoleMatrix's Device row and
        // Ability::issuableToDevice(), so it is two independent absences rather than one
        // check.
        ['postJson', '/v1/devices', ['name' => 'x', 'ability' => Ability::PagerRead->value, 'ttl_minutes' => 60]],
        ['deleteJson', '/v1/devices/'.Str::uuid7(), []],
    ];

    foreach ($refused as [$method, $path, $body]) {
        $response = $method === 'getJson'
            ? $this->getJson($path, $headers)
            : $this->{$method}($path, $body, $headers);

        expect($response->status())->toBe(403, "a device token was allowed {$method} {$path}");
    }
});

it('cannot act in another tenant even when its own issuer is a member of that tenant', function (): void {
    // BOUND 2, and this is the case a membership-derived principal gets wrong. The issuer
    // belongs to BOTH tenants; the token belongs to one.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $this->actingForTenant($alpha, TenantRole::Owner);

    // Give the same user a membership in beta as well, so the token's pin is the only thing
    // standing between it and beta.
    $userId = (string) auth()->id();

    withTenant($beta, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $beta,
        'user_id' => $userId,
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $betaSession = $this->makeSession($beta);

    // Alpha gets a session of its own, so the assertion below is "it reads ALPHA's and not
    // BETA's" rather than "it reads nothing, and beta's is part of nothing".
    $alphaSession = $this->makeSession($alpha);

    $device = issueDeviceToken($alpha);

    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $headers = ['Authorization' => 'Bearer '.$device['token']];

    // The board it reads is ALPHA's, never beta's, although the holder is an owner of both.
    $board = $this->getJson('/v1/pager-sessions', $headers);

    $board->assertStatus(200);

    expect(str_contains((string) $board->getContent(), $alphaSession))->toBeTrue(
        'the board did not contain alpha\'s own session, so the absence of beta\'s below '
        .'proves nothing',
    );

    expect(str_contains((string) $board->getContent(), $betaSession))->toBeFalse(
        "a device token pinned to one tenant read another tenant's board",
    );

    // And nothing of beta's can be acted on.
    $this->postJson("/v1/pager-sessions/{$betaSession}/ready", [], $headers)
        ->assertStatus(403);

    $status = withTenant($beta, fn () => DB::table('pager_sessions')->where('id', $betaSession)->value('status'));

    expect($status)->toBe('waiting');
});

it('cannot be issued without an explicit expiry, and stops working at it', function (): void {
    // BOUND 3. QRPAGER-12 §5: "make expiry explicit". `required` is what makes "forever"
    // inexpressible, and config/sanctum.php leaves the global expiration null so that no
    // default can supply one behind the caller's back.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $this->postJson('/v1/devices', [
        'name' => 'pass',
        'ability' => Ability::PagerRead->value,
    ])->assertStatus(422);

    $this->postJson('/v1/devices', [
        'name' => 'pass',
        'ability' => Ability::PagerRead->value,
        'ttl_minutes' => 0,
    ])->assertStatus(422);

    // A real token, then wound past its expiry in the database -- which is how a clock test
    // avoids being a wall-clock test (F10's class).
    $device = issueDeviceToken($tenant, 60);

    DB::connection('pgsql_owner')->table('personal_access_tokens')
        ->where('id', $device['id'])
        ->update(['expires_at' => now()->subMinute()]);

    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();

    $this->getJson('/v1/pager-sessions', ['Authorization' => 'Bearer '.$device['token']])
        ->assertStatus(401);
});

it('refuses a capability that is not issuable to a device at all', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // Every ability EXCEPT the one on the allowlist, driven rather than argued.
    foreach (Ability::cases() as $ability) {
        if ($ability->issuableToDevices()) {
            continue;
        }

        $this->postJson('/v1/devices', [
            'name' => 'pass',
            'ability' => $ability->value,
            'ttl_minutes' => 60,
        ])->assertStatus(422, "a device token was issued with {$ability->value}");
    }
});

it('cannot exceed the matrix even with its stored abilities tampered with', function (): void {
    // BOUND 4, and it is the strongest case here: the attacker writes the database
    // directly, which no HTTP path allows -- §2.7 gives qrpager_app UPDATE on
    // (last_used_at, updated_at) and nothing else, so `abilities` is not rewritable through
    // the application at all. This case grants that it somehow was.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);
    $session = $this->makeSession($tenant);

    $device = issueDeviceToken($tenant);

    // Widen the token to hold EVERYTHING, as the owner role.
    DB::connection('pgsql_owner')->table('personal_access_tokens')
        ->where('id', $device['id'])
        ->update(['abilities' => json_encode([
            TenantPin::for($tenant),
            ...Ability::values(),
        ], JSON_THROW_ON_ERROR)]);

    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $headers = ['Authorization' => 'Bearer '.$device['token']];

    // It still holds only what Principal::Device holds, because RequestPrincipal decides
    // from the CREDENTIAL SHAPE and the matrix, and the token's own abilities can only
    // narrow further from there. The weaker of the two always wins.
    $this->getJson('/v1/pager-sessions', $headers)->assertStatus(200);

    foreach ([
        ['postJson', "/v1/pager-sessions/{$session}/ready", []],
        ['getJson', '/v1/members', []],
        ['postJson', '/v1/devices', ['name' => 'x', 'ability' => Ability::PagerRead->value, 'ttl_minutes' => 60]],
    ] as [$method, $path, $body]) {
        $response = $method === 'getJson'
            ? $this->getJson($path, $headers)
            : $this->{$method}($path, $body, $headers);

        expect($response->status())->toBe(
            403,
            "a tampered device token reached {$method} {$path}, so the matrix is not the ceiling",
        );
    }
});

it('stores a verifier and never the token, and never logs the plaintext', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $device = issueDeviceToken($tenant);

    $row = DB::connection('pgsql_owner')->table('personal_access_tokens')
        ->where('id', $device['id'])->first();

    expect($row)->not->toBeNull();

    // The column holds the sha256 of the SECRET HALF of the plaintext -- the same posture
    // as pager_sessions.token_hash, so a database disclosure is not a credential
    // disclosure.
    //
    // THE PLAINTEXT IS `{id}|{secret}`, which is Sanctum's own format and is worth spelling
    // out because the first version of this assertion hashed the WHOLE string and failed on
    // a token that was stored perfectly correctly. NewAccessToken::$plainTextToken is
    // $token->getKey().'|'.$secret, and it is the secret half alone that is hashed into the
    // column -- the id prefix exists so the lookup is a keyed read rather than a scan.
    [$prefix, $secret] = explode('|', $device['token'], 2);

    expect($prefix)->toBe($device['id'], "the plaintext's prefix is not the token id")
        ->and((string) $row->token)->toBe(hash('sha256', $secret))
        ->and((string) $row->token)->not->toBe($secret)
        ->and((string) $row->token)->not->toBe($device['token']);

    // And the audit row carries the ability and the expiry, never the credential.
    $audit = withTenant($tenant, fn () => DB::table('audit_log')
        ->where('action', 'device_token.issued')->first());

    expect($audit)->not->toBeNull();

    $context = (string) $audit->context;

    expect(str_contains($context, $device['token']))->toBeFalse('the audit row carries the plaintext token')
        ->and(str_contains($context, $secret))->toBeFalse('the audit row carries the token secret')
        ->and(str_contains($context, (string) $row->token))->toBeFalse('the audit row carries the token hash')
        ->and($context)->toContain(Ability::PagerRead->value);
});

it('revokes a token, and reports one belonging to another tenant as not found', function (): void {
    $alpha = $this->makeTenant();
    $this->actingForTenant($alpha, TenantRole::Owner);
    $alphaDevice = issueDeviceToken($alpha);

    $beta = $this->makeTenant();
    $this->actingForTenant($beta, TenantRole::Owner);
    $betaDevice = issueDeviceToken($beta);

    // Beta's owner cannot revoke alpha's token, and the answer is 404 rather than 403:
    // §11.2's IDOR rule applied to a credential rather than to a row, so a token id
    // belonging to another tenant is indistinguishable from one that does not exist.
    $this->deleteJson('/v1/devices/'.$alphaDevice['id'])->assertStatus(404);
    $this->deleteJson('/v1/devices/'.Str::uuid7())->assertStatus(404);

    // Alpha's token still works, which is what makes the 404 above isolation and not a
    // broken route.
    expect(DB::connection('pgsql_owner')->table('personal_access_tokens')
        ->where('id', $alphaDevice['id'])->exists())->toBeTrue();

    // Its own, it can.
    $this->deleteJson('/v1/devices/'.$betaDevice['id'])->assertStatus(204);

    expect(DB::connection('pgsql_owner')->table('personal_access_tokens')
        ->where('id', $betaDevice['id'])->exists())->toBeFalse();

    // A revoked token no longer authenticates.
    auth()->forgetGuards();
    $this->app['auth']->guard('web')->logout();

    $this->getJson('/v1/pager-sessions', ['Authorization' => 'Bearer '.$betaDevice['token']])
        ->assertStatus(401);
});

it('writes a device token and its audit row in one transaction, or neither', function (): void {
    /*
     * `§6`'s "crash mid-way", answered for the one act this class's own comment calls
     * "exactly the kind of act the log exists for".
     *
     * `issue()` called `createToken()` outside any transaction and then opened a SEPARATE
     * one for the audit row. A failure between the two left a LIVE CREDENTIAL WITH NO AUDIT
     * ROW -- and since the plaintext is returned only on the response that never arrived, a
     * token nobody knows exists and nobody can find to revoke. Found by Task 5's QA review.
     *
     * THE FAILURE IS INJECTED THROUGH A REAL CODE PATH rather than by mocking: `ip_address`
     * is cast `::inet`, so an un-parseable address makes the audit INSERT raise `22P02`
     * after the token row has been written. That is the exact interleaving the finding is
     * about, produced by an argument this method already accepts.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $issuer = User::query()->findOrFail((string) auth()->id());

    $before = DB::connection('pgsql_owner')->table('personal_access_tokens')->count();

    $failed = false;

    try {
        app(DeviceTokens::class)->issue(
            $issuer,
            $tenant,
            'kitchen-screen',
            Ability::PagerRead,
            60,
            'not-an-ip-address',
        );
    } catch (Throwable $thrown) {
        $failed = true;

        // The SQLSTATE is named, so a future change that fails for a different reason
        // cannot pass for this one -- R-049's lesson about summaries versus output.
        expect($thrown->getCode())->toBe('22P02', 'the audit insert failed for another reason');
    }

    expect($failed)->toBeTrue('the bogus address was accepted, so this case proves nothing');

    // THE ASSERTION: no credential survived the failed audit. Against the non-atomic
    // version this count was one higher, and the extra row was a working token.
    expect(DB::connection('pgsql_owner')->table('personal_access_tokens')->count())->toBe($before,
        'a device token survived a failed audit insert -- a live credential with no audit row',
    );

    // And the audit row is not there either, so the rollback took both and not just one.
    $audited = withTenant($tenant, fn (): int => (int) DB::connection('pgsql_owner')
        ->table('audit_log')
        ->where('tenant_id', $tenant)
        ->where('action', 'device_token.issued')
        ->count(), 'pgsql_owner');

    expect($audited)->toBe(0);

    // THE POSITIVE CONTROL: the same call with a real address writes BOTH. Without it, a
    // zero count above would be indistinguishable from an issue path that never works.
    $device = app(DeviceTokens::class)->issue(
        $issuer, $tenant, 'kitchen-screen', Ability::PagerRead, 60, '203.0.113.9',
    );

    expect(DB::connection('pgsql_owner')->table('personal_access_tokens')->count())->toBe($before + 1);

    $auditedNow = withTenant($tenant, fn (): int => (int) DB::connection('pgsql_owner')
        ->table('audit_log')
        ->where('tenant_id', $tenant)
        ->where('action', 'device_token.issued')
        ->where('subject_id', $device['id'])
        ->count(), 'pgsql_owner');

    expect($auditedNow)->toBe(1);
});
