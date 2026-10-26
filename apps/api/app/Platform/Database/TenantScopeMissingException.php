<?php

declare(strict_types=1);

namespace App\Platform\Database;

use RuntimeException;

/**
 * A statement touching tenant data was about to run on a request with no tenant scope.
 *
 * Raised BEFORE the statement reaches Postgres -- see TenantScopeGuard for why that
 * word is load-bearing -- so nothing was read and nothing was written.
 *
 * This is a programming error, not a caller error: the fix is a withTenant() around
 * the unit of work, never a different request. It therefore renders as a generic 500
 * problem+json through the mapper's fall-through, and the detail below reaches the log
 * only. The message carries the TABLE and the CONNECTION and no values, which is the
 * same contract the log redaction states: names and locations in, values out.
 */
final class TenantScopeMissingException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly string $connection,
    ) {
        parent::__construct(
            "A statement touching [{$table}] was refused on connection [{$connection}]: no tenant "
            .'scope is open there. A request without tenant context never reaches the database '
            .'(PLAN.md §3.1, row 2), so this was stopped before execution rather than left to row '
            .'level security to return zero rows for. Wrap the unit of work in withTenant().'
        );
    }
}
