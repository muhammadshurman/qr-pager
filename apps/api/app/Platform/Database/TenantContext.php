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
 * Five places issue a statement. Four of them -- setGuc(), readState(), clearScope()
 * and insertBypassAudit() -- convert a driver error into a TenantContextException
 * carrying no statement text and no bindings (§4 › Edge & ops). The fifth is the
 * rollBack() inside assertScopeSurvived(), which deliberately SWALLOWS: it runs while
 * already reporting a failure, and replacing that failure with this one would hide the
 * problem the caller needs to see. Nothing escapes it, so nothing leaks; what it costs
 * is that a failed reset is not reported, which is why the refusal below says what it
 * attempted rather than what it achieved (F6, seventh security review).
 *
 * Neither helper trusts the connection it was handed. readState() reads the role's
 * rolsuper and rolbypassrls in the same round trip as the GUCs, and both helpers
 * refuse before doing anything else if either is set: row level security does not
 * apply to such a role, so a scope on it is the widest path in the codebase while
 * reading as the narrowest (H-C). RuntimeRoleGuard owns that rule; assertSafe() is
 * the wider boot-time check and stays Task 2's.
 */
class TenantContext
{
    /** @var array<string, true> */
    private array $openScopes = [];

    /** What app.tenant_id holds on $connection right now; null when nothing is in force. */
    public function current(?string $connection = null): ?string
    {
        // Defaults to the connection this object actually opened a scope on. Before
        // the mirror was removed this was a PHP field, so it answered the same for a
        // scope on any connection; reading the app connection by default made it
        // report "no tenant" inside a perfectly good scope on the owner or worker
        // connection, which §4 › Realtime would turn into one Redis key shared by
        // every tenant (M-3, fifth security review). run() refuses a second
        // connection, so there is never more than one to choose from, and a stale
        // entry can only point at a connection whose GUC is '' -- null, not a wrong
        // tenant.
        $connection ??= array_key_first($this->openScopes) ?? DatabaseRoles::connection('app');

        // Provenance before format, and this is the half that was missing. run()
        // treats a tenant setting no scope in this process opened as a hard failure
        // (M-1); current() only checked the value's shape, so the two entry points
        // disagreed about the same value -- measured, current() returned a
        // session-scoped uuid that withTenant() refused in the next breath. §4 ›
        // Realtime builds the Redis namespace from exactly this value and Task 4 will
        // build keys from it, so on a pooled Octane connection carrying a session value
        // that is a cross-tenant key with nothing behind it (M-1, sixth security
        // review). No scope here, no answer -- and no round trip either.
        if (! isset($this->openScopes[$connection])) {
            return null;
        }

        $tenant = $this->readState(DB::connection($connection))['tenant'];

        // Shape only. This is deliberately NOT a claim that the value is the one this
        // scope set: measured, after a raw set_config to another tenant's uuid inside a
        // live scope, current() returns that uuid -- provenance is satisfied by the open
        // entry and the format check cannot tell two uuids apart (F4, seventh security
        // review). clearScope() refuses the block at its end, but a Redis key already
        // written from this answer is not rolled back by that refusal. Closing it needs
        // the tenant recorded alongside the scope, which is the mirror H-A was; carried
        // as R-017 with its own entry rather than reintroduced here unasked.
        return Str::isUuid($tenant) ? $tenant : null;
    }

