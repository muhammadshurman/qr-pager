<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;
use Throwable;

/**
 * Support access could not be taken, or could not be released. `QRPAGER-22`.
 *
 * The reasons are a closed set and each is a clause of `§4 › Tenancy` (2026-11-04):
 *
 *   `NOT_A_PLATFORM_ADMIN`  only a platform admin may take one at all
 *   `ALREADY_HOLDS_ONE`     *"never holds more than one at a time"*
 *   `ALREADY_A_MEMBER`      the admin already belongs to this restaurant for real, so
 *                           support is not the mechanism — they have abilities already
 *   `NO_REASON`             *"written to the audit trail with the tenant, the actor and the
 *                           reason"*, so a take with no reason is refused before anything
 *                           exists to audit
 *   `NOT_HELD`              released without holding one
 *
 * ⚠ **THE REASON IS A CONSTANT AND NOT PART OF THE MESSAGE**, the shape
 * `OwnerNeedsConsent` established: the panel can say something useful while the message
 * itself stays free of anything about another tenant or another account.
 */
final class SupportAccessRefused extends RuntimeException
{
    public const NOT_A_PLATFORM_ADMIN = 'not_a_platform_admin';

    public const ALREADY_HOLDS_ONE = 'already_holds_one';

    public const ALREADY_A_MEMBER = 'already_a_member';

    public const NO_REASON = 'no_reason';

    public const NOT_HELD = 'not_held';

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(match ($reason) {
            self::NOT_A_PLATFORM_ADMIN => 'Only a platform administrator can take support access.',
            self::ALREADY_HOLDS_ONE => 'Release the support access you already hold first. Only one is allowed at a time.',
            self::ALREADY_A_MEMBER => 'You already belong to this restaurant, so support access would add nothing.',
            self::NO_REASON => 'Support access needs a reason, which is written to the audit trail.',
            self::NOT_HELD => 'There is no support access to release.',
            default => 'Support access was refused.',
        }, 0, $previous);
    }
}
