<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * `pager_sessions`' COLUMN GRANTS, PINNED EXACTLY — TASK 10d
 * ================================================================================
 *
 * `AuthGrantNarrowingTest`'s mechanism, applied to the table 10d's migration touches.
 * `2026_11_02_130000_grant_pager_token_rotation.php` adds **one** column to `qrpager_app`'s
 * UPDATE set on this table, and its docblock explains what that costs and why it is the
 * least-privilege way to satisfy Muhammad's re-issue ruling.
 *
 * **This file is what stops the next widening being quiet.** The sets below are pinned as
 * EXACT lists rather than as "contains", so a `GRANT UPDATE ON pager_sessions TO qrpager_app`
 * — or one more column added in passing — fails here and names itself.
 *
 * ⚠ **THE LOAD-BEARING ASSERTION IS THE `SELECT` ONE, NOT THE `UPDATE` ONE.** The rotation
 * grant makes `token_hash` REPLACEABLE; the thing that keeps it from being READABLE is the
 * absence of a SELECT grant on that column, and that absence is the whole of `§4 › Data`'s
 * *stored as a hash only* posture at the role layer. A reviewer checking whether 10d weakened
 * anything should read that case first.
 */

/**
 * The columns `$grantee` holds `$privilege` on, for one table. Read from
 * `information_schema`, which is the grant as POSTGRES sees it rather than as the migration
 * intended it — the difference a `GRANT ... ON ALL TABLES` somewhere else would hide.
 *
 * @return list<string>
 */
function pagerSessionGrants(string $grantee, string $privilege): array
{
    $rows = DB::connection('pgsql_owner')->select(
        "select column_name
           from information_schema.column_privileges
          where table_name = 'pager_sessions'
            and grantee = ?
            and privilege_type = ?
          order by column_name",
        [$grantee, $privilege],
    );

    return array_map(static fn (object $r): string => (string) $r->column_name, $rows);
}

it('lets the app role REPLACE a pager token but never READ one — 10d\'s grant, and its bound', function (): void {
    /*
     * ⚠ READ THIS CASE FIRST WHEN ASKING WHETHER 10d WEAKENED THE TOKEN POSTURE.
     *
     * `§4 › Data`: the QR token is *"128-bit CSPRNG stored as a hash only"*, and Muhammad's
     * re-issue ruling rests on that — *"the raw value exists once, at generation, and can never
     * be re-rendered from the database"*. At the ROLE layer, the half that makes that true is
     * the absence of a SELECT grant on `token_hash`, and 10d does not touch it.
     *
     * So after the rotation grant the app role can overwrite the column and still cannot read
     * it: a denial of service remains available and a DISCLOSURE does not. That distinction is
     * the whole argument in the migration's docblock, and this is it as an assertion.
     */
    /*
     * ⚠ `str_contains(...)->toBeFalse($message)` AND **NOT** `not->toContain($needle, $message)`
     * — AND THE FIRST VERSION OF THIS FILE GOT IT WRONG ON THIS EXACT LINE, THE LOAD-BEARING
     * ASSERTION OF THE FILE.
     *
     * Pest's `toContain` is VARIADIC and takes no message, so the two-argument form reads the
     * message as a SECOND NEEDLE. Negated, that asserts *"does not contain BOTH"* — and since
     * the message string never appears in the subject, it is **trivially true whatever the
     * grant is**. The assertion would have passed with `SELECT (token_hash)` granted to the app
     * role, which is the one posture this case exists to refuse.
     *
     * Caught by `composer guard`'s `vacuous-negated-contains` rule rather than by review, which
     * is what that rule is for: *an unproven guard is not a guard*, and a guard that cannot
     * fail is worse than none because it reads as coverage.
     */
    expect(in_array('token_hash', pagerSessionGrants('qrpager_app', 'SELECT'), true))->toBeFalse(
        'the app role can READ a pager token hash, which breaks §4 › Data\'s stored-as-a-hash-only posture',
    );

    // And it CAN write one, which is what makes `reissue()` work at all. Both halves, because
    // either alone describes a posture this task does not have.
    expect(pagerSessionGrants('qrpager_app', 'UPDATE'))->toContain('token_hash');
});

it('pins the app role\'s whole grant set on pager_sessions, so a widening cannot pass quietly', function (): void {
    // EXACT lists. `AuthGrantNarrowingTest`'s own shape: a `contains` check would pass against
    // a table-wide grant, which is the widening most likely to be added by accident.
    expect(pagerSessionGrants('qrpager_app', 'SELECT'))->toBe([
        'cancelled_at',
        'completed_at',
        'created_at',
        'created_by_user_id',
        'expired_at',
        'expires_at',
        'id',
        'last_notified_at',
        'order_reference',
        'ready_at',
        'renotify_count',
        'status',
        'tenant_id',
        'updated_at',
    ]);

    expect(pagerSessionGrants('qrpager_app', 'UPDATE'))->toBe([
        'cancelled_at',
        'completed_at',
        'last_notified_at',
        'ready_at',
        'renotify_count',
        'status',
        // 10d's one addition. The migration's docblock has what it costs and the three
        // alternatives that were measured and rejected.
        'token_hash',
        'updated_at',
    ]);

    // INSERT is unchanged by 10d and is pinned beside the others, because the rotation grant's
    // own justification leans on it — *"the role could already bind a chosen token to a NEW
    // session"* — and an assertion that rests on a grant should pin that grant.
    expect(pagerSessionGrants('qrpager_app', 'INSERT'))->toBe([
        'created_at',
        'created_by_user_id',
        'expires_at',
        'id',
        'order_reference',
        'status',
        'tenant_id',
        'token_hash',
        'updated_at',
    ]);
});

it('does not give the worker the rotation grant, because expiring a session is not re-issuing one', function (): void {
    /*
     * The migration grants to `qrpager_app` alone. The worker's job on this table is to expire
     * sessions whose time has run out (`UPDATE (status, expired_at, updated_at)`), and it has
     * no business minting or replacing a credential — it has no CSPRNG draw and no caller to
     * hand a new sheet to.
     *
     * Asserted because `GRANT ... TO qrpager_app, qrpager_worker` is the shape three other
     * lines in that migration use, so granting both is the easy slip.
     */
    expect(pagerSessionGrants('qrpager_worker', 'UPDATE'))->toBe([
        'expired_at',
        'status',
        'updated_at',
    ]);

    expect(pagerSessionGrants('qrpager_worker', 'SELECT'))->not->toContain('token_hash');
    expect(pagerSessionGrants('qrpager_worker', 'INSERT'))->toBe([]);
});
