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
 * ===================================================================================
 * AND THE LEASE IS RE-TAKEN PER ROW, WHICH IS WHAT MAKES THE ABOVE TRUE -- R-083
 * ===================================================================================
 *
 * THE DEFECT, found independently by this task's QA and Security reviews on 2026-10-27:
 * the paragraph above was true of the CLAIM and false of the BATCH. claim() leases every
 * row to `now() + lease_seconds` in one transaction and COMMITS; pass() then walks the
 * batch one row at a time, each row costing up to one HTTP send per device. After that
 * commit SKIP LOCKED protects nothing -- only `available_at` does -- so any row this
 * worker had not reached by `claim_time + lease_seconds` was `pending AND available_at <=
 * now()` again, a second worker claimed it, and BOTH sent. The first worker's keyed,
 * status-guarded finish() then reported 0 and wrote nothing, so the duplicate left no
 * trace in the table at all. No attacker was needed: at batch_size 100 and lease_seconds
 * 60, ~0.7 s per send already exceeded the lease.
 *
 * THE FIX IS ONE STATEMENT PER ROW, immediately before that row's send: re-take the lease
 * on THAT ROW ALONE and check the affected-row count. Zero means somebody else owns it --
 * skip it silently, send nothing, record nothing, count it as `skipped`. See reLease().
 *
 * WHY THIS AND NOT THE OTHER FOUR CANDIDATES R-083 lists (renew during a long row, bound
 * lease_seconds by batch_size x worst case x devices, a per-device cursor, send
 * concurrently under one budget): it makes correctness INDEPENDENT OF BATCH SIZE, device
 * count and network latency, instead of resting on an estimate that a larger batch_size or
 * a slower network silently invalidates. No heartbeat, no concurrency, no change to this
 * class's shape. Muhammad's ruling, 2026-10-27.
 *
 * WHAT IS LEFT, AND WHAT BOUNDS IT. The re-lease moves the window from "the whole batch"
 * to "one row's processing", which is the bound config/webpush.php states in terms. That
 * bound is only true if one row's processing fits inside the lease, and the thing that
 * could make it not fit is an unbounded device count -- so the second half of the same
 * ruling is a cap on devices per session (PushSubscriptions::forSession, and the
 * arithmetic relating it to the lease is in config/webpush.php beside both numbers).
 *
 * THE ARMS BEFORE THE RE-LEASE SEND NOTHING, which is why it sits where it does rather
 * than at the top of process(): an unroutable row, a session read that failed, a session
 * that is gone and a session with no device all finish or back off through a keyed,
 * status-guarded UPDATE and open no second send. The re-lease guards the only statement
 * in this class that reaches somebody else's infrastructure.
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
     * @return array{claimed: int, sent: int, retried: int, dead: int, skipped: int, pruned: int}
     */
    public function pass(?int $limit = null): array
    {
        $limit = $limit ?? (int) config('webpush.worker.batch_size');
        $claimed = $this->claim(max(1, $limit));

        $tally = ['claimed' => count($claimed), 'sent' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0, 'pruned' => 0];

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

                // DEFENSIVE AND SILENT, and the comment used to claim the opposite (QA
                // L-7, 2026-10-27): it read "loud rather than silent" above a bare
                // `continue` with no log and no counter. The arm is unreachable while this
                // transaction holds the row's FOR UPDATE lock, which is why it has never
                // been observed and why it is reported uncovered -- so the claim was the
                // risk, not the behaviour. Skipping is the right action either way:
                // processing a row we did not lease is the one way two workers could send
                // the same notification, so a row that did not take the lease is left
                // alone for the next pass.
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
     * @return array{result: 'sent'|'retried'|'dead'|'skipped', pruned: int}
     */
    private function process(ClaimedOutboxRow $row): array
    {
        // A type this build does not know, or a row with no session, cannot be turned
        // into a notification by any number of retries. Dead-letter it now rather than
        // leave it to be re-claimed by every pass forever -- and record the raw type, so
        // whoever reads the dead letter knows what arrived.
        if ($row->event === null || $row->sessionId === null) {
            if (! $this->finish($row, OutboxStatus::Dead, 'unroutable outbox type')) {
                return $this->abandon($row);
            }

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
            if (! $this->finish($row, OutboxStatus::Dead, 'session no longer exists')) {
                return $this->abandon($row);
            }

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
            if (! $this->finish($row, OutboxStatus::Sent, null)) {
                return $this->abandon($row);
            }

            $this->refreshProjection($row, $session);

            return ['result' => 'sent', 'pruned' => 0];
        }

        // R-083. THE LAST THING BEFORE THE SEND, and the only thing standing between a
        // batch that outlived its lease and a customer's phone buzzing twice.
        if (! $this->reLease($row)) {
            return $this->abandon($row);
        }

        $message = new PushMessage($row->event, (string) $session->order_reference, $row->dedupeKey);

        $delivered = 0;
        $retryable = 0;
        $pruned = 0;
        $permanent = 0;

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
                // loop, so it is neither delivered nor retryable -- but it IS counted, and
                // the count is load-bearing: see the dead-letter branch below.
                PagerDelivery::Permanent => $permanent++,
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

        // EVERY DEVICE REFUSED PERMANENTLY AND NOBODY TOOK IT: the row is DEAD, not sent.
        //
        // QA H-2 (2026-10-27). This branch did not exist: with `retryable` at zero the row
        // fell through to `finish(Sent, null)`, so a notification that can never be
        // delivered was recorded with `status = 'sent'`, a `sent_at`, a null `last_error`
        // and an info log reading `delivered: 0`. THREE documents and the ticket said
        // otherwise -- PagerDelivery's own case comment ("the row dead-letters now rather
        // than after six tries"), WebPushDispatcher's mapping comment, and §6.2's
        // "Permanent (dead-letter)" -- so the code was the one place that disagreed, and
        // the case named `it dead-letters a 400 immediately` asserted the tally and the
        // attempt count while never reading the row's status.
        //
        // THE CONDITION IS "NOTHING REACHED ANYBODY", not "a permanent failure happened".
        // A delivery to one device and a 400 from another is still a delivered
        // notification, and a 410 is a subscription that no longer exists rather than a
        // failure -- all-pruned stays `sent` for the same reason no-devices does, since
        // there is nobody left to tell. Both halves are driven by their own case.
        if ($delivered === 0 && $pruned === 0 && $permanent > 0) {
            // NOT abandon() ON FAILURE HERE, and the difference matters: we have already
            // sent to every device by this point, so the attempt was really spent. A
            // worker that loses the row between the send and the write has done the work
            // and must not hand the attempt back; it simply writes nothing.
            if (! $this->finish($row, OutboxStatus::Dead, 'edge refused permanently for all '.$permanent.' device(s)')) {
                return ['result' => 'skipped', 'pruned' => $pruned];
            }

            $this->log->warning('outbox notification dead-lettered', [
                'outbox_id' => $row->id,
                'devices' => count($devices),
            ]);

            return ['result' => 'dead', 'pruned' => 0];
        }

        // Same reasoning as the dead-letter arm above: the sends happened, so a lost
        // lease here costs the record and not the attempt.
        if (! $this->finish($row, OutboxStatus::Sent, null)) {
            return ['result' => 'skipped', 'pruned' => $pruned];
        }

        $this->refreshProjection($row, $session);

        $this->log->info('outbox notification sent', [
            'outbox_id' => $row->id,
            'delivered' => $delivered,
            'pruned' => $pruned,
        ]);

        return ['result' => 'sent', 'pruned' => $pruned];
    }

    /**
     * Re-take the lease on ONE row, immediately before its send. R-083.
     *
     * THE PREDICATE IS THE WHOLE POINT, so each clause is named:
     *
     *   id = ?                    the key. Never a bulk statement -- `§4 › Postgres traps`
     *                             is explicit that an UPDATE referencing no column of the
     *                             table escapes RLS row filtering entirely, and the rule it
     *                             ends on is "reference the key and check the affected-row
     *                             count", which is exactly what this does.
     *   status = 'pending'        the row has not been finished under us.
     *   attempts = ?              THE LEASE OWNER. `outbox` has no lease_owner column; the
     *                             claim's own `attempts = attempts + 1` is what tells one
     *                             claim of a row from the next, so the attempt number this
     *                             worker wrote is its ownership token. A second worker that
     *                             re-claimed the row moved it, and this match fails.
     *                             ClaimedOutboxRow::leaseIdentity() carries the proof that
     *                             the value cannot come back.
     *   available_at > now()      THE LEASE HAS NOT EXPIRED. A lapsed lease is not a theft
     *                             yet, but it is the state in which one can happen at any
     *                             instant -- so sending on it is precisely the duplicate.
     *
     * ZERO ROWS MEANS SKIP, SILENTLY. Not an error and not a retry: another worker owns the
     * row and will send it, or the lease lapsed and the next pass will claim it. Writing
     * anything here -- a back-off, a last_error -- would be this worker editing a row it no
     * longer holds, and a back-off in particular would move somebody else's `available_at`
     * and shorten THEIR lease. Doing nothing is the only safe action, and the row is
     * counted as `skipped` so that doing nothing is still observable in the tally.
     *
     * NO RETURNING, like every other write in this class and in the expiry job: the
     * affected-row count IS the signal (`§4 › Postgres traps`).
     *
     * `attempts` IS NOT INCREMENTED HERE. A re-lease is not a new attempt -- the row is
     * still being processed by the worker that claimed it, and incrementing would both
     * burn the retry budget on a healthy row and invalidate this worker's own token for
     * the next row.
     */
    private function reLease(ClaimedOutboxRow $row): bool
    {
        $lease = max(1, (int) config('webpush.worker.lease_seconds'));

        $held = $this->connection()->update(
            "update outbox
                set available_at = now() + (interval '1 second' * ?),
                    updated_at = now()
              where id = ?
                and status = 'pending'
                and attempts = ?
                and available_at > now()",
            [$lease, $row->id, $row->leaseIdentity()],
        );

        if ($held !== 1) {
            // DEBUG, not warning. On a correctly sized lease this never fires; when it
            // does it is the mechanism working, not a fault, and the row is about to be
            // sent by whoever does hold it. A warning here would page somebody for a
            // duplicate that DIDN'T happen.
            $this->log->debug('outbox row skipped: the lease was no longer ours', [
                'outbox_id' => $row->id,
                'attempt' => $row->leaseIdentity(),
            ]);
        }

        return $held === 1;
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
     * AND `attempts = ?`, WHICH IS THE OWNERSHIP GUARD -- QA `H-1` / security `M-2`, found
     * independently by both reviewers of R-083's fix, 2026-10-27. The re-lease closed the
     * window before the SEND and left it open on the two statements that WRITE: a worker
     * whose lease had lapsed could still mark a row `sent` that another worker was in the
     * middle of sending, and the record would say one notification where two went out.
     * The predicate is the same shape reLease() uses, for the same reason, and it is
     * `attempts` and not `available_at > now()` here: finishing a row whose lease expired
     * while WE held it is correct and must keep working, because that is the ordinary
     * end of a slow-but-unstolen row. What must not happen is finishing a row somebody
     * else has claimed, and only the attempt number can tell those two apart.
     *
     * NO TENANT SCOPE, because this is the outbox and the outbox is the named cross-tenant
     * path -- the worker's UPDATE policy on it is USING (true). Scoping it would be
     * harmless and would also be the only statement in the worker that pretends the
     * outbox is tenant-scoped when the whole claim above says it is not.
     *
     * @return bool whether this worker still owned the row and wrote it
     */
    private function finish(ClaimedOutboxRow $row, OutboxStatus $status, ?string $error): bool
    {
        $column = $status === OutboxStatus::Sent ? 'sent_at' : 'dead_at';

        $written = $this->connection()->update(
            "update outbox
                set status = ?::outbox_status, {$column} = now(), last_error = ?, updated_at = now()
              where id = ? and status = 'pending' and attempts = ?",
            [$status->value, $error, $row->id, $row->leaseIdentity()],
        );

        if ($written !== 1) {
            $this->log->debug('outbox row not finished: the lease was no longer ours', [
                'outbox_id' => $row->id,
                'attempt' => $row->leaseIdentity(),
            ]);
        }

        return $written === 1;
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
     * ⚠ AND IT CARRIES THE OWNERSHIP GUARD, WHICH IS THE HALF THAT WAS MISSING -- QA
     * `H-1`, 2026-10-27, and the most reachable duplicate this task had left. This method
     * is called from a path that runs BEFORE the re-lease (the session read, below), so a
     * worker whose lease had already lapsed could land `available_at = now() + 5 s` on top
     * of the CURRENT holder's sixty-second lease, truncate it, and let a third claim
     * arrive while that holder was still sending. reLease()'s own docblock forbids exactly
     * that action -- *"a back-off in particular would move somebody else's `available_at`
     * and shorten THEIR lease"* -- and it was forbidden at the one statement where it
     * could not happen and permitted at the one where it could. It is not theoretical:
     * `qrpager_worker` carries `statement_timeout = 15s` and `lock_timeout = 3s`, so a
     * failing session read is ordinary under exactly the load that makes a lease lapse.
     *
     * @return array{result: 'retried'|'dead'|'skipped', pruned: int}
     */
    private function backOff(ClaimedOutboxRow $row, string $reason): array
    {
        $max = max(1, (int) config('webpush.worker.max_attempts'));

        // attempts was already incremented by the claim, so it is the number of attempts
        // INCLUDING this one. At the cap the row has had its tries.
        if ($row->attempts + 1 >= $max) {
            if (! $this->finish($row, OutboxStatus::Dead, 'retries exhausted: '.$reason)) {
                return $this->abandon($row);
            }

            $this->log->warning('outbox row dead-lettered after exhausting retries', [
                'outbox_id' => $row->id,
                'attempts' => $row->attempts + 1,
            ]);

            return ['result' => 'dead', 'pruned' => 0];
        }

        $written = $this->connection()->update(
            "update outbox
                set available_at = now() + (interval '1 second' * ?), last_error = ?, updated_at = now()
              where id = ? and status = 'pending' and attempts = ?",
            [$this->backoffSeconds($row->attempts + 1), $reason, $row->id, $row->leaseIdentity()],
        );

        if ($written !== 1) {
            return $this->abandon($row);
        }

        return ['result' => 'retried', 'pruned' => 0];
    }

    /**
     * Give the row up: we do not hold it, so we write nothing -- except to hand back the
     * attempt our own claim charged for work we never did.
     *
     * ⚠ THE ROLLBACK IS QA `M-7` / SECURITY `M-3`, 2026-10-27, and it is a correctness fix
     * rather than tidiness. `claim()` charges an attempt unconditionally and `backOff()`
     * dead-letters at `max_attempts`, so a row that is claimed and skipped five times --
     * which the re-lease makes the EXPECTED steady state under a slow edge, since
     * `config/webpush.php` says ~0.7 s per send already outruns the lease at
     * `batch_size` 100 -- arrives at the cap having never been sent to, and the first
     * genuine transient failure dead-letters it as "retries exhausted". That is a lost
     * notification, which is the other half of the invariant this task ships on.
     *
     * IT IS A DECREMENT, AND THE LEASE IDENTITY SURVIVES IT, which needs saying because
     * the identity's soundness proof turns on `attempts` never going backwards. The
     * statement is guarded by `attempts = ?` -- OUR token -- so it fires only when no
     * other claim has intervened, and the worker abandons the row in the same breath. The
     * invariant that actually matters is NOT "the counter never decreases": it is
     * **no two workers ever hold the same token for the same row at the same time**, and
     * a guarded rollback that retires its own token the instant it undoes its own
     * increment cannot break that. If the row HAS been claimed away, the predicate matches
     * nothing, the thief's count stands, and we leave without writing -- which is the
     * whole point. `OutboxLeaseIdentityTest` proves both halves.
     *
     * @return array{result: 'skipped', pruned: int}
     */
    private function abandon(ClaimedOutboxRow $row): array
    {
        $this->connection()->update(
            "update outbox
                set attempts = attempts - 1, updated_at = now()
              where id = ? and status = 'pending' and attempts = ?",
            [$row->id, $row->leaseIdentity()],
        );

        return ['result' => 'skipped', 'pruned' => 0];
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
