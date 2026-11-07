<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel;

use App\Domain\Provisioning\ProvisioningStatus;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's reads. PLATFORM TABLES ONLY — this is the named invariant's main surface.
 *
 * TWO STATEMENTS, both unscoped, both on tables belonging to no tenant:
 *
 *   1. a grouped count over `tenant_provisionings` — the registry
 *   2. a sum over `tenant_statistics` — the aggregates a job wrote from inside each tenant
 *
 * THERE IS NO THIRD. The figures a platform admin wants that would need a tenant table —
 * which orders are live right now, which session is waiting longest — are deliberately not
 * here and are not obtainable from here: `§4`'s ruling is that an aggregate carries no
 * row-level detail, and the panel reads only the platform row. What the admin gets is "this
 * restaurant exists, it has had this many sessions, its median wait is this" and nothing
 * about any individual order, which is the ruling's "administer it from outside, and cannot
 * see inside it".
 *
 * `computed_at` IS RENDERED, and that is not decoration. The aggregates are refreshed every
 * five minutes by `platform:aggregate-stats`, so a figure here can be up to five minutes
 * old; a staleness that is not shown is a staleness a reader will assume is zero.
 */
final class PlatformDashboard
{
    public function summary(): PlatformSummary
    {
        // One grouped statement rather than four counts: the panel's query count is part of
        // what the invariant test measures, and four round trips for one tile row is the
        // N+1 shape §11 asks about.
        $byStatus = [];

        foreach (DB::select(
            'select status, count(*) as total from tenant_provisionings group by status'
        ) as $row) {
            $byStatus[(string) $row->status] = (int) $row->total;
        }

        // coalesce, so an empty table reads 0 rather than null -- a platform with no
        // restaurants yet is the panel's first-run state and it must render.
        $totals = DB::selectOne(
            'select coalesce(sum(sessions_total), 0) as sessions_total,
                    coalesce(sum(staff_count), 0)    as staff_total,
                    max(computed_at)                 as computed_at,
                    percentile_cont(0.5) within group (order by median_wait_seconds)
                        filter (where median_wait_seconds is not null) as median_wait_seconds
               from tenant_statistics'
        );

        return new PlatformSummary(
            restaurantsTotal: array_sum($byStatus),
            restaurantsActive: $byStatus[ProvisioningStatus::Completed->value] ?? 0,
            // `pending` and `running` together: an admin watching a restaurant appear does
            // not care which of the two it is, and splitting them would invite the question
            // of what `running` means when the scheduler is Task 13's.
            restaurantsPending: ($byStatus[ProvisioningStatus::Pending->value] ?? 0)
                + ($byStatus[ProvisioningStatus::Running->value] ?? 0),
            restaurantsFailed: $byStatus[ProvisioningStatus::Failed->value] ?? 0,
            sessionsTotal: (int) ($totals->sessions_total ?? 0),
            staffTotal: (int) ($totals->staff_total ?? 0),
            medianWaitSeconds: $totals === null || $totals->median_wait_seconds === null
                ? null
                : (int) round((float) $totals->median_wait_seconds),
            aggregatesComputedAt: $totals === null || $totals->computed_at === null
                ? null
                : (string) $totals->computed_at,
        );
    }
}
