<?php

declare(strict_types=1);

namespace App\Platform\Database;

use RuntimeException;

/**
 * Thrown when the application is connected as a role that is not safe to serve
 * traffic with. Task 2 turns this into "exit 1, reason logged" at boot
 * (PROJECT_INSTRUCTIONS.md §4 › Tenancy).
 */
final class UnsafeDatabaseRoleException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly string $role, public readonly array $reasons)
    {
        parent::__construct(
            "Refusing to run as database role [{$role}]: ".implode('; ', $reasons).'.'
        );
    }
}
