<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Domain\Audit\AuditActorType;
use Closure;
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
            $db->select('select set_config(?, ?, true)', [DatabaseRoles::guc('tenant'), $tenantId]);

            $this->tenantId = $tenantId;
            $this->connectionName = $connection;

            try {
                return $callback();
            } finally {
                $this->tenantId = null;
                $this->connectionName = null;
            }
        });
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
            $db->select('select set_config(?, ?, true)', [
                DatabaseRoles::guc('bypass'),
                DatabaseRoles::bypassValue(),
            ]);

            // Written first and inside the same transaction, so a bypass cannot happen
            // silently and cannot be committed without its own audit row.
            $db->table('audit_log')->insert([
                'tenant_id' => null,
                'actor_type' => AuditActorType::System->value,
                'action' => 'tenancy.bypass',
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $callback();
        });
    }
}
