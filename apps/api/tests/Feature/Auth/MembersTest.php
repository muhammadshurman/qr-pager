<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * STAFF MANAGEMENT -- R-007's Task 5 half, BEHAVIOUR rather than authorisation
 * ================================================================================
 *
 * WHY THIS FILE EXISTS, and it was the coverage number that said so rather than a review.
 * `RoleMatrixTest` drives every principal at every member route and asserts who is REFUSED
 * -- which is what the role matrix is for, and it is not a test of what the surface DOES.
 * `bin/coverage` put `MemberDirectory` at **27/94 statements**: every happy path, the
 * last-owner rule and all three audit writes were unexercised, while `R-007`'s closure
 * record pointed at the matrix as the thing that covered them.
 *
 * So the matrix tests authorisation and this tests behaviour, and the distinction is worth
 * keeping: a 403 proves nothing about whether the action, when permitted, does the right
 * thing to the database.
 *
 * THE BOUND WITH NO LAYER UNDERNEATH IT is the last-owner rule, and it gets the most cases
 * here for that reason. RLS stops a cross-tenant member id and `RequireAbility` stops a
 * staff member, but nothing below `MemberDirectory` stops an owner demoting the only owner
 * and leaving a tenant nobody can administer -- the recovery for which is a platform-admin
 * surface that does not exist until Task 6.
 */

/** A user who exists but belongs to no tenant. */
function existingAccount(): array
{
    $id = (string) Str::uuid7();
    $email = 'member-'.str_replace('-', '', $id).'@example.test';

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Member Candidate',
        'email' => $email,
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    return ['id' => $id, 'email' => $email];
}

/** @return list<array<string, mixed>> */
function auditRows(string $tenantId, string $action): array
{
    return withTenant($tenantId, fn (): array => DB::table('audit_log')
        ->where('action', $action)
        ->orderBy('created_at')
        ->get()
        ->map(fn ($row): array => (array) $row)
        ->all());
}

it('lists the tenant staff, with the role and the account behind each membership', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $listed = $this->getJson('/v1/members');

    $listed->assertStatus(200);

    // The acting owner is in it, which is what makes the list non-empty without seeding.
    expect($listed->json('members'))->toHaveCount(1)
        ->and($listed->json('members.0.role'))->toBe(TenantRole::Owner->value)
        ->and($listed->json('members.0.email'))->toBeString()
        ->and($listed->json('members.0.user_id'))->toBe((string) auth()->id());

    // AND NOTHING THE APP ROLE MAY NOT READ. `users` has four withheld columns (R-006) and
    // the listing joins that table, so an explicit column list is the only thing keeping
    // this query from being a 42501 -- and the response from carrying a credential.
    $body = (string) $listed->getContent();

    foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $withheld) {
        expect(str_contains($body, $withheld))->toBeFalse("the member list named {$withheld}");
    }
});

it('adds an existing account, audits it, and is idempotent on a second add', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $candidate = existingAccount();

    $added = $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Staff->value,
    ]);

    $added->assertStatus(201)
        ->assertJsonPath('member.user_id', $candidate['id'])
        ->assertJsonPath('member.role', TenantRole::Staff->value);

    expect($this->getJson('/v1/members')->json('members'))->toHaveCount(2);

    // Audited inside the tenant's own scope -- audit_log carries the same isolation policy
    // as every other tenant table, so an insert outside a scope is a 42501.
    $audit = auditRows($tenant, 'member.added');

    expect($audit)->toHaveCount(1)
        ->and((string) $audit[0]['context'])->toContain(TenantRole::Staff->value);

    // IDEMPOTENT, not a 409: adding somebody who is already here is not an error worth a
    // distinct answer, and UNIQUE (tenant_id, user_id) is what actually prevents the
    // duplicate. A second add returns the EXISTING membership id.
    $again = $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Manager->value,
    ]);

    $again->assertStatus(201)
        ->assertJsonPath('member.id', $added->json('member.id'));

    expect($this->getJson('/v1/members')->json('members'))->toHaveCount(2)
        ->and(auditRows($tenant, 'member.added'))->toHaveCount(1, 'the idempotent add wrote a second audit row');
});

