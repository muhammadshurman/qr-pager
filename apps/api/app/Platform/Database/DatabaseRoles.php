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

    /**
     * Postgres reserves connection slots for superusers only, so without a per-role
     * limit one role exhausting max_connections locks the others out of the cluster
     * (F5, security review of task-1). A missing limit is a hard failure rather than
     * an unlimited default: unlimited is the bug being fixed.
     */
    public static function connectionLimit(string $key): int
    {
        $limit = config("tenancy.connection_limits.{$key}");

        if (! is_int($limit) || $limit < 1) {
            throw new RuntimeException(
                "No connection limit is configured for role [{$key}]. One role flooding "
                .'the pool must not be able to lock the others out (PLAN.md §2.1).'
            );
        }

        return $limit;
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
     * R-014: refuse a configured timeout that is far too high, at the moment it would
     * be written.
     *
     * The firing proofs keep their lower bound only (F10), so nothing else can catch
     * this: a far-too-high value does not fail a test, it makes the test that waits for
     * it hang until the harness kills the run. And the SHOW-comparison test cannot
     * catch it either, because it compares current_setting against the same config
     * value -- it sees role-vs-config drift and nothing else.
     *
     * Compared as intervals, by Postgres, for the reason the SHOW test is: '60s' and
     * '1min' are the same value and string comparison says otherwise. The ONE allowed
     * exception is a named role/setting pair in config, not a magic value, so "no
     * statement timeout" cannot quietly become true of the app role.
     *
     * @return list<string> the settings past their ceiling, empty when all are within it
     */
    public static function timeoutsPastCeiling(ConnectionInterface $connection): array
    {
        /** @var array<string, string> $ceilings */
        $ceilings = is_array($c = config('tenancy.timeout_ceilings')) ? $c : [];

        /** @var array<string, list<string>> $exemptions */
        $exemptions = is_array($e = config('tenancy.timeout_ceiling_exemptions')) ? $e : [];

        $past = [];

        foreach (self::timeouts() as $roleKey => $settings) {
            foreach ($settings as $setting => $value) {
                $ceiling = $ceilings[$setting] ?? null;

                if (! is_string($ceiling) || in_array($setting, $exemptions[$roleKey] ?? [], true)) {
                    continue;
                }

                // Postgres decides both questions in one round trip, and the SECOND one
                // is the trap: statement_timeout = 0 means NO timeout at all, which is
                // the most dangerous value the app role could carry -- and an interval
                // comparison reads it as the smallest and safest. A ceiling that only
                // compared magnitudes would wave through exactly the value it exists to
                // stop. `0` is therefore past every ceiling unless the role/setting pair
                // is exempt by name, which is what makes the owner's deliberate
                // statement_timeout = 0 (PLAN.md §2.1) an exemption rather than an
                // accident.
                $row = $connection->selectOne(
                    'select ?::interval > ?::interval as too_long, ?::interval = ?::interval as unlimited',
                    [$value, $ceiling, $value, '0'],
                );

                if (! is_object($row)) {
                    continue;
                }

                if ((bool) ($row->unlimited ?? false)) {
                    $past[] = self::name($roleKey).": {$setting} = {$value} disables the timeout entirely, "
                        ."which is past any ceiling (the configured ceiling is {$ceiling})";

                    continue;
                }

                if ((bool) ($row->too_long ?? false)) {
                    $past[] = self::name($roleKey).": {$setting} = {$value} exceeds the configured ceiling of {$ceiling}";
                }
            }
        }

        return $past;
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
