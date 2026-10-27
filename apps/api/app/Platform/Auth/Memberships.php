<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Membership;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * "A user's own memberships" — §4 › Tenancy's second named cross-tenant path, read through
 * §2.8's one mechanism.
 *
 * ================================================================================
 * WHY IT CANNOT BE WIDENED — the thing QRPAGER-12 §10.3(c) has to show
 * ================================================================================
 *
 * 1. **One argument, and it is the authenticated user's id.** `for()` takes the id the
 *    guard produced. There is no overload that takes a filter, a pattern, a list or a
 *    tenant; `MembershipScopeTest` asserts this class exposes exactly one public read.
 * 2. **One call site.** `AuthenticatedTenantResolver` is the only caller, and it passes
 *    `$request->user()->getAuthIdentifier()`. A test greps the tree for any other caller,
 *    because "the user id comes from the guard" is a property of the call site and not of
 *    this file.
 * 3. **The SQL is `select * from public.user_memberships(?)`** — a function with one
 *    exact-match uuid parameter, compared with `=` inside its body. No input shape returns
 *    a second user's memberships.
 * 4. **The function is `RETURNS TABLE` with four named columns** (§2.8 rule 1), so nothing
 *    else from `tenant_users` can come back even if this class asked for it.
 * 5. **`EXECUTE` is granted to `qrpager_app` alone**, after `REVOKE ALL … FROM PUBLIC`.
 *
 * ================================================================================
 * CACHING, under Octane — QRPAGER-12 §10.5
 * ================================================================================
 *
 * **Request-scoped, and nothing else.** This object is bound `scoped()`, which Octane
 * flushes at the request boundary by contract, so the memoised set below cannot outlive
 * the request that filled it. The alternative — a cache keyed by user id — was rejected:
 * it buys one indexed query (`tenant_users` carries `INDEX (user_id)`, §2.5) and pays for
 * it with a membership set that can be stale across a revocation, on a worker that stays
 * alive between requests. A stale membership is a cross-tenant read.
 *
 * **`scoped()` and not `singleton()`, and the reason is measured in this repo rather than
 * assumed.** `AppServiceProvider` records it: a class bound `singleton()` resolved as
 * three *different* objects across three real Octane requests, because nothing warmed it,
 * so each request resolved it inside `clone $this->app`. A binding that happens to be
 * discarded is isolation by accident, and the next warm-up changes it.
 *
 * It is memoised **per user id** rather than in a bare property, so that a request which
 * legitimately authenticates twice (a login that then resolves a tenant) cannot read the
 * first user's set for the second user.
 */
final class Memberships
{
    /** @var array<string, list<Membership>> */
    private array $memoised = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Every membership this user holds. Cross-tenant by design, bounded by the five
     * properties in the class docblock.
     *
     * @return list<Membership>
     */
    public function for(string $userId): array
    {
        // Shape-checked before it reaches the database, like every other identifier in
        // this project: the value becomes a tenant scope and a Redis namespace
        // downstream, and `§4 › Realtime` makes that a thing to validate rather than
        // trust. A non-uuid here means a guard produced something impossible, so the
        // honest answer is "no memberships" rather than a query.
        if (! Str::isUuid($userId)) {
            return [];
        }

        if (array_key_exists($userId, $this->memoised)) {
            return $this->memoised[$userId];
        }

        $rows = $this->connection->select(
            'select tenant_id, role, tenant_status, tenant_slug from public.user_memberships(?)',
            [$userId],
        );

        $memberships = [];

        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->tenant_id, $row->role, $row->tenant_status)) {
                continue;
            }

            $role = TenantRole::tryFrom((string) $row->role);
            $status = TenantStatus::tryFrom((string) $row->tenant_status);

            // A value Postgres holds and PHP does not know is a contract drift, and the
            // safe direction is to drop the row rather than to guess: a membership whose
            // role cannot be mapped grants nothing. §2.3's generated-enum check is what
            // stops this arising; this is the belt.
            if ($role === null || $status === null) {
                continue;
            }

            $memberships[] = new Membership(
                tenantId: (string) $row->tenant_id,
                role: $role,
                tenantStatus: $status,
                tenantSlug: (string) ($row->tenant_slug ?? ''),
            );
        }

        return $this->memoised[$userId] = $memberships;
    }

    /**
     * The membership this user holds in exactly this tenant, or null.
     *
     * An exact match over the set from `for()`, in PHP — NOT a second query with the
     * tenant id in it. That is deliberate: a `where tenant_id = ?` variant would be a
     * second entry point taking a caller-supplied tenant, which is the widening property 1
     * exists to prevent. The set is at most a handful of rows.
     */
    public function in(string $userId, string $tenantId): ?Membership
    {
        foreach ($this->for($userId) as $membership) {
            if (hash_equals($membership->tenantId, $tenantId)) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * The one membership this user holds, or null when they hold none or several.
     *
     * "Several" answers null rather than picking the first: a request that does not say
     * which tenant it means, from a user who belongs to more than one, has no correct
     * answer, and choosing one silently would make the first tenant in an unordered result
     * set the default. The client names the tenant instead.
     */
    public function sole(string $userId): ?Membership
    {
        $all = $this->for($userId);

        return count($all) === 1 ? $all[0] : null;
    }
}
