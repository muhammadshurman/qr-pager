<?php

declare(strict_types=1);

namespace App\Features\Tenants;

use Illuminate\Support\Facades\DB;

/**
 * The caller's own restaurant, as a staff screen needs to show it.
 *
 * ================================================================================
 * ONE ROW, BY CONSTRUCTION RATHER THAN BY A `LIMIT`
 * ================================================================================
 *
 * `tenants` is a tenant table whose SCOPE COLUMN IS `id` (`PLAN.md §2.5`), so inside
 * `withTenant($tenantId, …)` row-level security leaves exactly one row visible: the
 * caller's own. There is no `where id = ?` below and that is deliberate — adding one would
 * suggest the filter is the predicate, when the filter is the policy. A `limit 1` would
 * suggest the same thing about the row count.
 *
 * ⚠ AND THE STATEMENT REFERENCES COLUMNS OF THE TABLE, WHICH IS WHAT PULLS THE SELECT
 * POLICY IN AT ALL (`§4 › Postgres traps`). A statement that references no column of a
 * tenant table escapes RLS row filtering entirely; this one is a plain `select` of four
 * named columns, so it is on the safe side of that trap by shape and not by luck.
 *
 * NO CROSS-TENANT READ IS ADDED HERE. `§4 › Tenancy` names four paths and this is none of
 * them: it is an ordinary scoped read of the caller's own row. That is the whole reason the
 * restaurant's name is fetched here instead of being added to `public.user_memberships()`,
 * which IS one of the four.
 */
final class TenantProfile
{
    /**
     * @return array{name: string, slug: string, timezone: string, status: string}|null
     */
    public function forTenant(string $tenantId): ?array
    {
        $rows = withTenant($tenantId, fn (): array => DB::select(
            'select name, slug, timezone, status from tenants'
        ));

        $row = $rows[0] ?? null;

        // isset-guarded rather than mapped blind, the same shape as `PagerSessions::board()`
        // and `Memberships::for()`: `DB::select()` answers plain objects, so a column renamed
        // underneath this method would otherwise be a property error at runtime.
        if (! is_object($row) || ! isset($row->name, $row->slug, $row->timezone, $row->status)) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'timezone' => (string) $row->timezone,
            'status' => (string) $row->status,
        ];
    }
}
