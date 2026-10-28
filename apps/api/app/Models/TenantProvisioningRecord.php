<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Provisioning\ProvisioningStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of `tenant_provisionings` — a PLATFORM table (PLAN.md §2.4).
 *
 * ⚠ THE PROPERTY THAT MATTERS MOST ABOUT THIS MODEL IS WHAT IT DOES NOT HAVE: there is NO
 * relation from here to any tenant-table model, and there never may be. The named
 * invariant is *"no panel query reaches a tenant table"*, and an Eloquent relation is the
 * realistic way that would come to be broken later — one `->with('tenant')` on a table
 * column and every panel list query joins `tenants`. `PlatformPanelInvariantTest` reflects
 * over this class and fails if a relation method appears, which is the mechanical half of
 * this paragraph.
 *
 * `$table` is stated rather than inferred: Eloquent would pluralise this class name to
 * `tenant_provisioning_records`, which does not exist.
 *
 * `$incrementing = false` and `$keyType = 'string'` because the key is a uuid — and that
 * uuid IS the tenant's id, which the migration explains at length.
 */
class TenantProvisioningRecord extends Model
{
    protected $table = 'tenant_provisionings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * No $fillable and no $guarded = []. Mass assignment is OFF for every attribute,
     * deliberately: nothing writes this model through the panel's forms. The write path is
     * TenantProvisioning::request(), which issues explicit column lists, so there is no
     * attribute here a request could set. §11.4's "mass assignment guarded" as an absence
     * rather than as a list that has to stay correct.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => ProvisioningStatus::class,
            'attempts' => 'integer',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
