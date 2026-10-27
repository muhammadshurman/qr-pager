<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;

/**
 * A problem document for a request that failed validation: the four usual members, plus
 * the per-field messages.
 *
 * `errors` maps a field name to the list of things wrong with it.
 */
final class ValidationProblemDocument extends ProblemDocument
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        ProblemType $type,
        string $title,
        int $status,
        ?string $instance,
        public readonly array $errors,
    ) {
        parent::__construct($type, $title, $status, $instance);
    }
}
