<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Domain\Outbox\OutboxStatus;
use App\Domain\Pager\PagerDelivery;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PushSubscriptions;
use App\Platform\Database\DatabaseRoles;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drain the outbox and send the buzz. C5's "the worker sends Web Push, never the request".
 *
 * ===================================================================================
 * THE SHAPE, AND WHY IT IS CLAIM-THEN-LEASE RATHER THAN ONE TRANSACTION
 * ===================================================================================
 *
 *   TX 1 (short, NO tenant context):
 *       select ... from outbox where status = 'pending' and available_at <= now()
 *           order by available_at, id for update skip locked limit N
 *       update outbox set attempts = attempts + 1, available_at = now() + lease
 *           where id = ?            -- one keyed UPDATE per row
 *       COMMIT                      -- the lease is now durable
 *
 *   --- NO TRANSACTION IS OPEN WHILE THE EDGE IS CALLED ---
 *
 *   per row, inside withTenant(row.tenantId):
 *       read the session, read its subscriptions
 *       send                        -- outside any transaction
 *       TX 2: mark sent | back off | dead-letter, and prune what came back 404/410
 *
 * THE OBVIOUS DESIGN -- hold the FOR UPDATE transaction open across the send -- is wrong
 * here for a reason that is measured rather than stylistic: the worker role carries
 * idle_in_transaction_session_timeout = 60s (§2.1), and a batch of 100 rows against a
 * slow edge at 8 seconds each would be killed mid-pass. It also holds row locks across a
 * network call to somebody else's infrastructure, which is the classic way one slow
 * third party becomes a database incident.
 *
 * SKIP LOCKED IS STILL WHAT MAKES THE CLAIM SAFE, and the lease is what extends that
 * safety past the commit. Measured 2026-10-27 on the composed Postgres 16, two concurrent
 * qrpager_worker sessions:
 *
 *     worker A: select ... for update skip locked        -> 2 rows
 *     worker B: the same statement, A still holding      -> 0 rows      <-- SKIP LOCKED
 *     worker B: the same without skip locked, lock_timeout 250ms -> 55P03
 *
 * ===================================================================================
 * KILL IT MID-BATCH: NOTHING LOST, NOTHING DUPLICATED
 * ===================================================================================
 *
 * NOTHING LOST. A kill at any instant leaves every unfinished row `pending` with
 * available_at in the near future. The lease expires, the next pass claims it, and it is
 * sent. There is no in-memory queue, no acknowledgement to lose, and no state that only
 * existed in the dead process -- which is the whole reason the lease is written to the
 * database before the send rather than held as a timer.
 *
 * NOTHING DUPLICATED, at the level the database can hold it. Two workers never hold one
 * row: SKIP LOCKED during the claim, the lease afterwards. A row that has been sent is
 * never claimed again: the claim predicate is `status = 'pending'`. And behind both,
 * UNIQUE (tenant_id, dedupe_key) is what Task 3 built so that one logical event is one
 * row at all.
 *
 * THE ONE WINDOW NO OUTBOX CLOSES, stated rather than claimed away: a crash after the
 * edge accepted a message and before TX 2 commits re-sends that one message. It is one
 * HTTP call wide. It is bounded by the `Topic` header -- a push service replaces an
 * undelivered message with the same topic instead of queueing a second
 * (WebPushDispatcher) -- and the remainder is closed on the client by the service
 * worker's notification tag, which is Task 9's and is written down as R-075.
 *
 * ATTEMPTS ARE INCREMENTED AT CLAIM TIME, not after a failure, and that is deliberate: a
 * worker that is killed mid-send has already paid for the attempt. The alternative --
 * increment on failure -- means a row that kills the worker every time it is processed is
 * retried forever, because the crash is exactly the path that never records the attempt.
 * A poison row must cost attempts.
 *
 * ===================================================================================
 * TENANT CONTEXT
 * ===================================================================================
 *
 * The CLAIM runs with no tenant context, which is §4 › Tenancy's named "outbox queue"
 * path and is bounded by the worker's two USING (true) policies on that table alone.
 * EVERYTHING ELSE runs inside withTenant($row->tenantId, ...), with the id taken from the
 * claimed row. Measured 2026-10-27: with no context this role sees ZERO rows in
 * push_subscriptions, so the scope is load-bearing rather than tidy.
 *
 * ===================================================================================
 * IT RUNS AS THE WORKER, AND THE CONNECTION IS NAMED
 * ===================================================================================
 *
 * The app role has no cross-tenant policy anywhere and no UPDATE grant on outbox at all
 * (§2.7), so this cannot run on the default connection: it would claim zero rows and
 * write nothing. Same reason ExpirePagerSessionsCommand names its connection.
 */
