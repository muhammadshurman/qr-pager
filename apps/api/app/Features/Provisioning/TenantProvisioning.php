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
    /**
     * How many times the tenant-side work may be attempted before the row is left alone.
     *
     * Three, because the failures this bounds are a transient cluster problem (worth a
     * retry) or a permanent one (worth stopping). A row that reaches the cap is terminal
     * until an operator reclaims it with `platform:provision-tenants --retry-stalled`,
     * which resets `attempts` deliberately rather than letting a loop run.
     */
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * Why this address may not be made the first owner, or null if it may.
     *
     * ONE RULE, TWO CALLERS, and that is the point of it being public. The FORM calls it so
     * a platform admin is told at the moment they type the address; `provision()` calls it
     * again because the answer can change between the request and the drain, and the drain
     * is the authoritative one. A rule enforced in the form alone would be a rule any other
     * caller of `request()` could walk around.
     *
     * `user_memberships()` is the `SECURITY DEFINER` reader `§4 › Tenancy`'s second named
     * path already licenses — "a user's own memberships". It takes one exact-match argument,
     * turns the bypass on for its own body only and restores it before returning, so this
     * adds no cross-tenant path: it uses the one that exists.
     *
     * @return OwnerNeedsConsent::ALREADY_A_MEMBER|OwnerNeedsConsent::PLATFORM_ADMIN|null
     */
    public function refusalForOwnerEmail(string $email): ?string
    {
        $existing = DB::selectOne(
            'select id, is_platform_admin from users where email = ?',
            [Str::lower($email)],
        );

        if ($existing === null) {
            return null;
        }

        if ((bool) $existing->is_platform_admin) {
            return OwnerNeedsConsent::PLATFORM_ADMIN;
        }

        return DB::select('select tenant_id from public.user_memberships(?)', [(string) $existing->id]) === []
            ? null
            : OwnerNeedsConsent::ALREADY_A_MEMBER;
    }

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

        // ENFORCED HERE AS WELL AS ON THE FORM (security M-2). The form's rule is for the
        // human; this is for the contract — `request()` is a public method and a second
        // caller must not be able to create a provisioning row whose drain is guaranteed to
        // fail on a rule it never saw.
        $refusal = $this->refusalForOwnerEmail($ownerEmail);

        if ($refusal !== null) {
            throw new OwnerNeedsConsent($refusal);
        }

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

        // ⚠ A REAL CAP (QA H-2). `attempts` was incremented, selected and read by NOTHING,
        // while three documents said "retries bounded by `attempts`" — which is `R-058`'s
        // shape (a column selected and never read) in new code. It is read here now, so a
        // row that keeps failing stops rather than being retried for ever.
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            throw new ProvisioningExhausted($tenantId, (int) $row->attempts);
        }

        // ⚠ THE AFFECTED-ROW COUNT IS THE IDEMPOTENCY SIGNAL, and discarding it was QA L-9.
        // `§4 › Postgres traps` prescribes this exact shape — "a policy-limited SELECT that
        // reads the keys → UPDATE … WHERE id = ? AND status = ? → check affected rows" —
        // and the check was the part that was missing. Without it, two concurrent drains
        // both read `pending`, both proceeded, and correctness fell to `tenants_pkey`'s
        // unique violation rather than to this guard; `attempts` was also incremented once
        // for two attempts, so the cap above would have understated them.
        //
        // `running` and the counter are committed BEFORE the tenant-side work, so a process
        // killed mid-flight leaves evidence that it tried.
        $claimed = DB::update(
            'update tenant_provisionings
                set status = ?::provisioning_status, attempts = attempts + 1, updated_at = now()
              where id = ? and status = ?::provisioning_status',
            [ProvisioningStatus::Running->value, $tenantId, $status->value],
        );

        if ($claimed === 0) {
            // Another runner took it between the SELECT and here. Not a failure and not a
            // second tenant: false means "this call did not create it".
            return false;
        }

        try {
            // THE WHOLE TENANT IN ONE TRANSACTION, inside one context. This is the clause
            // "the wizard is transactional — a failure halfway leaves no partial tenant",
            // and it is one transaction rather than three statements that each succeed or
            // fail: a half-created tenant is not representable, not merely unlikely.
            // The closure RETURNS the owner's id, so the platform audit row below can
            // record whom this platform admin made owner (security M-2) without reaching
            // back into a scope that has already closed.
            $ownerUserId = withTenant($tenantId, function () use ($tenantId, $row): string {
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

                return $ownerUserId;
            });
        } catch (Throwable $error) {
            // The tenant insert losing a race with another runner. One tenant exists, which
            // is the desired end state, so this is not a failure.
            if (DriverError::sqlState($error) === DriverError::UNIQUE_VIOLATION
                && str_contains($error->getMessage(), 'tenants_pkey')) {
                // THE LOSING SIDE OF A RACE. The tenant exists, which is the desired end
                // state, so the row becomes `completed` -- but WITHOUT an audit row, because
                // this call provisioned nothing and `platform_audit_log` is append-only
                // (QA L-9: a second `platform.tenant.provisioned` row for one tenant).
                $this->markCompleted($tenantId, (string) $row->slug, audit: false);

                return false;
            }

            $this->markFailed($tenantId, $error);

            throw $error;
        }

        $this->markCompleted($tenantId, (string) $row->slug, $ownerUserId);

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
        $existing = DB::selectOne(
            // `is_platform_admin` is readable by the app role (it holds SELECT on the
            // column; only INSERT and UPDATE were revoked at Task 5), and the membership
            // count comes from the SECURITY DEFINER reader that §4 › Tenancy's second named
            // path already licenses -- `user_memberships` turns the bypass on for its own
            // body only and takes the user id as an exact match.
            'select id, is_platform_admin from users where email = ?',
            [$email],
        );

        if ($existing !== null) {
            // RE-CHECKED AT DRAIN TIME, not trusted from the request: the account may have
            // gained a membership, or become a platform admin, in the minute between.
            $this->refuseUnlessSafeToAttach($email);

            return (string) $existing->id;
        }

        $id = (string) Str::uuid7();

        DB::insert(
            'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
            [$id, $this->nameFromEmail($email), $email],
        );

        return $id;
    }

    /**
     * Refuse to attach an EXISTING account that cannot be attached without consent.
     *
     * ================================================================================
     * SECURITY M-2, AND IT IS `R-106`'s CONSENT HALF ARRIVING THROUGH A NEW DOOR
     * ================================================================================
     *
     * `resolveOwner()` matched on `users.email` and, on a hit, made that account **owner**
     * of the new tenant: no consent, no invite, no check of who it was. Two measured
     * consequences, and the first is the worse one:
     *
     *   1. **It can strand a victim's account.** `AuthController::login()` auto-selects a
     *      tenant only when the account holds exactly ONE membership. A staff member who
     *      held one now holds two, resolves none, and every `/v1` call answers 401 until a
     *      client implements tenant selection — **which is Task 10**. That is the exact
     *      denial-of-service edge `R-106` records, and a platform admin could reach it by
     *      typing a colleague's address into this form.
     *   2. **A platform admin could mint itself a tenant membership** by typing its own
     *      address — which the 2026-10-28 ruling says is "a temporary audited membership and
     *      is **not built here**".
     *
     * WHAT THIS REFUSES, and the two cases are deliberately narrow: an account that already
     * holds any membership, and a platform admin. A brand-new address is still created and
     * attached, which is the ordinary case and the whole of S1's happy path.
     *
     * WHAT IT DOES NOT DO is decide the consent RULE. A restaurant group whose owner
     * legitimately wants a second restaurant is a real requirement and needs an invite flow
     * that does not exist yet; refusing is the safe position until it does, not the final
     * one. `R-119` carries that with Task 10, beside `R-106` itself.
     *
     * `R-106` still MOVES rather than closing: its prescribed fix is a rank-2 edit to
     * `§4 › Security`, which a builder session may not take. What changes is the
     * justification — the trigger HAS now partly fired, through this form rather than
     * through a staff screen, so the move rests on the rank-2 reason alone.
     *
     * @throws OwnerNeedsConsent
     */
    private function refuseUnlessSafeToAttach(string $email): void
    {
        $refusal = $this->refusalForOwnerEmail($email);

        if ($refusal !== null) {
            throw new OwnerNeedsConsent($refusal);
        }
    }

    /** A placeholder display name, which the owner replaces when they first sign in. */
    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::limit(Str::headline($local), 120, '');
    }

    /**
     * Mark the row provisioned.
     *
     * $audit is FALSE on the losing side of a race (QA L-9). A runner that lost to
     * `tenants_pkey` did not provision anything, so it must not write a
     * `platform.tenant.provisioned` row: the trail is append-only and nothing can ever
     * prune a duplicate out of it. A status guard alone was not enough, because the loser's
     * own row is legitimately not `completed` when it gets here -- it claimed it.
     */
    private function markCompleted(
        string $tenantId,
        string $slug,
        ?string $ownerUserId = null,
        bool $audit = true,
    ): void {
        DB::transaction(function () use ($tenantId, $slug, $ownerUserId, $audit): void {
            // ⚠ STATUS-GUARDED, AND THE AFFECTED-ROW COUNT DECIDES WHETHER TO AUDIT
            // (QA L-9). This was `where id = ?` alone, so the loser of a race -- which
            // takes the `tenants_pkey` path and calls this too -- wrote a SECOND
            // `platform.tenant.provisioned` row for one tenant, in an append-only table
            // that nothing can ever prune. `where status <> 'completed'` makes the first
            // writer the only writer.
            $completed = DB::update(
                'update tenant_provisionings
                    set status = ?::provisioning_status, completed_at = now(),
                        failure_reason = null, updated_at = now()
                  where id = ? and status <> ?::provisioning_status',
                [ProvisioningStatus::Completed->value, $tenantId, ProvisioningStatus::Completed->value],
            );

            if ($completed === 0 || ! $audit) {
                // Either another runner already marked it, or this call is the losing side
                // of a race and provisioned nothing. Its audit row is not ours to write.
                return;
            }

            $this->audit->record(
                PlatformAudit::TENANT_PROVISIONED,
                null,
                'tenant_provisioning',
                $tenantId,
                // `owner_user_id`, so the append-only trail records WHOM a platform admin
                // made owner (security M-2). The ADDRESS still stays out — it is personal
                // data and the registry row carries it once — but an id is the thing an
                // investigator needs and it is not personal data on its own.
                ['slug' => $slug, 'owner_user_id' => $ownerUserId],
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
            // Guarded the same way, for the mirror reason: a late failure from a losing
            // runner must not mark a tenant that another runner has already provisioned.
            $failed = DB::update(
                'update tenant_provisionings
                    set status = ?::provisioning_status, failure_reason = ?, updated_at = now()
                  where id = ? and status <> ?::provisioning_status',
                [ProvisioningStatus::Failed->value, $reason, $tenantId, ProvisioningStatus::Completed->value],
            );

            if ($failed === 0) {
                return;
            }

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
        // A CONSENT REFUSAL IS A KNOWN OUTCOME, not an unexplained one. It carries no
        // SQLSTATE, so without this arm it fell through to "an unexpected reason" — which
        // would tell an operator nothing and would read as a fault rather than a rule.
        if ($error instanceof OwnerNeedsConsent) {
            return $error->getMessage();
        }

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
