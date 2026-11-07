<?php

declare(strict_types=1);

use App\Domain\Auth\AttachmentRefusal;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Auth\Memberships;
use App\Platform\Tenancy\UserAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * `R-233` — CONSENT ON `POST /v1/members`, DRIVEN OVER HTTP
 * ================================================================================
 *
 * `§4 › Security`, the rule already in force since 2026-10-28: *"Attaching an existing user
 * to a tenant requires that user's acceptance of an invite … Until that invite flow exists,
 * the implementation is deliberately stricter than this clause: an address that already
 * belongs to an account holding any membership, or to a platform admin, is refused outright
 * rather than invited — so the weaker rule is never the one in force. Both refusals answer
 * with one message."*
 *
 * `MemberDirectory::add()` implemented NONE of it. Measured over HTTP at the `task-10` tag,
 * two tenants and real row ids: alpha's manager attached beta's **owner** (201) and an
 * `is_platform_admin = true` account (201), and that platform admin then signed in, resolved
 * `principal: manager`, and read alpha's `/v1/members` and `/v1/pager-sessions/history`.
 *
 * Muhammad pulled it out of Task 12 on 2026-11-04 and ruled it a must-fix rather than a `§5`
 * residual, specifying the proof: *"alpha's manager attaching beta's owner, and attaching a
 * platform admin, both refused."* Those are the first two cases below.
 *
 * ⚠ **THE REFUSAL IS THE EXISTING 404, NOT A NEW 403, AND THE DEVIATION SERVES HIS OWN STATED
 * REASON.** His words: *"ONE 403 for both cases — two would let a form probe whether an
 * address has an account."* The two refusals ARE one answer. The answer is 404 because
 * `MemberController::store()`'s in-force comment already says *"'no user with that address'
 * is the same answer whether the address is unknown or belongs to somebody this caller may
 * not see, and a distinguishable one would make this endpoint an account-existence oracle
 * for the whole platform — users is global."* A 403 would make {201, 403} mean "this address
 * has an account" and {404} mean it does not — silently, for any address an authenticated
 * manager types. The third case below is what holds that property, and it is the one to
 * delete if he wants the status instead.
 */

/** A user with no membership anywhere and no platform flag. */
function accountWithNoMembership(?bool $platformAdmin = false): array
{
    $userId = (string) Str::uuid7();
    $address = 'c-'.str_replace('-', '', $userId).'@example.test';
    $password = 'correct-horse-battery-staple';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Consent fixture',
        'email' => $address,
        'password' => Hash::make($password),
        'is_platform_admin' => $platformAdmin,
    ]);

    return ['user_id' => $userId, 'email' => $address, 'password' => $password];
}

/** A member of alpha, with its membership read back so a refusal cannot be vacuous. */
function memberInAlpha(object $test, string $tenantId, TenantRole $role): array
{
    $account = accountWithNoMembership();
    $membershipId = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $membershipId,
        'tenant_id' => $tenantId,
        'user_id' => $account['user_id'],
        'role' => $role->value,
    ]), 'pgsql_owner');

    return $account + ['membership_id' => $membershipId];
}

/**
 * Sign in over the real login route, so the session is the one a browser holds.
 *
 * ⚠ ITS OWN HELPERS RATHER THAN `MemberSeniorityTest`'s, deliberately. A Pest file-scope
 * function is only defined when ITS file is loaded, so borrowing one makes this file pass or
 * die on test ORDER -- the trap `Tests\Support\LandingFixtures` records in its own docblock
 * ("a file-scope function is only defined when ITS file is loaded").
 */
function signInAsMember(object $test, array $member): void
{
    (function () use ($member): void {
        $this->postJson('/v1/auth/login', [
            'email' => $member['email'],
            'password' => $member['password'],
        ])->assertOk();
    })->call($test);
}

