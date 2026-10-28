<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use App\Domain\Audit\AuditActorType;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use App\Platform\Database\DriverError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * S1 — the platform admin creates a new restaurant.
 *
 * ================================================================================
 * THE SPLIT THIS CLASS EXISTS TO ENFORCE, AND WHY IT IS TWO METHODS AND NOT ONE
 * ================================================================================
 *
 * The ruling of 2026-10-28 (`QRPAGER-13 §0`, rank 1): *a panel query that reaches a tenant
 * table is a rejected design.* Creating a restaurant obviously has to write `tenants` and
 * `tenant_users`, which are both tenant tables — so the write cannot happen on the panel's
 * request, and the two halves are deliberately not callable as one:
 *
 *   `request()`   runs ON the panel's request. Touches `tenant_provisionings` and
 *                 `platform_audit_log` and NOTHING ELSE. Both are platform tables, so it
 *                 needs no tenant context and the scope guard does not refuse it.
 *   `provision()` runs OUT OF BAND, from the job. Does the tenant-side work inside the new
 *                 tenant's own context.
 *
 * A single synchronous method would have been simpler and would have put `insert into
 * tenants` on the panel's request, which is the one thing the named invariant forbids. That
 * is the alternative that was rejected, and it is rejected for the invariant rather than for
 * taste.
 *
 * ================================================================================
 * WHY THE JOB'S CONTEXT IS NOT A BYPASS — the half of `§6` Q3 that asks by name
 * ================================================================================
 *
 * `provision()` sets `app.tenant_id` to **the id it is about to insert**. That reads like a
 * trick and is not: `tenants`' isolation policy is `id = ctx`, so `insert into tenants (id)
 * values (ctx)` satisfies `WITH CHECK` exactly, and `tenant_users`' is `tenant_id = ctx`.
 * `tests/TestCase::makeTenant()` has documented this as "the real tenant-creation path"
 * since Task 1 — *context is set to the id about to be inserted, so even this needs no
 * bypass*.
 *
 * What makes it not a general bypass, in the order a reviewer should check:
 *
 *   1. **`app.bypass_tenant` is never set here.** The only bypass policies in the schema are
 *      `owner_bypass_*`, targeted at `qrpager_owner` alone, which
 *      `RlsCoverageTest::it('targets every bypass policy at the owner alone')` pins.
 *   2. **The context is ONE id, transaction-scoped** (`set_config(..., true)` — C2), and it
 *      is the id of the tenant being created. So this method's reach is exactly one tenant,
 *      the new one, and it can read or write no other tenant's rows at any point — which a
 *      real bypass would destroy and which `TenantProvisioningTest` drives directly rather
 *      than taking from this comment.
 *   3. **It needs no grant that `qrpager_app` did not already hold.** `§2.7` has given this
 *      role `INSERT (id, name, slug, status, timezone, created_at, updated_at)` on `tenants`
 *      and `SELECT, INSERT, DELETE` on `tenant_users` since Task 1. **Task 6 widens no grant
 *      on any tenant table**, and `GrantMatrixTest` pins the whole matrix as exact sets.
 *
 * The role is `app` and not `worker` because the app role is the only one the contract gives
 * INSERT on those two tables; granting them to the worker would widen a role whose whole
 * value is that it cannot do them. It is `app` and not `owner` because `§4 › Tenancy` makes
 * owner migrations-only.
 */
final class TenantProvisioning
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * The panel's half. Platform tables only.
     *
     * Returns the id of the new provisioning row, which is also the id the tenant will
     * carry — see the migration's note on why those are the same column.
     *
     * @throws DuplicateSlug when the slug is already registered
     */
    public function request(
        string $name,
        string $slug,
        string $ownerEmail,
        ?string $requestedByUserId,
        ?string $actorIp = null,
    ): string {
        $tenantId = (string) Str::uuid7();

        // Lower-cased here as well as CHECKed in the database, because `users.email` is
        // stored lower-cased (R-007) and the job compares against it. Two sides agreeing
        // rather than one trusting the other.
        $ownerEmail = Str::lower($ownerEmail);

        try {
            // ONE transaction for both rows. A provisioning request with no audit row, or
            // an audit row for a request that is not there, are both states this makes
            // unrepresentable.
            DB::transaction(function () use ($tenantId, $name, $slug, $ownerEmail, $requestedByUserId, $actorIp): void {
                DB::insert(
                    'insert into tenant_provisionings
                        (id, name, slug, owner_email, status, requested_by_user_id, created_at, updated_at)
                     values (?, ?, ?, ?, ?::provisioning_status, ?, now(), now())',
                    [$tenantId, $name, $slug, $ownerEmail, ProvisioningStatus::Pending->value, $requestedByUserId],
                );

                $this->audit->record(
                    PlatformAudit::TENANT_REQUESTED,
                    $requestedByUserId,
                    'tenant_provisioning',
                    $tenantId,
                    // The slug and the name, which are platform identity. NOT the owner's
                    // address: an audit row is read by every platform admin and an email is
                    // personal data that the provisioning row already carries once.
                    ['slug' => $slug, 'name' => $name],
                    $actorIp,
                );
            });
        } catch (Throwable $error) {
            // 23505 is the only outcome here that is a user's mistake rather than a fault,
            // and it is mapped on the CONSTRAINT NAME rather than on the code alone: a
            // future unique index on this table would otherwise be reported as a duplicate
            // slug. The driver message never reaches the caller — it carries the SQL and the
            // bound parameters, which is `§4 › Edge & ops`.
            if (DriverError::sqlState($error) === DriverError::UNIQUE_VIOLATION
                && str_contains($error->getMessage(), 'tenant_provisionings_slug_unique')) {
                throw new DuplicateSlug($slug);
            }

            throw $error;
        }

        return $tenantId;
    }

    /**
     * The job's half. Creates the tenant, inside the tenant's own context.
     *
     * Idempotent in the way that matters: a second run against an already-provisioned id
     * raises 23505 on `tenants_pkey`, which is reported as already-provisioned rather than
     * as a failure, so a redelivered job leaves one tenant and a `completed` row.
     *
     * @return bool true when this call created the tenant, false when it was already there
     */
    public function provision(string $tenantId): bool
    {
        $row = DB::selectOne(
            'select id, name, slug, owner_email, status, attempts
               from tenant_provisionings where id = ?',
            [$tenantId],
        );

        if ($row === null) {
            throw new ProvisioningRecordMissing($tenantId);
        }

        $status = ProvisioningStatus::from((string) $row->status);

        // Already done. Not an error, and not a second tenant: this is the redelivery path.
        if ($status === ProvisioningStatus::Completed) {
            return false;
        }

        if (! $status->allowsMoveTo(ProvisioningStatus::Running)) {
            throw new ProvisioningTransitionDenied($status, ProvisioningStatus::Running);
        }

        // `running` and the attempt counter, committed BEFORE the tenant-side work, so a
        // process killed mid-flight leaves evidence that it tried. `attempts` is what stops
        // `running -> pending -> running` being an unbounded loop.
        DB::update(
            'update tenant_provisionings
                set status = ?::provisioning_status, attempts = attempts + 1, updated_at = now()
              where id = ? and status = ?::provisioning_status',
            [ProvisioningStatus::Running->value, $tenantId, $status->value],
        );

        try {
            // THE WHOLE TENANT IN ONE TRANSACTION, inside one context. This is the clause
            // "the wizard is transactional — a failure halfway leaves no partial tenant",
            // and it is one transaction rather than three statements that each succeed or
            // fail: a half-created tenant is not representable, not merely unlikely.
            withTenant($tenantId, function () use ($tenantId, $row): void {
                DB::insert(
                    'insert into tenants (id, name, slug, status, timezone, created_at, updated_at)
                     values (?, ?, ?, ?::tenant_status, ?, now(), now())',
                    [$tenantId, $row->name, $row->slug, TenantStatus::Active->value, 'UTC'],
                );

                $ownerUserId = $this->resolveOwner((string) $row->owner_email);

                DB::insert(
                    'insert into tenant_users (id, tenant_id, user_id, role, created_at, updated_at)
                     values (?, ?, ?, ?::tenant_role, now(), now())',
                    [(string) Str::uuid7(), $tenantId, $ownerUserId, TenantRole::Owner->value],
                );

                // The TENANT's own audit row, which is a different thing from the platform
                // one and belongs to the tenant: it is the first entry in the restaurant's
                // own history. tenant_id is set, so the isolation policy is satisfied — this
                // is the row shape that works, unlike the NULL-tenant one (SR-3).
                DB::insert(
                    'insert into audit_log
                        (tenant_id, actor_type, action, subject_type, subject_id, context)
                     values (?, ?::audit_actor_type, ?, ?, ?, ?::jsonb)',
                    [
                        $tenantId,
                        AuditActorType::Platform->value,
                        'tenant.provisioned',
                        'tenant',
                        $tenantId,
                        json_encode(['slug' => $row->slug], JSON_THROW_ON_ERROR),
                    ],
                );
            });
        } catch (Throwable $error) {
            // The tenant insert losing a race with another runner. One tenant exists, which
            // is the desired end state, so this is not a failure.
            if (DriverError::sqlState($error) === DriverError::UNIQUE_VIOLATION
                && str_contains($error->getMessage(), 'tenants_pkey')) {
                $this->markCompleted($tenantId, (string) $row->slug);

                return false;
            }

            $this->markFailed($tenantId, $error);

            throw $error;
        }

        $this->markCompleted($tenantId, (string) $row->slug);

        return true;
    }

    /**
     * The owner's `users` row, created if this is a new person.
     *
     * `users` is GLOBAL and carries no RLS (`§2.4`), so this read and this write are not
     * tenant-scoped and are not affected by the context around them. Stated because the
     * surrounding block is inside `withTenant()` and a reader would reasonably wonder.
     *
     * No password is set. A provisioned owner has no credential until they set one — this
     * project has no self-service registration at any tier (`§2.4`), and a password this
     * method invented would be a credential nobody asked for and nobody can rotate.
     */
    private function resolveOwner(string $email): string
    {
        $existing = DB::selectOne('select id from users where email = ?', [$email]);

        if ($existing !== null) {
            return (string) $existing->id;
        }

        $id = (string) Str::uuid7();

        DB::insert(
            'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
            [$id, $this->nameFromEmail($email), $email],
        );

        return $id;
    }

    /** A placeholder display name, which the owner replaces when they first sign in. */
    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::limit(Str::headline($local), 120, '');
    }

    private function markCompleted(string $tenantId, string $slug): void
    {
        DB::transaction(function () use ($tenantId, $slug): void {
            DB::update(
                'update tenant_provisionings
                    set status = ?::provisioning_status, completed_at = now(),
                        failure_reason = null, updated_at = now()
                  where id = ?',
                [ProvisioningStatus::Completed->value, $tenantId],
            );

            $this->audit->record(
                PlatformAudit::TENANT_PROVISIONED,
                null,
                'tenant_provisioning',
                $tenantId,
                ['slug' => $slug],
            );
        });
    }

    private function markFailed(string $tenantId, Throwable $error): void
    {
        // A MAPPED REASON AND NEVER THE DRIVER MESSAGE. `failure_reason` is rendered in the
        // panel, so a driver message here would put the SQL and the bound parameters on a
        // screen — which is exactly `R-007`/`R-021`'s shape and what `§4 › Edge & ops`
        // forbids at any APP_DEBUG setting. The SQLSTATE is the whole vocabulary.
        $reason = $this->reasonFor($error);

        // Its OWN transaction, deliberately. The caller's has already rolled back by the
        // time this runs, so writing the failure inside it would discard the only record
        // that the attempt happened.
        DB::transaction(function () use ($tenantId, $reason): void {
            DB::update(
                'update tenant_provisionings
                    set status = ?::provisioning_status, failure_reason = ?, updated_at = now()
                  where id = ?',
                [ProvisioningStatus::Failed->value, $reason, $tenantId],
            );

            $this->audit->record(
                PlatformAudit::TENANT_PROVISION_FAILED,
                null,
                'tenant_provisioning',
                $tenantId,
                ['reason' => $reason],
            );
        });
    }

    private function reasonFor(Throwable $error): string
    {
        return match (DriverError::sqlState($error)) {
            DriverError::UNIQUE_VIOLATION => 'A restaurant with this slug or owner already exists.',
            DriverError::CHECK_VIOLATION => 'A value failed a database constraint.',
            DriverError::FOREIGN_KEY_VIOLATION => 'A referenced record is missing.',
            DriverError::RLS_DENIED => 'The provisioning role was refused by row level security.',
            DriverError::CONNECTION_FAILURE, DriverError::TOO_MANY_CONNECTIONS => 'The database was unreachable.',
            DriverError::QUERY_CANCELED, DriverError::LOCK_NOT_AVAILABLE => 'The database timed out.',
            // Deliberately not the exception message. "Unexpected" with a SQLSTATE of null
            // means an application fault rather than a database outcome, and the two must
            // never be reported as the same thing (DriverError's own docblock).
            default => 'Provisioning failed for an unexpected reason.',
        };
    }
}
