<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * 10e's NAMED INVARIANT. `QRPAGER-29 §0`, VERBATIM:
 * ================================================================================
 *
 *   *"No member-management action can raise a caller's own privilege or strand a tenant: a
 *   manager cannot grant an ability they do not hold, cannot change their own role, and the
 *   last owner cannot be removed or demoted — proven by driving each attempt over HTTP and
 *   asserting the refusal."*
 *
 * `§4` of that ticket spells the gate out: **(a)** a manager granting `owner`, on both
 * `members.store` AND `members.update`; **(b)** a manager and an owner each targeting their OWN
 * membership id; **(c)** the last owner removed and demoted, through the screen's own route set.
 * And: *"Every refusal is a regression test that fails without the fix — each proven red."*
 *
 * ================================================================================
 * WHAT WAS ALREADY TRUE BEFORE 10e, MEASURED, SO NONE OF THIS IS VACUOUS
 * ================================================================================
 *
 * `QRPAGER-29 §0` carries the table and it was checked against `RoleMatrix` before the ticket
 * was written:
 *
 *   (a) grant a senior role       **NOT proven and NOT enforced.** Both FormRequests validated
 *                                 `role` against the whole of `TenantRole::cases()` and never
 *                                 compared it with the actor's. `R-106` measured it by driving
 *                                 the route.
 *   (b) change its own role       **NOT proven and NOT enforced.** `changeRole()` took the
 *                                 actor id only to write the audit row; the target membership
 *                                 id was never compared with the actor's own. `R-106`: a
 *                                 manager `PATCH /v1/members/{own-id}` with `role: owner`
 *                                 answered **200**.
 *   (c) last owner                **ALREADY PROVEN** — `MemberDirectory`'s rule, `FOR UPDATE`-
 *                                 locked, driven by `RoleMatrixTest` and `MemberConcurrencyTest`
 *                                 in two processes. 10e re-drives it **through the screen's own
 *                                 route set** and deliberately does NOT re-prove the lock.
 *
 * So (a) and (b) are new enforcement and the cases below are their regression tests; (c) is a
 * re-drive and says so in its own case name.
 *
 * ================================================================================
 * ⚠ THE REFUSALS ARE 403 AND THE LAST-OWNER REFUSAL IS 404, AND THAT IS DELIBERATE
 * ================================================================================
 *
 * `QRPAGER-29 §5`: *"A refusal must not be an oracle. `members.store` already answers 404 for
 * an unknown address AND for one this caller may not see, deliberately, so the platform's
 * `users` table is not an account-existence oracle. A new seniority refusal must not undo that
 * by answering distinguishably."*
 *
 * `MemberActionDenied`'s docblock carries the argument in full. The short version: both new
 * refusals depend only on facts the caller already holds — the role they just requested against
 * the role they are acting under, and the target id against their own membership id — so there
 * is nothing for a 403 to leak. The last-owner refusal stays 404 because it is about the
 * TENANT's state and a distinguishable answer would be information about other people's roles.
 *
 * **And the ordering is asserted, not just the status**: the case below drives `store` with an
 * UNKNOWN address and a senior role, and requires the same 403 a known address gets. Checked
 * after the email lookup instead, the pair (404, 403) would have told any manager whether any
 * address on the platform has an account.
 *
 * ================================================================================
 * ⚠ BOTH GUARDS ARE REVERT-PROVED, AND THE SPLIT IS MEASURED RATHER THAN ASSUMED
 * ================================================================================
 *
 * `QRPAGER-29 §4`: *"Every refusal is a regression test that fails without the fix — each
 * proven red."* Measured on 2026-11-02, reverting ONE guard at a time and running this file:
 *
 *   `requireSeniority()` removed      **4 red**, 10 green — *appointing an OWNER through
 *                                     store*, *promoting somebody to OWNER through update*,
 *                                     *the account-oracle pair*, and *the demoted manager on
 *                                     an old session*.
 *   `refuseSelfRoleChange()` removed  **2 red**, 12 green — *a manager DEMOTING itself* and
 *                                     *an OWNER changing its own role*.
 *
 * ⚠ **AND THE INTERESTING RESULT IS WHICH CASE DID *NOT* REDDEN: *"refuses a MANAGER changing
 * its own role, which is the exact request `R-106` measured at 200"* STAYED GREEN with the
 * self-role guard gone.** That is not a gap — it is the two guards overlapping on `R-106`'s own
 * request, because a manager asking for `owner` is refused by SENIORITY whether or not the
 * target is themselves. It is the reason *"refuses a manager DEMOTING itself"* exists beside
 * it: `staff` is junior to `manager`, so seniority permits that request and only the self-role
 * clause refuses it.
 *
 * So each guard has cases only it can fail, which is what makes them two guards rather than
 * one with a spare branch — and the prediction to that effect was written into that case's own
 * comment before the revert was run, then measured.
 */