it('reports an unknown address as not found, the same way it reports one it may not see', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    // 404 and not 422: `users` is GLOBAL, so an answer that distinguished "no such account"
    // from "not addable" would make this endpoint an account-existence oracle for the whole
    // platform, not just for this tenant.
    $this->postJson('/v1/members', [
        // Lower-cased: the `lowercase` rule runs before the lookup, so a mixed-case
        // address is a 422 and would never reach the not-found answer this case is about.
        'email' => 'nobody-'.Str::lower(Str::random(10)).'@example.test',
        'role' => TenantRole::Staff->value,
    ])->assertStatus(404);

    expect($this->getJson('/v1/members')->json('members'))->toHaveCount(1)
        ->and(auditRows($tenant, 'member.added'))->toHaveCount(0);
});

it('changes a role, audits the transition with both ends of it, and leaves the listing right', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $candidate = existingAccount();

    $membershipId = $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Staff->value,
    ])->json('member.id');

    $this->patchJson("/v1/members/{$membershipId}", ['role' => TenantRole::Manager->value])
        ->assertStatus(200)
        ->assertJsonPath('member.role', TenantRole::Manager->value);

    $roles = collect($this->getJson('/v1/members')->json('members'))
        ->pluck('role', 'id')->all();

    expect($roles[$membershipId])->toBe(TenantRole::Manager->value);

    // BOTH ENDS in the audit row, because "changed to manager" without the previous value
    // is not an audit trail -- it cannot tell a promotion from a no-op.
    $audit = auditRows($tenant, 'member.role_changed');

    expect($audit)->toHaveCount(1);

    $context = (string) $audit[0]['context'];

    expect($context)->toContain(TenantRole::Staff->value)
        ->and($context)->toContain(TenantRole::Manager->value);
});

it('removes a member and audits it, and reports a second removal as not found', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $candidate = existingAccount();

    $membershipId = $this->postJson('/v1/members', [
        'email' => $candidate['email'],
        'role' => TenantRole::Staff->value,
    ])->json('member.id');

    $this->deleteJson("/v1/members/{$membershipId}")->assertStatus(204);

    expect($this->getJson('/v1/members')->json('members'))->toHaveCount(1)
        ->and(auditRows($tenant, 'member.removed'))->toHaveCount(1);

    // The row is gone, so the second attempt is the same answer as a membership that never
    // existed -- the affected-row count is what decides, not a read-then-write check.
    $this->deleteJson("/v1/members/{$membershipId}")->assertStatus(404);

    expect(auditRows($tenant, 'member.removed'))->toHaveCount(1);

    // AND THE USER ROW SURVIVES. Removing somebody from a restaurant is not deleting their
    // account: `users` is global and they may hold memberships elsewhere.
    expect(DB::connection('pgsql_owner')->table('users')->where('id', $candidate['id'])->exists())
        ->toBeTrue('removing a membership deleted the account');
});

it('refuses to demote or remove the last owner, which is the bound with nothing underneath it', function (): void {
    // THE CASE THAT MATTERS MOST HERE. RLS stops a cross-tenant id and RequireAbility stops a
    // staff member; neither stops an owner leaving a tenant with no owner at all, and the
    // recovery for that is a platform-admin surface that does not exist until Task 6.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $ownMembership = $this->getJson('/v1/members')->json('members.0.id');

    /*
     * ⚠ **THE DEMOTION IS NOW DRIVEN BY A MANAGER AND NOT BY THE OWNER THEMSELVES, AND THAT IS
     * TASK 10e's SELF-ROLE GUARD RATHER THAN A WEAKENING OF THIS CASE.**
     *
     * This case used to PATCH the actor's OWN membership, which reached the last-owner rule and
     * answered 404. Since 10e, `§4 › Security`'s third limb — *no actor may … change its own
     * role at all* — refuses that **earlier and with a 403**, so the original request no longer
     * reaches the rule this case is about: it would have been asserting 10e's guard while
     * claiming to assert the last-owner rule.
     *
     * A manager of the same tenant is the actor that still reaches it. The self-role refusal has
     * its own cases in `MemberSeniorityTest` (*"refuses a MANAGER changing its own role"* and
     * *"refuses an OWNER changing its own role"*), so nothing is lost by moving this one.
     */
    $this->actingForTenant($tenant, TenantRole::Manager);

    // Demotion: refused, and 404 because the last-owner rule is about the TENANT's state.
    $this->patchJson("/v1/members/{$ownMembership}", ['role' => TenantRole::Manager->value])
        ->assertStatus(404);

    // Removal: refused. Still 404, and still driven at the last owner -- 10e's guard covers
    // the ROLE change only, so this half of the case is unchanged.
    $this->deleteJson("/v1/members/{$ownMembership}")->assertStatus(404);

    // Still an owner, and still there. Read as the manager, which also proves the two reads
    // below are of the same tenant the refusals were aimed at.
    $owner = collect($this->getJson('/v1/members')->json('members'))->firstWhere('id', $ownMembership);

    expect($owner['role'])->toBe(TenantRole::Owner->value);

    // NOTHING WAS AUDITED, which is the half that says the refusal is before the write
    // rather than a rollback after it.
    expect(auditRows($tenant, 'member.role_changed'))->toHaveCount(0)
        ->and(auditRows($tenant, 'member.removed'))->toHaveCount(0);
});

