<?php

declare(strict_types=1);

namespace App\Platform\Database;

use RuntimeException;

final class TenantContextException extends RuntimeException
{
    public static function notAUuid(string $value): self
    {
        // The value is echoed back deliberately: it is a tenant id, never a secret,
        // and a rejected value is useless to debug without it.
        return new self("Tenant id [{$value}] is not a uuid.");
    }

    public static function alreadyInAnotherTenant(string $active, string $requested): self
    {
        return new self(
            "Tenant context is already set to [{$active}]; it cannot be changed to [{$requested}] "
            .'inside the same transaction.'
        );
    }

    public static function alreadyOnAnotherConnection(string $active, string $requested): self
    {
        return new self(
            "Tenant context is already open on connection [{$active}]; it cannot be reused on [{$requested}]."
        );
    }

    /**
     * The message deliberately names no tenant id: the whole point of the refusal is
     * that the caller's tenant was about to mean nothing.
     */
    public static function cannotScopeInsideBypass(string $connection): self
    {
        return new self(
            "A tenant context cannot be opened on connection [{$connection}] while a tenant "
            .'bypass is active: every owner_bypass_* policy is permissive, so the tenant '
            .'predicate would narrow nothing and the block would run unscoped. Close the '
            .'bypass first -- PLAN.md §2.10 keeps the per-tenant loop outside it.'
        );
    }

    /**
     * The message deliberately names no tenant id: the point of the refusal is that
     * the caller's tenant was about to mean nothing.
     */
    public static function cannotBypassInsideTenantScope(string $connection): self
    {
        return new self(
            'A tenant bypass cannot be opened while a tenant context is open on connection '
            ."[{$connection}]: the scope's caller believes its queries are narrowed, and a "
            .'bypass would stop them being. Close the tenant scope first -- PLAN.md §2.10 '
            .'takes the tenant list in its own bypass and loops outside it.'
        );
    }

    /**
     * A driver error on one of this class's own statements. The statement and its
     * bindings are withheld and the original is deliberately NOT chained: Laravel
     * interpolates bindings into QueryException::getMessage(), and a chained previous
     * would put the statement back into any rendered trace
     * (PROJECT_INSTRUCTIONS.md §4 › Edge & ops). The SQLSTATE is what survives.
     */
    public static function statementFailed(string $operation, string $sqlstate): self
    {
        return new self(
            "Tenant context could not be {$operation}"
            .($sqlstate === '' || $sqlstate === '0' ? '' : " (SQLSTATE {$sqlstate})")
            .'. The statement and its bindings are withheld deliberately: an escaping '
            .'driver message would carry both.'
        );
    }

    public static function bypassRequiresOwner(string $role, string $owner): self
    {
        return new self(
            "A tenant bypass may only run as [{$owner}], not [{$role}]. "
            .'Every bypass policy is TO the owner role alone (PLAN.md §2.2).'
        );
    }

    public static function bypassNeedsAReason(): self
    {
        return new self('A tenant bypass needs a reason: it is written to audit_log (PLAN.md §2.10).');
    }
}
