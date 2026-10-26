<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Worker;
use RuntimeException;

/**
 * The real Laravel\Octane\Worker, driven in a test.
 *
 * Not a fake and not a mock: R-022 exists because the request-termination hooks do not
 * fire in the shape the planning chat assumed, and a double would have reproduced the
 * assumption rather than the behaviour. What this class adds is only the bookkeeping --
 * booting a worker replaces the global container, because Application's constructor
 * calls Container::setInstance() and Octane's CurrentApplication::set() calls it again,
 * so the test's own application has to be put back or every test after this one runs
 * against a dead container.
 */
final class OctaneWorker
{
    private Worker $worker;

    private FakeClient $client;

    private ?Container $previousContainer = null;

    private ?Application $previousFacadeApp = null;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    public function __construct(private readonly string $basePath)
    {
        $this->client = new FakeClient([]);
    }

    public function boot(): self
    {
        // APP_DEBUG=false for the worker's own application, and this was found by the
        // suite rather than reasoned out. A worker is a SERVING process, so
        // RefuseUnsafeBoot runs on WorkerStarting -- and §4 forbids APP_DEBUG outside
        // `local`, while the suite runs as `testing` with .env shipping APP_DEBUG=true.
        // The boot check therefore refused the worker and exited 1, which took the test
        // process with it ("Premature end of PHP process"). The check was right; the
        // worker this file boots has to be configured the way a serving process has to
        // be. Set in the environment rather than in config, because the worker builds
        // its own application and reads .env for itself.
        $this->rememberAndSetEnvironment(['APP_DEBUG' => 'false']);

        $this->previousContainer = Container::getInstance();
        $facadeApp = Facade::getFacadeApplication();
        $this->previousFacadeApp = $facadeApp instanceof Application ? $facadeApp : null;

        $this->worker = new Worker(new ApplicationFactory($this->basePath), $this->client);
        $this->worker->boot([Client::class => $this->client]);

        return $this;
    }

    /** The worker's ROOT application -- the one a sandbox is cloned from each request. */
    public function app(): Application
    {
        $app = Container::getInstance();

        if (! $app instanceof Application) {
            throw new RuntimeException('the worker is not the current application; call boot() first');
        }

        return $app;
    }

    /**
     * A connection on the worker's shared database manager.
     *
     * setApplication() is not decoration, and finding out why took a failing test.
     * Octane's own GiveNewApplicationInstanceToDatabaseManager points the SHARED
     * manager at the current sandbox on every RequestReceived, and Worker::handle()
     * flushes that sandbox when the request ends. So between requests the manager holds
     * an application with no config bound, and asking it for a new connection fails
     * with "Target class [config] does not exist". In the server loop the next request
     * re-points it; a test that inspects the connection between requests has to do the
     * same thing, which is all this line is. It is also why the cleanup can still purge:
     * OperationTerminated and WorkerErrorOccurred both fire BEFORE the finally that
     * flushes the sandbox.
     */
    public function connection(string $name = 'pgsql'): Connection
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app()->make('db');
        $manager->setApplication($this->app());

        /** @var Connection */
        return $manager->connection($name);
    }

    /** One request through Worker::handle(), which is the whole point of using the real worker. */
    public function handle(string $uri = '/healthz'): self
    {
        $this->worker->handle(Request::create($uri, 'GET'), new RequestContext);

        return $this;
    }

    /**
     * Make the next request throw AFTER the response has been sent.
     *
     * Octane's own ReportException listener writes the throwable to STDERR in local and
     * testing (Stream::throwable -> Octane::writeError), so a run of this file prints
     * two JSON lines there. That is Octane reporting a failure it was handed, on the
     * stream meant for it; stdout -- where `php artisan test`'s gate line goes -- stays
     * clean, which was checked rather than assumed. An `ignore()` on the exception
     * handler was tried first and did nothing, because Stream::throwable does not go
     * through the handler at all. A control that changes nothing is worse than no
     * control, so it is not here.
     *
     * This is the measured asymmetry, reached through public API rather than described:
     * Worker::handle() calls the request-handled callbacks BEFORE $gateway->terminate(),
     * and terminate() is the only thing that dispatches RequestTerminated. A callback
     * that throws therefore lands in handleWorkerError(), which dispatches
     * WorkerErrorOccurred and nothing else -- so the request is over, the client has its
     * response, and the hook B8 relies on never fired.
     */
    public function throwAfterResponding(string $message = 'deliberate, after responding'): self
    {
        $this->worker->onRequestHandled(function () use ($message): void {
            throw new DeliberateWorkerFailure($message);
        });

        return $this;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return array_values(array_map('strval', $this->client->errors));
    }

    public function responseCount(): int
    {
        return count($this->client->responses);
    }

    /**
     * @param  array<string, string>  $variables
     *
     * Env::enablePutenv() at the end is not optional, and finding out why cost a
     * debugging session worth writing down. Setting the variable alone does nothing:
     * phpdotenv's immutable writer refuses to overwrite a variable that already exists
     * in the environment UNLESS dotenv loaded that variable itself, and
     * Env::getRepository() is a STATIC SINGLETON -- so the writer that loaded .env for
     * the test's own application still remembers APP_DEBUG as its own, and the worker's
     * fresh safeLoad() puts .env's value straight back over the top. Nulling the
     * repository gives a writer with an empty `loaded` set, after which "already in the
     * environment" means what it says.
     */
    private function rememberAndSetEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        Env::enablePutenv();
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $this->previousEnvironment = [];

        Env::enablePutenv();
    }

    public function shutdown(): void
    {
        $this->restoreEnvironment();

        if ($this->previousContainer !== null) {
            Container::setInstance($this->previousContainer);
            Facade::clearResolvedInstances();

            if ($this->previousFacadeApp !== null) {
                Facade::setFacadeApplication($this->previousFacadeApp);
            }

            $this->previousContainer = null;
        }
    }

    /** @param  Closure(self): void  $body */
    public static function run(string $basePath, Closure $body): void
    {
        $worker = new self($basePath);

        try {
            $body($worker->boot());
        } finally {
            $worker->shutdown();
        }
    }
}
