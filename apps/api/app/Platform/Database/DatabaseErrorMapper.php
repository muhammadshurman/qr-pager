<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Domain\Http\ProblemType;
use Throwable;

/**
 * "Error mapping to real driver codes" (PLAN.md §3, row 2).
 *
 * Reads the SQLSTATE through DriverError, never through getCode() -- see that class for
 * the measurement that makes the difference concrete. The mapping is from
 * `PROJECT_INSTRUCTIONS.md §4 › Postgres traps`: 42501 RLS denied, 23505 unique, 23514
 * check, 23503 FK, plus the two an operator meets in practice.
 *
 * Returns a ProblemType and nothing else. No part of the driver's message, and
 * specifically not the constraint name 23514 carries, reaches the caller: that is
 * R-007's Task 2 half, whose condition is "map 23514 without returning the constraint
 * name or the SQL".
 */
final class DatabaseErrorMapper
{
    /**
     * Null when the throwable is not a database error at all, so the caller maps it as
     * an application fault instead of inventing a database outcome for it.
     */
    public function map(Throwable $error): ?ProblemType
    {
        $state = DriverError::sqlState($error);

        if ($state === null) {
            return null;
        }

        return match ($state) {
            // RLS refused the row. From the outside this is indistinguishable from
            // "not yours", and it must stay that way: a different answer here would
            // tell a caller that someone else's row exists.
            DriverError::RLS_DENIED => ProblemType::Forbidden,

            DriverError::UNIQUE_VIOLATION,
            DriverError::FOREIGN_KEY_VIOLATION => ProblemType::Conflict,

            // The constraint name is deliberately absent from the envelope. A check
            // constraint is a rule the request broke, so 422 -- but which rule is a
            // schema detail, and users_email_is_lowercase names a table and a column.
            DriverError::CHECK_VIOLATION,
            DriverError::INVALID_TEXT_REPRESENTATION => ProblemType::Validation,

            // 08006 is also how a saturated role CONNECTION LIMIT arrives at the
            // client -- measured: the 41st qrpager_app connection is refused with
            // 08006 and "too many connections for role", NOT with 53300. A mapper
            // keyed only on 53300 would never fire for the case the gate is about.
            DriverError::CONNECTION_FAILURE,
            DriverError::TOO_MANY_CONNECTIONS,
            DriverError::QUERY_CANCELED => ProblemType::Unavailable,

            default => ProblemType::ServerError,
        };
    }
}
