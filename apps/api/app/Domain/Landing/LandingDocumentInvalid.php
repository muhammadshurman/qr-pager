<?php

declare(strict_types=1);

namespace App\Domain\Landing;

use RuntimeException;

/**
 * One named refusal from LandingDocument::fromInput().
 *
 * CARRIES THE FIELD PATH AND THE REASON SEPARATELY, because §12.1's copy needs both:
 * "{Field} can't be published: {reason}". A single message string would have the panel
 * parsing its own error text to render that line.
 *
 * It carries NO VALUE. The refusing input is tenant-authored and goes into a problem
 * document, a log line or an inline error, and §4 › Edge & ops is unconditional about
 * what may appear there. Echoing the rejected string back is also the oldest reflected-XSS
 * shape there is, and this is the class whose whole job is to refuse that string.
 */
final class LandingDocumentInvalid extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
    ) {
        parent::__construct("{$field}: {$reason}");
    }
}
