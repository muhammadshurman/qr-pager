<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerSessionExpired;
use App\Domain\Pager\PagerSessionNotFound;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Domain\Pager\PagerTransitionDenied;
use DateTimeImmutable;
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
     * Task 4's Redis status projection, refreshed after every committed transition.
     *
     * WHY THE SERVICE AND NOT ONLY THE OUTBOX WORKER. The worker refreshes the projection
     * too, but it only runs for transitions that WRITE AN OUTBOX ROW -- and `complete`
     * deliberately writes none (the customer is at the counter), so a projection that
     * waited for the worker would never learn that an order was collected. Refreshing
     * here covers every transition and the worker's refresh then covers the one thing
     * this cannot know: that the push actually went out.
     *
     * WHY IT CANNOT CREATE A KEY. PagerProjection::refresh() is XX -- update-only -- and
     * this class has no token to build a pointer key with, which Task 3 arranged by never
     * letting a token near the service. So the projection's lifetime belongs to the SCAN:
     * a session nobody scanned gets no Redis key from any number of staff transitions.
     */
    public function __construct(private readonly PagerProjection $projection) {}

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
        $projected = null;

        $state = withTenant($tenantId, function () use ($tenantId, $sessionId, $actorUserId, $actorIp, $limit, &$projected): PagerSessionState {
            $session = $this->lock($sessionId);
            $status = PagerStatus::from($session->status);

            $this->refuseIfPastTtl($status, $session);

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

            $projected = [PagerStatus::Ready, (string) $session->order_reference, (string) $session->expires_at];

            return new PagerSessionState(
                $sessionId,
                PagerStatus::Ready,
                (string) $session->order_reference,
                $attempt,
                true,
            );
        });

        $this->project($tenantId, $sessionId, $projected);

        return $state;
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
        // Captured by reference from inside the scope and read AFTER it returns, which is
        // the whole point: withTenant() opens one transaction and rethrows on failure, so
        // a rolled-back transition never reaches the refresh below. A projection written
        // inside the transaction would survive a rollback -- Redis has no part in
        // Postgres's commit -- and would then publish a state that never happened.
        $projected = null;

        $state = withTenant($tenantId, function () use (
            $tenantId, $sessionId, $to, $action, $event, $actorUserId, $actorIp, $idempotentWhen, $columns, &$projected
        ): PagerSessionState {
            $session = $this->lock($sessionId);
            $from = PagerStatus::from($session->status);
            $reference = (string) $session->order_reference;
            $renotifies = (int) $session->renotify_count;

            // BEFORE the idempotency check, deliberately. A session past its TTL that is
            // already `ready` must not answer 200 "it is ready" to a retry -- the worker
            // is about to move it to `expired` and that answer would be a lie staff act
            // on. Concurrency is unaffected: two callers racing the same row see the same
            // TTL verdict, so the gate's "one outbox row, one notification" still holds,
            // with the count being zero.
            $this->refuseIfPastTtl($from, $session);

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

            $projected = [$to, $reference, (string) $session->expires_at];

            return new PagerSessionState($sessionId, $to, $reference, $renotifies, true);
        });

        $this->project($tenantId, $sessionId, $projected);

        return $state;
    }

    /**
     * Refresh the Redis projection for a transition that has already committed.
     *
     * BEST EFFORT, AND IT HAS TO BE. The status change, the outbox row and the audit row
     * are committed by the time this runs -- that is the gate's first invariant and it is
     * satisfied -- so a Redis failure here must not fail the staff request or, worse,
     * make a caller retry a transition that already happened. The cost of a miss is
     * bounded and defined: the customer's next poll reports no live status, their client
     * re-opens the session against Postgres, and the projection is rewritten from truth.
     * That is the same path a cold Redis takes, which is why it needs no second mechanism.
     *
     * $projected is null when the transition wrote nothing -- the idempotent case, where
     * the session was already in the state the caller asked for. Nothing changed, so
     * there is nothing to publish, and republishing would move the projection's `as_of`
     * forward for an event that did not occur.
     *
     * NOTHING IS DONE FOR `expired`, and that is not an omission: a projection's TTL is
     * never longer than the session's own remaining life (PagerProjection::ttlFor), so by
     * the moment a session is past its TTL both of its Redis keys are already gone. A
     * poll then answers Stale, the client re-opens, and Postgres says `409 Expired` --
     * which is the one path that can tell expired from invalid. That is why
     * ExpirePagerSessionsCommand needs no projection write and its statement shape, which
     * §4 › Postgres traps prescribes clause by clause, is left exactly as Task 3 proved it.
     *
     * @param  array{PagerStatus, string, string}|null  $projected  status, reference, expires_at
     */
    private function project(string $tenantId, string $sessionId, ?array $projected): void
    {
        if ($projected === null) {
            return;
        }

        [$status, $reference, $expiresAt] = $projected;

        $this->projection->refresh(
            $tenantId,
            $sessionId,
            $status,
            $reference,
            new DateTimeImmutable($expiresAt),
            new DateTimeImmutable,
        );
    }

    /**
     * Refuse a staff transition on a session whose TTL has passed.
     *
     * THE TTL HAD NO ENFORCEMENT POINT AT ALL until this method existed, which is the
     * defect QA H-1 and security S-2 both found from opposite ends. `expires_at` was
     * selected by lock() and then never read; ResolvedPagerSession::hasExpired() had no
     * production caller; and the expiry job that would have moved `status` is registered
     * and scheduled nowhere (R-058). So PROJECT_INSTRUCTIONS.md §4 › Data's "TTL 2 hours"
     * was enforced by nothing, and a leaked QR token was in practice a bearer credential
     * with an unbounded life.
     *
     * THE VERDICT COMES FROM POSTGRES, not from PHP. lock() selects `expires_at <= now()`
     * as a column, so the comparison uses the same clock as the worker's policies and the
     * `expires_at` default -- there is no skew between the role that writes the TTL, the
     * role that enforces it and the role that expires it.
     *
     * ONLY A LIVE STATUS, which is not a softening. `expires_at` recedes into the past for
     * every session that ever existed, so an unconditional check would refuse `complete`
     * on an order collected last week and break the idempotency the gate requires. A
     * terminal session is finished, not expired. isLive() is the same test the expiry
     * job's policy whitelist uses, so the two cannot drift.
     *
     * The customer half is deliberately NOT here: PagerTokenLookup resolves an expired
     * session on purpose, so the PWA can tell "expired" from "invalid" (§4.1, Task 9's
     * gate). Task 4 owns the route in front of it and ResolvedPagerSession::hasExpired()
     * is what it calls; that obligation is written into R-058 rather than left implied.
     *
     * @param  object{status: string, has_expired: bool}  $session
     */
    private function refuseIfPastTtl(PagerStatus $from, object $session): void
    {
        if ($from->isLive() && $session->has_expired) {
            throw PagerSessionExpired::pastTtl($from);
        }
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
     * @return object{id: string, status: string, order_reference: string, renotify_count: int, expires_at: string, has_expired: bool}
     */
    private function lock(string $sessionId): object
    {
        $row = DB::selectOne(
            'select id, status, order_reference, renotify_count, expires_at,
                    expires_at <= now() as has_expired
               from pager_sessions
              where id = ?
              for update',
            [$sessionId],
        );

        if (! is_object($row)) {
            throw PagerSessionNotFound::create();
        }

        /** @var object{id: string, status: string, order_reference: string, renotify_count: int, expires_at: string, has_expired: bool} $row */
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
     * The sessions a staff member or a kitchen screen needs to see: everything not yet
     * finished, newest first.
     *
     * ADDED AT TASK 5, and the reason is the device token rather than the board. A KDS
     * device token's one ability is `pager:read`, and until this existed there was nothing
     * for it to read -- which would have made "device token scope cannot escalate" a claim
     * about a credential that can reach nothing, and a credential that can reach nothing
     * cannot be shown not to escalate. Task 10 builds the screen; this is the data behind it.
     *
     * Bounded by `expiry_batch`'s sibling rather than unbounded: a board with no limit is a
     * transaction whose size is set by how busy a restaurant is. The partial index
     * `(tenant_id, status, created_at DESC)` from §2.5 is what serves it.
     *
     * @return list<array{id: string, order_reference: string, status: string, created_at: string, ready_at: string|null}>
     */
    public function board(string $tenantId, int $limit = 100): array
    {
        $rows = withTenant($tenantId, fn () => DB::select(
            'select id, order_reference, status, created_at, ready_at
               from pager_sessions
              where status in (?::pager_status, ?::pager_status)
              order by created_at desc
              limit ?',
            [PagerStatus::Waiting->value, PagerStatus::Ready->value, $limit],
        ));

        $board = [];

        foreach ($rows as $row) {
            // isset-guarded rather than mapped blind: DB::select() answers a list of plain
            // objects, so a column renamed underneath this method would otherwise be a
            // property error at runtime in a loop. Same shape as Memberships::for().
            if (! is_object($row) || ! isset($row->id, $row->order_reference, $row->status, $row->created_at)) {
                continue;
            }

            $board[] = [
                'id' => (string) $row->id,
                'order_reference' => (string) $row->order_reference,
                'status' => (string) $row->status,
                'created_at' => (string) $row->created_at,
                'ready_at' => isset($row->ready_at) ? (string) $row->ready_at : null,
            ];
        }

        return $board;
    }

    /**
     * The restaurant's PAST orders, filtered. Task 10d (`QRPAGER-28`).
     *
     * ================================================================================
     * HOW THIS DIFFERS FROM `board()`, WHICH IS NOT ONLY THE STATUS PREDICATE
     * ================================================================================
     *
     * `board()` is the live queue: two statuses, no input, every row. This is the record: any
     * status, four filter dimensions, and a bound on how much of it one question may ask for.
     * They are two reads with two audiences, which is also why they require two different
     * abilities — `Ability::PagerHistory`'s docblock has the measurement, and the short version
     * is that `pager:read` is the one ability a KITCHEN SCREEN may hold.
     *
     * ================================================================================
     * EVERY FILTER NARROWS INSIDE A SCOPE THAT IS ALREADY OPEN
     * ================================================================================
     *
     * `withTenant()` wraps the whole statement, so row-level security has made another
     * restaurant's rows invisible before a single filter is applied. That is why 10d's
     * invariant expects *the empty set* rather than a refusal: there is nothing to refuse.
     *
     * ⚠ AND IT IS ASSERTED BY ENUMERATION RATHER THAN BY THAT ARGUMENT, because
     * `QRPAGER-28 §0` says *"not by reading the query builder"* — an argument of exactly this
     * shape is how a dropped scope survives review. `PagerHistoryIsolationTest` derives the
     * cartesian product of `PagerHistoryFilters::DIMENSIONS` and drives every combination
     * across the boundary in both directions.
     *
     * ⚠ **NO RAW FRAGMENT AND NO SECOND CONNECTION, which is the one way a filter COULD
     * escape the scope.** Every predicate below is a bound parameter on the `pgsql` connection
     * inside the scope. The `reference` filter is the one to look at twice: it is a `LIKE`,
     * and the needle is wrapped in `%` **here** rather than coming from the caller, so a value
     * containing `%` or `_` cannot widen the pattern. `PagerHistoryRequest`'s regex also keeps
     * both characters out, so that is two independent bounds rather than one.
     *
     * @return list<array{id: string, order_reference: string, status: string, created_at: string, ready_at: string|null, completed_at: string|null}>
     */
    public function history(string $tenantId, PagerHistoryFilters $filters): array
    {
        $where = [];
        $bindings = [];

        if ($filters->hasStatuses()) {
            // One placeholder per status, each cast — an `IN` over a bound array is not
            // something Postgres accepts, and `ANY(?)` would need an array literal built as a
            // string, which is a fragment this method deliberately does not build.
            $placeholders = implode(', ', array_fill(0, count($filters->statuses), '?::pager_status'));
            $where[] = "status in ({$placeholders})";
            $bindings = [...$bindings, ...$filters->statusValues()];
        }

        if ($filters->from !== null) {
            $where[] = 'created_at >= ?';
            $bindings[] = $filters->from->toDateTimeString();
        }

        if ($filters->to !== null) {
            /*
             * ⚠ `< to + 1 day`, NOT `<= to`, AND THIS IS THE OFF-BY-ONE THAT MATTERS.
             *
             * A person filtering *"the 3rd to the 5th"* means the whole of the 5th.
             * `created_at <= '2026-11-05'` compares against MIDNIGHT at the start of that day,
             * so it silently drops every order the restaurant took on the 5th — the busiest
             * end of the range, and a loss nothing on the screen would reveal.
             * `PagerHistoryFilters::day()` normalises both ends to a day's start, and this
             * makes the upper end inclusive.
             */
            $where[] = 'created_at < ?';
            $bindings[] = $filters->to->addDay()->toDateTimeString();
        }

        if ($filters->hasReference()) {
            // ILIKE: a person typing `a-101` is looking for `A-101`. The wildcards are added
            // HERE and the needle is bound, so the caller cannot supply a pattern.
            $where[] = 'order_reference ilike ?';
            $bindings[] = '%'.$filters->reference.'%';
        }

        $predicate = $where === [] ? '' : ' and '.implode(' and ', $where);
        $bindings[] = PagerHistoryFilters::PAGE_SIZE;

        $rows = withTenant($tenantId, fn () => DB::select(
            'select id, order_reference, status, created_at, ready_at, completed_at
               from pager_sessions
              where true'.$predicate.'
              order by created_at desc
              limit ?',
            $bindings,
        ));

        $history = [];

        foreach ($rows as $row) {
            // isset-guarded rather than mapped blind, which is `board()`'s own rule:
            // `DB::select()` answers plain objects, so a renamed column would otherwise be a
            // property error at run time inside a loop.
            if (! is_object($row) || ! isset($row->id, $row->order_reference, $row->status, $row->created_at)) {
                continue;
            }

            $history[] = [
                'id' => (string) $row->id,
                'order_reference' => (string) $row->order_reference,
                'status' => (string) $row->status,
                'created_at' => (string) $row->created_at,
                'ready_at' => isset($row->ready_at) ? (string) $row->ready_at : null,
                'completed_at' => isset($row->completed_at) ? (string) $row->completed_at : null,
            ];
        }

        return $history;
    }

    /**
     * Re-issue a sheet: the SAME order, a NEW code, and the old code stops working.
     *
     * ================================================================================
     * MUHAMMAD's RULING, `QRPAGER-28 §2`: *A SHEET IS RE-ISSUED, NEVER REPRINTED*
     * ================================================================================
     *
     * *"The QR token is 128-bit CSPRNG stored as a hash only (`§4 › Data`), so the raw value
     * exists once, at generation, and can never be re-rendered from the database. Reprint is
     * not a feature being declined — it is physically unavailable, and `§4` forbids making it
     * available."*
     *
     * So the only thing that can be done for a staff member holding a lost or ruined sheet is
     * to give that order a new code. This method is that, and it is deliberately NOT any of
     * the three shapes it could have been:
     *
     *   **NOT cancel-then-create.** Cancelling NOTIFIES — that is the whole point of cancel,
     *   and `cancel()`'s own docblock says why (*"a pager that will never buzz is worse than
     *   news"*). A customer whose sheet was reprinted at the counter has not been cancelled on
     *   and must not be told they have. It would also break the order's identity in the
     *   history: two rows for one order, one of them a lie.
     *
     *   **NOT a new session for the same reference.** Same objection, without the false
     *   notification: the history screen would show two orders where the restaurant had one,
     *   and 10d's own `§3` bar is that no screen shows an unexplained thing.
     *
     *   **NOT a status change.** `PagerTokenLookup` resolves a presented token and REPORTS the
     *   status; a terminal status does not make a token stop resolving, it makes it resolve to
     *   *cancelled*. The old sheet has to become un-resolvable, which only a new hash achieves.
     *
     * **It is a TOKEN ROTATION on the existing row.** One session, one order, one history
     * entry, a new 128-bit secret, and the old one is gone because the column it was compared
     * against no longer holds its hash. Nothing is notified, because nothing has happened to
     * the customer's order — only to the piece of paper.
     *
     * ================================================================================
     * WHAT IT REFUSES, AND WHY A TERMINAL SESSION IS ONE OF THEM
     * ================================================================================
     *
     * A session past its TTL or in a terminal status is refused with the same two answers the
     * transitions give (`expired`, `conflict`), for the same reason: re-issuing a code for an
     * order that is finished, cancelled or expired would print a sheet that buzzes nobody. The
     * remedy there is a NEW pager, which is the create screen, and the copy says so.
     *
     * ⚠ **AND IT TAKES THE ROW LOCK.** Two staff members re-issuing the same sheet at the same
     * instant must produce ONE surviving code rather than two sheets whose holder cannot know
     * which works — the same `FOR UPDATE` serialisation `apply()` uses, and
     * `PagerReissueTest` drives two concurrent callers over HTTP against it.
     */
    public function reissue(
        string $tenantId,
        string $sessionId,
        ?string $actorUserId = null,
        ?string $actorIp = null,
    ): IssuedPagerSession {
        $token = PagerToken::issue();

        return withTenant($tenantId, function () use ($tenantId, $sessionId, $token, $actorUserId, $actorIp): IssuedPagerSession {
            return DB::transaction(function () use ($tenantId, $sessionId, $token, $actorUserId, $actorIp): IssuedPagerSession {
                // Locked first, so the status read below and the rotation are one serialised
                // act. `lock()` answers 404 for a row this tenant cannot see, which is where
                // the cross-tenant refusal comes from.
                $session = $this->lock($sessionId);

                $from = PagerStatus::from((string) $session->status);

                // The TTL check is `refuseIfPastTtl`'s, shared with every transition so the
                // two cannot disagree about what expired means -- computed from `expires_at`
                // against the clock rather than from `status`, which lags (`R-058`).
                $this->refuseIfPastTtl($from, $session);

                if ($from->isTerminal()) {
                    throw PagerTransitionDenied::cannotReissue($from);
                }

                DB::update(
                    'update pager_sessions
                        set token_hash = decode(?, \'hex\'), updated_at = now()
                      where id = ?',
                    [$token->hashHex(), $sessionId],
                );

                $this->audit($tenantId, $sessionId, PagerAuditAction::Reissued, $actorUserId, $actorIp, [
                    // The status is recorded because it is the thing that made the re-issue
                    // legal. NEVER the token and never its hash -- `audit()`'s own rule.
                    'status' => $from->value,
                ]);

                return new IssuedPagerSession(
                    new PagerSessionState(
                        $sessionId,
                        $from,
                        (string) $session->order_reference,
                        isset($session->renotify_count) ? (int) $session->renotify_count : 0,
                        true,
                    ),
                    $token,
                );
            });
        });
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
