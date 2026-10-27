<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerToken;

/**
 * A session just created, with the one and only copy of its raw token.
 *
 * The token is a PagerToken rather than a string all the way to the controller, so the
 * value stays unloggable in transit: this object serialises with the token redacted
 * because PagerToken does, and reaching the token takes a reveal() call that greps.
 *
 * There is no path that reads a token back later -- token_hash is a one-way column the
 * app role cannot even reference -- so a lost response is a lost session, by design.
 */
final class IssuedPagerSession
{
    public function __construct(
        public readonly PagerSessionState $session,
        public readonly PagerToken $token,
    ) {}
}