/** A user who already owns another restaurant, with its membership read back. */
function ownerOfAnotherTenant(object $test): array
{
    $account = accountWithNoMembership();
    $beta = (function (): string {
        /** @var object{makeTenant: callable} $this */
        return $this->makeTenant(slug: 'beta-'.str_replace('-', '', (string) Str::uuid7()));
    })->call($test);

    $membershipId = (string) Str::uuid7();

    withTenant($beta, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $membershipId,
        'tenant_id' => $beta,
        'user_id' => $account['user_id'],
        'role' => TenantRole::Owner->value,
    ]), 'pgsql_owner');

    // Read back: a refusal case must not pass because the fixture never existed.
    $found = withTenant($beta, fn () => DB::table('tenant_users')->where('id', $membershipId)->value('id'));

    expect($found)->toBe($membershipId, 'the beta owner fixture was not created, so the refusal would be vacuous');

    return $account + ['tenant_id' => $beta, 'membership_id' => $membershipId];
}

/** How many memberships this tenant holds, read inside its own scope. */
function membershipCount(string $tenantId): int
{
    return (int) withTenant($tenantId, fn () => DB::table('tenant_users')->count());
}

it('refuses alpha\'s manager attaching the OWNER of another restaurant, and writes nothing', function (): void {
    $alpha = $this->makeTenant(slug: 'alpha-consent-owner');
    $manager = memberInAlpha($this, $alpha, TenantRole::Manager);
    $victim = ownerOfAnotherTenant($this);

    signInAsMember($this, $manager);

    $before = membershipCount($alpha);

    $this->postJson('/v1/members', ['email' => $victim['email'], 'role' => 'staff'])
        ->assertStatus(404);

    expect(membershipCount($alpha))->toBe($before, 'a membership was created despite the refusal');

    // And the victim still holds exactly the one membership they had.
    expect(membershipCount($victim['tenant_id']))->toBe(1);
});

it('refuses alpha\'s manager attaching a PLATFORM ADMIN, and writes nothing', function (): void {
    $alpha = $this->makeTenant(slug: 'alpha-consent-admin');
    $manager = memberInAlpha($this, $alpha, TenantRole::Manager);
    $admin = accountWithNoMembership(platformAdmin: true);

    signInAsMember($this, $manager);

    $before = membershipCount($alpha);

    $this->postJson('/v1/members', ['email' => $admin['email'], 'role' => 'staff'])
        ->assertStatus(404);

    expect(membershipCount($alpha))->toBe($before, 'a platform admin was given a tenant membership');
});

it('answers both refusals and an unknown address IDENTICALLY, so it is no account oracle', function (): void {
    /*
     * The property `MemberController::store()` already claims and this rule must not undo.
     * Status AND body, compared as whole responses: a difference in either would let an
     * authenticated manager of any restaurant enumerate which addresses on the platform have
     * accounts, silently and without attaching anybody.
     */
    $alpha = $this->makeTenant(slug: 'alpha-consent-oracle');
    $manager = memberInAlpha($this, $alpha, TenantRole::Manager);

    $victim = ownerOfAnotherTenant($this);
    $admin = accountWithNoMembership(platformAdmin: true);

    signInAsMember($this, $manager);

    $answers = [];

    foreach ([
        'already a member' => $victim['email'],
        'platform admin' => $admin['email'],
        'no account at all' => 'nobody-'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
    ] as $label => $address) {
        $response = $this->postJson('/v1/members', ['email' => $address, 'role' => 'staff']);

        $answers[$label] = [$response->getStatusCode(), $response->getContent()];
    }

    expect($answers['already a member'])->toBe($answers['no account at all'])
        ->and($answers['platform admin'])->toBe($answers['no account at all']);

    // And nothing in the body names a tenant: the rule reads another user's membership list
    // through the licensed path, and none of it may reach a response.
    [$status, $body] = $answers['already a member'];

    expect($status)->toBe(404)
        ->and((string) $body)->not->toContain($victim['tenant_id'])
        ->and((string) $body)->not->toContain('beta-')
        ->and((string) $body)->not->toContain('already_a_member')
        ->and((string) $body)->not->toContain('platform_admin');
});