/** A real user with a password, and a membership in `$tenantId` at `$role`. */
function memberIn(object $test, string $tenantId, TenantRole $role, ?string $email = null): array
{
    $userId = (string) Str::uuid7();
    $address = $email ?? 'm-'.str_replace('-', '', $userId).'@example.test';
    $password = 'correct-horse-battery-staple';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Member '.substr($userId, 0, 8),
        'email' => $address,
        'password' => Hash::make($password),
    ]);

    $membershipId = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $membershipId,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => $role->value,
    ]), 'pgsql_owner');

    // Read back, so a case asserting a REFUSAL cannot pass because the target never existed.
    $found = withTenant($tenantId, fn () => DB::table('tenant_users')->where('id', $membershipId)->value('id'));

    expect($found)->toBe($membershipId, 'the member fixture was not created, so the refusal would be vacuous');

    return ['user_id' => $userId, 'membership_id' => $membershipId, 'email' => $address, 'password' => $password];
}

/** Sign in as this user over the real login route, so the session is the one a browser holds. */
function signInAs(object $test, array $member): void
{
    (function () use ($member): void {
        $this->postJson('/v1/auth/login', [
            'email' => $member['email'],
            'password' => $member['password'],
        ])->assertOk();
    })->call($test);
}

/** The role this membership currently holds, read inside the tenant's scope. */
function roleOfMembership(string $tenantId, string $membershipId): ?string
{
    $value = withTenant(
        $tenantId,
        fn () => DB::table('tenant_users')->where('id', $membershipId)->value('role'),
    );

    return $value === null ? null : (string) $value;
}

/* ------------------------------------------------------- the ladder itself, in isolation */

it('puts the three roles on a ladder and lets an actor grant its own role but nothing above it', function (): void {
    /*
     * ⚠ `>=` AND NOT `>`, WHICH IS THE CLAUSE AND IS EASY TO GET BACKWARDS. *"No actor may
     * grant a role senior to its own"* — so equal is allowed. A `>` would stop an owner from
     * ever appointing a second owner, which is the normal way a restaurant stops having a
     * single point of failure, and would make the last-owner rule permanent rather than
     * protective.
     */
    expect(TenantRole::Owner->maySeniorityGrant(TenantRole::Owner))->toBeTrue();
    expect(TenantRole::Owner->maySeniorityGrant(TenantRole::Manager))->toBeTrue();
    expect(TenantRole::Owner->maySeniorityGrant(TenantRole::Staff))->toBeTrue();

    expect(TenantRole::Manager->maySeniorityGrant(TenantRole::Owner))->toBeFalse();
    expect(TenantRole::Manager->maySeniorityGrant(TenantRole::Manager))->toBeTrue();
    expect(TenantRole::Manager->maySeniorityGrant(TenantRole::Staff))->toBeTrue();

    expect(TenantRole::Staff->maySeniorityGrant(TenantRole::Owner))->toBeFalse();
    expect(TenantRole::Staff->maySeniorityGrant(TenantRole::Manager))->toBeFalse();
    expect(TenantRole::Staff->maySeniorityGrant(TenantRole::Staff))->toBeTrue();

    // The screen's list is DERIVED from the same comparison, so the picker and the enforcement
    // cannot disagree about what is permitted. Exact lists, in `cases()` order.
    expect(TenantRole::Owner->grantableRoles())->toBe([TenantRole::Owner, TenantRole::Manager, TenantRole::Staff]);
    expect(TenantRole::Manager->grantableRoles())->toBe([TenantRole::Manager, TenantRole::Staff]);
    expect(TenantRole::Staff->grantableRoles())->toBe([TenantRole::Staff]);

    // Totality: every case has a rank, and the ranks are distinct — a role added without a
    // thought about where it sits would otherwise collide silently and compare as equal.
    $ranks = array_map(static fn (TenantRole $r): int => $r->rank(), TenantRole::cases());

    expect($ranks)->toHaveCount(count(TenantRole::cases()));
    expect(array_unique($ranks))->toHaveCount(count(TenantRole::cases()));
});

/* ------------------------------------------------- (a) granting a role senior to one's own */

