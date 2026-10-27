<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Platform\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            // ⚠ AND THE OLDEST BEYOND THE CAP IS EVICTED, NEWEST WINS -- security `M-1`,
            // 2026-10-27. The cap arrived with R-083 as a lease bound and `forSession()`
            // kept the OLDEST, which turned "extra devices are noisy" into an
            // attacker-controlled DENIAL: anyone holding the QR token could register five
            // endpoints of their own BEFORE the customer's browser subscribed, and the
            // customer's real device would sit outside the cap for the session's whole
            // two-hour life. The attacker's endpoints answer 200, so pruning never
            // reaches them (that is 404/410 only), and `forget()` needs an endpoint
            // nobody but its owner has. Five POSTs to silence the one notification this
            // product exists to send. **A cap that creates a denial is worse than the
            // unbounded case it replaced.**
            //
            // NEWEST WINS, which is also R-091's own "at least as defensible" reading:
            // the newest subscription is the device in the customer's hand. Enforced HERE
            // at registration rather than only at read time, so the table is bounded too
            // and the row an attacker planted is actually deleted rather than merely
            // skipped.
            //
            // Keyed by id from a policy-limited read inside this scope, never a bulk
            // DELETE -- `§4 › Postgres traps` applies to DELETE exactly as it does to
            // UPDATE, and the app role holds DELETE on this table for `forget()`.
            $this->evictOldestBeyondCap($session);

            return $existing === null;
        });
    }

    /**
     * Keep at most `max_devices_per_session` rows for one session, dropping the oldest.
     *
     * Runs INSIDE register()'s tenant scope, which is why it takes no tenant argument and
     * why it must never be called from anywhere else: outside a scope the SELECT below
     * sees nothing and the DELETE would be a no-op that looked like success.
     */
    private function evictOldestBeyondCap(ResolvedPagerSession $session): void
    {
        $cap = max(1, (int) config('webpush.worker.max_devices_per_session'));

        /** @var list<object{id: string}> $stale */
        $stale = DB::select(
            "select id
               from push_subscriptions
              where pager_session_id = ?
              order by created_at desc, id desc
             offset {$cap}",
            [$session->id],
        );

        foreach ($stale as $row) {
            DB::delete('delete from push_subscriptions where id = ?', [(string) $row->id]);
        }

        if ($stale !== []) {
            // The session and a count, never an endpoint: an endpoint is a bearer
            // capability for one device and this class keeps it out of logs.
            Log::info('evicted the oldest push subscription(s) beyond the per-session cap', [
                'pager_session_id' => $session->id,
                'cap' => $cap,
                'evicted' => count($stale),
            ]);
        }
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
     * Every device for one session -- up to the cap -- read AS THE WORKER inside its scope.
     *
     * The connection is named because the worker role is not interchangeable with the app
     * role here: the worker holds SELECT and DELETE on this table and no UPDATE, which is
     * exactly what a dispatcher that sends and prunes needs and nothing more (§2.7).
     *
     * Measured 2026-10-27: with no tenant context this role sees ZERO rows in this table
     * -- push_subscriptions has no cross-tenant worker policy, only the four §4 names --
     * so this scope is load-bearing and not decoration.
     *
     * ⚠ NEWEST FIRST, NOT OLDEST -- security `M-1`, 2026-10-27. register() now evicts the
     * oldest beyond the cap, so in practice this read never truncates anything; the
     * ordering here is the BACKSTOP agreeing with the enforcement, so that a session
     * whose rows predate the eviction logic is capped the same way round. Keeping the
     * oldest was what let a token holder register five endpoints before the customer and
     * lock the real device out for two hours.
     *
     * ⚠ IT IS CAPPED, AND THE CAP IS A LEASE BOUND BEFORE IT IS A PRODUCT RULE -- R-083's
     * second half, 2026-10-27. This returned EVERY row until then, and OutboxWorker sends
     * to each one sequentially, so the number of devices one session carried was the
     * number of HTTP round trips one outbox row cost. Nothing bounded it: a token holder
     * could register endpoints until a single row's processing exceeded its own lease,
     * at which point a second worker claims the row and the customer is buzzed twice.
     * That is the SAME duplicate-send window QA found from the batch-wide lease, reached
     * from the device side; this task's two §11 reviews found the two ends independently.
     *
     * `config/webpush.php` carries the number and the arithmetic that picks it: it is
     * sized so that `cap x request` fits inside `lease_seconds`. The final product figure
     * is `PLAN.md §5 R-091`, owner Muhammad; which devices survive is no longer open --
     * security `M-1` settled it as newest-wins.
     *
     * ONE ROW OVER THE CAP IS READ ON PURPOSE, so that the cap biting is OBSERVABLE
     * rather than silent: a LIMIT of exactly the cap cannot tell "five devices" from
     * "five of nine", and a customer whose newest phone is quietly never buzzed is
     * exactly the complaint nobody can reproduce. The extra row is discarded.
     *
     * @return list<object{id: string, endpoint: string, p256dh: string, auth: string}>
     */
    public function forSession(string $tenantId, string $sessionId): array
    {
        $connection = DatabaseRoles::connection('worker');
        $cap = max(1, (int) config('webpush.worker.max_devices_per_session'));

        // THE LIMIT IS INTERPOLATED AND THE REST IS BOUND, which is OutboxWorker::claim()'s
        // split and the expiry job's, for the same reason: it is an integer this class
        // produced from config, never caller text, and casting it here pins it at the one
        // place it is decided.
        $probe = $cap + 1;

        /** @var list<object{id: string, endpoint: string, p256dh: string, auth: string}> $devices */
        $devices = withTenant($tenantId, fn (): array => DB::connection($connection)->select(
            "select id, endpoint, p256dh, auth
               from push_subscriptions
              where pager_session_id = ?
              order by created_at desc, id desc
              limit {$probe}",
            [$sessionId],
        ), $connection);

        if (count($devices) <= $cap) {
            return $devices;
        }

        // The session id and a count, never an endpoint: an endpoint is a bearer
        // capability for one device and this class's own docblock keeps it out of logs.
        Log::warning('push subscriptions for one session exceed the cap; the oldest are not being sent to', [
            'pager_session_id' => $sessionId,
            'cap' => $cap,
        ]);

        return array_slice($devices, 0, $cap);
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
