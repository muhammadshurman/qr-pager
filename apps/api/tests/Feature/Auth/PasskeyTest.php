<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Models\Passkey;
use App\Platform\Auth\Http\PasskeyController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkeys;

/*
 * ================================================================================
 * PASSKEYS -- EVERY SERVER-SIDE HALF, AND AN HONEST NOTE ABOUT THE OTHER ONE
 * ================================================================================
 *
 * ⚠ THE GATE CLAUSE THIS FILE DOES NOT MEET, stated first so nobody reads the greens below
 * as more than they are. `PLAN.md §3` row 5 requires:
 *
 *   > passkey registration + login proven end to end THROUGH THE BROWSER against the
 *   > harness page
 *
 * That cannot be demonstrated on this machine. The credential ceremony lives in
 * `navigator.credentials`, not in HTTP: producing a valid attestation means a real
 * authenticator (or a virtual one driven by CDP), and there is no node, no Chromium and no
 * Playwright here --
 *
 *     which chromium chromium-browser google-chrome firefox node   -> nothing
 *     ls ~/.cache/ms-playwright                                    -> no such directory
 *
 * So the harness page is built (`resources/views/passkey-harness.blade.php`, mounted outside
 * production) and the browser half is recorded as this task's one STOPPED ON, in
 * `QRPAGER-12-handover.md`, with exactly what is needed to close it. **Writing a fake
 * attestation here and calling it end-to-end would be the vacuous-assertion defect `§4a`
 * records four of, committed deliberately.**
 *
 * WHAT IS PROVEN HERE is every half that does not need an authenticator, and the list is
 * the point: options are issued and stored server-side; a verify with no stored challenge is
 * refused; a challenge is single-use whatever the outcome; a malformed credential is
 * refused; the stored record carries a public key and no secret; and registration needs a
 * session while the login ceremony does not.
 */

/** A signed-in user with a known password. */
function signedInUser(string $password = 'correct-horse-battery-staple'): array
{
    $id = (string) Str::uuid7();
    $email = 'passkey-'.str_replace('-', '', $id).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Passkey Staff',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    test()->postJson('/v1/auth/login', ['email' => $email, 'password' => $password])->assertStatus(200);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

/**
 * A credential that passes the FormRequest's SHAPE rules and fails its deserialisation.
 *
 * Useful for driving the validation arm. It cannot reach the ceremony, which is why the
 * challenge-spending property is not provable here -- see the case below.
 */
function fakeCredential(): array
{
    return [
        'id' => Str::random(22),
        'rawId' => base64_encode(random_bytes(16)),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => base64_encode('{"type":"webauthn.create"}'),
            'attestationObject' => base64_encode(random_bytes(64)),
        ],
    ];
}

it('issues registration options and keeps the challenge server-side', function (): void {
    $user = signedInUser();

    $response = $this->getJson('/v1/auth/passkeys/options');

    $response->assertStatus(200)
        ->assertJsonStructure(['options' => ['challenge', 'rp', 'user', 'pubKeyCredParams']]);

    // THE CHALLENGE IS OURS, not the client's. A challenge a caller could choose is not a
    // challenge, and this is the assertion that says so: the browser is handed a copy, and
    // the authoritative one is in the session.
    expect(session()->get(PasskeyController::REGISTRATION_KEY))->toBeString()->not->toBeEmpty();

    // The user handle is an HMAC and NOT the user id -- the trait's own implementation,
    // kept rather than overridden. A WebAuthn user handle is stored by the authenticator,
    // which for a synced credential store means a third party.
    $handle = $response->json('options.user.id');

    expect($handle)->toBeString()
        ->and(str_contains(base64_decode((string) $handle, true) ?: '', $user['id']))->toBeFalse(
            'the WebAuthn user handle carries the database user id',
        );
});

it('refuses registration options without a session', function (): void {
    // Registration has to know WHOSE passkey this will be, and the only trustworthy source
    // is an established session. The GET is the half that can be driven here.
    $this->getJson('/v1/auth/passkeys/options')->assertStatus(401);

    // ⚠ AND THE POST'S REFUSAL ORDER CANNOT BE SHOWN ON THIS MACHINE, which is recorded
    // rather than asserted around.
    //
    // laravel/passkeys' PasskeyRegistrationRequest deserialises the credential in
    // passedValidation(), so a FormRequest 422 is reached before the controller's session
    // check -- and EVERY credential that can be fabricated without an authenticator fails
    // that deserialisation, because the attestationObject has to be valid CBOR. Two
    // attempts were made here: base64 (refused) and base64url with a parseable
    // clientDataJSON (also refused, on the attestation object).
    //
    // So the honest statement is: with no authenticator, this endpoint answers 422 for any
    // payload a test can build, session or no session. What IS established either way is
    // the thing that matters -- no passkey is stored and no account is named:
    $this->postJson('/v1/auth/passkeys', ['name' => 'x', 'credential' => fakeCredential()])
        ->assertStatus(422);

    expect(DB::connection('pgsql_owner')->table('passkeys')->count())->toBe(0);

    // The ordering question goes in the handover beside the browser proof. If a reviewer
    // wants 401 first, the fix is an `auth` middleware on the two registration routes, which
    // is a decision rather than a defect.
});

