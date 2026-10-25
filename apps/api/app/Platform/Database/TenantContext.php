<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Domain\Audit\AuditActorType;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way tenant context reaches a query (PLAN.md §2.2).
 *
 * The GUC is always set with set_config(name, value, true) -- transaction-local,
 * third argument always true (C2). A session-scoped tenant setting is a hard
 * failure, so there is deliberately no code path here that can produce one.
 *
 * ONE SOURCE OF TRUTH: app.tenant_id on the target connection. This class keeps no
 * mirror of it and current() reads it. Four fix rounds each added one more
 * order-dependent guard to run() and the next composition walked around it; H-A
 * existed because withoutTenantScope() cleared the GUC in Postgres and left a PHP
 * field set, and the re-entry short-circuit trusted the stale field. Removing the
 * mirror removes that class of bug rather than guarding it.
 *
 * $openScopes is NOT a mirror of the GUC. It records which connections this object
 * opened a scope on, is read only to refuse cross-connection reuse, and cannot
 * affect which tenant is in force -- a stale entry can only make this class
 * stricter, never looser.
 *
 * Every statement here goes through setGuc() or readContext(), which convert a
 * driver error into a TenantContextException carrying no statement text and no
 * bindings (§4 › Edge & ops).
 */
class TenantContext
{
    /** @var array<string, true> */
    private array $openScopes = [];

    /** What app.tenant_id holds on $connection right now; null when nothing is in force. */
    public function current(?string $connection = null): ?string
    {
        $connection ??= DatabaseRoles::connection('app');

        $tenant = $this->readContext(DB::connection($connection))['tenant'];

        return $tenant === '' ? null : $tenant;
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
        $db = DB::connection($connection);

        $active = $this->readContext($db);

        // FIRST, above every short-circuit. Measured on 16.14: owner_bypass_* and
        // tenant_isolation_* are both PERMISSIVE and FOR ALL, so Postgres ORs them --
        // while a bypass is live the tenant predicate narrows nothing and this block
        // would run completely unscoped, for reads and for writes. ANY non-empty value
        // refuses, so no configuration value can disarm this check.
        if ($active['bypass'] !== '') {
            throw TenantContextException::cannotScopeInsideBypass($connection);
        }

        // Re-entry is decided by what is in force on this connection, never by PHP
        // state. A service calling another service with the same tenant is fine.
        if ($active['tenant'] !== '') {
            if ($active['tenant'] !== $tenantId) {
                throw TenantContextException::alreadyInAnotherTenant($active['tenant'], $tenantId);
            }

            return $callback();
        }

        foreach (array_keys($this->openScopes) as $open) {
            if ($open !== $connection) {
                throw TenantContextException::alreadyOnAnotherConnection($open, $connection);
            }
        }

        $guc = DatabaseRoles::guc('tenant');

        return $db->transaction(function () use ($db, $guc, $tenantId, $connection, $callback): mixed {
            $this->setGuc($db, $guc, $tenantId);
            $this->openScopes[$connection] = true;

            try {
                $result = $callback();

                // Success path only. A callback that threw needs no clearing: Laravel
                // rolls back -- the transaction, or to the savepoint it opened for a
                // nested block -- and measured on 16.14 a full ROLLBACK leaves the
                // setting empty while ROLLBACK TO SAVEPOINT restores the value held
                // when the savepoint was taken.
                //
                // This clear is LOUD. Swallowing it let an aborted transaction commit as
                // a silent rollback while this method returned the callback's value:
                // measured, a write made before a caught QueryException was gone
                // afterwards with nothing raised anywhere.
                $this->setGuc($db, $guc, '');

                return $result;
            } finally {
                unset($this->openScopes[$connection]);
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

        $owner = DatabaseRoles::name('owner');
        $role = $this->currentRole($db);

        if ($role !== $owner) {
            throw TenantContextException::bypassRequiresOwner($role, $owner);
        }

        // A bypass may not open inside a tenant scope: the scope's caller believes its
        // queries are narrowed, and they would stop being. Both halves fail closed --
        // what is in force on this connection, and whether this object holds a scope
        // anywhere.
        $active = $this->readContext($db);

        if ($active['tenant'] !== '') {
            throw TenantContextException::cannotBypassInsideTenantScope($connection);
        }

        if ($this->openScopes !== []) {
            throw TenantContextException::cannotBypassInsideTenantScope((string) array_key_first($this->openScopes));
        }

        $bypass = DatabaseRoles::guc('bypass');
        $previousBypass = $active['bypass'];

        return $db->transaction(function () use ($db, $bypass, $previousBypass, $reason, $callback): mixed {
            $this->setGuc($db, $bypass, DatabaseRoles::bypassValue());

            // Written first and inside the same transaction, so a bypass that commits
            // always carries its own audit row. A bypass that throws rolls that row
            // back with the work and leaves no trace: R-005, owned by Task 2.
            $this->insertBypassAudit($db, $reason);

            $result = $callback();

            // Only the bypass is restored, and no ordering hazard is left to get wrong:
            // app.tenant_id is '' on entry because this helper refuses to open inside a
            // tenant scope, and it stays '' because run() refuses to open inside a
            // bypass. The "narrowed tenant with the bypass still open" state is
            // therefore unreachable rather than merely ordered against.
            $this->setGuc($db, $bypass, $previousBypass);

            return $result;
        });
    }

    /**
     * Both GUCs in one round trip, so opening a scope costs one read and one write.
     *
     * "Never set" and "set to empty" both read as '', which is exactly the value
     * every policy treats as no context: each tenant_isolation_* policy compares
     * against NULLIF(current_setting('app.tenant_id', true), '').
     *
     * @return array{tenant: string, bypass: string}
     */
    private function readContext(ConnectionInterface $db): array
    {
        try {
            $row = $db->selectOne(
                'select coalesce(current_setting(?, true), ?) as tenant,
                        coalesce(current_setting(?, true), ?) as bypass',
                [DatabaseRoles::guc('tenant'), '', DatabaseRoles::guc('bypass'), ''],
            );
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed('read', (string) $e->getCode());
        }

        return [
            'tenant' => is_object($row) && is_string($row->tenant ?? null) ? $row->tenant : '',
            'bypass' => is_object($row) && is_string($row->bypass ?? null) ? $row->bypass : '',
        ];
    }

    private function setGuc(ConnectionInterface $db, string $name, string $value): void
    {
        try {
            $db->select('select set_config(?, ?, true)', [$name, $value]);
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed(
                $value === '' ? 'cleared' : 'set', (string) $e->getCode(),
            );
        }
    }

    private function currentRole(ConnectionInterface $db): string
    {
        try {
            $row = $db->selectOne('select current_user as role');
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed('read', (string) $e->getCode());
        }

        return is_object($row) && is_string($row->role ?? null) ? $row->role : '';
    }

    private function insertBypassAudit(ConnectionInterface $db, string $reason): void
    {
        try {
            $db->table('audit_log')->insert([
                'tenant_id' => null,
                'actor_type' => AuditActorType::System->value,
                'action' => 'tenancy.bypass',
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed('audited', (string) $e->getCode());
        }
    }
}
