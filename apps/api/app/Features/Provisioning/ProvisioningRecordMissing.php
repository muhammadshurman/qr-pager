<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use RuntimeException;

/**
 * The job was handed an id with no provisioning row behind it.
 *
 * An application fault rather than a database outcome, which is why it is its own type: a
 * job that silently returned here would make a lost provisioning record look like a
 * successful no-op.
 */
final class ProvisioningRecordMissing extends RuntimeException
{
    public function __construct(public readonly string $tenantId)
    {
        parent::__construct('No provisioning record exists for that id.');
    }
}