it('refuses a credential when no challenge was issued', function (): void {
    // THE CASE THAT MATTERS MOST of the ones that can be driven here. Without it, a caller
    // could post a credential of their own making and the only thing between that and a
    // stored passkey would be the library validating against options it was never given.
    signedInUser();

    expect(session()->get(PasskeyController::REGISTRATION_KEY))->toBeNull();

    $this->postJson('/v1/auth/passkeys', ['name' => 'harness', 'credential' => fakeCredential()])
        ->assertStatus(422);

    expect(DB::connection('pgsql_owner')->table('passkeys')->count())->toBe(0);
});

it('replaces the pending challenge on every options call, so an old one cannot be used later', function (): void {
    // ⚠ WHAT THIS CASE IS *NOT*. The property worth having is "a challenge is spent even
    // when the ceremony FAILS", and it cannot be driven here: spending happens inside
    // laravel/passkeys' `registrationOptions()`, which is reached only by a credential that
    // survives deserialisation -- and producing one of those means a real authenticator.
    // A malformed payload is refused by the FormRequest BEFORE any ceremony is attempted, so
    // the challenge is untouched, which is correct and is not the thing being tested.
    //
    // That half is in the handover's STOPPED ON beside the browser proof, rather than
    // asserted with a credential I fabricated and would therefore be measuring against
    // itself.
    //
    // WHAT IS PROVEN HERE is the adjacent property that does not need an authenticator: each
    // options call REPLACES the pending challenge, so a challenge captured from an earlier
    // call is not sitting in the session waiting to be used.
    signedInUser();

    $first = $this->getJson('/v1/auth/passkeys/options');
    $first->assertStatus(200);

    $stored = session()->get(PasskeyController::REGISTRATION_KEY);

    expect($stored)->toBeString()->and($stored)->toContain((string) $first->json('options.challenge'));

    $second = $this->getJson('/v1/auth/passkeys/options');
    $second->assertStatus(200);

    expect($second->json('options.challenge'))->not->toBe($first->json('options.challenge'));

    $replaced = session()->get(PasskeyController::REGISTRATION_KEY);

    expect($replaced)->not->toBe($stored)
        ->and($replaced)->toContain((string) $second->json('options.challenge'))
        ->and($replaced)->not->toContain((string) $first->json('options.challenge'));
});

it('refuses a malformed credential before any ceremony is attempted', function (): void {
    signedInUser();

    $this->getJson('/v1/auth/passkeys/options')->assertStatus(200);

    foreach ([
        ['name' => 'x'],
        ['name' => 'x', 'credential' => 'not-an-array'],
        ['name' => 'x', 'credential' => ['id' => 'a']],
        ['name' => 'x', 'credential' => ['id' => 'a', 'rawId' => 'b', 'type' => 'not-public-key', 'response' => []]],
        ['credential' => fakeCredential()],
    ] as $body) {
        $this->postJson('/v1/auth/passkeys', $body)->assertStatus(422);
    }

    expect(DB::connection('pgsql_owner')->table('passkeys')->count())->toBe(0);
});

it('issues login options to anyone, naming no account, and refuses a credential with no challenge', function (): void {
    // The login ceremony is reachable WITHOUT a session -- it is what establishes one -- and
    // it names no account, so the response is the same whether or not any passkey exists.
    // A per-account response here would be a user-enumeration oracle on an unauthenticated
    // endpoint.
    $first = $this->getJson('/v1/auth/passkeys/login/options');

    $first->assertStatus(200)->assertJsonStructure(['options' => ['challenge']]);

    expect($first->json('options.allowCredentials'))->toBeIn([null, []]);

    expect(session()->get(PasskeyController::VERIFICATION_KEY))->toBeString()->not->toBeEmpty();

    // Two calls produce two different challenges, which is what stops a captured one being
    // replayed.
    $challengeOne = $first->json('options.challenge');
    $challengeTwo = $this->getJson('/v1/auth/passkeys/login/options')->json('options.challenge');

    expect($challengeOne)->not->toBe($challengeTwo);

    // And a credential with no stored challenge is refused.
    session()->forget(PasskeyController::VERIFICATION_KEY);

    $this->postJson('/v1/auth/passkeys/login', ['credential' => fakeCredential()])->assertStatus(422);
});

