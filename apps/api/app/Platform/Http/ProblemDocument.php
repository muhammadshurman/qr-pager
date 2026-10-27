<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;

/**
 * An RFC 7807 problem document: the four members every error response carries.
 *
 * `type` is the contractual part -- a stable URI from a published vocabulary, which a
 * client may switch on. `title` is a fixed human-readable phrase for that type, `status`
 * repeats the HTTP status, and `instance` is the path the request was made to.
 */
class ProblemDocument
{
    public function __construct(
        public readonly ProblemType $type,
        public readonly string $title,
        public readonly int $status,
        public readonly ?string $instance,
    ) {}
}
