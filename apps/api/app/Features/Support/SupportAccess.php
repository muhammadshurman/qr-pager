<?php

declare(strict_types=1);

namespace App\Features\Support;

use App\Domain\Audit\AuditActorType;
use App\Domain\Auth\SupportAccessRefused;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Tenancy\SupportMemberships;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ================================================================================
 * TAKE SUPPORT ACCESS, ACT UNDER IT, RELEASE IT. `QRPAGER-22`.
 * ================================================================================
 *
 * `§4 › Tenancy`, the clause Muhammad added 2026-11-04 and which this class implements
 * literally: *"Support access is a temporary, audited membership, never a cross-tenant read.
 * A platform admin may enter exactly one explicitly chosen tenant's context as a membership
 * that expires, and inside it holds only the abilities that membership grants — RLS stays in
 * force and every query stays tenant-scoped. Taking it, acting under it and leaving it are
 * each written to the audit trail with the tenant, the actor and the reason. A platform admin
 * never holds more than one at a time, and an expired or released membership leaves no
 * residual ability. Its lifetime is fixed at 60 minutes and is not chosen by the taker."*
 *
 * ================================================================================
 * WHAT THIS CLASS DOES **NOT** DO, which is the part that keeps the clause true
 * ================================================================================
 *
 * It grants **no cross-tenant read and no bypass**. It writes one `tenant_users` row inside
 * the chosen tenant's own scope, and from then on the ordinary machinery does everything:
 * RLS is in force, `RequestPrincipal` resolves `Principal::Support` from the row's expiry,
 * and `RoleMatrix` gives that principal four READ abilities and no write. Nothing here
 * touches `app.bypass_tenant`, and nothing here is consulted at request time.
 *
 * ⚠ **THE LIFETIME IS A CONSTANT HERE AND IS NOT A PARAMETER, because `§4` says it is not
 * the taker's to choose.** A `$minutes` argument would make the clause a default rather than
 * a rule, and the first caller that passed 480 would be inside the letter of the code and
 * outside the clause.
 */
final class SupportAccess
{
    /**
     * Sixty minutes, fixed by `§4 › Tenancy`.
     *
     * ⚠ NOT CONFIGURABLE, not an argument, and not read from `config()`. The clause says
     * *"fixed at 60 minutes and is not chosen by the taker"*, and a config key is chosen by
     * whoever edits the environment.
     */
    public const LIFETIME_MINUTES = 60;

    /** The audit actions, so the panel, the tests and the trail cannot spell them apart. */
    public const TAKEN = 'support.taken';

    public const RELEASED = 'support.released';

    public const ACTED = 'support.acted';

    public function __construct(private readonly SupportMemberships $memberships) {}