    /**
     * Run $callback inside one transaction with app.tenant_id set to $tenantId.
     *
     * $connection narrows THAT connection and no other. A transaction-local GUC is
     * per Postgres session, so a callback that queries a different connection is not
     * narrowed by this scope -- measured: inside a bypass on the owner connection, a
     * scope on the app connection is genuinely scoped on the app connection while the
     * owner one still sees every tenant (R-015). The H-C check below means the
     * connection at least cannot be one row level security does not apply to.
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

        $active = $this->readState($db);

        // FIRST, before every other check and every short-circuit: if row level
        // security does not apply to this role, nothing below this line means
        // anything. A SUPERUSER or BYPASSRLS connection sets app.tenant_id correctly,
        // reads every tenant, commits a cross-tenant write and raises nothing -- the
        // scope advertises itself as narrowed while being the widest path in the
        // codebase (H-C, fifth security review).
        $this->assertRowLevelSecurityApplies($active);

        // Measured on 16.14: owner_bypass_* and tenant_isolation_* are both PERMISSIVE
        // and FOR ALL, so Postgres ORs them -- while a bypass is live the tenant
        // predicate narrows nothing and this block would run completely unscoped, for
        // reads and for writes. ANY non-empty value refuses, so no configuration value
        // can disarm this check.
        if ($active['bypass'] !== '') {
            throw TenantContextException::cannotScopeInsideBypass($connection);
        }

        // Re-entry is decided by what is in force on this connection, never by PHP
        // state. A service calling another service with the same tenant is fine.
        if ($active['tenant'] !== '') {
            // ... but only when a scope in this process put it there. $openScopes is
            // the record of that, and it is not a mirror of the value: it cannot say
            // WHICH tenant is in force, only whether this object opened one here. A
            // value in force without one is session-scoped, and C2 makes that a hard
            // failure -- adopting it skipped the transaction and the clear entirely,
            // so a throw inside the block did not roll back and the context outlived
            // it (M-1, fifth security review).
            if (! isset($this->openScopes[$connection])) {
                throw TenantContextException::tenantContextNotOpenedHere($connection, $active['tenant']);
            }

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
            // The depth Laravel reports inside the transaction it has just opened.
            // Anything else here when the callback returns means the callback moved the
            // transaction this scope runs in, and the COMMIT about to be issued is not
            // the one that would commit the callback's work.
            $depth = $db->transactionLevel();

            $this->setGuc($db, $guc, $tenantId);
            $this->openScopes[$connection] = true;

            try {
                $result = $callback();

                // Before the clear, because the clear cannot see this: a callback that
                // leaves a transaction open leaves the GUC reading correctly inside it,
                // so the read-back below succeeds and the failure stays silent (M-2,
                // sixth security review).
                $this->assertScopeSurvived($db, $depth, $connection);

                // Success path only. A callback that threw needs no clearing: Laravel
                // rolls back -- the transaction, or to the savepoint it opened for a
                // nested block -- and measured on 16.14 a full ROLLBACK leaves a
                // transaction-local setting empty, while ROLLBACK TO SAVEPOINT restores
                // the value held when the savepoint was taken. ("Empty" holds because
                // no session-scoped value can be underneath it: the check above refuses
                // one rather than adopting it. With one underneath, a full ROLLBACK
                // leaves that session value in place -- measured.)
                //
                // This clear is LOUD, and it proves the scope was still ours. Swallowing
                // it let an aborted transaction commit as a silent rollback while this
                // method returned the callback's value; not checking what it cleared let
                // the same thing happen when the connection underneath was replaced.
                $this->clearScope($db, $guc, $tenantId, $connection);

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

        // One read for the role, both GUCs and the role's RLS attributes, so the
        // checks below cost nothing extra between them.
        $active = $this->readState($db);

        // Same first check as run(), for the same reason: this is the named, audited
        // cross-tenant path, and a role that ignores row level security makes every
        // other path a cross-tenant one too, unnamed and unaudited (H-C).
        $this->assertRowLevelSecurityApplies($active);

        $owner = DatabaseRoles::name('owner');

        if ($active['role'] !== $owner) {
            throw TenantContextException::bypassRequiresOwner($active['role'], $owner);
        }

        // A bypass may not open inside a tenant scope: the scope's caller believes its
        // queries are narrowed, and they would stop being. Both halves fail closed --
        // what is in force on this connection, and whether this object holds a scope
        // anywhere. The GUC half is not redundant: it is the only half that catches a
        // tenant setting this process did not open (L-2).
        if ($active['tenant'] !== '') {
            throw TenantContextException::cannotBypassInsideTenantScope($connection);
        }

        if ($this->openScopes !== []) {
            throw TenantContextException::cannotBypassInsideTenantScope((string) array_key_first($this->openScopes));
        }

        $bypass = DatabaseRoles::guc('bypass');
        $previousBypass = $active['bypass'];

        return $db->transaction(function () use ($db, $bypass, $previousBypass, $reason, $connection, $callback): mixed {
            $depth = $db->transactionLevel();

            $this->setGuc($db, $bypass, DatabaseRoles::bypassValue());

            // Written first and inside the same transaction, so a bypass that commits
            // always carries its own audit row. A bypass that throws rolls that row
            // back with the work and leaves no trace: R-005, owned by Task 2.
            $this->insertBypassAudit($db, $reason);

            $result = $callback();

            // The same postcondition run() has, for the same reason, on the one path
            // that is cross-tenant by design. Without it the bypass was still M-2:
            // measured, a callback that left a transaction open made this method return
            // its value while the bypass's OWN audit_log row stayed uncommitted and
            // invisible to any other session, with the connection going back to the
            // pool at depth 1 -- after which the next scope on it opened as a savepoint
            // inside the previous request's transaction (F2, seventh security review).
            $this->assertScopeSurvived($db, $depth, $connection);

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
     * Both GUCs, the role, and the two role attributes that decide whether row level
     * security applies at all -- in one round trip, so neither helper pays for the
     * H-C check and opening a scope still costs one read and one write (L-D).
     *
     * "Never set" and "set to empty" both read as '', which is exactly the value
     * every policy treats as no context: each tenant_isolation_* policy compares
     * against NULLIF(current_setting('app.tenant_id', true), '').
     *
     * @return array{tenant: string, bypass: string, role: string, superuser: bool, bypassrls: bool}
     */
    private function readState(ConnectionInterface $db): array
    {
        try {
            $row = $db->selectOne(
                'select coalesce(current_setting(?, true), ?) as tenant,
                        coalesce(current_setting(?, true), ?) as bypass,
                        current_user                          as role,
                        r.rolsuper                            as superuser,
                        r.rolbypassrls                        as bypassrls
                 from pg_roles r
                 where r.rolname = current_user',
                [DatabaseRoles::guc('tenant'), '', DatabaseRoles::guc('bypass'), ''],
            );
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed('read', (string) $e->getCode());
        }

        // current_user always has a pg_roles row, so no row is a broken connection
        // rather than an unprivileged one -- and "cannot tell whether row level
        // security applies" must never read as "it does". The attribute defaults
        // below fail closed for the same reason.
        if (! is_object($row)) {
            throw TenantContextException::statementFailed('read', '');
        }

        return [
            'tenant' => is_string($row->tenant ?? null) ? $row->tenant : '',
            'bypass' => is_string($row->bypass ?? null) ? $row->bypass : '',
            'role' => is_string($row->role ?? null) ? $row->role : '',
            'superuser' => (bool) ($row->superuser ?? true),
            'bypassrls' => (bool) ($row->bypassrls ?? true),
        ];
    }

