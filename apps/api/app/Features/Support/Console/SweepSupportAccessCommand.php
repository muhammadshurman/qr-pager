<?php

declare(strict_types=1);

namespace App\Features\Support\Console;

use App\Platform\Database\DatabaseRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete support memberships whose hour is up. `QRPAGER-22`.
 *
 * ================================================================================
 * ⚠ IT IS HOUSEKEEPING AND NOT THE ENFORCEMENT, WHICH IS THE WHOLE POINT
 * ================================================================================
 *
 * `§4 › Tenancy` (2026-11-04): *"an expired or released membership leaves no residual
 * ability."* **That is already true before this command runs**, and it has to be: an expiry
 * enforced by a scheduled job has a window one minute wide in which a lapsed membership still
 * works, and a job that fails has a window the length of the outage.
 *
 * So the enforcement is in SQL, inside `public.user_memberships(uuid)`, which filters
 * `expires_at IS NULL OR expires_at > now()` — every membership read in the application goes
 * through it, and a lapsed row is invisible the instant it lapses.
 * `SupportMembershipTest` drives that with the row still present.
 *
 * What this command is for is the OTHER consequence of leaving a spent row in place: the
 * partial unique index `UNIQUE (user_id) WHERE expires_at IS NOT NULL` cannot know about
 * `now()` (that predicate is not immutable, so Postgres refuses it in an index), so a lapsed
 * row keeps holding its admin's one slot. Without this they could never take another. That is
 * a usability failure rather than a security one, which is exactly the right thing to leave to
 * a minute of lag.
 *
 * ================================================================================
 * THE WORKER ROLE, AND NO TENANT SCOPE
 * ================================================================================
 *
 * ⚠ `qrpager_worker`, like `pager:expire`, and for the same reason: this is a cross-tenant
 * sweep with no request behind it, and the worker role is the one `§4 › Tenancy` already names
 * for *"the expiry job"*. So this adds **no new named path** — it IS the fourth one.
 *
 * ⚠ AND THE DELETE IS BOUNDED BY `expires_at <= now()` AND `expires_at IS NOT NULL`, both
 * stated rather than one implied by the other: without the null check a predicate change could
 * sweep ordinary memberships, which would remove real people from real restaurants. The second
 * condition is the one that makes a mistake survivable.
 */
final class SweepSupportAccessCommand extends Command
{
    protected $signature = 'support:sweep';

    protected $description = 'Delete platform support memberships whose expiry has passed.';

    public function handle(): int
    {
        // `DatabaseRoles::connection()` returns the connection NAME, not a connection --
        // the shape `ExpirePagerSessionsCommand` uses two lines apart. Caught by running it.
        $worker = DB::connection(DatabaseRoles::connection('worker'));

        /*
         * ⚠ A FUNCTION CALL AND NOT A `DELETE`, AND THE DATABASE DECIDED THAT. The plain
         * statement answered `SQLSTATE[42501]: permission denied for table tenant_users` --
         * the worker role holds `SELECT` there and nothing more. Granting it `DELETE` was
         * rejected because `DELETE` has no column-level form: the role would gain the ability
         * to remove ANY membership. `public.sweep_support_memberships()` is `§2.8`'s recipe
         * instead -- `SECURITY DEFINER`, owned by the owner, `EXECUTE` to the worker alone,
         * and the bound (`expires_at IS NOT NULL AND expires_at <= now()`) inside the body
         * where a caller cannot mistype it. It returns a count and nothing else.
         */
        /** @var object{swept: int}|null $row */
        $row = $worker->selectOne('select public.sweep_support_memberships() as swept');

        $deleted = (int) ($row->swept ?? 0);

        $this->info("support:sweep: {$deleted} lapsed membership(s) deleted.");

        return self::SUCCESS;
    }
}
