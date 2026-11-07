<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use App\Domain\Provisioning\ProvisioningStatus;
use RuntimeException;

/**
 * The transition table on ProvisioningStatus refused this move.
 *
 * The same shape as PagerTransitionDenied: one source for what is legal, and a named
 * exception so a caller can tell an illegal transition from a database refusal.
 */
final class ProvisioningTransitionDenied extends RuntimeException
{
    public function __construct(
        public readonly ProvisioningStatus $from,
        public readonly ProvisioningStatus $to,
    ) {
        parent::__construct("A provisioning record cannot move from [{$from->value}] to [{$to->value}].");
    }
}