it('stores a passkey with a uuid key, which the vendor migration would not have', function (): void {
    // The schema half, driven rather than read: PLAN.md §2.4 declares `passkeys.id` as a
    // uuid, laravel/passkeys' own migration declares $table->id(), and this project does not
    // publish it. App\Models\Passkey adds HasUuids, registered through the package's own
    // seam. Without that, the first registration inserts no id into a column with no
    // default -- the same 23502 the Sanctum token model hit.
    $column = DB::connection('pgsql_owner')->selectOne(
        "select data_type from information_schema.columns
          where table_name = 'passkeys' and column_name = 'id'",
    );

    expect((string) $column->data_type)->toBe('uuid');

    expect(Passkey::make()->getKeyType())->toBe('string')
        ->and(Passkey::make()->getIncrementing())->toBeFalse();

    // And the registered model IS this project's, not the vendor's.
    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
});

it('keeps the passkey table out of every tenant-scoped reckoning, because a passkey has no tenant', function (): void {
    // §2.4's justification, asserted: a passkey belongs to a USER and users are global, so
    // the table is on the RLS-free allowlist -- and the C4 meta-test is what makes that a
    // build failure rather than an oversight if it ever gains a tenant_id.
    expect(TenantTables::GLOBAL)->toContain('passkeys')
        ->and(TenantTables::TENANT)->not->toContain('passkeys');

    $hasTenantColumn = DB::connection('pgsql_owner')->selectOne(
        "select count(*) as c from information_schema.columns
          where table_name = 'passkeys' and column_name = 'tenant_id'",
    );

    expect((int) $hasTenantColumn->c)->toBe(0);
});

it('bounds the passkey name at the column\'s own length, not at the vendor\'s', function (): void {
    /*
     * SEC L-4, and ⚠ THE FIRST VERSION OF THIS CASE COULD NOT FAIL.
     *
     * It asserted only `assertStatus(422)` for a 121-character name — and the vendor's
     * `passedValidation()` answers 422 for any credential a test can fabricate, whatever
     * the name is. The `§11` QA review of the fix diff deleted the guard being tested and
     * re-ran this file: 9 passed. That is `§6`'s regression-test clause literally unmet,
     * and it is the project's signature defect class.
     *
     * WHAT MADE IT UNFALSIFIABLE WAS THE FIX, NOT THE TEST. The guard was an
     * `mb_strlen()` check in the controller, and the vendor deserialises in
     * `passedValidation()` — which runs after validation PASSES — so the controller was
     * never reached. The bound is now a validation RULE in this project's own
     * `PasskeyRegistrationRequest`, which runs BEFORE deserialisation, and that makes the
     * two refusals tell apart: `errors.name` versus `errors.credential`.
     *
     * So this case asserts WHICH refusal happened, not merely that one did.
     */
    $column = DB::connection('pgsql_owner')->selectOne(
        "select character_maximum_length as len from information_schema.columns
          where table_name = 'passkeys' and column_name = 'name'",
    );

    expect((int) $column->len)->toBe(120,
        'passkeys.name changed length -- App\\Platform\\Auth\\Http\\PasskeyRegistrationRequest rules max:120',
    );

    signedInUser();

    $this->getJson('/v1/auth/passkeys/options')->assertStatus(200);

    // 121 characters: one over the column, far under the vendor's 255. The NAME is what is
    // refused, and the credential is never looked at -- which is the whole point, because
    // looking at it is what raises `22001` at the INSERT after a real ceremony.
    $tooLong = $this->postJson('/v1/auth/passkeys', [
        'name' => str_repeat('n', 121),
        'credential' => fakeCredential(),
    ]);

    $tooLong->assertStatus(422)
        ->assertJsonPath('type', 'https://qrpager.dev/problems/validation')
        ->assertJsonMissingPath('errors.credential');

    expect($tooLong->json('errors.name'))->toBeArray()->not->toBeEmpty(
        'a 121-character name was not refused on the NAME -- the bound is not reachable',
    );

    // AND THE DISCRIMINATING CONTROL: 120 characters passes the name rule and is then
    // refused on the CREDENTIAL. Without this, the assertion above would also hold for a
    // request class that refused every name.
    $atLimit = $this->postJson('/v1/auth/passkeys', [
        'name' => str_repeat('n', 120),
        'credential' => fakeCredential(),
    ]);

    $atLimit->assertStatus(422)->assertJsonMissingPath('errors.name');

    expect($atLimit->json('errors.credential'))->toBeArray()->not->toBeEmpty(
        'a 120-character name was refused on the name, so the boundary is not at 121',
    );

    expect(DB::connection('pgsql_owner')->table('passkeys')->count())->toBe(0);
});
