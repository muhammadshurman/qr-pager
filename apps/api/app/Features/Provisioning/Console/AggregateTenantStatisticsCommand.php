<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Console;

use App\Domain\Pager\PagerStatus;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Domain\Provisioning\TenantAggregate;
use App\Platform\Database\DatabaseRoles;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Per-tenant statistics, as aggregates on a platform table.
 *
 * ================================================================================
 * THIS IS THE RULING'S SECOND CLAUSE, AND IT IS WHY THE PANEL NEEDS NO CROSS-TENANT READ
 * ================================================================================
 *
 * *"Per-tenant statistics are aggregates written to a platform table, never a read of a
 * tenant table. A counter or a timestamp is maintained by a job or the outbox worker, and
 * the panel reads only that platform row."* (`QRPAGER-13 §0`, rank 1.)
 *
 * `§0`'s *Blocking anything?* row had said since 2026-10-26 that Task 6 would need either a
 * **fifth named cross-tenant path** — a rank-2 edit to `§4 › Tenancy` — or a different
 * dashboard design. **This is the different design, and it needs no new path at all**, which
 * is the single most important property of this file:
 *
 *   1. The tenant list comes from `tenant_provisionings`, a **platform** table with no RLS.
 *      So enumerating tenants is not a read of `tenants` and needs no context and no bypass.
 *   2. Each tenant's rows are then read **inside that tenant's own context**, one tenant at
 *      a time, through `withTenant()`. That is an ORDINARY SCOPED READ — the same thing any
 *      request does — and not a cross-tenant one. At no instant is a statement issued that
 *      could see two tenants' rows.
 *   3. The aggregate is written to `tenant_statistics`, a platform table, outside the context.
 *
 * `RlsCoverageTest::it('has exactly the four named cross-tenant policies and no others')` is
 * unchanged by this task, which is the mechanical statement of the same thing.
 *
 * WHY THE WORKER ROLE. It already holds `SELECT` on exactly the `pager_sessions` columns
 * this needs — `status`, `created_at` and `ready_at` are all in its Task 1 column grant
 * (`§2.7`) — and `SELECT` on `tenant_users`. **No grant on any tenant table is widened by
 * this task.** The worker is also the role the contract points at background work, and the
 * panel deliberately holds no INSERT or UPDATE on `tenant_statistics` at all, which
 * `GrantMatrixTest` pins: "the job is the only writer" is a grant here, not a convention.
 *
 * IDEMPOTENT, which the prompt requires by name. The write is `INSERT ... ON CONFLICT (id)
 * DO UPDATE`, so a second pass leaves one row with the same values rather than a second row
 * or a doubled counter. A `pg_advisory_xact_lock` on the tenant id serializes two concurrent
 * runners — the mechanism `§4 › Postgres traps` already prescribes for the role-bootstrap
 * race, used here for the same reason: two passes interleaving would otherwise write a
 * mixture of two windows.
 *
 * NO ROW-LEVEL DETAIL LEAVES THE TENANT. Every value written is a `count`, a
 * `percentile_cont` or a timestamp. No order reference, no token, no session id — the
 * ruling's words, and `PlatformTableContractTest` enforces them against the column set so
 * this docblock is not the only thing holding them.
 */
final class AggregateTenantStatisticsCommand extends Command
{
    /**
     * Below this many ready sessions, the wait percentiles and the window bounds are not
     * written at all.
     *
     * ⚠ SECURITY L-4, AND IT IS THE RULING RATHER THAN A PREFERENCE. "An aggregate carries
     * no row-level detail" — and `percentile_cont(0.5)` over ONE ready session is that
     * customer's exact wait, while `min`/`max(created_at)` are single-row timestamps by
     * definition. The ruling's own enumeration names identifiers (order reference, token,
     * session id) and `PlatformTableContractTest` enforces exactly that list, so nothing
     * caught a figure that IS a row without being called one.
     *
     * Five, because the dashboard's purpose is a sense of how a restaurant is doing and a
     * median over four orders tells an admin nothing they should act on anyway — so the
     * threshold costs no real information. A tenant below it still gets its COUNTS, which
     * are aggregates at any size.
     */
    public const WAIT_SUPPRESSION_THRESHOLD = 5;

