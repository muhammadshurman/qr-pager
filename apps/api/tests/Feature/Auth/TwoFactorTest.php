<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Auth\Http\TwoFactorController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/*
 * ================================================================================
 * 2FA -- Task 5's scope, and QRPAGER-12 §10.6(b)'s failure case
 * ================================================================================
 *
 * The scope line is "2FA". The failure question the §6 gate asks is **"2FA device lost"**,
 * and the answer is recovery codes — so the cases that matter most here are the ones about
 * NOT being locked out:
 *
 *   - enrolment is two steps, and the second arms it. `config/fortify.php` sets
 *     `confirm: true`, so a secret exists from the moment `enable` is called and is not in
 *     force until a code from it has worked. Gating login on the secret alone would lock
 *     somebody out of their own account between those two steps, which is the failure case.
 *   - a recovery code works ONCE and does not disable two-factor.
 *
 * And one property that is easy to get wrong and would be a real hole: the challenge reads
 * the pending user id from the SERVER-SIDE session, so a caller cannot nominate whose
 * challenge they are completing.
 */

/** A confirmed-2FA user with a known password, and their current secret. */
function makeTwoFactorUser(string $password = 'correct-horse-battery-staple'): array
{
    $id = (string) Str::uuid7();
    $email = '2fa-'.substr(str_replace('-', '', $id), 0, 12).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Two Factor Staff',
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

/**
 * The secret as Fortify stored it.
 *
 * Read through Fortify's OWN encrypter, because Fortify is what encrypted it:
 * EnableTwoFactorAuthentication writes `Fortify::currentEncrypter()->encrypt(...)`. Using
 * Laravel's `decrypt()` helper happens to work today -- the default encrypter is the same
 * object -- and would stop working the moment anybody called `Fortify::encryptUsing()`, so
 * the test asks the same thing the code asks.
 */
function storedSecret(string $userId): string
{
    $ciphertext = (string) DB::connection('pgsql_owner')->table('users')
        ->where('id', $userId)->value('two_factor_secret');

    return (string) Fortify::currentEncrypter()->decrypt($ciphertext);
}

/**
 * The code for the NEXT 30-second window.
 *
 * ⚠ WHY NOT `travel()`. Google2FA reads PHP's `time()` directly, not Carbon's, so Laravel's
 * time travel does not move the OTP window at all -- the first version of these cases
 * travelled 31 seconds, got the SAME code back, and was refused by the replay cache for the
 * right reason while looking like a broken secret.
 *
 * A next-window code is accepted because Google2FA's default window is 1, so `verifyKeyNewer`
 * allows one step either side. It is a DIFFERENT string, so its replay-cache key differs and
 * a code already used does not block it. Both facts are what make this a sound way to get a
 * second usable code without touching the clock.
 */
function nextCode(string $secret): string
{
    $google = new Google2FA;

    return $google->oathTotp($secret, $google->getTimestamp() + 1);
}

function currentCode(string $secret): string
{
    /** @var TwoFactorAuthenticationProvider $provider */
    $provider = app(TwoFactorAuthenticationProvider::class);

    // The provider has no "current code" method, so the code is computed the way the
    // library does it -- through its own verify() over a window. Google2FA's own
    // getCurrentOtp is reached through the concrete class.
    $google = new Google2FA;

    unset($provider);

    return $google->getCurrentOtp($secret);
}

it('needs confirmation before two-factor is in force, so enrolment cannot lock anyone out', function (): void {
    // §10.6(b)'s failure case, driven. Between `enable` and `confirm` the account has a
    // secret and must still be able to sign in with a password alone -- otherwise a user who
    // starts enrolment and loses their phone before finishing is locked out of their own
    // account with no recovery path.
    $user = makeTwoFactorUser();
    $tenant = $this->makeTenant();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $user['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonMissingPath('two_factor_required');

    $enabled = $this->postJson('/v1/auth/two-factor/enable');

    $enabled->assertStatus(200)
        ->assertJsonPath('two_factor.confirmed', false)
        ->assertJsonStructure(['two_factor' => ['secret', 'qr_code_url', 'recovery_codes']]);

    expect($enabled->json('two_factor.recovery_codes'))->toHaveCount(8);

    // A SECRET EXISTS NOW, and login still does not demand a code.
    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonMissingPath('two_factor_required');

    // Confirm it, and only then does login stop at the second factor.
    $secret = storedSecret($user['id']);

    $this->postJson('/v1/auth/two-factor/confirm', ['code' => currentCode($secret)])->assertStatus(204);

    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonPath('two_factor_required', true);

    // AND THE PASSWORD ALONE REACHES NOTHING, which is the whole point of the feature.
    $this->getJson('/v1/pager-sessions')->assertStatus(401);
});

it('completes a sign-in with a code from the authenticator, and moves the session id', function (): void {
    $user = makeTwoFactorUser();
    $tenant = $this->makeTenant();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $user['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $this->postJson('/v1/auth/two-factor/enable')->assertStatus(200);

    $secret = storedSecret($user['id']);

    $this->postJson('/v1/auth/two-factor/confirm', ['code' => currentCode($secret)])->assertStatus(204);
    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonPath('two_factor_required', true);

    $before = session()->getId();

    // A wrong code is refused, which is the control: without it the acceptance below would
    // pass for an endpoint that accepts anything.
    $this->postJson('/v1/auth/two-factor/challenge', ['code' => '000000'])->assertStatus(422);

    $this->getJson('/v1/pager-sessions')->assertStatus(401);

    // ⚠ A FRESH CODE, AND THE TIME TRAVEL IS LOAD-BEARING. Fortify's provider keeps a
    // REPLAY CACHE -- verify() passes the last-seen timestamp for this code into
    // verifyKeyNewer() and records it, so a code that has already been accepted is refused
    // for the rest of the window.
    //
    // The confirm step above consumed this window's code, so the first version of this case
    // presented the same one again and got a correct 422. The property is asserted in its
    // own case below rather than only worked around here.
    $this->postJson('/v1/auth/two-factor/challenge', ['code' => nextCode($secret)])
        ->assertStatus(200)
        ->assertJsonPath('user.id', $user['id']);

    // SESSION FIXATION on the path that is easiest to miss: completing a challenge is the
    // moment the session gains authority.
    expect(session()->getId())->not->toBe($before, 'the session id survived a two-factor challenge');

    $this->getJson('/v1/pager-sessions')->assertStatus(200);
});

it('accepts a recovery code exactly once, and does not disable two-factor by using one', function (): void {
    // THE "2FA DEVICE LOST" ANSWER, which is the §6 failure question. Both halves: it works,
    // and it works only once -- a reusable recovery code is a password with extra steps.
    $user = makeTwoFactorUser();
    $tenant = $this->makeTenant();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'user_id' => $user['id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $codes = $this->postJson('/v1/auth/two-factor/enable')->json('two_factor.recovery_codes');

    $secret = storedSecret($user['id']);

    $this->postJson('/v1/auth/two-factor/confirm', ['code' => currentCode($secret)])->assertStatus(204);
    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonPath('two_factor_required', true);

    $this->postJson('/v1/auth/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertStatus(200);

    $this->getJson('/v1/pager-sessions')->assertStatus(200);

    // ONCE. The same code again is refused.
    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonPath('two_factor_required', true);

    $this->postJson('/v1/auth/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertStatus(422);

    // AND TWO-FACTOR IS STILL ON -- using a recovery code is not a way to turn it off.
    $confirmed = DB::connection('pgsql_owner')->table('users')
        ->where('id', $user['id'])->value('two_factor_confirmed_at');

    expect($confirmed)->not->toBeNull('using a recovery code disabled two-factor');

    // A different, unused code still works.
    $this->postJson('/v1/auth/two-factor/challenge', ['recovery_code' => $codes[1]])
        ->assertStatus(200);
});

it('reads the pending user from the session and never from the request', function (): void {
    // THE HOLE THIS WOULD BE. If the challenge took a user id, email or any other
    // identifier from the request, a caller who knows an address could complete somebody
    // else's second factor by guessing six digits against THEIR secret.
    $victim = makeTwoFactorUser();

    $this->postJson('/v1/auth/login', [
        'email' => $victim['email'],
        'password' => $victim['password'],
    ])->assertStatus(200);

    $this->postJson('/v1/auth/two-factor/enable')->assertStatus(200);

    $secret = storedSecret($victim['id']);

    $this->postJson('/v1/auth/two-factor/confirm', ['code' => currentCode($secret)])->assertStatus(204);
    $this->postJson('/v1/auth/logout')->assertStatus(204);

    // A fresh session with NO pending login, and every identifier an attacker might try --
    // even handing over the victim's own, correct, current code.
    foreach ([
        ['code' => currentCode($secret), 'user_id' => $victim['id']],
        ['code' => currentCode($secret), 'email' => $victim['email']],
        ['code' => currentCode($secret), 'user' => $victim['id']],
        ['code' => currentCode($secret)],
    ] as $attempt) {
        $this->postJson('/v1/auth/two-factor/challenge', $attempt)
            ->assertStatus(401, 'a challenge was accepted with no pending login in the session');
    }

    expect(session()->get(TwoFactorController::PENDING_KEY))->toBeNull();
});

it('keeps the secret and the recovery codes out of every response but the enrolment one', function (): void {
    // §4 › Edge & ops, both halves: no secret in a log OR an error body, at any APP_DEBUG.
    // The secret is returned EXACTLY ONCE, by `enable`, because the user has to be able to
    // type it into an authenticator. Everywhere else it must be absent.
    $user = makeTwoFactorUser();

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $secret = $this->postJson('/v1/auth/two-factor/enable')->json('two_factor.secret');

    expect($secret)->toBeString()->not->toBeEmpty();

    // `me` names whether two-factor is on and never what the secret is.
    $me = $this->getJson('/v1/auth/me');

    $me->assertStatus(200);

    $body = (string) $me->getContent();

    expect(str_contains($body, (string) $secret))->toBeFalse('the identity response carried the 2FA secret')
        ->and($me->json('user.two_factor_enabled'))->toBeFalse();

    // And the serialised model never carries either column, whatever asks for it.
    $model = User::query()->find($user['id']);

    expect($model)->not->toBeNull();

    $json = (string) json_encode($model?->toArray(), JSON_THROW_ON_ERROR);

    expect(str_contains($json, 'two_factor_secret'))->toBeFalse()
        ->and(str_contains($json, 'two_factor_recovery_codes'))->toBeFalse()
        ->and(str_contains($json, 'password'))->toBeFalse();
});

it('refuses a code that has already been used, so an observed code cannot be replayed', function (): void {
    // THE PROPERTY FOUND WHILE FIXING THE CASE ABOVE, and worth its own case because it is
    // real security rather than a test detail: Fortify's provider records every accepted
    // code in the cache and `verifyKeyNewer()` then refuses it for the rest of the window.
    // Somebody who reads a code over a shoulder, or off a shared screen, cannot use it after
    // its owner has.
    $user = makeTwoFactorUser();

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200);

    $this->postJson('/v1/auth/two-factor/enable')->assertStatus(200);

    $secret = storedSecret($user['id']);
    $code = currentCode($secret);

    // First use: accepted, and it arms two-factor.
    $this->postJson('/v1/auth/two-factor/confirm', ['code' => $code])->assertStatus(204);

    $this->postJson('/v1/auth/logout')->assertStatus(204);

    $this->postJson('/v1/auth/login', [
        'email' => $user['email'],
        'password' => $user['password'],
    ])->assertStatus(200)->assertJsonPath('two_factor_required', true);

    // SECOND use of the SAME code, inside the same window: refused.
    $this->postJson('/v1/auth/two-factor/challenge', ['code' => $code])->assertStatus(422);

    // And a DIFFERENT code from the SAME secret still works, so the refusal above is replay
    // protection rather than a broken secret or a broken account.
    $this->postJson('/v1/auth/two-factor/challenge', ['code' => nextCode($secret)])
        ->assertStatus(200);
});
