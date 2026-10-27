<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Platform\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Register, forget and prune the devices one session may be buzzed on.
 *
 * EVERY METHOD OPENS A TENANT SCOPE, and the tenant always comes from a
 * ResolvedPagerSession or from a row the worker claimed -- never from HTTP input, which
 * on these routes does not carry one at all. Inside the scope another tenant's
 * pager_sessions row is invisible to row level security, so the composite foreign key
 * (tenant_id, pager_session_id) -> pager_sessions (tenant_id, id) CANNOT BE SATISFIED for
 * a session this token does not own. That is structural rather than a check anyone has to
 * remember: the IDOR answer here is a foreign key, not an `if`.
 *
 * NO MODEL, NO MASS ASSIGNMENT. Every statement names its columns, which is the same
 * posture PagerSessions takes and the reason §11's "mass assignment guarded" has nothing
 * to guard.
 *
 * THE ENDPOINT IS TREATED AS A CREDENTIAL. A push endpoint URL is a bearer capability for
 * one device: anyone holding it can send that device a notification until it is revoked.
 * So it is never logged, never returned in a response body, and where something must be
 * said about one, it is said about a short hash (see fingerprint()). That is the same
 * rule §4 › Data applies to the QR token, applied to the other secret this feature holds.
 */
final class PushSubscriptions
{
    /**
     * Register a device, or update the keys of one already registered.
     *
     * IDEMPOTENT BY THE UNIQUE INDEX, not by a read-then-write check. A browser that
     * re-subscribes sends the same endpoint with freshly derived keys, which is the
     * ordinary case rather than a conflict -- UNIQUE (pager_session_id, endpoint) turns
     * it into one row either way. Same mechanism as Task 3's outbox dedupe key, and the
     * app role's grants are exactly the three columns ON CONFLICT needs (§2.7).
     *
     * Returns true when the row was CREATED, so the endpoint can answer 201 or 200
     * honestly. That cannot be read from the affected-row count: Postgres reports 1 for
     * an insert and 1 for an ON CONFLICT update alike. `RETURNING (xmax = 0)` would
     * distinguish them and this project keeps no RETURNING anywhere (§4 › Postgres
     * traps), so the question is answered by a pre-read INSIDE THE SAME SCOPE -- one
     * index probe on the very unique index the conflict target names, inside the one
     * transaction withTenant() opens, so it cannot disagree with the write that follows
     * it.
     */
    public function register(ResolvedPagerSession $session, string $endpoint, string $p256dh, string $auth): bool
    {
        return withTenant($session->tenantId, function () use ($session, $endpoint, $p256dh, $auth): bool {
            $existing = DB::selectOne(
                'select 1 as present from push_subscriptions where pager_session_id = ? and endpoint = ?',
                [$session->id, $endpoint],
            );

            DB::insert(
                'insert into push_subscriptions
                    (id, tenant_id, pager_session_id, endpoint, p256dh, auth)
                 values (?, ?, ?, ?, ?, ?)
                 on conflict (pager_session_id, endpoint)
                 do update set p256dh = excluded.p256dh, auth = excluded.auth, updated_at = now()',
                [(string) Str::uuid7(), $session->tenantId, $session->id, $endpoint, $p256dh, $auth],
            );

            return $existing === null;
        });
    }

    /**
     * Unsubscribe one device. Idempotent: removing what is not there is a success.
     *
     * The customer half of pruning. A browser that revokes permission tells us here; a
     * push service that has already dropped the device tells the worker, through 404/410.
     */
    public function forget(ResolvedPagerSession $session, string $endpoint): int
    {
        return withTenant($session->tenantId, fn (): int => DB::delete(
            'delete from push_subscriptions where pager_session_id = ? and endpoint = ?',
            [$session->id, $endpoint],
        ));
    }

    /**
     * Every device for one session, read AS THE WORKER inside its tenant scope.
     *
     * The connection is named because the worker role is not interchangeable with the app
     * role here: the worker holds SELECT and DELETE on this table and no UPDATE, which is
     * exactly what a dispatcher that sends and prunes needs and nothing more (§2.7).
     *
     * Measured 2026-10-27: with no tenant context this role sees ZERO rows in this table
     * -- push_subscriptions has no cross-tenant worker policy, only the four §4 names --
     * so this scope is load-bearing and not decoration.
     *
     * @return list<object{id: string, endpoint: string, p256dh: string, auth: string}>
     */
    public function forSession(string $tenantId, string $sessionId): array
    {
        $connection = DatabaseRoles::connection('worker');

        /** @var list<object{id: string, endpoint: string, p256dh: string, auth: string}> */
        return withTenant($tenantId, fn (): array => DB::connection($connection)->select(
            'select id, endpoint, p256dh, auth
               from push_subscriptions
              where pager_session_id = ?
              order by created_at',
            [$sessionId],
        ), $connection);
    }

    /**
     * Delete a subscription the push service has told us is finished. 404 or 410.
     *
     * Keyed by id and scoped to the tenant that owns it. The worker has DELETE on this
     * table and the grant's own comment in the Task 1 migration says why: "the worker
     * prunes on 404/410".
     */
    public function prune(string $tenantId, string $subscriptionId): int
    {
        $connection = DatabaseRoles::connection('worker');

        return withTenant($tenantId, fn (): int => DB::connection($connection)->delete(
            'delete from push_subscriptions where id = ?',
            [$subscriptionId],
        ), $connection);
    }

    /**
     * A short, stable, non-reversible handle for one endpoint, for a log line.
     *
     * Twelve hex characters of a sha256. Enough to follow one device through a log and
     * to tell two apart; not enough to send it anything. The full URL never reaches a
     * log, which is the clause this method exists to make easy to obey.
     */
    public static function fingerprint(string $endpoint): string
    {
        return substr(hash('sha256', $endpoint), 0, 12);
    }
}