it('refuses a manager appointing an OWNER through members.store, over HTTP', function (): void {
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);
    $outsider = memberIn($this, $this->makeTenant(), TenantRole::Staff);

    signInAs($this, $manager);

    $this->postJson('/v1/members', ['email' => $outsider['email'], 'role' => 'owner'])
        ->assertStatus(403);

    // AND NOTHING WAS WRITTEN. A 403 after the insert would be the same status and a real
    // escalation, so the refusal is asserted at the table as well as at the status.
    expect(withTenant($tenant, fn () => DB::table('tenant_users')->where('user_id', $outsider['user_id'])->count()))
        ->toBe(0, 'the refused request still added the member');
});

it('lets a manager appoint a MANAGER and a STAFF member, so the refusal above is about seniority', function (): void {
    /*
     * ⚠ THE CONTROL. Without it, a `requireSeniority()` that refused EVERYTHING would pass
     * every refusal case in this file — a guard that is simply broken is indistinguishable
     * from one that is correct if only the negatives are driven.
     */
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);
    $peer = memberIn($this, $this->makeTenant(), TenantRole::Staff);
    $junior = memberIn($this, $this->makeTenant(), TenantRole::Staff);

    signInAs($this, $manager);

    $this->postJson('/v1/members', ['email' => $peer['email'], 'role' => 'manager'])->assertStatus(201);
    $this->postJson('/v1/members', ['email' => $junior['email'], 'role' => 'staff'])->assertStatus(201);
});

it('refuses a manager promoting somebody else to OWNER through members.update, over HTTP', function (): void {
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);
    $target = memberIn($this, $tenant, TenantRole::Staff);

    signInAs($this, $manager);

    $this->patchJson('/v1/members/'.$target['membership_id'], ['role' => 'owner'])
        ->assertStatus(403);

    // The role is unchanged at the table, which is the escalation that matters.
    expect(roleOfMembership($tenant, $target['membership_id']))->toBe(TenantRole::Staff->value);
});

it('answers the SAME 403 for an unknown address as for a known one, so the seniority refusal is not an account oracle', function (): void {
    /*
     * ⚠ THIS IS `QRPAGER-29 §5`'s TRAP, AND IT IS AN ASSERTION ABOUT ORDERING RATHER THAN
     * ABOUT A STATUS.
     *
     * `members.store` answers 404 for an unknown address, deliberately, so the global `users`
     * table is not an account-existence oracle. Had the seniority check run AFTER the email
     * lookup, the pair (404, 403) would have told any manager whether any address on the
     * platform has an account — by asking for a role they knew would be refused.
     *
     * Checked first, both answer 403 and the body distinguishes nothing. The two statuses are
     * compared to each other rather than each to a literal, which is what makes this a test of
     * indistinguishability rather than two tests of two constants.
     */
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);
    $exists = memberIn($this, $this->makeTenant(), TenantRole::Staff);

    signInAs($this, $manager);

    $known = $this->postJson('/v1/members', ['email' => $exists['email'], 'role' => 'owner']);
    /*
     * ⚠ `Str::lower()`, AND THE FIRST VERSION OF THIS CASE WENT RED WITHOUT IT WITH A **422**
     * RATHER THAN A 403 — which is worth keeping, because the red taught the case something
     * about its own claim.
     *
     * `StoreMemberRequest` requires `lowercase` (that rule exists because
     * `users_email_is_lowercase` is a CHECK constraint and the lookup is an exact match), and
     * `Str::random()` is MIXED CASE. So the unknown address was not merely unknown, it was
     * invalid — and a FormRequest runs before the controller, so it answered 422 and never
     * reached the seniority check at all.
     *
     * **The sharpened claim: the ordering guarantee is *seniority before the LOOKUP*, not
     * *before validation*.** Validation answering differently is not an oracle — a caller
     * knows whether the address they typed is well-formed, and the 422 names the field. What
     * would be an oracle is a well-formed address answering 404-vs-403 on whether it exists,
     * which is what this case drives.
     */
    $unknown = $this->postJson('/v1/members', [
        'email' => 'nobody-'.Str::lower(Str::random(12)).'@example.test',
        'role' => 'owner',
    ]);

    expect($unknown->status())->toBe(
        $known->status(),
        'a senior-role request distinguishes a known address from an unknown one, which makes it an account oracle',
    );
    expect($known->status())->toBe(403);

    // And the bodies are identical too -- a status pair that matches while the `detail` member
    // differs is the same oracle one layer down.
    expect($unknown->json('type'))->toBe($known->json('type'));
    expect($unknown->json('title'))->toBe($known->json('title'));
});

