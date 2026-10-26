<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Support\Str;
use RuntimeException;

final class TenantContextException extends RuntimeException
{
    /**
     * Anything interpolated into one of these messages that did not come from this
     * class, rendered so it cannot forge a log line.
     *
     * Four of these messages carry text this class never validated: the tenant setting
     * read from the session, the role read from current_user, the caller's own rejected
     * value, and the SQLSTATE taken from whatever Throwable escaped a statement -- which
     * is only five alphanumerics when it comes from PDO, and is not guaranteed to come
     * from PDO (F8, seventh security review). Measured with the GUC set to
     * "injected\nFAKE LOG LINE level=emergency token=secret": the message, and the
     * Monolog line built from it, carried the newline and the forged content verbatim
     * (L-1, sixth security review). An exception message reaches the log, so an
     * embedded newline is a log entry the attacker wrote.
     *
     * \p{C} rather than a C0 range: it also covers C1, the bidi overrides and the
     * zero-width characters, which spoof a log line without needing a newline at all.
     * A uuid passes through untouched, because that is the legitimate case and these
     * messages are useless without it.
     */
    private static function printable(string $value, int $limit = 80): string
    {
        if (Str::isUuid($value)) {
            return $value;
        }

        $clean = preg_replace('/\p{C}/u', '.', $value);

        if ($clean === null) {
            // Not valid UTF-8, so nothing can be reasoned about it character by
            // character. Keep printable ASCII and replace the rest.
            $clean = preg_replace('/[^\x20-\x7E]/', '.', $value) ?? '';
        }

        return mb_strlen($clean) > $limit ? mb_substr($clean, 0, $limit).'...' : $clean;
    }

    public static function notAUuid(string $value): self
    {
        // The value is echoed back deliberately: it is a tenant id, never a secret,
        // and a rejected value is useless to debug without it.
        return new self('Tenant id ['.self::printable($value).'] is not a uuid.');
    }

    public static function alreadyInAnotherTenant(string $active, string $requested): self
    {
        return new self(
            'Tenant context is already set to ['.self::printable($active).']; it cannot be changed to '
            .'['.self::printable($requested).'] inside the same transaction.'
        );
    }

    /**
     * C2: "a session-scoped tenant setting is a hard failure". A value in force that
     * no scope in this process opened is exactly that -- set_config(..., false), an
     * operator's SET, or a pooled connection handed over carrying one -- and adopting
     * it ran the callback with no transaction and no clear, so a throw did not roll
     * back and the value outlived the block (M-1, fifth security review).
     *
     * The value is named for diagnosis: it is a tenant id, never a secret, and the
     * whole problem is not knowing where it came from.
     */
    public static function tenantContextNotOpenedHere(string $connection, string $active): self
    {
        return new self(
            'app.tenant_id is already ['.self::printable($active)."] on connection [{$connection}], but no tenant scope "
            .'in this process opened it. A tenant setting this process did not open is a hard '
            .'failure (PROJECT_INSTRUCTIONS.md C2): it is not transaction-local to any block here, '
            .'so nothing would roll it back. Clear it before opening a scope.'
        );
    }

    /**
     * The scope was in force when the block started and gone when it finished, which
     * means the connection underneath was replaced -- a reconnect, or DB::purge().
     * Laravel's transaction counter resets with it, so the COMMIT became a no-op and
     * the block's writes were discarded. Loud, because silent was H-B
     * (M-2, fifth security review).
     *
     * Deliberately does not claim the work was discarded. Measured: a callback that
     * calls commit() reaches this refusal with its writes COMMITTED, so a caller that
     * retried on the old wording would double-write, against §4 › Realtime's "a retry
     * never double-notifies" (F7, seventh security review).
     */
    public static function scopeWasLostDuringCallback(string $connection): self
    {
        return new self(
            "The tenant scope opened on connection [{$connection}] was no longer in force when the "
            .'block finished: the transaction this method opened is gone, either because the '
            .'connection underneath it was replaced or because something in the block committed '
            .'it. Whether the block\'s writes were discarded or already committed cannot be '
            .'determined from here, so this is not safe to retry blindly. Reported rather than '
            .'returned: returning here is how a rolled-back write looks like a success.'
        );
    }

    /**
     * The setting in force at the end of the block is not the one this scope set, at
     * the same transaction depth -- so something inside the callback wrote over it with
     * a raw set_config and every query after that point ran under a tenant the caller
     * did not ask for. The depth postcondition cannot see this, which is the only
     * reason clearScope() still reads before it writes.
     */
    public static function tenantSettingChangedDuringCallback(string $connection): self
    {
        return new self(
            "app.tenant_id on connection [{$connection}] is not the value this tenant scope set. "
            .'Something in the block wrote over it, so part of the block ran under a tenant its '
            .'caller did not choose. The work has been rolled back; the value is deliberately not '
            .'named, because the point of the refusal is that it is not the caller\'s.'
        );
    }

    /**
     * The callback left the transaction deeper than the scope opened it, so Laravel's
     * commit at that depth only decrements and issues no COMMIT: the callback's work is
     * discarded while this method would otherwise have returned its value. The third
     * route to H-B's failure, and the one that also carries an open transaction into
     * the next request on an Octane worker (M-2, sixth security review).
     */
    public static function transactionLeftOpenByCallback(string $connection, int $opened, int $found): self
    {
        return new self(
            "The callback left connection [{$connection}] at transaction depth {$found}, where this "
            ."tenant scope opened it at {$opened}. A transaction the callback opened and did not close "
            .'swallows the commit for everything the block wrote, so the work this scope opened has been '
            .'rolled back, back to the depth the caller was at, rather than reported as a success.'
        );
    }

    /**
     * The bypass was in force when the block started and was not at the end, at the same
     * transaction depth -- so the callback committed and reopened, and the transaction-local
     * bypass GUC reverted at that COMMIT. Everything in the block after that point ran with
     * no bypass and read zero rows, which for §2.10's pattern 2 is a backfill that does
     * nothing and reports success (S-6, eighth security review).
     */
    public static function bypassWasLostDuringCallback(string $connection): self
    {
        return new self(
            "The tenant bypass opened on connection [{$connection}] was no longer in force when the "
            .'block finished, so part of the block ran without it and read nothing rather than '
            .'everything. Whether the block\'s writes were discarded or already committed cannot be '
            .'determined from here, so this is not safe to retry blindly.'
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
            'Tenant context could not be '.self::printable($operation, 16)
            .($sqlstate === '' || $sqlstate === '0' ? '' : ' (SQLSTATE '.self::printable($sqlstate, 16).')')
            .'. The statement and its bindings are withheld deliberately: an escaping '
            .'driver message would carry both.'
        );
    }

    public static function bypassRequiresOwner(string $role, string $owner): self
    {
        return new self(
            "A tenant bypass may only run as [{$owner}], not [".self::printable($role).']. '
            .'Every bypass policy is TO the owner role alone (PLAN.md §2.2).'
        );
    }

    public static function bypassNeedsAReason(): self
    {
        return new self('A tenant bypass needs a reason: it is written to audit_log (PLAN.md §2.10).');
    }
}
