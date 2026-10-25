<?php

declare(strict_types=1);

use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\TenantContext;

/**
 * Run $callback in one transaction with app.tenant_id set (PLAN.md §2.2).
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function withTenant(string $tenantId, Closure $callback, ?string $connection = null): mixed
{
    return app(TenantContext::class)->run($tenantId, $callback, $connection);
}

/**
 * The named, audited, owner-only cross-tenant escape (PLAN.md §2.10).
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function withoutTenantScope(string $reason, Closure $callback, ?string $connection = null): mixed
{
    return app(TenantContext::class)->withoutTenantScope($reason, $callback, $connection);
}

/**
 * Refuse to serve traffic as a role that can see past row level security
 * (PROJECT_INSTRUCTIONS.md §4 › Tenancy). Task 2 calls this at boot.
 */
function assertSafeRuntimeRole(?string $connection = null): void
{
    app(RuntimeRoleGuard::class)->assertSafe($connection);
}
