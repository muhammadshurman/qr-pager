<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Typed access to the role, connection and GUC names in config/tenancy.php.
 *
 * Role names are part of the data contract, not environment configuration: the
 * RLS policies name them literally, so PHP and SQL have to agree (PLAN.md §2.1).
 */
final class DatabaseRoles
{
    /** Roles the migrations grant privileges to. The owner is not one of them: it owns the tables. */
    public const GRANTABLE = ['app', 'worker'];

    public static function name(string $key): string
    {
        $name = config("tenancy.roles.{$key}");

        if (! is_string($name) || $name === '') {
            throw new RuntimeException("No database role is configured for [{$key}].");
        }

        return $name;
    }

    public static function connection(string $key): string
    {
        $name = config("tenancy.connections.{$key}");

        if (! is_string($name) || $name === '') {
            throw new RuntimeException("No database connection is configured for [{$key}].");
        }

        return $name;
    }

    public static function guc(string $key): string
    {
        $name = config("tenancy.guc.{$key}");

        if (! is_string($name) || $name === '') {
            throw new RuntimeException("No GUC is configured for [{$key}].");
        }

        return $name;
    }

    public static function bypassValue(): string
    {
        $value = config('tenancy.bypass_value');

        return is_string($value) && $value !== '' ? $value : 'on';
    }

    /** @return list<string> */
    public static function runtimeRoleNames(): array
    {
        $keys = config('tenancy.runtime_roles');
        $keys = is_array($keys) ? $keys : [];

        return array_values(array_map(
            static fn (mixed $key): string => self::name(is_string($key) ? $key : ''),
            $keys,
        ));
    }

    /** @return array<string, array<string, string>> */
    public static function timeouts(): array
    {
        $timeouts = config('tenancy.timeouts');

        /** @var array<string, array<string, string>> */
        return is_array($timeouts) ? $timeouts : [];
    }

    /**
     * Migrations grant privileges to roles and name them in policies, so a missing
     * role has to fail with the command that creates it, not with "role does not exist".
     */
    public static function assertRolesExist(ConnectionInterface $connection): void
    {
        $required = array_map(self::name(...), ['owner', 'app', 'worker']);

        $present = [];

        foreach ($connection->select(
            'select rolname from pg_roles where rolname = any(?::text[])',
            ['{'.implode(',', $required).'}'],
        ) as $row) {
            if (is_object($row) && is_string($row->rolname ?? null)) {
                $present[] = $row->rolname;
            }
        }

        $missing = array_values(array_diff($required, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                'These database roles do not exist yet: '.implode(', ', $missing).'. '
                ."Run 'php artisan db:bootstrap' before migrating (PLAN.md §2.1)."
            );
        }
    }
}
