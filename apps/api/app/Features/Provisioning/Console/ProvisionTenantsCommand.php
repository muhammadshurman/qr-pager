<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Console;

use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\TenantProvisioning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drain pending provisioning requests: the out-of-band half of S1.
 *
 * ================================================================================
 * WHY A SCHEDULED COMMAND AND NOT A QUEUED JOB
 * ================================================================================
 *
 * This looked like a `ShouldQueue` job and is not, for a measured reason rather than a
 * preference. **There is no queued job anywhere in this application** — the out-of-band
 * mechanisms that exist are the outbox worker (C5) and `pager:expire`, and both are console
 * commands on the schedule. More concretely, `§2.4` records that `jobs` and `job_batches`
 * were **dropped from the Laravel skeleton** at Task 1, so a database queue has no table to
 * write to; the locked store is Redis (`§1`), whose own posture is still `R-107`. Adding the
 * project's first queued job, on the driver with the open ledger row, to ship a provisioning
 * wizard would be two unrelated decisions in one commit.
 *
 * THE OUTBOX WAS THE OTHER CANDIDATE AND IT CANNOT WORK HERE. `outbox` is a tenant table
 * whose `tenant_id` is `NOT NULL REFERENCES tenants (id)` — and the whole point of this
 * command is that the tenant does not exist yet, so the row could not be written. The
 * provisioning record on a platform table IS the queue, which is why it carries `status` and
 * `attempts`.
 *
 * R-058 IS THE REASON IT IS SCHEDULED IN THE SAME COMMIT. "A command registered and
 * scheduled nowhere does not enforce anything" was the ruling on the expiry job, which
 * shipped registered and unscheduled and made `§2.11` false as deployed. This one is on the
 * schedule in `routes/console.php` in the same commit that creates it.
 *
 * NO TENANT CONTEXT ON THE DRAIN QUERY, and that is correct rather than an omission:
 * `tenant_provisionings` is a platform table with no RLS, so there is no scope to set and
 * the scope guard does not refuse it. The per-tenant context is opened by
 * `TenantProvisioning::provision()`, one tenant at a time, for exactly the tenant it creates.
 *
 * THE APP ROLE, NOT THE WORKER. `§2.7` gives `qrpager_app` the `tenants` and `tenant_users`
 * inserts and gives the worker neither; granting them to the worker would widen a role whose
 * whole value is that it cannot do them. So this command runs on the default connection, and
 * `ProvisioningRoleTest` pins that rather than leaving it to a reader.
 */
final class ProvisionTenantsCommand extends Command
{
    protected $signature = 'platform:provision-tenants
                            {--limit=25 : How many requests to drain in one pass}
                            {--retry-stalled= : Reclaim one row left at running or exhausted, by id}';

    protected $description = 'Create the tenants that the platform admin panel has requested';

    public function handle(TenantProvisioning $provisioning): int
    {
        $stalled = $this->option('retry-stalled');

        if (is_string($stalled) && $stalled !== '') {
            return $this->reclaim($stalled);
        }

        $limit = $this->boundedLimit();

        // `pending` only, and never `running`: a row left at `running` by a killed process
        // is NOT picked up here, because this pass cannot tell a stalled attempt from one
        // that is in flight on another host. Recovering those is `--retry-stalled`'s job,
        // below, which is a deliberate per-row act rather than a loop.
        //
        // ⚠ UNTIL 2026-10-28 THAT OPTION DID NOT EXIST while this comment named it, and
        // `attempts` was read by nothing — so a row left at `running` was stuck for ever,
        // shown as "In progress" on the dashboard, recoverable only by hand SQL, which is
        // the very thing `§3` row 6's "no SQL run by hand" forbids. QA H-2.
        //
        // ORDER BY created_at: the oldest request waits least, and a deterministic order
        // makes a partial drain reproducible.
        $pending = DB::select(
            'select id from tenant_provisionings
              where status = ?::provisioning_status
              order by created_at
              limit '.$limit,
            [ProvisioningStatus::Pending->value],
        );

        $created = 0;
        $failed = 0;

        foreach ($pending as $row) {
            if (! is_object($row) || ! isset($row->id)) {
                continue;
            }

            try {
                $created += $provisioning->provision((string) $row->id) ? 1 : 0;
            } catch (Throwable $error) {
                // provision() has already written `failed` and its reason in its own
                // transaction, so the record is durable before this line runs. The loop
                // continues: one restaurant that cannot be created must not stop the next
                // one, which is the difference between a drain and a batch.
                //
                // THE MESSAGE IS NOT LOGGED HERE. A driver message carries the SQL and the
                // bound parameters (§4 › Edge & ops), and the mapped reason is already on
                // the row where an operator can read it.
                $failed++;
            }
        }

        $this->info(sprintf(
            'platform:provision-tenants — %d created, %d failed, of %d pending.',
            $created,
            $failed,
            count($pending),
        ));

        // Zero even when some failed: a failure is recorded on its row and the pass itself
        // did its job. A non-zero exit would make the scheduler treat an ordinary duplicate
        // slug as an infrastructure fault.
        return self::SUCCESS;
    }

    /**
     * Reclaim one row by id — the operator path QA H-2 found missing.
     *
     * A row at `running` is one a killed process abandoned; a row at `failed` with
     * `attempts` at the cap is one this command has stopped retrying. Neither is picked up
     * by the drain, by design: the drain cannot tell a stalled attempt from one in flight on
     * another host, and a cap that a loop resets is not a cap.
     *
     * So reclaiming is explicit, per row, and it RESETS `attempts` — which is the thing that
     * makes it safe for the cap to exist at all. It takes one id rather than a status,
     * because "reclaim everything stuck" is how a hot loop gets written by accident.
     */
    private function reclaim(string $tenantId): int
    {
        $row = DB::selectOne(
            'select id, status, attempts from tenant_provisionings where id = ?',
            [$tenantId],
        );

        if ($row === null) {
            $this->error('platform:provision-tenants — no provisioning record with that id.');

            return self::FAILURE;
        }

        $status = ProvisioningStatus::from((string) $row->status);

        if ($status === ProvisioningStatus::Completed) {
            $this->info('platform:provision-tenants — already provisioned; nothing to reclaim.');

            return self::SUCCESS;
        }

        // Keyed and status-guarded, with the affected-row count checked: the same shape
        // §4 › Postgres traps prescribes everywhere else in this repository.
        $reclaimed = DB::update(
            'update tenant_provisionings
                set status = ?::provisioning_status, attempts = 0, failure_reason = null,
                    updated_at = now()
              where id = ? and status = ?::provisioning_status',
            [ProvisioningStatus::Pending->value, $tenantId, $status->value],
        );

        if ($reclaimed === 0) {
            $this->error('platform:provision-tenants — the row changed while being reclaimed; try again.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'platform:provision-tenants — reclaimed %s from [%s]; attempts reset from %d. '
            .'The next pass will pick it up.',
            $tenantId,
            $status->value,
            (int) $row->attempts,
        ));

        return self::SUCCESS;
    }

    /**
     * The drain size, interpolated into SQL — so it is proven to be an integer first.
     *
     * `limit` takes no bind parameter in this builder path, so the value is cast and
     * clamped rather than trusted. The same shape as the expiry job's `pager.expiry_batch`,
     * and the reason is the same: an option is caller input.
     */
    private function boundedLimit(): int
    {
        $raw = $this->option('limit');
        $limit = is_numeric($raw) ? (int) $raw : 25;

        return max(1, min(500, $limit));
    }
}
