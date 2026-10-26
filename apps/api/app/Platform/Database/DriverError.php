<?php

declare(strict_types=1);

namespace App\Platform\Database;

use PDOException;
use Throwable;

/**
 * The SQLSTATE a failed statement actually carried.
 *
 * `PLAN.md §6 Open items`: "QueryException::getCode() is not reliably the SQLSTATE; the
 * real code is on the PDO exception underneath, at getPrevious()->errorInfo[0]."
 *
 * Measured in this task's phase-1 research, which is what makes "not reliably" precise:
 * for ordinary statement errors the two agree -- 42P01, 22P02 and 22012 all came back
 * identical. For a CONNECTION-level failure they do not. A refused connection and a
 * wrong password both gave getCode() = the integer **7** while errorInfo[0] was
 * **08006**. So anything built on getCode() reports "SQLSTATE 7", which is not a
 * SQLSTATE at all, for exactly the failures an operator most needs named -- and 08006
 * is also how a saturated role limit arrives (40/40 refuses the 41st connection).
 *
 * tests/TestCase::sqlState() has had this shape since Task 1; this is the production
 * half, so the two cannot drift.
 */
final class DriverError
{
    /** RLS denied the row (PROJECT_INSTRUCTIONS.md §4 › Postgres traps). */
    public const RLS_DENIED = '42501';

    public const UNIQUE_VIOLATION = '23505';

    public const CHECK_VIOLATION = '23514';

    public const FOREIGN_KEY_VIOLATION = '23503';

    /** Connection failure, and how a role's CONNECTION LIMIT surfaces at the client. */
    public const CONNECTION_FAILURE = '08006';

    public const QUERY_CANCELED = '57014';

    public const TOO_MANY_CONNECTIONS = '53300';

    public const INVALID_TEXT_REPRESENTATION = '22P02';

    /**
     * Null when the throwable carries no driver error at all, which must never be
     * confused with "a code we do not map": a caller that cannot tell the difference
     * maps an application bug to a database outcome.
     */
    public static function sqlState(Throwable $error): ?string
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && is_array($current->errorInfo)) {
                $state = $current->errorInfo[0] ?? null;

                if (is_string($state) && $state !== '') {
                    return $state;
                }
            }
        }

        return null;
    }

    /**
     * The code for a message, with the shape of a SQLSTATE or nothing.
     *
     * Five `TenantContextException::statementFailed()` call sites and
     * `RoleBootstrapper::withoutLeakingCredentials()` used `(string) $e->getCode()`,
     * which prints "7" on a connectivity failure. This is what they use instead: a
     * real SQLSTATE, or an empty string so the message omits the clause rather than
     * stating a wrong code (R-013).
     */
    public static function sqlStateForMessage(Throwable $error): string
    {
        $state = self::sqlState($error);

        if ($state !== null) {
            return $state;
        }

        $code = $error->getCode();

        // A SQLSTATE is five alphanumerics. Anything else -- PDO's integer 7, or a
        // RuntimeException's 0 -- is not one and is not reported as one.
        return is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code) === 1 ? $code : '';
    }
}
