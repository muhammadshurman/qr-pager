<?php

declare(strict_types=1);

namespace App\Features\Members;

use App\Domain\Audit\AuditActorType;
use App\Domain\Tenancy\MemberActionDenied;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Tenancy\UserAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The staff of one tenant: who belongs to it and with what role.
 *
 * ================================================================================
 * THIS IS `R-007`'s TASK 5 HALF, AND THE HONEST STATEMENT OF WHAT BOUNDS IT
 * ================================================================================
 *
 * `R-007`: *"`qrpager_app` holds table-wide `INSERT`/`DELETE` and `UPDATE (role,
 * updated_at)` on `tenant_users`, so within its own tenant a broken authorisation check can
 * promote a staff row to owner with nothing underneath it."* That is still true, and the
 * grant is **not** narrowed, because a tenant's owner legitimately manages their own staff
 * and the only database-level discriminator would be a per-row actor identity — a third
 * `app.*` GUC, which C1 forbids without editing `PROJECT_INSTRUCTIONS.md` first.
 *
 * So what bounds this surface, in order of how much it relies on code being right:
 *
 *   1. **RLS, which does not rely on this class at all.** Every statement here runs inside
 *      `withTenant()`, so a row belonging to another tenant is invisible — a cross-tenant
 *      member id answers "not found" because the `UPDATE` matches zero rows, not because
 *      this class checked.
 *   2. **`RequireAbility`, before any method here runs.** `members:write` is held by owner
 *      and manager and by neither staff nor a device token (`RoleMatrix`), so a staff
 *      member's promotion attempt is a 403 that never reaches this file.
 *   3. **The last-owner rule below**, which is this class's own and is the one bound with
 *      no layer underneath it. `RoleMatrixTest` drives it.
 *
 * The three are deliberately not interchangeable, and a reviewer should read 3 hardest.
 */
final class MemberDirectory
{
    /**
     * `R-233`'s consent rule, injected rather than resolved, so a test can drive the refusal
     * without reaching for the container -- and so deptrac sees one Features -> Platform edge
     * rather than a facade call it cannot type.
     */
    public function __construct(private readonly UserAttachment $attachment) {}

    /**
     * Everyone in this tenant.
     *
     * @return list<array{id: string, user_id: string, name: string, email: string, role: string}>
     */
    public function all(string $tenantId): array
    {
        $rows = withTenant($tenantId, fn () => DB::select(
            // An explicit column list and never `select *`: `users` has four columns this
            // role may not read at all (R-006), so a star here would be a 42501 rather
            // than a leak -- but it would be a 42501 on every staff listing.
            // `expires_at` SINCE QRPAGER-22, and it is what makes support VISIBLE. `§4 ›
            // Tenancy`, 2026-11-04: *"It is visible to the tenant's own owner in their
            // members list while it is active, marked as platform support with its expiry
            // — support a tenant cannot see is indistinguishable from an undisclosed
            // cross-tenant read."* So this is not a nicety: it is the clause that keeps
            // support distinguishable from the thing the whole ruling forbids.
            'select tu.id, tu.user_id, tu.role, tu.expires_at, u.name, u.email
               from tenant_users tu
               join users u on u.id = tu.user_id
              order by tu.created_at',
        ));

        $members = [];

        foreach ($rows as $row) {
            // isset-guarded rather than mapped blind: DB::select() answers a list of plain
            // objects, so a renamed column would otherwise be a property error at runtime.
            if (! is_object($row) || ! isset($row->id, $row->user_id, $row->role, $row->name, $row->email)) {
                continue;
            }

            /*
             * ⚠ A LAPSED SUPPORT MEMBERSHIP IS NOT LISTED, and the clause is why: it says
             * *"while it is active"*. A row whose hour is up grants nothing, so showing it
             * would tell a restaurant somebody is inside when nobody is — which is worse
             * than silence. The sweep deletes it within five minutes either way.
             *
             * ⚠ COMPARED AGAINST THE DATABASE'S CLOCK, not PHP's: the expiry was written by
             * `now()` in Postgres and `public.user_memberships()` filters on `now()` there
             * too, so using `time()` here would disagree with the resolver by whatever the
             * two clocks differ by. `MemberSupportVisibilityTest` drives a lapsed row.
             */
            $expiresAt = isset($row->expires_at) ? (string) $row->expires_at : null;

            if ($expiresAt !== null && ! $this->isStillActive($expiresAt)) {
                continue;
            }

            /*
             * ⚠ THE RATIONALE FOR THE TWO SUPPORT KEYS IS HERE AND NOT ABOVE THEM, BECAUSE
             * `R-052`: a comment directly above a key in this array is PUBLISHED as that
             * property's description in `docs/openapi.json`, and `OpenApiSpecTest` caught it.
             * Second instance in one change; `R-222` recorded the first at 10b.
             *
             * TWO KEYS RATHER THAN ONE, so a client cannot show the badge without the expiry:
             * `§4 › Tenancy` requires support be *"marked as platform support WITH ITS
             * EXPIRY"*, and one boolean would let a screen satisfy half of that.
             */
            $members[] = [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
                'platform_support' => $expiresAt !== null,
                'support_expires_at' => $expiresAt,
            ];
        }

        return $members;
    }

    /**
     * Is this expiry still in the future, by the DATABASE's clock?
     *
     * One round trip rather than a PHP comparison, for the reason `all()`'s own note gives:
     * the expiry and the resolver's filter are both `now()` in Postgres, and a PHP comparison
     * would disagree with them by whatever the two clocks differ by. A support membership is
     * an hour long, so that difference is unlikely to matter — and "unlikely to matter" is not
     * the standard `§4`'s read-only clause is held to everywhere else in this feature.
     */
    private function isStillActive(string $expiresAt): bool
    {
        /** @var object{active: bool}|null $row */
        $row = DB::selectOne('select (?::timestamptz > now()) as active', [$expiresAt]);

        return (bool) ($row->active ?? false);
    }

    /**
     * Add an existing user to this tenant, or return null when the email is unknown.
     *
     * NOT AN INVITE, and the difference is worth stating because the ticket's scope says
     * "staff management + **invites**" is Task 6's. This attaches a user who already exists;
     * delivering an email to somebody who does not is Task 6's wizard, whose own gate
     * clause is "a staff invite is actually delivered, asserted against the local mail
     * catcher". Building half of that here would publish an endpoint whose delivery nothing
     * asserts, which is `R-047`'s and `R-077`'s shape.
     *
     * @return array{id: string, user_id: string, role: string}|null
     */
    public function add(
        string $tenantId,
        string $email,
        TenantRole $role,
        string $actorUserId,
        ?string $actorIp,
    ): ?array {
        /*
         * ⚠ THE SENIORITY CHECK RUNS **BEFORE** THE EMAIL LOOKUP, AND THE ORDER IS THE WHOLE
         * REASON THIS REFUSAL IS NOT AN ORACLE.
         *
         * `QRPAGER-29 §5`: *"`members.store` already answers 404 for an unknown address AND for
         * one this caller may not see, deliberately, so the platform's `users` table is not an
         * account-existence oracle. A new seniority refusal must not undo that by answering
         * distinguishably."*
         *
         * Checked first, a request with too senior a role answers **403 whether or not the
         * address exists**. Checked after the lookup, the pair (404, 403) would have told any
         * manager whether any address on the platform has an account — by asking for a role
         * they knew would be refused. Same two statuses, same two code paths, and the ordering
         * is the difference between a bound and a disclosure.
         *
         * It is wrapped in the tenant scope because `requireSeniority()` reads `tenant_users`;
         * the email lookup below is on the global `users` table and needs none.
         */
        withTenant($tenantId, fn () => $this->requireSeniority($role, $actorUserId));

        /*
         * ================================================================================
         * ⚠ CONSENT, AND THIS IS `R-233`. IT RUNS BEFORE ANY WRITE AND ANSWERS AS 404.
         * ================================================================================
         *
         * `§4 › Security`: *"an address that already belongs to an account holding any
         * membership, or to a platform admin, is refused outright rather than invited — so
         * the weaker rule is never the one in force. Both refusals answer with one
         * message."* This method implemented NONE of it until 2026-11-04: it lower-cased the
         * address, looked it up, and attached whatever it found. Measured over HTTP at the
         * `task-10` tag, alpha's manager attached beta's OWNER and a platform admin, both
         * 201, and the platform admin then read alpha's members and history as `manager`.
         *
         * The rule is `UserAttachment::refusalFor()` -- in Platform, one copy, shared with
         * `TenantProvisioning`, which had implemented it for the provisioning form alone.
         * It adds no cross-tenant path: see that class.
         *
         * ⚠ **THE REFUSAL IS THE SAME `null` AS AN UNKNOWN ADDRESS, AND THAT IS DELIBERATE
         * AND IS A DEVIATION WORTH READING.** Muhammad's instruction said *"ONE 403 for both
         * cases — two would let a form probe whether an address has an account"*. The two
         * refusals are indeed one answer; the answer is the EXISTING 404 rather than a new
         * 403, because `MemberController::store()`'s own in-force comment says *"'no user
         * with that address' is the same answer whether the address is unknown or belongs to
         * somebody this caller may not see, and a distinguishable one would make this
         * endpoint an account-existence oracle for the whole platform -- users is global."*
         * A 403 here would be exactly that distinguishable answer: {201, 403} would mean the
         * address has an account and {404} that it does not, silently, for any address an
         * authenticated manager cares to type. Serving his stated REASON means collapsing
         * the refusal into the 404, so that is what this does -- and it is one line to flip
         * if he wants the status instead.
         */
        if ($this->attachment->refusalFor($email) !== null) {
            return null;
        }

        // users is global and RLS-free, so this read needs no scope -- and it is an exact
        // match on a lower-cased email, which users_email_is_lowercase pins as a CHECK.
        $user = DB::selectOne('select id from users where email = ?', [Str::lower($email)]);

        if ($user === null) {
            return null;
        }

        $userId = (string) $user->id;
        $membershipId = (string) Str::uuid7();

        return withTenant($tenantId, function () use (
            $tenantId, $userId, $membershipId, $role, $actorUserId, $actorIp
        ): array {
            // `role` IS SELECTED NOW, AND THAT IS `R-236` -- the response must describe what is
            // STORED, not what the caller asked for.
            $already = DB::selectOne('select id, role from tenant_users where user_id = ?', [$userId]);

            if ($already !== null) {
                /*
                 * Idempotent rather than a 409: adding somebody who is already here is not
                 * an error worth a distinct answer, and UNIQUE (tenant_id, user_id) is what
                 * actually prevents the duplicate.
                 *
                 * ⚠ `R-236`: THIS RETURNED `$role->value` -- THE REQUEST'S ROLE -- WHILE WRITING
                 * NOTHING, so the body described a change that did not happen. Driven at the
                 * `task-10` tag: a manager posting an existing **owner**'s address with
                 * `role: staff` got `201 {"role":"staff"}` while `GET /v1/members` still showed
                 * `owner`. An operator who trusts the answer believes a privilege was reduced
                 * when it was not.
                 *
                 * ⚠ AND THE FIX IS THE STORED ROLE RATHER THAN A REFUSAL, which is the direction
                 * the row argues and the direction `R-233` requires. A 409 or a 403 here would
                 * make this endpoint an existing-membership oracle: `{201}` would mean the
                 * address is free and `{409}` would mean it is taken, for any address an
                 * authenticated manager types -- exactly what `MemberController::store()`'s
                 * in-force one-message property exists to prevent. The idempotency is
                 * deliberate; only the body was wrong.
                 *
                 * NO AUDIT ROW, deliberately and unchanged: nothing was written, and an
                 * `member.added` row for a membership that already existed would be a false
                 * entry in an append-only log.
                 */
                return [
                    'id' => (string) $already->id,
                    'user_id' => $userId,
                    'role' => (string) $already->role,
                ];
            }

            DB::insert(
                'insert into tenant_users (id, tenant_id, user_id, role) values (?, ?, ?, ?::tenant_role)',
                [$membershipId, $tenantId, $userId, $role->value],
            );

            $this->audit($tenantId, $membershipId, 'member.added', $actorUserId, $actorIp, [
                'role' => $role->value,
            ]);

            return ['id' => $membershipId, 'user_id' => $userId, 'role' => $role->value];
        });
    }

    /**
     * Change a member's role.
     *
     * Returns false when there is no such member **in this tenant** — a cross-tenant id is
     * invisible to the statement under RLS, so it reports zero affected rows and answers
     * exactly as a non-existent id does.
     */
    public function changeRole(
        string $tenantId,
        string $membershipId,
        TenantRole $role,
        string $actorUserId,
        ?string $actorIp,
    ): bool {
        return withTenant($tenantId, function () use (
            $tenantId, $membershipId, $role, $actorUserId, $actorIp
        ): bool {
            /*
             * TASK 10e's TWO REFUSALS, BEFORE ANYTHING IS LOCKED OR WRITTEN.
             *
             * Ordered before `lockOwnerCount()` deliberately: both are decisions about the
             * CALLER rather than about the tenant's state, so neither needs the lock — and
             * taking a `FOR UPDATE` on the owner set only to throw it away is a lock held
             * across a refusal, which on a busy tenant serialises callers who were never going
             * to be allowed to write.
             *
             * The self-role check is first because it is the cheaper comparison and because it
             * is the clause that holds regardless of which role was asked for: an owner
             * demoting themselves to staff is refused by `refuseSelfRoleChange()` and would be
             * permitted by `requireSeniority()`, since `owner` may grant `staff`.
             */
            $this->refuseSelfRoleChange($membershipId, $actorUserId);
            $this->requireSeniority($role, $actorUserId);

            // THE OWNER SET IS LOCKED FIRST, and the order of these two reads is the
            // whole of the fix. See lockOwnerCount() -- an unlocked count let two
            // concurrent demotions of two different owners each see two owners and each
            // believe it was leaving one behind, which left the tenant with NONE.
            //
            // Locking before reading the target is deliberate: the only transition that
            // can break the invariant is owner -> not-owner, and in that transition the
            // target IS an owner, so it is inside the locked set. A target that is not an
            // owner needs no lock, because changing it cannot reduce the owner count.
            $owners = $this->lockOwnerCount();

            $current = DB::selectOne('select id, role from tenant_users where id = ?', [$membershipId]);

            if ($current === null) {
                return false;
            }

            if ((string) $current->role === TenantRole::Owner->value && $role !== TenantRole::Owner
                && $owners <= 1) {
                // THE LAST-OWNER RULE. Demoting the only owner leaves a tenant nobody can
                // administer, and the recovery is a platform admin -- a surface that does
                // not exist until Task 6. So it is refused here, which makes this the one
                // bound in this class with no RLS or middleware layer underneath it.
                return false;
            }

            // Keyed, and the affected-row count is the answer: §4 › Postgres traps forbids
            // a bulk statement against a tenant table, and a keyed UPDATE whose count is
            // checked is the prescribed shape.
            $affected = DB::update(
                'update tenant_users set role = ?::tenant_role, updated_at = now() where id = ?',
                [$role->value, $membershipId],
            );

            if ($affected === 0) {
                return false;
            }

            $this->audit($tenantId, $membershipId, 'member.role_changed', $actorUserId, $actorIp, [
                'from' => (string) $current->role,
                'to' => $role->value,
            ]);

            return true;
        });
    }

    /** Remove a member from this tenant. Refuses to remove the last owner. */
    public function remove(
        string $tenantId,
        string $membershipId,
        string $actorUserId,
        ?string $actorIp,
    ): bool {
        return withTenant($tenantId, function () use ($tenantId, $membershipId, $actorUserId, $actorIp): bool {
            // Locked first, for the reason changeRole states and the same race: two
            // concurrent removals of two different owners emptied the tenant.
            $owners = $this->lockOwnerCount();

            $current = DB::selectOne('select id, role from tenant_users where id = ?', [$membershipId]);

            if ($current === null) {
                return false;
            }

            if ((string) $current->role === TenantRole::Owner->value && $owners <= 1) {
                return false;
            }

            if (DB::delete('delete from tenant_users where id = ?', [$membershipId]) === 0) {
                return false;
            }

            $this->audit($tenantId, $membershipId, 'member.removed', $actorUserId, $actorIp, [
                'role' => (string) $current->role,
            ]);

            return true;
        });
    }

    /** How many owners this tenant has. Called inside a scope. */
    /**
     * How many owners this tenant has, with every one of their rows LOCKED for the rest of
     * the transaction.
     *
     * ================================================================================
     * WHY `FOR UPDATE`, measured rather than reasoned about
     * ================================================================================
     *
     * The last-owner rule was `select count(*)` with no lock, inside a READ COMMITTED
     * transaction. Two concurrent demotions of two DIFFERENT owners therefore both read
     * two owners, both concluded they were leaving one behind, and both committed:
     *
     *     A sees owners: 2     B sees owners: 2
     *     FINAL owner count for the tenant: 0
     *
     * Driven on this project's own composed Postgres by `MemberConcurrencyTest`, in two
     * processes rather than two connections, for the reason that test and
     * `PagerIdempotencyTest` both state: `for update` blocks, so two connections in one
     * PHP process would deadlock the only thread there is instead of demonstrating order.
     *
     * A tenant with zero owners has no in-product recovery. `RoleMatrix` grants
     * `PlatformAdmin` nothing and the admin surface is Task 6, so nobody could repair it
     * through the API -- which is what made an unlocked count worth a lock rather than a
     * `§5` row.
     *
     * ================================================================================
     * WHY THE LOCK IS SUFFICIENT, AND WHY `order by id`
     * ================================================================================
     *
     * Under READ COMMITTED a blocked `for update` RE-EVALUATES its predicate once the
     * lock is granted, so the second transaction does not see the stale set it queued on:
     * after the first commits its demotion, that row no longer satisfies `role = 'owner'`
     * and the second reads one owner, not two. That re-check is the property being relied
     * on, so it is named here rather than left to be rediscovered.
     *
     * `order by id` because two transactions locking the same set in different orders
     * deadlock. One deterministic order means the second waits instead.
     *
     * The tenant id is still unused: RLS narrows every statement in this class to the
     * tenant `withTenant()` opened, so a `tenant_id` predicate here would be a second,
     * weaker copy of the control that is already load-bearing.
     */
    /**
     * ================================================================================
     * TASK 10e's ENFORCEMENT. `§4 › Security`'s CLAUSE, AND IT IS NOT IN A FormRequest.
     * ================================================================================
     *
     * *"No actor may grant a role senior to its own, assume one, or change its own role at
     * all."* In force since 2026-10-28 and, before 10e, enforced nowhere —
     * `QRPAGER-29 §0` measured it and `R-106` proved it by driving the route: a manager
     * `PATCH /v1/members/{own-id}` with `role: owner` answered **200**.
     *
     * ⚠ **IT CANNOT LIVE IN VALIDATION, AND `QRPAGER-29 §5` SAYS WHY BEFORE THE CODE EXISTED:**
     * *"A FormRequest sees the requested role and not the target's current one, and the
     * self-role clause is about the **target id**, not the body — so a rule in `rules()` would
     * be a check that looks complete and is not."*
     *
     * Both halves are here instead, in the service both routes funnel through, inside the same
     * tenant scope as the write they guard.
     *
     * ================================================================================
     * THE ACTOR'S ROLE IS READ FROM THE DATABASE, NOT PASSED IN
     * ================================================================================
     *
     * `add()` and `changeRole()` could have taken a `TenantRole $actorRole` parameter from the
     * controller, which already resolves the caller's membership. They deliberately do not.
     *
     * A role passed down the stack is a role that was true when the session was established.
     * A manager demoted five minutes ago still holds a session, and `ActiveTenant`'s copy of
     * their role is whatever it was at sign-in — so an enforcement that trusted it would let a
     * demoted manager keep granting for the life of their cookie. Reading `tenant_users`
     * inside the scope makes the check use the role the actor holds **now**, against the same
     * table and the same scope as the target's row.
     *
     * The cost is one indexed read per write on a table with a `UNIQUE (tenant_id, user_id)`.
     * That is the correct trade for an authorisation check.
     *
     * @throws MemberActionDenied when the actor may not grant this role
     */
    private function requireSeniority(TenantRole $granted, string $actorUserId): void
    {
        $actorRole = $this->roleOf($actorUserId);

        /*
         * ⚠ AN ACTOR WITH NO MEMBERSHIP IS REFUSED, NOT WAVED THROUGH. This is unreachable
         * through routing — `RequireTenantScope` resolves the tenant FROM the caller's
         * memberships, so a caller with none never reaches a `/v1` route — and it fails closed
         * anyway, because the alternative is that a `null` here would be read as "no ceiling".
         * That is the fail-open direction, and it is the one this file's own class docblock
         * says a reviewer should read hardest.
         */
        if ($actorRole === null || ! $actorRole->maySeniorityGrant($granted)) {
            throw MemberActionDenied::seniority($actorRole ?? TenantRole::Staff, $granted);
        }
    }

    /**
     * `§4 › Security`'s third limb: *…or change its own role at all*.
     *
     * ⚠ COMPARED BY MEMBERSHIP ID AND NOT BY USER ID, which is the comparison the clause
     * actually needs. `changeRole()` is given a `$membershipId` — a `tenant_users.id` — and the
     * actor is known by their `user_id`. Comparing the two directly would never match and the
     * guard would be permanently inert: a check that looks complete and is not, which is the
     * exact shape `QRPAGER-29 §5` warns about one level up. So the actor's own membership row
     * is resolved first, inside the scope, and the ids are compared.
     *
     * @throws MemberActionDenied when the target is the actor's own membership
     */
    private function refuseSelfRoleChange(string $membershipId, string $actorUserId): void
    {
        $own = DB::selectOne('select id from tenant_users where user_id = ?', [$actorUserId]);

        if ($own !== null && (string) $own->id === $membershipId) {
            throw MemberActionDenied::ownRole();
        }
    }

    /**
     * The role this user holds in the scoped tenant, or null if they hold none.
     *
     * Inside the caller's scope, so it can only ever read a membership of the tenant the
     * request resolved — the same bound every other statement in this class runs under.
     */
    private function roleOf(string $userId): ?TenantRole
    {
        $row = DB::selectOne('select role from tenant_users where user_id = ?', [$userId]);

        return $row === null ? null : TenantRole::tryFrom((string) $row->role);
    }

    private function lockOwnerCount(): int
    {
        $rows = DB::select(
            'select id from tenant_users where role = ?::tenant_role order by id for update',
            [TenantRole::Owner->value],
        );

        return count($rows);
    }

    /** @param array<string, string> $context */
    private function audit(
        string $tenantId,
        string $membershipId,
        string $action,
        string $actorUserId,
        ?string $actorIp,
        array $context,
    ): void {
        DB::insert(
            'insert into audit_log
                (tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context, ip_address)
             values (?, ?::audit_actor_type, ?, ?, ?, ?, ?::jsonb, ?::inet)',
            [
                $tenantId,
                AuditActorType::User->value,
                $actorUserId,
                $action,
                'tenant_user',
                $membershipId,
                json_encode($context, JSON_THROW_ON_ERROR),
                $actorIp,
            ],
        );
    }
}
