<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Schema facts read from pg_catalog rather than from the migrations.
 *
 * The meta-tests have to be an independent verdict: a test that reads the same
 * table list the migration wrote would agree with it by construction.
 */
final class Schema
{
    /**
     * Tables with row level security enabled.
     *
     * @return list<string>
     */
    public static function rlsTables(): array
    {
        return self::column(<<<'SQL'
            select c.relname
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r' and c.relrowsecurity
            order by c.relname
        SQL);
    }

    /**
     * Every ordinary table in the public schema.
     *
     * @return list<string>
     */
    public static function allTables(): array
    {
        return self::column(<<<'SQL'
            select c.relname
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
            order by c.relname
        SQL);
    }

    /**
     * Tables that carry tenant data, found by shape rather than by name: anything
     * with a tenant_id column, plus the tenant root itself.
     *
     * @return list<string>
     */
    public static function tenantScopedTables(): array
    {
        return self::column(<<<'SQL'
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and (
                  c.relname = 'tenants'
                  or exists (
                      select 1 from pg_attribute a
                      where a.attrelid = c.oid and a.attname = 'tenant_id' and a.attnum > 0 and not a.attisdropped
                  )
              )
            order by c.relname
        SQL);
    }

    /**
     * Every app.* GUC any policy in the database reads.
     *
     * @return list<string>
     */
    public static function gucsUsedByPolicies(): array
    {
        return self::column(<<<'SQL'
            select distinct m[1] as guc
            from pg_policy p,
                 lateral regexp_matches(
                     coalesce(pg_get_expr(p.polqual, p.polrelid), '')
                     || ' ' || coalesce(pg_get_expr(p.polwithcheck, p.polrelid), ''),
                     'current_setting\(''(app\.[a-z_]+)''', 'g'
                 ) as m
            order by 1
        SQL);
    }

    /** @return list<array{polname: string, polcmd: string, roles: string, using: string|null, check: string|null}> */
    public static function policies(string $table): array
    {
        /** @var list<array{polname: string, polcmd: string, roles: string, using: string|null, check: string|null}> */
        return array_map(static fn (object $row): array => (array) $row, DB::connection('pgsql_owner')->select(<<<'SQL'
            select p.polname,
                   p.polcmd::text                            as polcmd,
                   array_to_string(p.polroles::regrole[], ',') as roles,
                   pg_get_expr(p.polqual, p.polrelid)         as using,
                   pg_get_expr(p.polwithcheck, p.polrelid)    as check
            from pg_policy p
            where p.polrelid = ?::regclass
            order by p.polname
        SQL, [$table]));
    }

    /**
     * Columns $role may use for $privilege on $table.
     *
     * @return list<string>
     */
    public static function grantedColumns(string $role, string $table, string $privilege): array
    {
        return self::column(<<<'SQL'
            select a.attname
            from pg_attribute a
            where a.attrelid = ?::regclass
              and a.attnum > 0
              and not a.attisdropped
              and has_column_privilege(?, a.attrelid, a.attname, ?)
            order by a.attname
        SQL, [$table, $role, $privilege]);
    }

    /**
     * Every table $role can touch at all, by table-level or column-level grant.
     *
     * has_table_privilege alone is not enough: it reports false for a table the
     * role can only reach through a column grant.
     *
     * @return list<string>
     */
    public static function reachableTables(string $role): array
    {
        return self::column(<<<'SQL'
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and (
                  has_table_privilege(?, c.oid, 'SELECT, INSERT, UPDATE, DELETE')
                  or exists (
                      select 1 from pg_attribute a
                      where a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
                        and has_column_privilege(?, c.oid, a.attname, 'SELECT, INSERT, UPDATE')
                  )
              )
            order by c.relname
        SQL, [$role, $role]);
    }

    public static function hasTablePrivilege(string $role, string $table, string $privilege): bool
    {
        return (bool) DB::connection('pgsql_owner')->selectOne(
            'select has_table_privilege(?, ?::regclass, ?) as granted',
            [$role, $table, $privilege],
        )->granted;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private static function column(string $sql, array $bindings = []): array
    {
        $values = [];

        foreach (DB::connection('pgsql_owner')->select($sql, $bindings) as $row) {
            $values[] = (string) array_values((array) $row)[0];
        }

        return $values;
    }
}
