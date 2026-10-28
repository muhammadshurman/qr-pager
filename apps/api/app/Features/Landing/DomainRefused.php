<?php

declare(strict_types=1);

namespace App\Features\Landing;

use RuntimeException;

/** A domain action the service refused, with a reason §12.3 can show. */
final class DomainRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
