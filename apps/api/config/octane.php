<?php

declare(strict_types=1);

use App\Platform\Octane\RequestBoundaryCleanup;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

return [

    /*
    |--------------------------------------------------------------------------
    | Octane server
    |--------------------------------------------------------------------------
    |
    | FrankenPHP, not the package default of roadrunner: the runtime is locked by
    | PLAN.md §1 and the image is pinned (Q7) to
    | dunglas/frankenphp:1.13.3-php8.4-bookworm. The tag names the PHP version on
    | purpose -- 1.13.3-php8.5 ships in the same release, so the unqualified tag can
    | float to a PHP this project does not run.
    |
    */

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Listeners
    |--------------------------------------------------------------------------
    |
    | RequestBoundaryCleanup is registered on TWO keys and that is the whole point of
    | this file (R-022, gate clause (a)). Measured against the real worker: a request
    | that leaves a transaction open fires RequestTerminated, so one hook is enough for
    | it. A request that THROWS OUT OF THE GATEWAY fires only WorkerErrorOccurred --
    | Worker::handle()'s catch goes to handleWorkerError() -- and the next request on
    | that worker entered at transaction level 1, in a transaction, with the previous
    | request's tenant uuid still in force. FrankenPhpClient is not a StoppableClient,
    | so StopWorkerIfNecessary does nothing under this runtime and the worker keeps
    | serving on that connection. B8's single hook was necessary and not sufficient.
    |
    | It is the FIRST listener on both keys. On the error path it has to run before
    | ReportException, so the reporting is not the thing that first touches a connection
    | left mid-transaction.
    |
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            RequestBoundaryCleanup::class,
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],

        WorkerErrorOccurred::class => [
            RequestBoundaryCleanup::class,
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / flush bindings
    |--------------------------------------------------------------------------
    |
    | TenantContext is deliberately absent from `warm`, and `flush` is deliberately
    | empty rather than listing it: it is bound scoped(), which
    | FlushTemporaryContainerInstances forgets on OperationTerminated. Warming it would
    | move it into the root application and share $openScopes across requests for the
    | worker's life -- D10 measured that the current per-request behaviour is correct by
    | accident, because nothing warms it, and scoped() is what makes it correct on
    | purpose.
    |
    */

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        //
    ],

    'tables' => [
        //
    ],

    /*
    | Octane's own cache table is not used: Redis is the locked cache and the only
    | shared state (PLAN.md §1), and a second per-worker cache would be a second
    | source of truth for exactly the readiness value §6.3 keeps in one Redis key.
    */
    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'watch' => [
        'app',
        'bootstrap/app.php',
        'config',
        'database/migrations',
        'routes',
        'composer.lock',
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage collection threshold
    |--------------------------------------------------------------------------
    */

    'garbage' => 50,

    /*
    |--------------------------------------------------------------------------
    | Maximum execution time
    |--------------------------------------------------------------------------
    |
    | Zero, because the request-level bound that matters here is the per-role
    | statement_timeout in config/tenancy.php -- 5s for qrpager_app -- which Postgres
    | enforces whatever PHP believes. A second, longer timeout in PHP would only
    | obscure which limit fired.
    |
    */

    'max_execution_time' => 0,

];