final class OutboxWorker
{
    public function __construct(
        private readonly WebPushDispatcher $dispatcher,
        private readonly PushSubscriptions $subscriptions,
        private readonly PagerProjection $projection,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * One pass: claim up to $limit rows, then process each. Returns what happened.
     *
     * @return array{claimed: int, sent: int, retried: int, dead: int, pruned: int}
     */
    public function pass(?int $limit = null): array
    {
        $limit = $limit ?? (int) config('webpush.worker.batch_size');
        $claimed = $this->claim(max(1, $limit));

        $tally = ['claimed' => count($claimed), 'sent' => 0, 'retried' => 0, 'dead' => 0, 'pruned' => 0];

        foreach ($claimed as $row) {
            $outcome = $this->process($row);

            $tally[$outcome['result']]++;
            $tally['pruned'] += $outcome['pruned'];
        }

        return $tally;
    }

    /**
     * Take up to $limit rows and lease them. One short transaction, no tenant context.
     *
     * ORDER BY available_at, id matches outbox_claim_index exactly -- the partial index
     * `(available_at, id) WHERE status = 'pending'` Task 1 built -- so a claim is an
     * index scan over exactly the claimable rows and never a filter over the table.
     * Oldest first, so a backlog drains in the order customers joined it.
     *
     * THE LIMIT IS INTERPOLATED AND THE REST IS BOUND, which is the same split
     * ExpirePagerSessionsCommand makes and for the same reason: the limit is an integer
     * this class produced from config, never caller text, and casting it here pins it at
     * the one place it is decided.
     *
     * @return list<ClaimedOutboxRow>
     */
    private function claim(int $limit): array
    {
        $worker = $this->connection();
        $lease = max(1, (int) config('webpush.worker.lease_seconds'));

        return $worker->transaction(function () use ($worker, $limit, $lease): array {
            $rows = $worker->select(
                "select id, tenant_id, pager_session_id, type, dedupe_key, attempts
                   from outbox
                  where status = 'pending'
                    and available_at <= now()
                  order by available_at, id
                    for update skip locked
                  limit {$limit}",
            );

            $claimed = [];

            foreach ($rows as $row) {
                if (! is_object($row) || ! isset($row->id, $row->tenant_id, $row->type, $row->dedupe_key)) {
                    continue;
                }

                // The lease. Keyed, status-guarded, no RETURNING -- the same prescribed
                // shape §4 › Postgres traps requires of the expiry job, and for the same
                // reason: a bulk UPDATE that references no column of the table escapes
                // RLS row filtering entirely.
                $leased = $worker->update(
                    "update outbox
                        set attempts = attempts + 1,
                            available_at = now() + (interval '1 second' * ?),
                            updated_at = now()
                      where id = ? and status = 'pending'",
                    [$lease, (string) $row->id],
                );

                // Unreachable while this transaction holds the row lock. Loud rather than
                // silent if it ever is not: processing a row we did not lease is the one
                // way two workers could send the same notification.
                if ($leased !== 1) {
                    continue;
                }

                $claimed[] = new ClaimedOutboxRow(
                    (string) $row->id,
                    (string) $row->tenant_id,
                    isset($row->pager_session_id) ? (string) $row->pager_session_id : null,
                    PagerEvent::tryFrom((string) $row->type),
                    (string) $row->type,
                    (string) $row->dedupe_key,
                    (int) ($row->attempts ?? 0),
                );
            }

            return $claimed;
        });
    }

    /**
     * One row: build the message, send to every device, then finish the row.
     *
     * @return array{result: 'sent'|'retried'|'dead', pruned: int}
     */
    private function process(ClaimedOutboxRow $row): array
    {
        // A type this build does not know, or a row with no session, cannot be turned
        // into a notification by any number of retries. Dead-letter it now rather than
        // leave it to be re-claimed by every pass forever -- and record the raw type, so
        // whoever reads the dead letter knows what arrived.
        if ($row->event === null || $row->sessionId === null) {
            $this->finish($row, OutboxStatus::Dead, 'unroutable outbox type');

            $this->log->warning('outbox row is unroutable and was dead-lettered', [
                'outbox_id' => $row->id,
                'type' => $row->rawType,
            ]);

            return ['result' => 'dead', 'pruned' => 0];
        }

        try {
            $session = $this->session($row);
        } catch (Throwable $failure) {
            // The database refused or was unreachable. Transient by assumption, bounded
            // by max_attempts: a row whose session genuinely cannot be read six times
            // running is a row nothing will ever send.
            return $this->backOff($row, $failure::class);
        }

        // The session is gone -- the FK cascade removed it, or it never existed. There is
        // nobody to notify and no retry that changes that.
        if ($session === null) {
            $this->finish($row, OutboxStatus::Dead, 'session no longer exists');

            return ['result' => 'dead', 'pruned' => 0];
        }

        $devices = $this->subscriptions->forSession($row->tenantId, $row->sessionId);

        // NO SUBSCRIPTION IS NOT A FAILURE, and this is the ticket's "the dispatcher must
        // not assume a subscription exists". On iOS a customer who has not installed to
        // the Home Screen has no subscription at all, which is the single most likely
        // real-world case. There is nobody to tell, so the row is finished rather than
        // retried forever -- and the customer still sees the change by polling, which is
        // precisely what O2 made the fallback for.
        if ($devices === []) {
            $this->finish($row, OutboxStatus::Sent, null);
            $this->refreshProjection($row, $session);

            return ['result' => 'sent', 'pruned' => 0];
        }

        $message = new PushMessage($row->event, (string) $session->order_reference, $row->dedupeKey);

        $delivered = 0;
        $retryable = 0;
        $pruned = 0;

        foreach ($devices as $device) {
            $outcome = $this->dispatcher->send(
                $message,
                (string) $device->endpoint,
                (string) $device->p256dh,
                (string) $device->auth,
            );

            match ($outcome) {
                PagerDelivery::Delivered => $delivered++,
                // 404/410: the push service says this subscription is finished. Delete it
                // on the FIRST occurrence -- the gate's pruning clause -- and count it as
                // handled rather than failed: there is no device left to retry to.
                PagerDelivery::Gone => $pruned += $this->subscriptions->prune($row->tenantId, (string) $device->id),
                PagerDelivery::Retry => $retryable++,
                // Permanent: the request itself is wrong for this device. Retrying is a
                // loop, so it counts as neither delivered nor retryable and the row
                // finishes on whatever the other devices did.
                PagerDelivery::Permanent => null,
            };
        }

        // ANY device still worth retrying holds the row back, because the row is the unit
        // of work and there is no per-device cursor. The cost is a duplicate to the
        // devices that already took it, which is the same window the Topic header bounds.
        // The alternative -- finish the row when the first device succeeds -- silently
        // drops the notification for a customer's second device, and a customer with a
        // phone and a tablet is not exotic.
        if ($retryable > 0) {
            return $this->backOff($row, 'edge refused for '.$retryable.' of '.count($devices).' device(s)');
        }

        $this->finish($row, OutboxStatus::Sent, null);
        $this->refreshProjection($row, $session);

        $this->log->info('outbox notification sent', [
            'outbox_id' => $row->id,
            'delivered' => $delivered,
            'pruned' => $pruned,
        ]);

        return ['result' => 'sent', 'pruned' => $pruned];
    }

    /**
     * The session's own row, inside its tenant scope.
     *
     * `order_reference` is the only field the notification needs; `status` and
     * `expires_at` are what the projection refresh needs. token_hash is deliberately
     * absent -- the worker has no grant on it and naming it would be a 42501.
     *
     * @return object{order_reference: string, status: string, expires_at: string}|null
     */
    private function session(ClaimedOutboxRow $row): ?object
    {
        $connection = DatabaseRoles::connection('worker');

        /** @var object{order_reference: string, status: string, expires_at: string}|null */
        return withTenant($row->tenantId, fn (): ?object => DB::connection($connection)->selectOne(
            'select order_reference, status, expires_at from pager_sessions where id = ?',
            [$row->sessionId],
        ), $connection);
    }

    /**
     * Finish a row: sent, or dead. One keyed, status-guarded UPDATE, no RETURNING.
     *
     * `status = 'pending'` in the WHERE is the idempotency guard: a second attempt to
     * finish a row that is already finished reports 0 and writes nothing, which is what
     * makes the whole pass re-runnable after a kill.
     *
     * NO TENANT SCOPE, because this is the outbox and the outbox is the named cross-tenant
     * path -- the worker's UPDATE policy on it is USING (true). Scoping it would be
     * harmless and would also be the only statement in the worker that pretends the
     * outbox is tenant-scoped when the whole claim above says it is not.
     */
    private function finish(ClaimedOutboxRow $row, OutboxStatus $status, ?string $error): void
    {
        $column = $status === OutboxStatus::Sent ? 'sent_at' : 'dead_at';

        $this->connection()->update(
            "update outbox
                set status = ?::outbox_status, {$column} = now(), last_error = ?, updated_at = now()
              where id = ? and status = 'pending'",
            [$status->value, $error, $row->id],
        );
    }

    /**
     * Push the row into the future and leave it pending. Exponential backoff with jitter.
     *
     * base * 2^attempts, capped, +/- a fraction. THE JITTER IS THE SERVER'S OWN and is a
     * different thing from the client-side jitter §4 › Realtime asks of a polling client:
     * without it, every row that failed during one edge outage retries on the same tick,
     * which is the outage's second wave arriving all at once.
     *
     * `last_error` is a CATEGORY, never a driver message or an endpoint URL: this column
     * is readable by anyone who can read the row, and §4 › Edge & ops keeps SQL, bound
     * parameters and secrets out of anything a caller can reach.
     *
     * @return array{result: 'retried'|'dead', pruned: int}
     */
    private function backOff(ClaimedOutboxRow $row, string $reason): array
    {
        $max = max(1, (int) config('webpush.worker.max_attempts'));

        // attempts was already incremented by the claim, so it is the number of attempts
        // INCLUDING this one. At the cap the row has had its tries.
        if ($row->attempts + 1 >= $max) {
            $this->finish($row, OutboxStatus::Dead, 'retries exhausted: '.$reason);

            $this->log->warning('outbox row dead-lettered after exhausting retries', [
                'outbox_id' => $row->id,
                'attempts' => $row->attempts + 1,
            ]);

            return ['result' => 'dead', 'pruned' => 0];
        }

        $this->connection()->update(
            "update outbox
                set available_at = now() + (interval '1 second' * ?), last_error = ?, updated_at = now()
              where id = ? and status = 'pending'",
            [$this->backoffSeconds($row->attempts + 1), $reason, $row->id],
        );

        return ['result' => 'retried', 'pruned' => 0];
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = max(1, (int) config('webpush.worker.backoff.base_seconds'));
        $cap = max($base, (int) config('webpush.worker.backoff.cap_seconds'));
        $fraction = (float) config('webpush.worker.backoff.jitter_fraction');

        // 2 ** 30 is already far past any sane cap, and shifting further overflows on a
        // 32-bit build -- so the exponent is clamped before it is used rather than the
        // result after.
        $delay = min($cap, $base * (2 ** min(30, max(0, $attempt - 1))));
        $spread = (int) round($delay * max(0.0, $fraction));

        return max(1, $delay + random_int(-$spread, $spread));
    }

    /**
     * Keep the polling endpoint honest after a notification goes out.
     *
     * BEST EFFORT, and it must be: the notification has already been delivered, so a
     * Redis failure here must not turn a successful send into a retry that sends it
     * again. PagerProjection::refresh() is XX -- it updates a projection that exists and
     * creates nothing -- so a customer who never scanned gets no key from this.
     *
     * The status comes from the session row the worker read inside the scope, not from
     * the event: the row is what Postgres says now, and between the transition and this
     * pass a staff member may have moved it on again.
     *
     * @param  object{order_reference: string, status: string, expires_at: string}  $session
     */
    private function refreshProjection(ClaimedOutboxRow $row, object $session): void
    {
        $status = PagerStatus::tryFrom((string) $session->status);

        if ($status === null) {
            return;
        }

        $this->projection->refresh(
            $row->tenantId,
            (string) $row->sessionId,
            $status,
            (string) $session->order_reference,
            new DateTimeImmutable((string) $session->expires_at),
            new DateTimeImmutable,
        );
    }

    private function connection(): Connection
    {
        return DB::connection(DatabaseRoles::connection('worker'));
    }
}
