<?php

declare(strict_types=1);

namespace App\Features\Members;

use App\Domain\Audit\AuditActorType;
use App\Domain\Tenancy\TenantRole;
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
            'select tu.id, tu.user_id, tu.role, u.name, u.email
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

            $members[] = [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
            ];
        }

        return $members;
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
            $already = DB::selectOne('select id from tenant_users where user_id = ?', [$userId]);

            if ($already !== null) {
                // Idempotent rather than a 409: adding somebody who is already here is not
                // an error worth a distinct answer, and UNIQUE (tenant_id, user_id) is what
                // actually prevents the duplicate.
                return [
                    'id' => (string) $already->id,
                    'user_id' => $userId,
                    'role' => $role->value,
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
