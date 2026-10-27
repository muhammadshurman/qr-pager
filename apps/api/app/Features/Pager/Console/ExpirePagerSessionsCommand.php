<?php

declare(strict_types=1);

namespace App\Features\Pager\Console;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerStatus;
use App\Platform\Database\DatabaseRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expire sessions whose TTL has passed. The fourth of §4's named cross-tenant paths.
 *
 * THE STATEMENT PATTERN IS PRESCRIBED, not chosen, and the gate names it clause by clause:
 * "expiry job uses a policy-limited SELECT of id, tenant_id and status -> UPDATE ...
 * WHERE id = ? AND status = ? with no RETURNING, and never a bulk statement".
 * PROJECT_INSTRUCTIONS.md §4 › Postgres traps is where that comes from and §2.12 is the
 * measurement behind it.
 *
 * BOTH CLAUSES WERE AMENDED ON 2026-10-27 and this code did not change. They said
 * "SELECT id", QA L-2 read that against this file and found them disagreeing (PLAN.md
 * R-065), and the user ruled that the wording was wrong rather than the code: the trap's
 * point was never the column count -- it is SELECT-then-keyed-UPDATE instead of a bulk
 * UPDATE that references no column and so escapes RLS row filtering entirely. The §4 edit
 * is rank 2 and is marked as such in that file. Three properties of it matter and each is a line
 * below:
 *
 *   1. THE POLICY-LIMITED KEYED READ is bounded by the worker's own cross-tenant SELECT
 *      policy -- this line said "SELECT id is bounded by..." until 2026-10-27, inside the
 *      very docblock the amendment round edited to announce the change, which is R-069.
 *      The bound is the POLICY and is true whatever the select list; the list itself is
 *      `id, tenant_id, status`, each column load-bearing for the reasons above. As of
 *      Task 3 is `expires_at <= now() AND status IN ('waiting','ready','expired')` --
 *      time AND a status whitelist (R-003). So the rows this job can even see are live
 *      sessions past their TTL, plus already-expired ones, and nothing else.
 *   2. The UPDATE names the KEY and the expected status. Never a bulk statement: a bulk
 *      UPDATE that references no column of the table escapes RLS row filtering entirely
 *      and writes rows the role cannot read -- measured, and the trap §4 spells out. This
 *      one references columns, so both policy passes apply.
 *   3. NO `RETURNING`. It is a convention rather than the mechanism (§2.12 corrected that
 *      claim), and it is kept because it costs nothing, keeps the worker's read surface
 *      narrow, and preserves the AFFECTED-ROW COUNT as the idempotency signal.
 *
 * WHY THE AFFECTED-ROW COUNT IS THE WHOLE OF THE CRASH STORY. Each row is its own keyed
 * UPDATE with `status = ?` in the WHERE, so a second attempt on a row already expired
 * reports 0 and writes nothing. Kill the job mid-pass and the rows it committed are
 * expired, the rest keep their status, and the next pass finds them: nothing lost,
 * nothing double-expired, no state to reconcile.
 *
 * IT RUNS AS THE WORKER, which is not interchangeable with the app role. The app role has
 * no UPDATE grant on `expired_at` at all (§2.7) and no cross-tenant policy anywhere, so
 * this job cannot be run on the default connection -- it would see zero rows and write
 * nothing. The connection is named explicitly for that reason.
 *
 * NO OUTBOX ROW, and that is settled by the schema rather than by taste: §2.7 gives
 * qrpager_worker no INSERT grant on `outbox`, so an expiry notification is not something
 * this job could write if somebody decided it should. The audit row it CAN write, and
 * does -- `audit_log` is INSERT for both runtime roles.
 *
 * In App\Features, not App\Platform\Console: deptrac forbids Platform from importing
 * Features, and this is feature logic that happens to have a console entry point.
 */
final class ExpirePagerSessionsCommand extends Command
{
    protected $signature = 'pager:expire {--limit= : how many sessions this pass may claim}';

    protected $description = 'Move pager sessions past their TTL to expired';

