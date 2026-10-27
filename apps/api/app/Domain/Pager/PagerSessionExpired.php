<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use DomainException;

/**
 * A staff transition refused because the session's TTL has passed.
 *
 * WHY THIS IS NOT PagerTransitionDenied. Both are 409s and both mean "the state makes
 * this impossible", but they are different states and a client has to be able to act on
 * the difference: an illegal transition is a staff mistake to correct, an expired session
 * is a session to re-issue. The envelope's `type` is the contractual discriminator --
 * "a client may switch on type" is ProblemType's own wording -- so the distinction is
 * published as ProblemType::Expired rather than left in a message no body carries.
 *
 * ONLY A LIVE STATUS CAN BE PAST ITS TTL, which is the whole of why this check is
 * conditional. `expires_at` recedes into the past for every session that ever existed, so
 * an unconditional check would refuse `complete` on an order collected last week and
 * break the idempotency the gate requires. A terminal session is finished, not expired;
 * PagerStatus::isLive() is the test and it is the same one the expiry job's policy
 * whitelist uses.
 *
 * The message names our own status values and nothing else, so it is safe for a log --
 * and it still never reaches a body: Expired's title is a fixed phrase.
 */
final class PagerSessionExpired extends DomainException
{
    private function __construct(string $message, public readonly PagerStatus $from)
    {
        parent::__construct($message);
    }

    public static function pastTtl(PagerStatus $from): self
    {
        return new self(
            "A pager session in [{$from->value}] is past its TTL and accepts no further "
            .'staff transition. Only the expiry job may move it, and only to [expired].',
            $from,
        );
    }
}