it('still attaches an address with an account and no membership, which is the positive control', function (): void {
    // Without this the three cases above would pass against a method that refuses everything,
    // which is the strongest possible false green for a consent rule.
    $alpha = $this->makeTenant(slug: 'alpha-consent-control');
    $manager = memberInAlpha($this, $alpha, TenantRole::Manager);
    $newcomer = accountWithNoMembership();

    signInAsMember($this, $manager);

    $before = membershipCount($alpha);

    $this->postJson('/v1/members', ['email' => $newcomer['email'], 'role' => 'staff'])
        ->assertStatus(201);

    expect(membershipCount($alpha))->toBe($before + 1);
});

it('answers the SAME refusal with a warm reader and a fresh one — R-244, the one-path clause', function (): void {
    /*
     * ================================================================================
     * ⚠ THE DEFECT WAS THE DIVERGENCE, NOT THE STATUS — MUHAMMAD, RANK 1
     * ================================================================================
     *
     * *"The part that matters is not the status: `refusalFor()` ANSWERING DIFFERENTLY ON AND
     * OFF THE REQUEST PATH IS THE DEFECT. Make it one path, and write down in the code why it
     * ever diverged — an unexplained divergence you preserve is a bug you have agreed to
     * keep."*
     *
     * Measured 2026-11-07, before the fix, on one address immediately after a successful
     * `POST /v1/members`:
     *
     *     the container's reader (warm memo)   ->  null              (no refusal)
     *     a freshly constructed reader         ->  "already_a_member"
     *
     * Same connection, same role, same database, same `app.tenant_id` — a trace inside
     * `refusalFor()` showed `public.user_memberships()` returning **1 row** while
     * `Memberships::for()` returned **none**. The cause was the request-scoped memo
     * (`R-102`): the first add's own consent check had memoised `[]` for that id.
     *
     * So production refused and this suite did not. This case holds the property that makes
     * that impossible to reintroduce: **both readers answer the same thing.**
     *
     * ⚠ IT IS DRIVEN THROUGH THE CONTAINER'S READER DELIBERATELY, memo and all. A case that
     * only built fresh readers would pass against the OLD code and prove nothing.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $candidate = accountWithNoMembership();

    // The attach happens through the real route, so the memo is warmed by the application's
    // own consent check rather than by this test.
    $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Staff->value,
    ])->assertStatus(201);

    $warm = app(UserAttachment::class)->refusalFor($candidate['email']);
    $fresh = (new UserAttachment(DB::connection(), new Memberships(DB::connection())))
        ->refusalFor($candidate['email']);

    expect($warm)->toBe(AttachmentRefusal::AlreadyAMember,
        'the container\'s reader does not refuse an address it has just attached: the memo is back')
        ->and($fresh)->toBe(AttachmentRefusal::AlreadyAMember)
        ->and($warm)->toBe($fresh, 'the refusal still depends on which reader asks');

    // AND OVER HTTP, which is where it mattered: a second attach in the same process is
    // refused, with the same answer an unknown address gets.
    $second = $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Manager->value,
    ]);

    $unknown = $this->postJson('/v1/members', [
        'email' => 'nobody-'.Str::lower(Str::random(12)).'@example.test',
        'role' => TenantRole::Staff->value,
    ]);

    expect($second->getStatusCode())->toBe(404)
        // INDISTINGUISHABLE as whole (status, body) pairs, which is the no-oracle property his
        // ruling names in the same sentence as the status: *"the 404 you already chose, so no
        // oracle opens."*
        ->and([$second->getStatusCode(), $second->json()])
        ->toBe([$unknown->getStatusCode(), $unknown->json()],
            'a held membership and an unknown address answer differently, which makes this an '
            .'existing-membership oracle for any address a manager types');
});