/* ------------------------------------------------------- (b) changing one's OWN role at all */

it('refuses a MANAGER changing its own role, which is the exact request R-106 measured at 200', function (): void {
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);

    signInAs($this, $manager);

    // `R-106`'s own request, verbatim: a manager PATCHes its OWN membership id with `owner`.
    $this->patchJson('/v1/members/'.$manager['membership_id'], ['role' => 'owner'])
        ->assertStatus(403);

    expect(roleOfMembership($tenant, $manager['membership_id']))->toBe(TenantRole::Manager->value);
});

it('refuses a manager DEMOTING itself too, because the clause is absolute rather than about seniority', function (): void {
    /*
     * ⚠ THE CASE THAT TELLS THE TWO GUARDS APART. `staff` is junior to `manager`, so
     * `requireSeniority()` would ALLOW this — only `refuseSelfRoleChange()` refuses it. If the
     * self-role guard were deleted, this case reddens and the promotion case above does not
     * (seniority would still catch that one). So the two guards each have a case only they can
     * fail, which is what makes them two guards rather than one with a spare branch.
     */
    $tenant = $this->makeTenant();
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);

    signInAs($this, $manager);

    $this->patchJson('/v1/members/'.$manager['membership_id'], ['role' => 'staff'])
        ->assertStatus(403);

    expect(roleOfMembership($tenant, $manager['membership_id']))->toBe(TenantRole::Manager->value);
});

it('refuses an OWNER changing its own role, so the clause is about every actor and not only a manager', function (): void {
    /*
     * `QRPAGER-29 §4`(b): *"a manager AND an owner each targeting their OWN membership id"*.
     *
     * An owner is the case somebody would be tempted to exempt — it is their restaurant — and
     * the clause does not. Two owners exist here, so the last-owner rule is NOT what produces
     * the refusal: that rule would also refuse this and would do it with a 404, which this case
     * would then pass for the wrong reason. The 403 is what distinguishes them.
     */
    $tenant = $this->makeTenant();
    $owner = memberIn($this, $tenant, TenantRole::Owner);
    memberIn($this, $tenant, TenantRole::Owner);

    signInAs($this, $owner);

    $this->patchJson('/v1/members/'.$owner['membership_id'], ['role' => 'manager'])
        ->assertStatus(403);

    expect(roleOfMembership($tenant, $owner['membership_id']))->toBe(TenantRole::Owner->value);
});

it('lets an owner change SOMEBODY ELSE to owner, so the self-role refusal is about the target id', function (): void {
    // The control for the self-role guard: it must compare the TARGET id, not refuse every
    // owner-granting request. `refuseSelfRoleChange()`'s own docblock records why the
    // comparison is by MEMBERSHIP id rather than by user id -- the two never match, so a guard
    // written the other way would be permanently inert.
    $tenant = $this->makeTenant();
    $owner = memberIn($this, $tenant, TenantRole::Owner);
    $other = memberIn($this, $tenant, TenantRole::Staff);

    signInAs($this, $owner);

    $this->patchJson('/v1/members/'.$other['membership_id'], ['role' => 'owner'])->assertOk();

    expect(roleOfMembership($tenant, $other['membership_id']))->toBe(TenantRole::Owner->value);
});

/* ---------------------------------------- (c) the last owner, through the screen's route set */

it('refuses demoting the LAST owner through members.update — re-driven, not re-proved', function (): void {
    /*
     * `QRPAGER-29 §0`'s table calls this one **already proven**: `MemberDirectory`'s rule is
     * `FOR UPDATE`-locked and is driven by `RoleMatrixTest` and by `MemberConcurrencyTest` in
     * two processes. What 10e adds is this clause *"through the screen's own route set"*, and
     * it deliberately does NOT re-prove the lock — two concurrent demotions are
     * `MemberConcurrencyTest`'s and duplicating them here would be a second copy of a
     * two-process test.
     *
     * ⚠ THE ACTOR IS A MANAGER AND NOT THE OWNER THEMSELVES, which is the only way to reach
     * this rule through `update` now: an owner demoting themselves is refused earlier, by the
     * self-role guard, with a 403. So a case written the obvious way would be testing 10e's new
     * guard while claiming to test the last-owner rule.
     */
    $tenant = $this->makeTenant();
    $onlyOwner = memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);

    signInAs($this, $manager);

    // 404, not 403: the last-owner rule is about the TENANT's state, and a distinguishable
    // answer would be information about other people's roles.
    $this->patchJson('/v1/members/'.$onlyOwner['membership_id'], ['role' => 'manager'])
        ->assertStatus(404);

    expect(roleOfMembership($tenant, $onlyOwner['membership_id']))->toBe(TenantRole::Owner->value);
});

