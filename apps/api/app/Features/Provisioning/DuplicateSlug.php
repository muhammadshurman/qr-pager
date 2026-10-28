<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use RuntimeException;

/**
 * The slug is already registered to another restaurant.
 *
 * Carries the slug and nothing else. The constraint name and the driver message stay out
 * of it: both name schema detail, and a message that reaches a panel field error is a
 * message that reaches a screen (§4 › Edge & ops).
 */
final class DuplicateSlug extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct('That address is already taken by another restaurant.');
    }
}
