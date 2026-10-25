<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Domain\Audit\AuditActorType;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way tenant context reaches a query (PLAN.md §2.2).
 *
 * The GUC is always set with set_config(name, value, true) -- transaction-local,
 * third argument always true (C2). A session-scoped tenant setting is a hard
 * failure, so there is deliberately no code path here that can produce one.
 */
class TenantContext
{
    private ?string $tenantId = null;

    private ?string $connectionName = null;

    public function current(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Run $callback inside one transaction with app.tenant_id set to $tenantId.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $tenantId, Closure $callback, ?string $connection = null): mixed
    {
        if (! Str::isUuid($tenantId)) {
            throw TenantContextException::notAUuid($tenantId);
        }

        $connection ??= DatabaseRoles::connection('app');

        if ($this->tenantId !== null) {
            // Re-entry with the same tenant on the same connection is the nested-service
            // case and is fine -- the context is already set on this transaction.
            if ($this->tenantId !== $tenantId) {
                throw TenantContextException::alreadyInAnotherTenant($this->tenantId, $tenantId);
            }

            if ($this->connectionName !== $connection) {
                throw TenantContextException::alreadyOnAnotherConnection((string) $this->connectionName, $connection);
            }

            return $callback();
        }

        $db = DB::connection($connection);

        return $db->transaction(function () use ($db, $tenantId, $connection, $callback): mixed {
            $guc = DatabaseRoles::guc('tenant');

            // Laravel opens a SAVEPOINT rather than a BEGIN when this runs inside a
            // transaction it did not start, and set_config(..., true) binds to the
            // OUTERMOST transaction, never to the savepoint. Without putting the old
            // value back, the context would outlive this block and the next query on
            // the connection would read the last tenant used. Migrations are the
            // ordinary case, not an exotic one: PostgresGrammar reports
            // supportsSchemaTransactions, so every migration body already runs at
            // transaction level 1 (PLAN.md §2.2).
            $previous = $this->readGuc($db, $guc);

            $db->select('select set_config(?, ?, true)', [$guc, $tenantId]);

            $this->tenantId = $tenantId;
            $this->connectionName = $connection;

            $completed = false;

            try {
                $result = $callback();
                $completed = true;

                return $result;
            } finally {
                // Only the success path restores. A callback that threw leaves the
                // transaction aborted, so a statement here would raise 25P02 and mask
                // the original exception -- and the rollback, of the transaction or to
                // the savepoint, reverts set_config on its own. Verified on 16.14.
                if ($completed) {
                    $db->select('select set_config(?, ?, true)', [$guc, $previous]);
                }

                $this->tenantId = null;
                $this->connectionName = null;
            }
        });
    }

    /**
     * What a GUC holds right now. "Never set" and "set to empty" both read as '',
     * which is exactly the value every policy treats as no context (PLAN.md §2.2).
     */
    private function readGuc(ConnectionInterface $db, string $name): string
    {
        $row = $db->selectOne('select coalesce(current_setting(?, true), ?) as value', [$name, '']);

        return is_object($row) && is_string($row->value ?? null) ? $row->value : '';
    }

    /**
     * The named, audited bypass for work that genuinely spans tenants (PLAN.md §2.10).
     *
     * Only the owner connection can use it, because every owner_bypass_* policy is
     * TO the owner role alone: the same call as qrpager_app sets the GUC and still
     * reads zero rows.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenantScope(string $reason, Closure $callback, ?string $connection = null): mixed
    {
        if (trim($reason) === '') {
            throw TenantContextException::bypassNeedsAReason();
        }

        $connection ??= DatabaseRoles::connection('owner');
        $db = DB::connection($connection);

        $role = (string) $db->selectOne('select current_user as role')->role;
        $owner = DatabaseRoles::name('owner');

        if ($role !== $owner) {
            throw TenantContextException::bypassRequiresOwner($role, $owner);
        }

        return $db->transaction(function () use ($db, $reason, $callback): mixed {
            $bypass = DatabaseRoles::guc('bypass');
            $tenant = DatabaseRoles::guc('tenant');

            // Same savepoint trap as run(), and worse here: a leaked app.bypass_tenant
            // is OR'd into every owner_bypass_* policy, so it would hand the rest of
            // the outer transaction an unaudited cross-tenant read and write.
            $previousBypass = $this->readGuc($db, $bypass);
            $previousTenant = $this->readGuc($db, $tenant);

            $db->select('select set_config(?, ?, true)', [$bypass, DatabaseRoles::bypassValue()]);

            // A bypass block must not inherit a tenant filter from whatever opened the
            // transaction. The two policies are permissive, so a stale app.tenant_id
            // cannot narrow what the block sees -- but leaving it set makes the block's
            // scope ambiguous to read and to debug.
            $db->select('select set_config(?, ?, true)', [$tenant, '']);

            // Written first and inside the same transaction, so a bypass that commits
            // always carries its own audit row. A bypass that *throws* rolls the row
            // back with the work and leaves no trace: recorded as F12 in PLAN.md §5,
            // owned by Task 2, which owns logging.
            $db->table('audit_log')->insert([
                'tenant_id' => null,
                'actor_type' => AuditActorType::System->value,
                'action' => 'tenancy.bypass',
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            $completed = false;

            try {
                $result = $callback();
                $completed = true;

                return $result;
            } finally {
                // Success path only, for the 25P02 reason in run().
                if ($completed) {
                    $db->select('select set_config(?, ?, true)', [$tenant, $previousTenant]);
                    $db->select('select set_config(?, ?, true)', [$bypass, $previousBypass]);
                }
            }
        });
    }
}
