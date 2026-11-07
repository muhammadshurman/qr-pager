<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\MediaReferences;
use Illuminate\Support\Facades\DB;

/**
 * The real MediaReferences: a `media_assets` lookup for ONE tenant.
 *
 * CONSTRUCTED WITH A TENANT ID AND NEVER WITH A REQUEST. The id it holds is the one whose
 * scope the caller has already opened, so `has()` runs inside that transaction and RLS
 * narrows it as well -- the `where tenant_id` is belt to the policy's braces, not the
 * control. A class that resolved the tenant itself would be a second answer to "which
 * tenant is this", which is the property RequireTenantScope's docblock depends on.
 *
 * It is deliberately NOT a cache. A document is validated once per save and once per
 * publish, against at most 24 ids, and a stale "yes" here is a published page pointing at
 * a deleted asset.
 */
final class TenantMediaReferences implements MediaReferences
{
    public function __construct(private readonly string $tenantId) {}

    public function has(string $mediaId): bool
    {
        return DB::table('media_assets')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $mediaId)
            ->exists();
    }
}
