<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/** Mirrored by the Postgres enum type [tenant_role] (PLAN.md §2.3). */
enum TenantRole: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /**
     * ================================================================================
     * THE SENIORITY LADDER, ADDED AT TASK 10e. AND WHY IT IS HERE AND NOT IN `RoleMatrix`.
     * ================================================================================
     *
     * `QRPAGER-29 §5` names the trap, and it is the design decision in this task:
     *
     *   *"`RoleMatrix` is `(principal × ability)` and has no seniority dimension (`R-106`'s own
     *   reasoning). Adding one to that array would change the matrix every other test reads;
     *   the seniority rule is about **which role may be granted**, which is a different
     *   question from **which ability is held**."*
     *
     * Those really are two questions. `RoleMatrix` answers *may this principal do this thing*,
     * and at Task 5 it deliberately made owner and manager identical — its own comment says so
     * and says why (*"what separates an owner from a manager is billing and tenant settings,
     * and neither exists before Task 6"*). A seniority dimension bolted onto that array would
     * have to break that identity, and every test that reads the matrix would move with it.
     *
     * Seniority is a property of the ROLE, not of the (principal, ability) pair. So it lives on
     * the role, in `App\Domain`, where `§4 › Security`'s clause can be read beside it.
     *
     * ================================================================================
     * `§4 › Security`'s CLAUSE, VERBATIM, AND IT HAS BEEN IN FORCE SINCE 2026-10-28
     * ================================================================================
     *
     *   *"No actor may grant a role senior to its own, assume one, or change its own role at
     *   all."*
     *
     * `QRPAGER-29 §0` measured what was actually enforced before 10e and the answer was
     * **nothing**: `UpdateMemberRoleRequest` and `StoreMemberRequest` validated `role` against
     * the whole of `TenantRole::cases()` and never compared it with the actor's, and
     * `MemberDirectory::changeRole()` took the actor id only to write the audit row. `R-106`
     * measured the consequence by driving the route: a manager `PATCH /v1/members/{own-id}`
     * with `role: owner` answered **200**.
     *
     * So this is a rank-2 clause being implemented, not a new rule being invented — which is
     * why 10e needs no `PROJECT_INSTRUCTIONS.md` edit and takes none.
     */

    /**
     * Where this role sits on the ladder. Higher is more senior.
     *
     * ⚠ THE NUMBERS ARE NOT A CONTRACT AND NOTHING OUTSIDE THIS ENUM READS THEM. They exist
     * so that `maySeniorityGrant()` is a comparison rather than a nested match, and a role
     * inserted between two existing ones needs only a number here. Deliberately spaced by 1
     * rather than by 10: a gap invites somebody to slot a role in without thinking about where
     * it sits relative to BOTH neighbours, which is the one judgement this ladder needs.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Staff => 1,
        };
    }

    /**
     * Whether an actor holding THIS role may grant `$granted` to somebody.
     *
     * ⚠ `>=` AND NOT `>`, WHICH IS THE WHOLE OF THE CLAUSE AND IS EASY TO GET BACKWARDS.
     * *"No actor may grant a role senior to its own"* — so an owner may grant `owner` and a
     * manager may grant `manager`. Equal is allowed; only MORE senior is refused. A `>` here
     * would stop an owner from ever appointing a second owner, which is the normal way a
     * restaurant stops having a single point of failure, and would make the last-owner rule
     * permanent rather than protective.
     */
    public function maySeniorityGrant(self $granted): bool
    {
        return $this->rank() >= $granted->rank();
    }

    /**
     * The roles an actor holding this role may grant — what the SCREEN offers.
     *
     * Derived from `maySeniorityGrant()` rather than listed, so the screen and the enforcement
     * cannot disagree about what is permitted. The client reads the same list through
     * `/v1/auth/me`'s `grantable_roles`, which is why a manager's role picker has no *Owner*
     * option at all: `§12`'s bar is that a screen never offers an action the server refuses.
     *
     * ⚠ AND THE SCREEN NOT OFFERING IT IS NOT THE ENFORCEMENT. `MemberDirectory` checks
     * independently, on every call, reading the actor's role from the database inside the
     * tenant's own scope — a client is a client, and `R-106` was found by driving the route
     * rather than the screen.
     *
     * @return list<self>
     */
    public function grantableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $candidate): bool => $this->maySeniorityGrant($candidate),
        ));
    }
}