    protected $signature = 'platform:aggregate-stats
                            {--limit=500 : How many tenants to aggregate in one pass}
                            {--tenant= : Aggregate one tenant only, by id}';

    protected $description = 'Recompute the per-tenant aggregates the platform dashboard reads';

    public function handle(): int
    {
        $workerConnection = DatabaseRoles::connection('worker');
        $worker = DB::connection($workerConnection);

        // The registry is a PLATFORM table, so enumerating it needs no context and no
        // cross-tenant anything. Read on the worker, which holds three columns of it.
        $tenants = $this->tenantIds($worker);

        $aggregated = 0;
        $failed = 0;

        foreach ($tenants as $tenantId) {
            // PER-TENANT ISOLATION OF FAILURE (security L-3). Without this, one tenant that
            // throws aborts the whole pass: every tenant later in the ordering is never
            // aggregated, while the dashboard still reports the freshest row's timestamp --
            // a silently partial pass. The drain command has had this shape since it was
            // written; this one did not.
            try {
                $this->aggregate($worker, $tenantId);
                $aggregated++;
            } catch (Throwable) {
                // No message logged: a driver message carries the SQL and the bound
                // parameters (§4 › Edge & ops). The count is what an operator needs, and a
                // tenant that keeps failing surfaces as a stale `computed_at`.
                $failed++;
            }
        }

        $this->info(sprintf(
            'platform:aggregate-stats - %d of %d tenant(s) aggregated, %d failed.',
            $aggregated,
            count($tenants),
            $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * The tenants to aggregate, from the PLATFORM registry rather than from `tenants`.
     *
     * @return list<string>
     */
    private function tenantIds(ConnectionInterface $worker): array
    {
        $one = $this->option('tenant');

        if (is_string($one) && $one !== '') {
            // Still checked against the registry, so an id that was never provisioned is
            // not aggregated into existence by a typo on the command line.
            $row = $worker->selectOne(
                'select id from tenant_provisionings where id = ? and status = ?::provisioning_status',
                [$one, ProvisioningStatus::Completed->value],
            );

            return $row === null ? [] : [(string) $row->id];
        }

        $limit = $this->boundedLimit();

        // ⚠ ORDERED BY STALENESS, NOT BY ID (QA M-4). `order by id limit <n>` with no
        // offset or cursor recomputes the same first N every five minutes, so above the cap
        // every remaining tenant NEVER gets a `tenant_statistics` row and reads "No data
        // yet" for ever. `computed_at asc nulls first` turns the cap into a rate limit
        // instead of a cliff: a tenant never aggregated goes first, then the stalest.
        //
        // The join is to a PLATFORM table, so this is still one unscoped statement over
        // platform data and reaches no tenant table.
        $rows = $worker->select(
            'select p.id
               from tenant_provisionings p
               left join tenant_statistics s on s.id = p.id
              where p.status = ?::provisioning_status
              order by s.computed_at asc nulls first, p.id
              limit '.$limit,
            [ProvisioningStatus::Completed->value],
        );

        return array_values(array_map(
            static fn (object $r): string => (string) $r->id,
            array_filter($rows, static fn (mixed $r): bool => is_object($r) && isset($r->id)),
        ));
    }

    /** One tenant: read as the app role inside its own scope, write as the worker outside it. */
    private function aggregate(ConnectionInterface $worker, string $tenantId): void
    {
        // Read in the tenant's own context, ON THE APP CONNECTION. One transaction, one
        // tenant, and the context is transaction-scoped (C2) so it cannot outlive this call
        // on a pooled connection. The app role carries no cross-tenant policy anywhere,
        // which is the bound the worker connection did not have (security H-1).
        $aggregate = withTenant($tenantId, fn (): TenantAggregate => $this->read(DB::connection(), $tenantId));

        // Write OUTSIDE the tenant context, on a platform table. An advisory lock keyed on
        // the tenant id, so two concurrent passes do not interleave two windows into one row.
        $worker->transaction(function () use ($worker, $tenantId, $aggregate): void {
            $worker->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenantId]);

            $worker->insert(
                'insert into tenant_statistics
                    (id, sessions_total, sessions_waiting, sessions_ready, sessions_completed,
                     sessions_cancelled, sessions_expired, staff_count,
                     median_wait_seconds, p95_wait_seconds, window_start, window_end,
                     computed_at, created_at, updated_at)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now(), now())
                 on conflict (id) do update set
                     sessions_total      = excluded.sessions_total,
                     sessions_waiting    = excluded.sessions_waiting,
                     sessions_ready      = excluded.sessions_ready,
                     sessions_completed  = excluded.sessions_completed,
                     sessions_cancelled  = excluded.sessions_cancelled,
                     sessions_expired    = excluded.sessions_expired,
                     staff_count         = excluded.staff_count,
                     median_wait_seconds = excluded.median_wait_seconds,
                     p95_wait_seconds    = excluded.p95_wait_seconds,
                     window_start        = excluded.window_start,
                     window_end          = excluded.window_end,
                     computed_at         = now(),
                     updated_at          = now()',
                [
                    $tenantId,
                    $aggregate->sessionsTotal,
                    $aggregate->sessions(PagerStatus::Waiting->value),
                    $aggregate->sessions(PagerStatus::Ready->value),
                    $aggregate->sessions(PagerStatus::Completed->value),
                    $aggregate->sessions(PagerStatus::Cancelled->value),
                    $aggregate->sessions(PagerStatus::Expired->value),
                    $aggregate->staffCount,
                    $aggregate->medianWaitSeconds,
                    $aggregate->p95WaitSeconds,
                    $aggregate->windowStart,
                    $aggregate->windowEnd,
                ],
            );
        });
    }