    /**
     * The transaction this scope opened is still the transaction the callback ran in.
     *
     * Measured, and this is the third route to the same failure H-B was about: a
     * callback that leaves a transaction open -- a bare beginTransaction() it never
     * commits -- returned normally with its write discarded, because Laravel's commit
     * at the deeper level only decrements and issues no COMMIT, while clearScope()'s
     * read-back still saw the right tenant inside the still-open outer transaction.
     * Worse under Octane: the next request on the same worker and the same singleton
     * opened a scope that nested into the first request's transaction, putting two
     * requests' work in one transaction that `idle_in_transaction_session_timeout`
     * then threw away.
     *
     * A shallower depth is the connection going away underneath -- a reconnect, or a
     * commit from inside the callback -- which is reported as the scope being lost. A
     * deeper one is the callback's own doing.
     */
    private function assertScopeSurvived(ConnectionInterface $db, int $depth, string $connection): void
    {
        $found = $db->transactionLevel();

        if ($found === $depth) {
            return;
        }

        // Unwind only what the callback added, down to the depth this scope was opened
        // at -- never past it. Unwinding to 0 took the CALLER's transaction with it:
        // measured, a scope nested inside an application transaction (which §2.10 says
        // every migration body is) left the caller at depth 0, so its earlier write was
        // gone, its later writes autocommitted one at a time, and a caller that caught
        // the refusal -- the shape §2.10's own loop uses -- had its transaction
        // returned as 'outer-ok' with nothing raised. That is H-B's failure one level
        // up, introduced by the fix for H-B's third route (F1, seventh security review).
        //
        // run()'s own level is left to Laravel: transaction() rolls back exactly the
        // level it opened when this throw reaches it. Each rollBack() unwinds one level;
        // the cap is there because a loop driven by a driver's own counter should not be
        // able to spin.
        for ($i = 0; $i < 32 && $db->transactionLevel() > $depth; $i++) {
            try {
                $db->rollBack();
            } catch (Throwable) {
                // Reporting the original failure matters more than tidying succeeding.
                break;
            }
        }

        throw $found < $depth
            ? TenantContextException::scopeWasLostDuringCallback($connection)
            : TenantContextException::transactionLeftOpenByCallback($connection, $depth, $found);
    }

    /**
     * @param  array{tenant: string, bypass: string, role: string, superuser: bool, bypassrls: bool}  $state
     */
    private function assertRowLevelSecurityApplies(array $state): void
    {
        $reasons = RuntimeRoleGuard::reasonsRowLevelSecurityIsNotEnforced(
            $state['superuser'], $state['bypassrls'],
        );

        if ($reasons !== []) {
            throw new UnsafeDatabaseRoleException($state['role'], $reasons);
        }
    }

    /**
     * Clear app.tenant_id, and prove in the same round trip that the scope this
     * method opened was still the one in force.
     *
     * Without the read-back the clear simply succeeded against whatever session it
     * landed on. Measured: replacing the connection mid-callback -- a reconnect, or
     * DB::purge() -- leaves Laravel's transaction counter at 0, so the later COMMIT
     * is a no-op, the rest of the block runs unscoped, and run() returns the
     * callback's value with the scoped write silently discarded. That is H-B's own
     * failure mode reached by another route (M-2, fifth security review).
     *
     * MATERIALIZED is load-bearing, not decoration: Postgres does not specify the
     * evaluation order of a target list, so reading and writing one GUC in a single
     * unfenced select is only accidentally correct. It does return the previous value
     * on 16.14 -- measured -- which is exactly the kind of accident that stops being
     * true without warning.
     */
    private function clearScope(ConnectionInterface $db, string $guc, string $expected, string $connection): void
    {
        try {
            $row = $db->selectOne(
                'with previous as materialized (
                     select coalesce(current_setting(?, true), ?) as value
                 )
                 select previous.value as before, set_config(?, ?, true) as cleared
                 from previous',
                [$guc, '', $guc, ''],
            );
        } catch (Throwable $e) {
            throw TenantContextException::statementFailed('cleared', (string) $e->getCode());
        }

        $before = is_object($row) && is_string($row->before ?? null) ? $row->before : '';

        if ($before !== $expected) {
            throw TenantContextException::tenantSettingChangedDuringCallback($connection);
        }
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
