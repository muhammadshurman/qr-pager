<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The two membership questions support access asks, answered in the Platform layer.
 *
 * ⚠ **IT EXISTS FOR TWO REASONS, AND BOTH WERE MEASURED RATHER THAN DESIGNED.**
 *
 * **One: a layer boundary.** `App\Features\Support\SupportAccess` read
 * `App\Platform\Auth\Memberships` directly and `composer deptrac` refused it —
 * `app/Platform/Auth/.*` is its own **Auth** layer, excluded from **Platform** by that
 * layer's collector, and `Features` may depend on `Domain`, `Platform` and `Framework`, not
 * on `Auth`. The same refusal produced `UserAttachment` at `R-233`.
 *
 * **Two: a memo that is right for reads and wrong for writes.** `Memberships` is bound
 * `scoped()` and memoises per user id for a request. A service that TAKES and RELEASES
 * memberships cannot read through that: measured, the second `take()` in one request saw the
 * first one's stale empty memo. So this class reads the same function unmemoised — see
 * `live()`.
 *
 * ⚠ **AND IT ADDS NO CROSS-TENANT PATH.** `Memberships` reads
 * `public.user_memberships(uuid)` — `§4 › Tenancy`'s second named path, *"a user's own
 * memberships"* — which takes one exact-match uuid, turns the bypass on for its own body
 * only, and since `QRPAGER-22` filters `expires_at IS NULL OR expires_at > now()` in SQL.
 * So "holds one" below means holds a LIVE one, decided by the database rather than by this
 * class remembering to compare a timestamp.
 */
final class SupportMemberships
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * This user's live memberships, read fresh every time.
     *
     * ⚠ **UNMEMOISED ON PURPOSE, AND THAT IS WHY IT DOES NOT USE `Memberships`.** That class
     * is bound `scoped()` and memoises per user id for the lifetime of a request, which is
     * right for request-time principal resolution and WRONG for a service that mutates
     * memberships: measured, `SupportAccess::take()` read an empty memo, inserted, and the
     * next `take()` in the same request read the same stale memo and reached the database
     * instead of the friendly refusal — `23505` where a `SupportAccessRefused` was expected.
     *
     * It is the SAME named path: `public.user_memberships(uuid)`, `§4 › Tenancy`'s second,
     * one exact-match uuid, bypass on for the function's own body only. Only the caching
     * differs, so no cross-tenant path is added — `MembershipCallSiteTest` carries this call
     * site and the answer to where its user id comes from.
     *
     * The shape check mirrors `Memberships::for()`: an id that is not a uuid means a guard
     * produced something impossible, and the honest answer is "no memberships", not a query.
     *
     * @return list<object{tenant_id: string, expires_at: ?string}>
     */
    private function live(string $userId): array
    {
        if (! Str::isUuid($userId)) {
            return [];
        }

        /*
         * Two columns, named, never `select *`: the function gained `tenant_name` at 10b and
         * `expires_at` at QRPAGER-22, and a star here would silently start reading whatever a
         * later migration adds. `§2.8` rule 1 on the caller's side.
         *
         * The function already drops rows whose expiry has passed, so every row here is live.
         */
        /** @var list<object{tenant_id: string, expires_at: ?string}> $rows */
        $rows = $this->connection->select(
            'select tenant_id, expires_at from public.user_memberships(?)',
            [$userId],
        );

        return $rows;
    }

    /**
     * The tenant this user holds live support access to, or `null`.
     *
     * One value and not the membership object: the caller needs the tenant to scope a write
     * and to audit, and nothing else. Returning the object would hand a feature the role,
     * the slug and the name of a tenant it has not yet entered.
     */
    public function heldTenantId(string $userId): ?string
    {
        foreach ($this->live($userId) as $row) {
            if ($row->expires_at !== null) {
                return (string) $row->tenant_id;
            }
        }

        return null;
    }

    /**
     * Is this user an ORDINARY member of this tenant — one with no expiry?
     *
     * The question `§4`'s *"enter exactly one explicitly chosen tenant's context"* implies but
     * does not spell out: support is the bridge for an admin who is NOT a member. One who
     * genuinely belongs already has abilities by the ordinary path, and a support membership
     * on top would be a second row the one-at-a-time rule then has to reason about.
     */
    public function isOrdinaryMemberOf(string $userId, string $tenantId): bool
    {
        foreach ($this->live($userId) as $row) {
            if ($row->expires_at === null && (string) $row->tenant_id === $tenantId) {
                return true;
            }
        }

        return false;
    }
}
