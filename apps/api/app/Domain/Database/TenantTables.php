<?php

declare(strict_types=1);

namespace App\Domain\Database;

/**
 * Which tables carry tenant data, and which deliberately do not.
 *
 * One registry, shared by the runtime guard and by the meta-tests. The point is not
 * tidiness: before this existed, the tenant-table list lived in RlsCoverageTest's
 * GLOBAL_TABLES const and nowhere the application could read, so a guard that needed
 * the same list would have had a second copy of it -- and a table in one list and not
 * the other is exactly the gap §2.4 is written to prevent. RlsCoverageTest now reads
 * this and pins it against pg_catalog, so the registry cannot drift from the schema
 * and the schema cannot drift from the guard.
 *
 * In App\Domain on purpose: no framework, no database, no config -- deptrac enforces
 * it. These names are part of the data contract (PLAN.md §2), not environment
 * configuration, for the same reason the role names are: the RLS policies name them
 * literally, so PHP and SQL have to agree.
 */
final class TenantTables
{
    /**
     * Tables RLS narrows. Every one of them has a tenant_isolation_* policy and an
     * owner_bypass_* policy, which RlsCoverageTest proves from pg_catalog.
     *
     * `tenants` is here although it has no tenant_id column: its own id IS the tenant
     * scope, and its isolation policy compares that id against app.tenant_id.
     *
     * @var list<string>
     */
    public const TENANT = [
        'audit_log',
        'outbox',
        'pager_sessions',
        'push_subscriptions',
        'tenant_users',
        'tenants',
    ];

    /**
     * Tables with no tenant scope at all, each justified in PLAN.md §2.4.
     *
     * A statement that touches only these is not refused by the scope guard, because
     * there is no scope that could narrow it. Adding a name here is a deliberate act
     * that shows up in a diff and has to be argued for in §2.4 -- and RlsCoverageTest
     * fails if anything on this list turns out to carry a tenant_id.
     *
     * @var list<string>
     */
    public const GLOBAL = [
        'failed_jobs',
        'migrations',
        'password_reset_tokens',
        'users',
    ];

    /**
     * The first tenant table this statement names, or null.
     *
     * Matched on the statement text with an identifier boundary either side, which is
     * what makes `"tenant_users"` match tenant_users and not users, and `users` match
     * users and not tenant_users. Quoted and unquoted both work, because the boundary
     * is "not an identifier character" rather than a quote.
     *
     * KNOWN IMPRECISION, stated rather than discovered later: this reads the whole
     * statement, so a tenant table's name inside a SQL string literal matches too --
     * `where relname = 'tenants'` against pg_catalog would be reported as touching
     * `tenants`. That direction is chosen deliberately. A false positive is a loud
     * refusal on a statement that mentions tenant data; a false negative is the task's
     * own invariant being false while its test is green. Bound parameters are not SQL
     * text and never reach here, so an ordinary insert of a row whose VALUE happens to
     * be a table name is unaffected -- the builder emits placeholders.
     */
    public static function referencedBy(string $sql): ?string
    {
        foreach (self::TENANT as $table) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                return $table;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        $all = array_merge(self::TENANT, self::GLOBAL);
        sort($all);

        return $all;
    }
}
