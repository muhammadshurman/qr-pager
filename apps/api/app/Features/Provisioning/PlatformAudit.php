<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use Illuminate\Support\Facades\DB;

/**
 * The platform's own audit trail: what a platform admin did to a platform record.
 *
 * THIS CLASS EXISTS BECAUSE `audit_log` MEASURABLY CANNOT SERVE IT, which is worth stating
 * where the writes happen rather than only in the migration. `audit_log` is a tenant table
 * under FORCE RLS, a platform-scope row is `tenant_id` NULL, and `tenant_isolation_audit_log`
 * compares `tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid` -- where
 * `NULL = anything` is `NULL` and never true. Measured at Task 6's Phase 0: the insert is
 * refused `42501` for `qrpager_app` with AND without a context and for `qrpager_owner`
 * without the bypass, and a committed row reads back as 0 rows for the app role either way.
 * Only the owner with `app.bypass_tenant = 'on'` can write or read one, which is a
 * migration-time posture and not a request-time one.
 *
 * So `§3` row 6's "platform admin actions all land in `audit_log`" was never reachable by a
 * serving process, and `platform_audit_log` is where they land instead (`QRPAGER-13 §0` SR-3).
 *
 * EVERY WRITE HERE IS CALLED INSIDE ITS CALLER'S TRANSACTION, never after it. An audit row
 * committed separately from the change it records is a row that can exist without the change
 * or the change without the row, and both are worse than no row at all.
 *
 * NO TENANT CONTEXT IS SET, AND THAT IS THE POINT: this is a platform table with no RLS, so
 * a context would narrow nothing and setting one would imply a tenant boundary that is not
 * there.
 */
final class PlatformAudit
{
    /**
     * Actions this trail records. A closed set rather than a free string, so a typo is a
     * PHP error rather than a row nobody will ever query for.
     */
    public const TENANT_REQUESTED = 'platform.tenant.requested';

    public const TENANT_PROVISIONED = 'platform.tenant.provisioned';

    public const TENANT_PROVISION_FAILED = 'platform.tenant.provision_failed';

    /** @param array<string, scalar|null> $context */
    public function record(
        string $action,
        ?string $actorUserId,
        ?string $subjectType,
        ?string $subjectId,
        array $context = [],
        ?string $actorIp = null,
    ): void {
        DB::insert(
            // An explicit column list. `id` and `created_at` are not granted to this role
            // and are not ours to write: an identity column and a creation timestamp are
            // the database's (§2.7's rule for every table here).
            'insert into platform_audit_log
                (actor_user_id, action, subject_type, subject_id, context, ip_address)
             values (?, ?, ?, ?, ?::jsonb, ?::inet)',
            [
                $actorUserId,
                $action,
                $subjectType,
                $subjectId,
                json_encode($context, JSON_THROW_ON_ERROR),
                $actorIp,
            ],
        );
    }
}
