<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * The project's passkey model: `laravel/passkeys`' own, with a uuid key.
 *
 * WHY IT EXISTS AT ALL. The vendor model is a plain Eloquent model with an
 * auto-incrementing integer key, because its migration declares `$table->id()`. This
 * project's migration declares `id uuid PRIMARY KEY` with no default -- every id in the
 * schema is a uuid except `audit_log.id` (PLAN.md §2.5), and that is a data-contract
 * decision rather than a preference. So an insert through the vendor model would send no
 * id and the column has no default: a null-violation on the first registration.
 *
 * `HasUuids` is the whole of the difference. Registered with
 * `Passkeys::usePasskeyModel()` in AppServiceProvider, which is the package's own
 * supported seam -- `Passkeys::$passkeyModel` is a static with a setter for exactly this.
 *
 * Nothing else is changed. `credential` stays a json cast on the parent, and the parent's
 * `$fillable` stays as it is: `name`, `credential_id`, `credential`, none of which is a
 * user id, so a mass-assignment of a registration payload cannot move a passkey between
 * users.
 */
class Passkey extends BasePasskey
{
    use HasUuids;
}
