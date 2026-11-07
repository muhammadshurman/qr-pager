<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of `tenant_statistics` — a PLATFORM table of AGGREGATES (PLAN.md §2.4).
 *
 * Every attribute is a count, a whole number of seconds or a window bound. There is no
 * row-level detail here because there is none in the table: no order reference, no token,
 * no session identifier (`QRPAGER-13 §0`). `PlatformTableContractTest` enforces that
 * against the column set, and `PlatformPanelInvariantTest` enforces the no-relation rule
 * against this class.
 *
 * `id` IS the tenant id these aggregates describe, which is why there is no `tenant_id`.
 *
 * NO WRITES FROM HERE, and the grant is what guarantees it rather than this comment: the
 * app role holds `SELECT` on this table and no `INSERT` or `UPDATE` at all, so a write
 * attempted through this model is refused by Postgres. The only writer is the aggregate
 * job, on the worker connection. `GrantMatrixTest` pins both halves.
 */
class TenantStatistic extends Model
{
    protected $table = 'tenant_statistics';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'sessions_total' => 'integer',
            'sessions_waiting' => 'integer',
            'sessions_ready' => 'integer',
            'sessions_completed' => 'integer',
            'sessions_cancelled' => 'integer',
            'sessions_expired' => 'integer',
            'staff_count' => 'integer',
            'median_wait_seconds' => 'integer',
            'p95_wait_seconds' => 'integer',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
