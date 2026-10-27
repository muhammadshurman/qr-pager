<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use RuntimeException;

/**
 * No session with that id is visible in the current tenant scope.
 *
 * "NOT VISIBLE", not "does not exist", and the difference is the IDOR answer. Inside a
 * tenant scope another tenant's row is invisible to row level security, so a staff member
 * of tenant A asking for tenant B's session id gets exactly this -- and it renders as
 * **404 NotFound, never 403**. A 403 would confirm the id exists, which is the leak the
 * gate clause "cross-tenant IDOR blocked through HTTP, not just at the DB" is about.
 *
 * So the two cases are deliberately indistinguishable from outside, and that is why this
 * exception carries no tenant id and no reason: there is nothing to tell apart.
 *
 * In App\Domain for the same reason as PagerTransitionDenied: ProblemRenderer lives in
 * App\Platform and deptrac forbids Platform from importing Features.
 */
final class PagerSessionNotFound extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'No pager session with that id is visible in the current tenant scope. '
            .'A session belonging to another tenant is invisible rather than forbidden, '
            .'which is why this is not an authorization failure.'
        );
    }
}