    /**
     * The aggregate query, inside the tenant's scope. Returns a type, not a row.
     *
     * ONE STATEMENT PER TABLE AND BOTH ARE AGGREGATES. Nothing selected here is row-level:
     * `count`, `percentile_cont`, `min` and `max`, and the two window bounds are of
     * `created_at` rather than of any identifier.
     */
    private function read(ConnectionInterface $connection, string $tenantId): TenantAggregate
    {
        // The per-status counters are derived from PagerStatus rather than written out, so
        // a sixth status cannot leave a counter silently at zero. The migration snapshots
        // the same set of columns, and PlatformTableContractTest asserts the two match.
        $counters = implode(",\n                   ", array_map(
            // THE PREMISE IS CHECKED RATHER THAN DOCUMENTED, which is PagerStatus' own rule
            // for the same interpolation: a case value is safe to put in SQL only while it
            // IS a bare identifier, and a docblock is not a control. The alias
            // `sessions_<value>` is a column name, so it could not be a bound parameter
            // even if the literal could. SQL_SAFE_VALUE is the pattern liveSqlList() uses,
            // and EnumContractTest pins the property from the other side, so an unsafe case
            // fails the suite before it can reach this throw.
            static function (PagerStatus $status): string {
                if (preg_match(PagerStatus::SQL_SAFE_VALUE, $status->value) !== 1) {
                    throw new LogicException(
                        'PagerStatus::'.$status->name.' has the value ['.$status->value.'], which is not a bare '
                        .'identifier, so it can be neither interpolated as a literal nor used as a column alias '
                        .'here. Keep enum values to [a-z_]: a quoted case value is injection from inside our own '
                        .'code, and an alias cannot be bound at all.'
                    );
                }

                return sprintf(
                    "count(*) filter (where status = '%s') as sessions_%s",
                    $status->value,
                    $status->value,
                );
            },
            PagerStatus::cases(),
        ));

        // `where tenant_id = ?` EXPLICITLY, as well as running on a role that holds no
        // cross-tenant policy. Two bounds, and only one of them lives in this file.
        $row = $connection->selectOne(
            "select count(*) as sessions_total,
                   {$counters},
                   percentile_cont(0.5) within group (
                       order by extract(epoch from (ready_at - created_at))
                   ) filter (where ready_at is not null) as median_wait_seconds,
                   percentile_cont(0.95) within group (
                       order by extract(epoch from (ready_at - created_at))
                   ) filter (where ready_at is not null) as p95_wait_seconds,
                   min(created_at) as window_start,
                   max(created_at) as window_end
              from pager_sessions
             where tenant_id = ?",
            [$tenantId],
        );

        $staff = $connection->selectOne(
            'select count(*) as staff_count from tenant_users where tenant_id = ?',
            [$tenantId],
        );

        $byStatus = [];

        foreach (PagerStatus::cases() as $status) {
            $byStatus[$status->value] = $this->int($row, 'sessions_'.$status->value) ?? 0;
        }

        // The number of sessions with a wait at all is what the percentiles are computed
        // over, so it is what the threshold has to be measured against -- not the total.
        // No `?? 0`: the loop above writes a key for every PagerStatus case, so PHPStan
        // knows both offsets exist — a null coalesce here would be dead code pretending to
        // be a guard, which is the shape this task has already corrected three times.
        $readySessions = $byStatus[PagerStatus::Ready->value] + $byStatus[PagerStatus::Completed->value];

        $suppress = $readySessions < self::WAIT_SUPPRESSION_THRESHOLD;

        return new TenantAggregate(
            sessionsTotal: $this->int($row, 'sessions_total') ?? 0,
            sessionsByStatus: $byStatus,
            staffCount: $this->int($staff, 'staff_count') ?? 0,
            // Whole seconds. A fractional percentile is rounded rather than truncated, and
            // null stays null: a tenant with no ready session has NO median, which is a
            // different fact from a median of zero.
            // SUPPRESSED BELOW THE THRESHOLD, which is the ruling applied to a figure that
            // would otherwise BE a row rather than merely describe one.
            medianWaitSeconds: $suppress ? null : $this->seconds($row, 'median_wait_seconds'),
            p95WaitSeconds: $suppress ? null : $this->seconds($row, 'p95_wait_seconds'),
            windowStart: $suppress ? null : $this->text($row, 'window_start'),
            windowEnd: $suppress ? null : $this->text($row, 'window_end'),
        );
    }

    /**
     * One numeric column, or null when the row or the column is absent.
     *
     * Three narrow readers rather than casts on an untyped row: PHPStan cannot check a
     * dynamic property, and a cast would only have silenced it. `selectOne` returns null on
     * an empty result, which for an aggregate query means the statement failed rather than
     * that there were no rows -- so absent is reported as null and defaulted by the caller,
     * never read as zero by accident.
     */
    private function int(mixed $row, string $column): ?int
    {
        $value = $this->raw($row, $column);

        return is_numeric($value) ? (int) $value : null;
    }

    private function seconds(mixed $row, string $column): ?int
    {
        $value = $this->raw($row, $column);

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function text(mixed $row, string $column): ?string
    {
        $value = $this->raw($row, $column);

        return is_scalar($value) ? (string) $value : null;
    }

    private function raw(mixed $row, string $column): mixed
    {
        if (! is_object($row)) {
            return null;
        }

        return ((array) $row)[$column] ?? null;
    }

    private function boundedLimit(): int
    {
        $raw = $this->option('limit');
        $limit = is_numeric($raw) ? (int) $raw : 500;

        return max(1, min(5000, $limit));
    }
}
