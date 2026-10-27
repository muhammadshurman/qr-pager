<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerSessionNotFound;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Domain\Pager\PagerTransitionDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pager state machine, and the one place the task's first invariant is enforced.
 *
 * THE INVARIANT (PLAN.md §3 row 3, first clause): "the status change, the outbox row and
 * the audit row commit in one transaction, or none of them does". Every operation here
 * goes through apply(), which opens exactly ONE withTenant() scope and does all three
 * writes inside it. withTenant() opens one transaction (TenantContext::run), so the
 * boundary is not something each operation has to remember -- there is one, it is shared,
 * and it is proven by injecting a failure between the writes rather than by reading this
 * comment (PagerTransactionTest).
 *
 * WHY A ROW LOCK AND NOT A READ-THEN-WRITE CHECK. markReady has to be idempotent under
 * CONCURRENT calls ("one outbox row, one notification"), and two mechanisms do that here,
 * in this order:
 *
 *   1. `select ... for update` serialises concurrent callers on the row. The second one
 *      blocks, then sees a status that is no longer `waiting`, and writes NOTHING -- no
 *      update, no outbox row, no audit row. That is where idempotency actually comes from.
 *   2. `UNIQUE (tenant_id, dedupe_key)` on outbox is the backstop behind it, which is what
 *      §2.5 says that index exists for. If the lock were ever lost -- a different
 *      connection, a future rewrite -- the database still refuses the second row, with
 *      23505, which DatabaseErrorMapper renders as a 409 rather than a 500.
 *
 * Measured, and the reason FOR UPDATE is safe here at all: the app role holds only
 * COLUMN-level UPDATE on pager_sessions (§2.7), and `select ... for update` nonetheless
 * works under it inside a tenant scope. That was verified rather than assumed, because
 * Postgres requires UPDATE/DELETE/TRUNCATE privilege for FOR UPDATE and a column-level
 * grant satisfying it is not obvious.
 *
 * NO `RETURNING` ANYWHERE, including on the app's own writes where the worker's trap does
 * not apply. It is a convention this project keeps (§4 › Postgres traps: "a defensive
 * convention, not the mechanism") and keeping it uniformly means nobody has to work out
 * per call site whether the new row still satisfies a policy. The affected-row count is
 * the signal instead.
 *
 * In App\Features: it may import Domain, Platform and the framework, and deptrac keeps it
 * out of App\Platform\Auth, so this cannot be built on the pre-Task-5 auth stub.
 */
final class PagerSessions
{
    /**
     * Two hours, per PROJECT_INSTRUCTIONS.md §4 › Data.
     *
     * A CONSTANT AND NOT CONFIG, deliberately. The TTL is a locked invariant in a rank-2
     * file, so making it environment-configurable would let a deployment violate it with
     * no diff and no review -- the same reason PagerToken::BYTES is a constant. The two
     * genuinely tunable numbers in this feature (the renotify cap and the expiry batch
     * size) are in config/pager.php, where Task 14 can set them from measurements.
     */
    private const TTL_HOURS = 2;

    /**
     * Create a session: a fresh token, stored only as a hash, with a 2 h TTL.
     *
     * The token is returned exactly once, here, because this is the only moment it can
     * reach the QR code. Nothing can read it back: token_hash is one-way and the app role
     * cannot even name the column in a predicate (§2.7, measured).
     *
     * No outbox row. Creating a session notifies nobody -- the customer has not scanned
     * yet, and there is no subscription to send to. The audit row is still written, which
     * is the asymmetry PagerEvent documents: the audit log records that it happened, the
     * outbox records that somebody has to be told.
     */
    public function create(
        string $tenantId,
        string $orderReference,
        ?string $actorUserId = null,
        ?string $actorIp = null,
    ): IssuedPagerSession {
        $id = (string) Str::uuid7();
        $token = PagerToken::issue();

        $state = withTenant($tenantId, function () use ($id, $tenantId, $token, $orderReference, $actorUserId, $actorIp): PagerSessionState {
            // token_hash is bytea and PDO will not bind raw binary to it, so the hash is
            // bound as hex and decoded in SQL (§2.5). The RAW token never reaches this
            // statement, this connection or Postgres at all -- only its sha256 does.
            DB::insert(
                'insert into pager_sessions
                    (id, tenant_id, token_hash, order_reference, status, expires_at, created_by_user_id)
                 values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, now() + interval \''.self::TTL_HOURS.' hours\', ?)',
                [$id, $tenantId, $token->hashHex(), $orderReference, PagerStatus::Waiting->value, $actorUserId],
            );

            $this->audit($tenantId, $id, PagerAuditAction::Created, $actorUserId, $actorIp, [
                'status' => PagerStatus::Waiting->value,
            ]);

            return new PagerSessionState($id, PagerStatus::Waiting, $orderReference, 0, true);
        });

        return new IssuedPagerSession($state, $token);
    }

    /**
     * The buzz. Idempotent: a second call writes nothing and answers the same state.
     */
    public function markReady(string $tenantId, string $sessionId, ?string $actorUserId = null, ?string $actorIp = null): PagerSessionState
    {
        return $this->apply(
            $tenantId,
            $sessionId,
            PagerStatus::Ready,
            PagerAuditAction::Ready,
            PagerEvent::Ready,
            $actorUserId,
            $actorIp,
            // Already ready is the idempotent case, not a conflict: the caller asked for a
            // state the session is in, so the answer is that state.
            idempotentWhen: PagerStatus::Ready,
            columns: 'status = ?::pager_status, ready_at = now(), last_notified_at = now(), updated_at = now()',
        );
    }

    /** Repeat the notification. Needs `ready`, and is capped per session. */
    public function renotify(string $tenantId, string $sessionId, ?string $actorUserId = null, ?string $actorIp = null): PagerSessionState
    {
        $limit = (int) config('pager.renotify_limit');

        return withTenant($tenantId, function () use ($tenantId, $sessionId, $actorUserId, $actorIp, $limit): PagerSessionState {
            $session = $this->lock($sessionId);
            $status = PagerStatus::from($session->status);

            // Renotify changes no status, so it has its own guard rather than going
            // through the transition table: the question is not "may waiting become
            // ready" but "is there a notification to repeat".
            if ($status !== PagerStatus::Ready) {
                throw PagerTransitionDenied::cannotRenotify($status);
            }

            $count = (int) $session->renotify_count;

            if ($count >= $limit) {
                throw PagerTransitionDenied::renotifyLimitReached($status, $limit);
            }

            $attempt = $count + 1;

            // The count is read and written under the row lock, so two concurrent
            // renotifies cannot compute the same attempt number -- which is what keeps
            // the dedupe keys distinct and lets both notifications through. The status in
            // the WHERE is belt and braces behind the lock.
            $affected = DB::update(
                'update pager_sessions
                    set renotify_count = ?, last_notified_at = now(), updated_at = now()
                  where id = ? and status = ?::pager_status',
                [$attempt, $sessionId, PagerStatus::Ready->value],
            );

            if ($affected !== 1) {
                // Unreachable while the lock holds; a loud failure rather than a silent
                // no-op if it ever does not, because the alternative is an audit row for
                // work that did not happen.
                throw PagerTransitionDenied::cannotRenotify($status);
            }

            $this->enqueue($tenantId, $sessionId, PagerEvent::Renotify, $attempt);
            $this->audit($tenantId, $sessionId, PagerAuditAction::Renotified, $actorUserId, $actorIp, [
                'renotify_count' => $attempt,
            ]);

            return new PagerSessionState(
                $sessionId,
                PagerStatus::Ready,
                (string) $session->order_reference,
                $attempt,
                true,
            );
        });
    }

    /** Collected. No outbox row: the customer is at the counter. */
    public function complete(string $tenantId, string $sessionId, ?string $actorUserId = null, ?string $actorIp = null): PagerSessionState
    {
        return $this->apply(
            $tenantId,
            $sessionId,
            PagerStatus::Completed,
            PagerAuditAction::Completed,
            null,
            $actorUserId,
            $actorIp,
            idempotentWhen: PagerStatus::Completed,
            columns: 'status = ?::pager_status, completed_at = now(), updated_at = now()',
        );
    }

    /** Called off. Notifies, because a customer waiting for a buzz that will never come is worse. */
    public function cancel(string $tenantId, string $sessionId, ?string $actorUserId = null, ?string $actorIp = null): PagerSessionState
    {
        return $this->apply(
            $tenantId,
            $sessionId,
            PagerStatus::Cancelled,
            PagerAuditAction::Cancelled,
            PagerEvent::Cancelled,
            $actorUserId,
            $actorIp,
            idempotentWhen: PagerStatus::Cancelled,
            columns: 'status = ?::pager_status, cancelled_at = now(), updated_at = now()',
        );
    }

    /**
     * One transaction, one scope, three writes -- the shape the gate's first clause is about.
     *
     * The order inside is deliberate: lock, decide, then write. Deciding before the lock
     * would be the read-then-write race the row lock exists to remove.
     */
    private function apply(
        string $tenantId,
        string $sessionId,
        PagerStatus $to,
        PagerAuditAction $action,
        ?PagerEvent $event,
        ?string $actorUserId,
        ?string $actorIp,
        PagerStatus $idempotentWhen,
        string $columns,
    ): PagerSessionState {
        return withTenant($tenantId, function () use (
            $tenantId, $sessionId, $to, $action, $event, $actorUserId, $actorIp, $idempotentWhen, $columns
        ): PagerSessionState {
            $session = $this->lock($sessionId);
            $from = PagerStatus::from($session->status);
            $reference = (string) $session->order_reference;
            $renotifies = (int) $session->renotify_count;

            // IDEMPOTENCY, before the transition table. The session is already in the
            // state the caller asked for, so there is nothing to do and nothing to
            // record -- and critically no second outbox row, which is the gate's "one
            // outbox row, one notification" under a retry.
            if ($from === $idempotentWhen) {
                return new PagerSessionState($sessionId, $from, $reference, $renotifies, false);
            }

            if (! $from->canTransitionTo($to)) {
                throw PagerTransitionDenied::between($from, $to);
            }

            $affected = DB::update(
                "update pager_sessions set {$columns} where id = ? and status = ?::pager_status",
                [$to->value, $sessionId, $from->value],
            );

            if ($affected !== 1) {
                // The status moved between the lock and the update, which the lock makes
                // unreachable. Loud rather than silent: an audit row for work that did
                // not happen is worse than a failed request.
                throw PagerTransitionDenied::between($from, $to);
            }

            if ($event !== null) {
                $this->enqueue($tenantId, $sessionId, $event);
            }

            $this->audit($tenantId, $sessionId, $action, $actorUserId, $actorIp, [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            return new PagerSessionState($sessionId, $to, $reference, $renotifies, true);
        });
    }

    /**
     * The row, locked, or PagerSessionNotFound.
     *
     * NOT FOUND AND NEVER FORBIDDEN, which is the IDOR answer. Inside this tenant scope
     * another tenant's row is invisible to row level security, so a staff member of
     * tenant A passing tenant B's session id lands here and gets a 404 -- a 403 would
     * confirm the id exists. The gate clause is "cross-tenant IDOR blocked through HTTP,
     * not just at the DB", and this is the DB half; the HTTP half is asserted through a
     * real request.
     *
     * token_hash is deliberately absent from the select list. The app role could not read
     * it anyway, so naming it would turn every operation into a 42501.
     *
     * The shape is declared ONCE here rather than at each of the three call sites, which
     * is the same object{...} annotation RuntimeRoleGuard uses for the same reason:
     * selectOne() genuinely returns `object`, and what the columns are is decided by the
     * SQL above, which static analysis cannot read. Declaring it at the boundary means a
     * column added to or removed from that select list is one edit, not four.
     *
     * @return object{id: string, status: string, order_reference: string, renotify_count: int, expires_at: string}
     */
    private function lock(string $sessionId): object
    {
        $row = DB::selectOne(
            'select id, status, order_reference, renotify_count, expires_at
               from pager_sessions
              where id = ?
              for update',
            [$sessionId],
        );

        if (! is_object($row)) {
            throw PagerSessionNotFound::create();
        }

        /** @var object{id: string, status: string, order_reference: string, renotify_count: int, expires_at: string} $row */
        return $row;
    }

    /**
     * One outbox row, inside the caller's transaction.
     *
     * No `status` in the column list: §2.7 gives the app role no INSERT grant on it, so
     * the row takes the column default `pending` and the application cannot write a row
     * that is already sent. The same is true of `attempts`. That is a grant doing work, so
     * it is stated rather than looking like an omission.
     */
    private function enqueue(string $tenantId, string $sessionId, PagerEvent $event, ?int $attempt = null): void
    {
        DB::insert(
            'insert into outbox (id, tenant_id, pager_session_id, type, dedupe_key, payload)
             values (?, ?, ?, ?, ?, ?::jsonb)',
            [
                (string) Str::uuid7(),
                $tenantId,
                $sessionId,
                $event->value,
                $event->dedupeKey($sessionId, $event->isRepeatable() ? $attempt : null),
                json_encode(['session_id' => $sessionId], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * One audit row, inside the caller's transaction.
     *
     * actor_type is `user` with a NULL actor_user_id until Task 5 exists, and that is the
     * honest pair rather than a convenient one: a staff member really did this, and we
     * really cannot say who yet. `system` would be a lie and would make these rows
     * indistinguishable from the expiry job's, which are the only genuinely actorless
     * ones. Task 5 fills the id and changes nothing else here.
     *
     * `context` carries status values and counts -- our own enum cases and integers. Never
     * the token, never its hash, never anything the caller sent beyond what the column
     * already holds.
     *
     * @param  array<string, string|int>  $context
     */
    private function audit(
        string $tenantId,
        string $sessionId,
        PagerAuditAction $action,
        ?string $actorUserId,
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
                $action->value,
                PagerAuditAction::SUBJECT,
                $sessionId,
                json_encode($context, JSON_THROW_ON_ERROR),
                $actorIp,
            ],
        );
    }
}