it('refuses removing the LAST owner through members.destroy — re-driven, not re-proved', function (): void {
    $tenant = $this->makeTenant();
    $onlyOwner = memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);

    signInAs($this, $manager);

    $this->deleteJson('/v1/members/'.$onlyOwner['membership_id'])->assertStatus(404);

    expect(roleOfMembership($tenant, $onlyOwner['membership_id']))->toBe(TenantRole::Owner->value);
});

it('allows demoting an owner when there are two, so the last-owner refusal is about the COUNT', function (): void {
    // The control. A rule that refused every owner demotion would pass both cases above.
    $tenant = $this->makeTenant();
    $first = memberIn($this, $tenant, TenantRole::Owner);
    memberIn($this, $tenant, TenantRole::Owner);
    $manager = memberIn($this, $tenant, TenantRole::Manager);

    signInAs($this, $manager);

    $this->patchJson('/v1/members/'.$first['membership_id'], ['role' => 'manager'])->assertOk();

    expect(roleOfMembership($tenant, $first['membership_id']))->toBe(TenantRole::Manager->value);
});

/* -------------------------------------------------------------- the actor's role is CURRENT */

it('reads the actor\'s role from the database, so a demoted manager cannot keep granting on an old session', function (): void {
    /*
     * ⚠ THE CASE THAT JUSTIFIES `requireSeniority()` NOT TAKING THE ACTOR'S ROLE AS A PARAMETER.
     *
     * The controller already resolves the caller's membership, so the role could have been
     * passed down the stack. It is not, and this is why: a role passed down is a role that was
     * true when the SESSION was established. An actor demoted mid-session still holds a cookie,
     * and an enforcement that trusted the session's copy would let them keep granting for the
     * life of it.
     *
     * Driven as the real thing: an owner signs in, is demoted to manager by somebody else, and
     * then — on the SAME session — tries to appoint an owner. The request must be refused using
     * their CURRENT role.
     */
    $tenant = $this->makeTenant();
    $demoted = memberIn($this, $tenant, TenantRole::Owner);
    $other = memberIn($this, $tenant, TenantRole::Owner);
    $outsider = memberIn($this, $this->makeTenant(), TenantRole::Staff);

    signInAs($this, $demoted);

    // Their session is live and their role permits owner-granting right now.
    $this->getJson('/v1/members')->assertOk();

    // Somebody else demotes them, directly at the table -- the mechanism is not what is under
    // test here, and `$other`'s own HTTP demotion is covered by the case above.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_users')
        ->where('id', $demoted['membership_id'])
        ->update(['role' => TenantRole::Manager->value]), 'pgsql_owner');

    expect(roleOfMembership($tenant, $demoted['membership_id']))->toBe(TenantRole::Manager->value);
    expect($other['membership_id'])->not->toBe($demoted['membership_id']);

    // Same session, same cookie, new role. The grant must now be refused.
    $this->postJson('/v1/members', ['email' => $outsider['email'], 'role' => 'owner'])
        ->assertStatus(403);

    expect(withTenant($tenant, fn () => DB::table('tenant_users')->where('user_id', $outsider['user_id'])->count()))
        ->toBe(0);
});

/* ------------------------------------------------------------- what the screen is told it may do */

it('publishes the grantable roles on /v1/auth/me, so the picker cannot offer what the server refuses', function (): void {
    /*
     * `§12`'s bar: a screen never offers an action the server refuses. The list is published
     * rather than computed on the client, because computing it there would be a second
     * implementation of the ladder in TypeScript -- the duplicated-source defect `§6.2`
     * mechanises against for the status enum.
     *
     * AND IT IS NOT THE ENFORCEMENT, which every case above drives independently over HTTP.
     */
    $tenant = $this->makeTenant();
    $manager = memberIn($this, $tenant, TenantRole::Manager);
    memberIn($this, $tenant, TenantRole::Owner);

    signInAs($this, $manager);

    $response = $this->getJson('/v1/auth/me')->assertOk();

    $memberships = $response->json('memberships');

    expect($memberships)->toHaveCount(1);
    expect($memberships[0]['role'])->toBe('manager');
    // Exactly the ladder's answer for a manager, and `owner` is absent.
    expect($memberships[0]['grantable_roles'])->toBe(['manager', 'staff']);
});
