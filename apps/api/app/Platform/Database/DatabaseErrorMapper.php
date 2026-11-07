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
            // 55P03 joins QUERY_CANCELED here rather than falling to the default,
            // which is QA L-7. Both are timeouts the app role sets on purpose, and a
            // lock_timeout means another transaction holds the row -- transient
            // contention the caller may retry, not a fault on our side. It rendered a
            // 500 while 57014 rendered a 503, so two spellings of "we waited and gave
            // up" answered differently. Unavailable, not Conflict: the STATE is fine,
            // we simply could not get the lock in time, and Conflict would assert
            // something about the state that is not true.
            DriverError::CONNECTION_FAILURE,
            DriverError::TOO_MANY_CONNECTIONS,
            DriverError::QUERY_CANCELED,
            DriverError::LOCK_NOT_AVAILABLE => ProblemType::Unavailable,

            default => ProblemType::ServerError,
        };
    }

    /**
     * How long a caller should wait before retrying this particular failure. R-070.
     *
     * WHAT R-070 FOUND. Every mapped driver error was built as `new Problem($database,
     * $instance)` with the retryAfter argument omitted, so 55P03, 57014, 08006 and 53300
     * all rendered a 503 with no retry hint -- while ShedLoad passed
     * config('shedding.readiness.cadence') on its 503 and RateLimit passed
     * availableIn($key) on its 429. The mapper's own comment for the lock-timeout case
     * said "transient contention the caller may retry" and the response said nothing
     * about when. Three of the four SQLSTATEs were pre-existing; Task 3 routed the
     * fourth into the same bucket.
     *
     * THE NUMBERS DIFFER PER CODE, which is the half of R-070's remedy that matters --
     * "a lock timeout and a saturated pool deserve different numbers":
     *
     *   55P03 lock_timeout        1s   Another transaction held the row for the 2s the
     *                                  app role waits (§2.1). Whoever held it is
     *                                  finishing a single keyed UPDATE, so the contention
     *                                  is over in well under a second and retrying fast
     *                                  is right.
     *   57014 query canceled      2s   statement_timeout, 5s (§2.1). Something was slow
     *                                  rather than contended, so give it longer than a
     *                                  lock but not long.
     *   08006 connection failure 10s   Measured at Task 2: this is ALSO how a saturated
     *                                  role CONNECTION LIMIT arrives (the 41st
     *                                  qrpager_app connection). An immediate retry adds
     *                                  to the saturation it is reporting.
     *   53300 too many conns     10s   The same condition named explicitly.
     *
     * IT IS ADVISORY AND IT IS NOT THE CONTROL. §4 › Realtime requires the CLIENT to use
     * a jittered interval with exponential backoff, which no server header replaces --
     * every caller obeying the same number is a thundering herd with a schedule. The
     * hint exists so that a client has somewhere sane to start, and because a 503 that
     * says nothing about when is the one answer in this project's envelope that leaves
     * the caller guessing.
     *
     * Null for everything that is not a "come back later": a 409, a 422 and a 500 have
     * no retry interval to advertise, and inventing one would tell a caller that
     * repeating an invalid request might work.
     */
    public function retryAfter(Throwable $error): ?int
    {
        return match (DriverError::sqlState($error)) {
            DriverError::LOCK_NOT_AVAILABLE => 1,
            DriverError::QUERY_CANCELED => 2,
            DriverError::CONNECTION_FAILURE,
            DriverError::TOO_MANY_CONNECTIONS => 10,
            default => null,
        };
    }
}
