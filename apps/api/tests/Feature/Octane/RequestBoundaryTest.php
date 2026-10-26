<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantScopeGuard;
use App\Platform\Octane\RequestBoundaryCleanup;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Listeners\ReportException;
use Tests\Support\OctaneWorker;

/*
 * R-022 and gate clause (a), approved on 2026-10-26:
 *
 *   "A connection returned to the pool carries no open transaction and no tenant
 *    setting, proven by two consecutive operations on one connection."
 *
 * Driven through the REAL Laravel\Octane\Worker, because the row exists precisely
 * because the hooks do not fire in the shape that was assumed. A double would have
 * reproduced the assumption.
 *
 * EXPECTED OUTPUT, so nobody later reads it as a failure: running this file prints two
 * `{"type":"throwable","class":"Tests\Support\DeliberateWorkerFailure",...}` lines.
 * They are Octane's own ReportException listener doing its job -- Stream::throwable()
 * writes a worker error to STDERR in local and testing, and `php artisan test` merges
 * the child process's stderr into its own stdout. The gate's JSON line is still the
 * LAST line, which was checked. The alternative was to stop ReportException from
 * running, and every way of doing that either removed the listener chain this file is
 * here to prove or made production config read a test switch.
 */

/** A connection left exactly as a request that abandoned a tenant scope leaves it. */
function leaveATransactionOpen(Connection $connection, string $tenant): void
{
    $connection->beginTransaction();

    // Transaction-local, third argument the literal true (C2) -- the state is an
    // ABANDONED scope, not an illegal one, which is the whole point: C2 is satisfied
    // and the setting still outlives the request, because nothing closed the
    // transaction it is local to.
    $connection->select('select set_config(?, ?, true)', [DatabaseRoles::guc('tenant'), $tenant]);
}

function tenantSettingOn(Connection $connection): string
{
    return (string) $connection->selectOne(
        'select coalesce(current_setting(?, true), ?) as value',
        [DatabaseRoles::guc('tenant'), ''],
    )->value;
}

function backendPidOf(Connection $connection): int
{
    return (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
}

it('rolls back a transaction a completed request abandoned, and does not reuse the connection', function (): void {
    $tenant = (string) Str::uuid7();

    OctaneWorker::run(base_path(), function (OctaneWorker $worker) use ($tenant): void {
        $before = backendPidOf($worker->connection());

        leaveATransactionOpen($worker->connection(), $tenant);

        expect($worker->connection()->transactionLevel())->toBe(1)
            ->and(tenantSettingOn($worker->connection()))->toBe($tenant);

        // One real request through Worker::handle(). RequestTerminated fires, the
        // cleanup runs, and the operation AFTER it is what the clause is about.
        $worker->handle('/healthz');

        expect($worker->responseCount())->toBe(1)
            ->and($worker->errors())->toBe([]);

        // The second of the clause's two consecutive operations.
        expect($worker->connection()->transactionLevel())->toBe(0)
            ->and(tenantSettingOn($worker->connection()))->toBe('')
            ->and(backendPidOf($worker->connection()))->not->toBe($before);
    });
});

it('cleans up after a request that throws, where RequestTerminated never fires — B8 corrected', function (): void {
    $tenant = (string) Str::uuid7();

    OctaneWorker::run(base_path(), function (OctaneWorker $worker) use ($tenant): void {
        leaveATransactionOpen($worker->connection(), $tenant);

        // The throw lands after the response, so Worker::handle() has already called
        // respond() and has NOT yet called $gateway->terminate() -- the only thing that
        // dispatches RequestTerminated. handleWorkerError() dispatches
        // WorkerErrorOccurred and nothing else. Measured without the second
        // registration: the next request entered at transaction level 1, in a
        // transaction, with this uuid still in force.
        $worker->throwAfterResponding()->handle('/healthz');

        expect($worker->responseCount())->toBe(1);

        expect($worker->connection()->transactionLevel())->toBe(0)
            ->and(tenantSettingOn($worker->connection()))->toBe('');
    });
});

it('leaves a clean connection alone, so the cleanup is not a reconnect on every request', function (): void {
    OctaneWorker::run(base_path(), function (OctaneWorker $worker): void {
        $pid = backendPidOf($worker->connection());

        $worker->handle('/healthz')->handle('/healthz')->handle('/healthz');

        // Same backend after three requests. Purging unconditionally would have been
        // the easy way to satisfy the clause above and would have thrown away the one
        // thing Octane is for, while spending a qrpager_app connection slot per request
        // on handshakes. The transaction depth is the trigger, and it needs no round
        // trip to read.
        expect(backendPidOf($worker->connection()))->toBe($pid)
            ->and($worker->connection()->transactionLevel())->toBe(0);
    });
});

it('registers the cleanup on both keys, and first on each', function (): void {
    $listeners = config('octane.listeners');

    $operation = $listeners[OperationTerminated::class];
    $error = $listeners[WorkerErrorOccurred::class];

    expect($operation[0])->toBe(RequestBoundaryCleanup::class)
        ->and($error[0])->toBe(RequestBoundaryCleanup::class);

    // First on the error key for a reason: ReportException runs on that key too, and
    // reporting must not be the thing that first touches a connection left
    // mid-transaction.
    expect($error)->toContain(ReportException::class);
});

it('forgets tenant context at the request boundary even when the root app holds it — D10', function (): void {
    OctaneWorker::run(base_path(), function (OctaneWorker $worker): void {
        // D10 measured that TenantContext is already per-request under Octane, but BY
        // ACCIDENT: it was bound singleton() and merely never warmed, so each request
        // resolved it inside the sandbox clone. Anything that resolves it during boot --
        // adding it to octane.warm, or a future provider touching it -- moves it into
        // the root application and $openScopes starts carrying between requests. This
        // test does that deliberately, which is the only way to prove scoped() is
        // load-bearing rather than cosmetic.
        $warmed = $worker->app()->make(TenantContext::class);

        $worker->handle('/healthz');

        // FlushTemporaryContainerInstances runs on OperationTerminated and forgets
        // scoped instances, so the root application no longer holds the warmed one.
        expect($worker->app()->make(TenantContext::class))->not->toBe($warmed);
    });
});

it('disarms the tenant scope guard at the boundary, including after a throw', function (): void {
    OctaneWorker::run(base_path(), function (OctaneWorker $worker): void {
        $guard = $worker->app()->make(TenantScopeGuard::class);

        // armedFor()'s finally never runs when the throw escapes the gateway, and the
        // guard is a root-application singleton, so the flag would outlive the request.
        // A leaked "armed" is the safe direction -- stricter, never looser -- but a
        // worker left armed would refuse the next console-shaped thing it does.
        $worker->throwAfterResponding()->handle('/healthz');

        expect($guard->isArmed())->toBeFalse();
    });
});