    public function handle(): int
    {
        $limit = $this->resolveLimit();
        $connection = DatabaseRoles::connection('worker');
        $worker = DB::connection($connection);

        // (1) The policy-limited SELECT, with NO tenant context -- it has to span tenants,
        // which is the whole reason this is one of §4's named cross-tenant paths. The
        // status filter here is not the security boundary (the policy is); it keeps the
        // pass from re-reading rows it has already expired, which the policy deliberately
        // still shows it. ORDER BY expires_at so a capped pass makes progress oldest-first
        // rather than arbitrarily, and the partial index on (expires_at) WHERE status IN
        // ('waiting','ready') is exactly this query's index.
        //
        // `id, tenant_id, status`, which is now what the gate ASKS FOR rather than a
        // reading of it. Until 2026-10-27 the clause said "SELECT id" and this comment
        // argued the difference away; QA L-2 found that, and the user amended the clause
        // (R-065). The mechanism is a policy-limited read to find the KEYS, as opposed to
        // a bulk UPDATE -- and the keyed UPDATE below needs the status it expects to find
        // (that guard IS the idempotency signal) and the tenant to open a scope for the
        // audit row, because audit_log carries the same isolation policy and the insert
        // is refused with 42501 outside one. Both columns are already readable by this
        // role: `status` is in the worker's SELECT grant and in its policy whitelist, and
        // `tenant_id` likewise, so the select list costs no reach; guessing the status
        // instead would mean a WHERE that cannot be written.
        // THE LIVE-STATUS LIST COMES FROM THE ENUM (QA M-2). It was the SQL literal
        // `('waiting', 'ready')` until 2026-10-27, while PagerStatus's own docblock claimed
        // "the service, the expiry job and the tests all read allows()" -- so the set lived
        // in the enum, in this string, and in the two worker policies, and only two of the
        // three were pinned by anything. liveSqlList() derives it from allows(), and
        // PagerExpiryTest asserts the policy whitelist and this list are the same set.
        $live = PagerStatus::liveSqlList();

        $due = $worker->select(
            "select id, tenant_id, status
               from pager_sessions
              where status in ({$live})
                and expires_at <= now()
              order by expires_at
              limit {$limit}",
        );

        $expired = 0;

        foreach ($due as $row) {
            if (! is_object($row) || ! isset($row->id, $row->tenant_id, $row->status)) {
                continue;
            }

            $expired += $this->expire(
                $connection,
                (string) $row->id,
                (string) $row->tenant_id,
                (string) $row->status,
            );
        }

        $this->info("pager:expire — {$expired} of ".count($due).' due session(s) expired.');

        return self::SUCCESS;
    }

    /**
     * One session: the status change and its audit row, in one transaction. Returns 1 or 0.
     *
     * A TENANT SCOPE PER SESSION, and the audit row is the reason it is not optional.
     * `audit_log` carries `tenant_isolation_audit_log` for the worker like every other
     * tenant table, so with no context `ctx` is NULL, the INSERT's WITH CHECK is false and
     * the row is refused with 42501. The worker therefore CANNOT audit anything
     * cross-tenant -- so to write the audit row at all it has to enter the tenant it has
     * just read from the row. That also gives the gate's first clause its boundary here:
     * withTenant() opens one transaction, so the status change and the audit row commit
     * together or not at all.
     *
     * The cost is honest and bounded: one scope per expired session, 3 statements of
     * overhead and 2 transaction round trips each (§2.11's D11 figure), against a pass
     * capped by config. The alternative was a cross-tenant UPDATE with no audit row, which
     * is precisely what R-003 objected to about the old policies ("with no audit row").
     *
     * The UPDATE is still the prescribed shape inside the scope: keyed, status-guarded, no
     * RETURNING, affected-row count as the signal. The isolation policy permits it and the
     * worker's narrowed cross-tenant policy ORs in beside it, so neither is load-bearing
     * alone -- and the column grant still allows only (status, expired_at, updated_at).
     */
    private function expire(string $connection, string $id, string $tenantId, string $observed): int
    {
        return withTenant($tenantId, function () use ($connection, $id, $tenantId, $observed): int {
            $affected = DB::connection($connection)->update(
                'update pager_sessions
                    set status = ?::pager_status, expired_at = now(), updated_at = now()
                  where id = ? and status = ?::pager_status',
                [PagerStatus::Expired->value, $id, $observed],
            );

            // 0 means somebody else moved it between the select and the update -- a staff
            // member completing or cancelling it, or a concurrent pass. Nothing to audit,
            // because nothing happened. This is the re-runnability property: a second
            // attempt on an already-expired row is a 0, not an error and not a duplicate.
            if ($affected !== 1) {
                return 0;
            }

            // actor_type `system`, and this is the ONE place in the feature where that is
            // the honest value: no user asked for it. Every staff-initiated transition
            // writes `user` with a null actor_user_id until Task 5 fills it in.
            DB::connection($connection)->insert(
                'insert into audit_log
                    (tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context)
                 values (?, ?::audit_actor_type, null, ?, ?, ?, ?::jsonb)',
                [
                    $tenantId,
                    AuditActorType::System->value,
                    PagerAuditAction::Expired->value,
                    PagerAuditAction::SUBJECT,
                    $id,
                    json_encode(['from' => $observed, 'to' => PagerStatus::Expired->value], JSON_THROW_ON_ERROR),
                ],
            );

            return 1;
        }, $connection);
    }

    /**
     * The pass size: the option, else config, and never unbounded.
     *
     * An unbounded SELECT would hold one transaction across an arbitrary row count, and
     * the worker role carries idle_in_transaction_session_timeout = 60s (§2.1) -- so the
     * failure mode of "no limit" is a pass killed partway rather than a slow pass.
     * Interpolated into the statement rather than bound because it is an integer this
     * method produces, never caller text: LIMIT takes a bind parameter happily, but
     * casting to int here means the value is pinned at the one place it is decided.
     */
    private function resolveLimit(): int
    {
        $option = $this->option('limit');
        $configured = (int) config('pager.expiry_batch');

        $limit = is_numeric($option) ? (int) $option : $configured;

        return max(1, $limit);
    }
}
