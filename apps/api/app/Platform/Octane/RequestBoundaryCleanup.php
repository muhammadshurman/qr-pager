<?php

declare(strict_types=1);

namespace App\Platform\Octane;

use App\Platform\Database\TenantScopeGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * R-022: a connection must not go back to the pool carrying an open transaction or a
 * tenant setting. Gate clause (a), approved on 2026-10-26.
 *
 * WHAT WAS MEASURED, because this is a clause with a red test behind it rather than an
 * aspiration. Driving the real Laravel\Octane\Worker with FakeClient: a request that
 * leaves a transaction open fires RequestTerminated, and with this listener the next
 * request enters at level 0 with an empty app.tenant_id. A request that THROWS OUT OF
 * THE GATEWAY never fires it -- Worker::handle()'s catch goes to handleWorkerError(),
 * which dispatches WorkerErrorOccurred and nothing else. The next request then entered
 * at transactionLevel 1, inTransaction true, with the PREVIOUS REQUEST'S tenant uuid
 * still in force. And FrankenPhpClient does not implement StoppableClient, so
 * StopWorkerIfNecessary does nothing under the locked runtime: the worker keeps serving
 * on that connection. B8's single hook is necessary and not sufficient, which is why
 * this is registered on BOTH keys.
 *
 * NO PRECONDITION. It would have been easy to also assert "the connection enters at
 * level 0" at the start of a request, and it would have been wrong: §2.10's per-tenant
 * loop legitimately runs a scope inside an application transaction, so transaction
 * level 1 is a normal state to be in and a precondition would refuse it. This cleans up
 * after; it does not police before.
 *
 * IT STORES NO TENANT ID. The one thing this class must not become is a place where a
 * tenant id lives across a request boundary. It reads the transaction depth, which is a
 * number about this process, and it never reads or writes app.tenant_id -- a full
 * ROLLBACK drops a transaction-local setting by itself, and the purge is what covers
 * anything a rollback would not.
 */
final class RequestBoundaryCleanup
{
    /**
     * @param  object  $event  RequestTerminated/TaskTerminated/TickTerminated (all
     *                         OperationTerminated) or WorkerErrorOccurred
     */
    public function handle(object $event): void
    {
        // The two event shapes do not agree, and a listener registered on both has to
        // know it: OperationTerminated carries `app` and `sandbox`, while
        // WorkerErrorOccurred carries only `sandbox` -- it has no `app` property at
        // all. Reading `$event->app` alone would make the error path throw inside the
        // error path.
        $application = $this->applicationFrom($event);

        if (! $application instanceof Application) {
            return;
        }

        // Before the connections, because this is the cheap half and it is the half a
        // failing request leaks: armedFor()'s finally never runs when the throw escapes
        // the gateway. A leaked "armed" is the safe direction -- stricter, never looser
        // -- but a worker left armed would refuse the next console-shaped thing it does.
        if ($application->bound(TenantScopeGuard::class)) {
            $application->make(TenantScopeGuard::class)->disarm();
        }

        if (! $application->bound('db')) {
            return;
        }

        /** @var DatabaseManager $manager */
        $manager = $application->make('db');

        foreach ($manager->getConnections() as $name => $connection) {
            $this->reset($manager, (string) $name, $connection);
        }
    }

    /**
     * Roll an abandoned transaction back, then drop the connection.
     *
     * Only when there is something to clean. Purging unconditionally would reconnect on
     * every single request, which throws away the one thing Octane is for and would
     * also spend one of the forty qrpager_app slots per request on handshakes. So the
     * depth is the trigger: it needs no round trip, and a connection at level 0 with a
     * transaction-local setting has nothing left on it by definition (C2 makes
     * session-scoped settings a hard failure, the repo guard enforces it, and R-020's
     * boot check covers a connection that arrives dirty from outside this process).
     *
     * The purge is belt and braces on the path that is already broken: a rollback alone
     * drops a transaction-local GUC, and purging means the next request cannot inherit
     * anything the rollback did not reach -- a statement left in an aborted state, a
     * temporary table, an advisory lock.
     */
    private function reset(DatabaseManager $manager, string $name, Connection $connection): void
    {
        try {
            if ($connection->transactionLevel() === 0) {
                return;
            }

            // Down to 0 in one call: rollBack($toLevel) sets the counter to the level
            // asked for, so there is no loop to get wrong. If it does not get there --
            // handleRollBackException swallows a lost connection and resets the counter
            // itself -- the purge below is what covers it.
            $connection->rollBack(0);
        } catch (Throwable) {
            // A rollback that fails is a connection that must not be reused, which is
            // exactly what happens next. Swallowed rather than reported: this runs while
            // the worker is already handling a failure, and replacing that failure with
            // this one hides the problem the operator needs to see -- the same trade
            // assertScopeSurvived()'s rollback makes, and for the same reason.
        }

        try {
            $manager->purge($name);
        } catch (Throwable) {
            // Nothing to disconnect is the same outcome as disconnecting.
        }
    }

    private function applicationFrom(object $event): ?object
    {
        if (property_exists($event, 'app')) {
            /** @var object|null */
            return $event->app;
        }

        if (property_exists($event, 'sandbox')) {
            /** @var object|null */
            return $event->sandbox;
        }

        return null;
    }
}
