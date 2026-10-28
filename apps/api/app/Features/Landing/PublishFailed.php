<?php

declare(strict_types=1);

namespace App\Features\Landing;

use RuntimeException;

/**
 * A publish that refused, with a reason §12.1's copy can show:
 * "Publish failed — your live page is unchanged. {reason}".
 *
 * THE SECOND HALF OF THAT SENTENCE IS A PROMISE THIS CLASS'S EXISTENCE MAKES. Every
 * throw site is before or at the pointer write, so a publish that raises this has left
 * the previous page serving (§6.6, decision D8). LandingPublishTest drives the failure
 * paths and asserts exactly that.
 */
final class PublishFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