    /**
     * Take support access to one explicitly chosen tenant.
     *
     * @throws SupportAccessRefused
     */
    public function take(string $tenantId, string $adminUserId, string $reason, ?string $actorIp = null): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            // Refused BEFORE anything exists to audit, which is the only order that makes
            // "written with the reason" unconditional.
            throw new SupportAccessRefused(SupportAccessRefused::NO_REASON);
        }

        $admin = DB::selectOne('select is_platform_admin from users where id = ?', [$adminUserId]);

        if ($admin === null || ! (bool) $admin->is_platform_admin) {
            throw new SupportAccessRefused(SupportAccessRefused::NOT_A_PLATFORM_ADMIN);
        }

        /*
         * ⚠ READ THROUGH `SupportMemberships`, WHICH REACHES `§4 › Tenancy`'s SECOND NAMED
         * PATH, so this adds no cross-tenant path: it uses the one that exists. And because
         * `user_memberships()` filters expired rows in SQL, "holds one" means holds a LIVE
         * one — an admin whose last support membership lapsed may take another.
         */
        if ($this->memberships->heldTenantId($adminUserId) !== null) {
            throw new SupportAccessRefused(SupportAccessRefused::ALREADY_HOLDS_ONE);
        }

        if ($this->memberships->isOrdinaryMemberOf($adminUserId, $tenantId)) {
            throw new SupportAccessRefused(SupportAccessRefused::ALREADY_A_MEMBER);
        }

        $membershipId = (string) Str::uuid7();
        $expiresAt = Carbon::now()->addMinutes(self::LIFETIME_MINUTES);

        return withTenant($tenantId, function () use (
            $tenantId, $adminUserId, $membershipId, $expiresAt, $reason, $actorIp
        ): string {
            /*
             * ⚠ THE ROLE IS `staff`, THE LEAST PRIVILEGED, AND IT IS NOT WHAT DECIDES THE
             * ABILITIES. `Principal::forMembership()` returns `Support` for any membership
             * with an expiry, whatever the role says, and `RoleMatrix` gives `Support` four
             * reads. Storing the floor means that if that resolution were ever bypassed the
             * failure is a staff member rather than an owner — it fails closed.
             *
             * ⚠ AND `expires_at` IS WHAT MAKES THIS A SUPPORT MEMBERSHIP. The partial unique
             * index `UNIQUE (user_id) WHERE expires_at IS NOT NULL` is what enforces "never
             * more than one at a time" against every path, not just this method: the check
             * above is the friendly answer, this is the guarantee, and `23505` is what a race
             * between two takes gets.
             */
            try {
                DB::insert(
                    'insert into tenant_users (id, tenant_id, user_id, role, expires_at)
                     values (?, ?, ?, ?::tenant_role, ?)',
                    [$membershipId, $tenantId, $adminUserId, TenantRole::Staff->value, $expiresAt],
                );
            } catch (UniqueConstraintViolationException $e) {
                /*
                 * ⚠ **THE DATABASE IS THE AUTHORITY ON ONE-AT-A-TIME, AND THIS TRANSLATES ITS
                 * ANSWER.** The pre-check above is the friendly path and it is not the
                 * guarantee: two concurrent takes both pass it and both arrive here, and the
                 * partial unique index is what refuses the second. Translating `23505` into
                 * the same refusal the pre-check raises means the caller cannot tell a race
                 * from a sequential second attempt — which is correct, because the answer is
                 * the same.
                 *
                 * `UNIQUE (tenant_id, user_id)` can also raise here, when an admin who is
                 * already an ordinary member of THIS tenant slips past the pre-check the same
                 * way. `ALREADY_HOLDS_ONE` is the right answer for both: either way they
                 * already have what support would have given them.
                 */
                throw new SupportAccessRefused(SupportAccessRefused::ALREADY_HOLDS_ONE, $e);
            }

            $this->audit($tenantId, $membershipId, self::TAKEN, $adminUserId, $actorIp, [
                'reason' => $reason,
                'expires_at' => $expiresAt->toIso8601String(),
                'lifetime_minutes' => self::LIFETIME_MINUTES,
            ]);

            return $membershipId;
        });
    }

    /**
     * Release the support access this admin holds.
     *
     * ⚠ **IT DELETES THE ROW, and that is the strongest reading of *"leaves no residual
     * ability"*** — there is nothing left to mis-read. It is also required by the
     * one-at-a-time index, whose predicate cannot be `expires_at > now()` (`now()` is not
     * immutable, so Postgres refuses it in an index): a spent row left in place would block
     * the next take forever.
     *
     * @throws SupportAccessRefused
     */
    public function release(string $adminUserId, string $reason = 'released by the holder', ?string $actorIp = null): void
    {
        $tenantId = $this->memberships->heldTenantId($adminUserId);

        if ($tenantId === null) {
            throw new SupportAccessRefused(SupportAccessRefused::NOT_HELD);
        }

        withTenant($tenantId, function () use ($tenantId, $adminUserId, $reason, $actorIp): void {
            /** @var object{id: string}|null $row */
            $row = DB::selectOne(
                'select id from tenant_users where user_id = ? and expires_at is not null',
                [$adminUserId],
            );

            if ($row === null) {
                // Raced, or already swept. Nothing to delete and nothing to audit: the
                // post-condition the caller wanted is already true.
                return;
            }

            DB::delete('delete from tenant_users where id = ?', [(string) $row->id]);

            // Audited AFTER the delete, so a trail entry never claims a release that did not
            // happen -- the ordering `LandingPages::publish()` uses for the same reason.
            $this->audit($tenantId, (string) $row->id, self::RELEASED, $adminUserId, $actorIp, [
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Record that the holder acted under support access.
     *
     * `§4` requires *"acting under it"* to be audited as well as taking and leaving. This is
     * the hook a surface calls when a support holder performs a read worth recording; it
     * writes nothing to `tenant_users` and refuses nothing.
     */
    public function recordAction(string $tenantId, string $adminUserId, string $what, ?string $actorIp = null): void
    {
        withTenant($tenantId, fn () => $this->audit(
            $tenantId,
            $adminUserId,
            self::ACTED,
            $adminUserId,
            $actorIp,
            ['acted' => $what],
        ));
    }

    /** @param array<string, mixed> $context */
    private function audit(
        string $tenantId,
        string $subjectId,
        string $action,
        string $actorUserId,
        ?string $actorIp,
        array $context,
    ): void {
        /*
         * ⚠ `AuditActorType::Platform` AND NOT `User`, which is the one place the trail must
         * distinguish this from ordinary member management. `MemberDirectory::audit()` writes
         * `User` because its actor is a member of the restaurant; a support holder is not —
         * they are the platform, inside for an hour. `§4` requires the trail to carry "the
         * tenant, the actor and the reason", and the actor's KIND is part of who they are.
         */
        DB::insert(
            'insert into audit_log
                (tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context, ip_address)
             values (?, ?::audit_actor_type, ?, ?, ?, ?, ?::jsonb, ?::inet)',
            [
                $tenantId,
                AuditActorType::Platform->value,
                $actorUserId,
                $action,
                'support_membership',
                $subjectId,
                json_encode($context, JSON_THROW_ON_ERROR),
                $actorIp,
            ],
        );
    }
}
