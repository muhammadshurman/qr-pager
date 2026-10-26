<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the composition matrix.
 *
 * These were file-scope constants and functions in the test file itself, which made
 * the whole suite one duplicate declaration away from a fatal error: a later file --
 * or Task 2 -- defining `probe()` or `APP` would have taken every test down with it,
 * and the failure names the redeclaration rather than the collision (L-6, fifth
 * security review).
 */
final class Composition
{
    /**
     * Different Laravel connections are different Postgres sessions, so a
     * transaction-local GUC set on one is invisible to the others. The matrix needs
     * all three: APP and WORKER are the two runtime roles, OWNER is the only role the
     * bypass accepts.
     */
    public const APP = 'pgsql';

    public const OWNER = 'pgsql_owner';

    public const WORKER = 'pgsql_worker';

    public static function tenantGuc(string $connection): string
    {
        return self::guc($connection, 'app.tenant_id');
    }

    public static function bypassGuc(string $connection): string
    {
        return self::guc($connection, 'app.bypass_tenant');
    }

    /**
     * Set a GUC at SESSION scope -- set_config(..., false) -- which is what C2 calls a
     * hard failure and what no code path in TenantContext can produce. The connection
     * is purged between tests, so the session carrying it dies with it.
     */
    public static function setSessionGuc(string $connection, string $name, string $value): void
    {
        DB::connection($connection)->select('select set_config(?, ?, false)', [$name, $value]);
    }

    /**
     * What a block claims to be able to see and change: the number of tenants visible,
     * and whether a keyed cross-tenant UPDATE lands. Inside a correctly scoped block
     * that is 1 and 0.
     *
     * @return array{int, int}
     */
    public static function probe(ConnectionInterface $connection, string $other): array
    {
        return [
            $connection->table('tenants')->count(),
            $connection->table('tenants')->where('id', $other)->update(['timezone' => 'Pacific/Auckland']),
        ];
    }

    private static function guc(string $connection, string $name): string
    {
        $row = DB::connection($connection)->selectOne(
            'select coalesce(current_setting(?, true), ?) as value', [$name, ''],
        );

        return is_object($row) && is_string($row->value ?? null) ? $row->value : '';
    }
}
