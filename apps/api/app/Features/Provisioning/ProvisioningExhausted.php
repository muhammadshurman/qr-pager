<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use RuntimeException;

/**
 * The tenant-side work has been attempted `MAX_ATTEMPTS` times and is not attempted again.
 *
 * QA H-2: `attempts` was incremented, selected, and read by nothing, while three documents
 * claimed "retries bounded by `attempts`" — `R-058`'s shape (a column selected and never
 * read) repeated in new code. This is the bound existing.
 *
 * A named type rather than a generic failure, because the operator's next step differs: an
 * exhausted row is not waiting for the scheduler, it is waiting for a person to look at
 * `failure_reason` and then reclaim it with `platform:provision-tenants --retry-stalled`.
 */
final class ProvisioningExhausted extends RuntimeException
{
    public function __construct(public readonly string $tenantId, public readonly int $attempts)
    {
        parent::__construct(
            'Provisioning has been attempted '.$attempts.' times and will not be retried automatically.'
        );
    }
}
