<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;

/**
 * How a request's tenant is decided. One method, and it may answer null.
 *
 * The interface lives in Platform and the only implementation lives in
 * App\Platform\Auth, which deptrac keeps out of reach of every feature module: a
 * feature that built on the pre-Task-5 stub would be a feature that breaks when real
 * authentication arrives.
 *
 * Task 5 replaces the implementation and nothing else. RequireTenantScope, the rate
 * limiter's per-tenant key and the tenant-scope guard all speak to this interface, so
 * the shape of "which tenant is this request" is settled now and the answer is Task 5's.
 */
interface TenantResolver
{
    /** The tenant this request belongs to, or null when it cannot be decided. */
    public function resolve(Request $request): ?string;
}
