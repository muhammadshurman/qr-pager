<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;

/**
 * One row of "a user's own memberships" — §4 › Tenancy's second named cross-tenant path,
 * as it reaches the application.
 *
 * A value object in App\Domain: no framework, no database. It is what
 * public.user_memberships(uuid) returns, one row at a time, and nothing more. In
 * particular it carries NO user id — the set it belongs to is already scoped to one
 * authenticated user, and a user id on the row would invite a second lookup keyed by
 * something other than the guard.
 */
final readonly class Membership
{
    public function __construct(
        public string $tenantId,
        public TenantRole $role,
        public TenantStatus $tenantStatus,
        public string $tenantSlug,
        /**
         * The restaurant's own name — ADDED AT TASK 10b, `R-222`.
         *
         * It is here and not only on `GET /v1/tenant` because the staff restaurant chooser
         * runs BEFORE a tenant is selected, so there is no scope to read a name inside. The
         * slug was standing in for it and is a DNS label, which `§12`'s
         * zero-technical-background bar forbids putting on a screen unexplained.
         *
         * `$tenantSlug` stays, and is not deprecated: it is this row's stable identifier in
         * a log line or a support conversation, where a name is ambiguous between two
         * branches of the same restaurant. What changed is which one a SCREEN shows.
         */
        public string $tenantName = '',
    ) {}

    /**
     * Whether this membership can authenticate a request at all — R-081.
     *
     * A suspended tenant's staff member holds a real membership with a real role, and
     * every one of those is irrelevant: the tenant is not active, so no request resolves.
     * Kept here, on the value object, rather than as an `if` in the resolver, so that
     * every future reader of a membership has the question in front of them.
     */
    public function tenantIsActive(): bool
    {
        return $this->tenantStatus === TenantStatus::Active;
    }
}