it('allows the demotion once a second owner exists, so the rule is about the LAST owner', function (): void {
    // The positive control for the case above. Without it, a rule that refused every
    // owner-demotion would pass that test and be wrong.
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $firstOwner = $this->getJson('/v1/members')->json('members.0.id');

    $second = existingAccount();

    $this->postJson('/v1/members', [
        'email' => $second['email'],
        'role' => TenantRole::Owner->value,
    ])->assertStatus(201);

    /*
     * ⚠ THE DEMOTION IS DRIVEN BY A MANAGER FOR THE CASE ABOVE's REASON — 10e's self-role guard
     * refuses an actor changing their own role, whatever the roles involved and whatever the
     * owner count. So the positive control has to come from a third party, as it does in use:
     * one owner demotes another, or a manager does.
     */
    $this->actingForTenant($tenant, TenantRole::Manager);

    // Two owners now, so demoting one is allowed.
    $this->patchJson("/v1/members/{$firstOwner}", ['role' => TenantRole::Manager->value])
        ->assertStatus(200);

    $roles = collect($this->getJson('/v1/members')->json('members'))->pluck('role', 'id')->all();

    expect($roles[$firstOwner])->toBe(TenantRole::Manager->value);

    // And now the remaining owner cannot be demoted either, because they are the last one.
    $remaining = collect($this->getJson('/v1/members')->json('members'))
        ->firstWhere('role', TenantRole::Owner->value);

    $this->patchJson("/v1/members/{$remaining['id']}", ['role' => TenantRole::Staff->value])
        ->assertStatus(404);
});

it('reports another tenant member id as not found, because RLS makes it invisible', function (): void {
    // §11.2's IDOR rule at the member surface, and the mechanism is worth naming: the
    // statement runs inside the RESOLVED tenant's scope, so another tenant's row is not
    // forbidden -- it is not there. The affected-row count is zero and the answer is 404.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $this->actingForTenant($beta, TenantRole::Owner);
    $betaMembership = $this->getJson('/v1/members')->json('members.0.id');

    $this->actingForTenant($alpha, TenantRole::Owner);

    $this->patchJson("/v1/members/{$betaMembership}", ['role' => TenantRole::Staff->value])
        ->assertStatus(404);
    $this->deleteJson("/v1/members/{$betaMembership}")->assertStatus(404);

    // Beta's owner is untouched, which is what makes the 404 isolation and not a broken route.
    $stillOwner = withTenant($beta, fn () => DB::table('tenant_users')
        ->where('id', $betaMembership)->value('role'));

    expect($stillOwner)->toBe(TenantRole::Owner->value);
});

it('refuses a malformed role or address before anything is written', function (): void {
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Owner);

    $candidate = existingAccount();

    foreach ([
        ['email' => $candidate['email'], 'role' => 'platform_admin'],
        ['email' => $candidate['email'], 'role' => 'owner-ish'],
        ['email' => $candidate['email']],
        ['email' => 'NOT-LOWERCASE@example.test', 'role' => TenantRole::Staff->value],
        ['email' => 'not-an-email', 'role' => TenantRole::Staff->value],
        ['role' => TenantRole::Staff->value],
    ] as $body) {
        $this->postJson('/v1/members', $body)->assertStatus(422);
    }

    // `platform_admin` is refused by the role allowlist, which is built from TenantRole --
    // so the one principal that is NOT a tenant role cannot be assigned as one. That is
    // §2.3's "tenant_role holds tenant-scoped roles only" arriving as a 422.
    expect($this->getJson('/v1/members')->json('members'))->toHaveCount(1);

    $this->patchJson('/v1/members/'.Str::uuid7(), ['role' => 'platform_admin'])->assertStatus(422);
});
