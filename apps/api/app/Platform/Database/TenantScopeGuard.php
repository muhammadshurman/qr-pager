<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Domain\Database\TenantTables;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;

/**
 * "A request without tenant context never reaches the DB" -- the first of the three
 * invariants this task ships on (PLAN.md §3.1, row 2), enforced rather than asserted.
 *
 * WHY beforeExecuting AND NOT DB::listen, which is the mechanism the planning chat
 * specified (B4). Measured in phase 1 against password_reset_tokens: DB::listen()
 * fires AFTER execution -- the event carries the elapsed time -- so a listener that
 * throws raises the exception and leaves `rows written = 1`. The same throw from
 * Connection::beforeExecuting() left `rows written = 0`. A listen()-based guard would
 * have made this task's own invariant test GREEN WHILE BEING FALSE, which is the
 * single most expensive shape of bug this project has had. Both callbacks run inside
 * Connection::run() (framework's Connection.php:808 invokes the beforeExecuting ones,
 * before reconnectIfMissingConnection and before the statement), and every query
 * method funnels through run() -- select, statement, affectingStatement, cursor,
 * unprepared and therefore the whole query builder and Eloquent. Verified by reading
 * the framework source as well as by running it.
 *
 * WHY ConnectionEstablished. beforeExecuting() registers on one Connection OBJECT, and
 * Octane purges and reconnects. Hanging the attachment on the event means every
 * connection the manager builds from now on carries the guard, including the ones made
 * after a DB::purge() -- measured: the guard refused again after a purge, and let an
 * unrelated `select 1 as ok` through.
 *
 * WHAT ARMS IT, and the honest boundary. The invariant is about a REQUEST, so the
 * guard is armed by ArmTenantScopeGuard on the GLOBAL middleware stack -- the kernel's,
 * not a route group's, so a new route cannot forget it and an unmatched path (a 404)
 * is armed too. It is deliberately NOT armed for console commands, scheduled work or
 * queue workers: the suite proves the RLS backstop by querying a tenant table on the
 * app connection with no scope and expecting zero rows (TenantIsolationTest,
 * ConnectionReuseTest, WorkerScopeTest), and that property is worth keeping provable.
 * An always-armed guard would have needed a suspension helper for those tests, which
 * is an escape hatch in the one class that exists to have none. So the scope of the
 * enforcement is exactly the scope of the invariant, and for everything outside a
 * request RLS remains the backstop it already is.
 *
 * WHAT IT DOES NOT CLAIM. It answers one question: was a scope opened on this
 * connection? It does not and cannot answer "is the right tenant still in force" --
 * that is clearScope()'s read-back and assertScopeSurvived()'s depth postcondition,
 * and those own the S-1/M-2 class of failure where a scope is opened and then lost
 * mid-callback. And RLS still decides WHICH ROWS. Three layers, three questions: the
 * guard turns a silent zero-row answer into a loud refusal, it does not replace the
 * policies.
 */
final class TenantScopeGuard
{
    private bool $armed = false;

    /** @var list<string> */
    private array $guarded;

    /**
     * The container is held rather than the TenantContext, and that is not a style
     * choice. This guard is resolved at boot to register its connection listener, so it
     * lives in Octane's ROOT application for the worker's life -- D10's measurement is
     * about exactly this: a binding resolved during boot stops being per-request. A
     * constructor-injected TenantContext would therefore pin the root app's instance,
     * while the request resolves its own inside the sandbox clone, and the guard would
     * be asking an object that never has a scope open whether a scope is open. It
     * would refuse every scoped query in production and pass every test that called
     * inspect() directly. Resolved per call, from the container in force, instead.
     */
    public function __construct(private readonly Container $container)
    {
        // The runtime connections, and only those. The owner, root and bootstrap
        // connections are not runtime surfaces: the audited cross-tenant bypass runs
        // on the owner connection by design (§2.10), and what keeps those credentials
        // out of a serving process is D9's boot assertion, which is where PLAN.md §5
        // already puts that control (R-015). Guarding them here would refuse the
        // audited path and still not be the control.
        $this->guarded = array_map(
            static fn (string $role): string => DatabaseRoles::connection($role),
            array_values(array_filter(
                (array) config('tenancy.runtime_roles', []),
                'is_string',
            )),
        );
    }

    /**
     * Attach to every connection the manager builds, from now on.
     *
     * Called once from AppServiceProvider::boot(). Nothing is attached to connections
     * that already exist, because at boot there are none -- the provider boots before
     * the first query, and a connection resolved earlier would be a boot-time one that
     * is not serving a request.
     */
    public function listen(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $name = $event->connection->getName();

            // A connection with no name cannot be matched against the guarded list, and
            // guessing which one it is would be the wrong kind of helpful. The manager
            // names every connection it builds from config, so this is unreachable
            // through the application and is left failing closed the other way:
            // unguarded, rather than guarded under a name it does not have.
            if ($name === null || ! in_array($name, $this->guarded, true)) {
                return;
            }

            $event->connection->beforeExecuting(
                function (string $query, array $bindings, Connection $connection) use ($name): void {
                    $this->inspect($query, $connection->getName() ?? $name);
                },
            );
        });
    }

    /** @param  Closure():mixed  $callback */
    public function armedFor(Closure $callback): mixed
    {
        $was = $this->armed;
        $this->armed = true;

        try {
            return $callback();
        } finally {
            // Restored rather than cleared, so a nested request -- which the test
            // harness can produce -- does not disarm the one around it.
            $this->armed = $was;
        }
    }

    /**
     * Forget that a request was in flight.
     *
     * The request-boundary cleanup calls this (R-022): a request that throws out of
     * Octane's gateway never reaches armedFor()'s finally, and although a leaked
     * "armed" is the SAFE direction -- stricter, never looser -- a worker that stays
     * armed after a failure would refuse the next console-shaped thing it does.
     */
    public function disarm(): void
    {
        $this->armed = false;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** @return list<string> */
    public function guardedConnections(): array
    {
        return $this->guarded;
    }

    /**
     * Refuse, before execution, a statement that touches tenant data with no scope open.
     *
     * Public so the invariant can be tested at the point it is decided as well as
     * through a real request. It reads no state of its own: the connection name and
     * the statement are the whole input.
     */
    public function inspect(string $query, string $connection): void
    {
        if (! $this->armed || ! in_array($connection, $this->guarded, true)) {
            return;
        }

        /** @var TenantContext $context */
        $context = $this->container->make(TenantContext::class);

        if ($context->hasOpenScope($connection)) {
            return;
        }

        $table = TenantTables::referencedBy($query);

        if ($table !== null) {
            throw new TenantScopeMissingException($table, $connection);
        }
    }
}
