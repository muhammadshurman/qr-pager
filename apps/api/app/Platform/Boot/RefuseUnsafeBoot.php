<?php

declare(strict_types=1);

namespace App\Platform\Boot;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Boot refuses an unsafe DB role (exit 1, reason logged)" -- PLAN.md §3, row 2, the
 * first clause of this task's acceptance gate.
 *
 * Registered on Octane's WorkerStarting, which is the moment a process begins serving
 * and the only place "refuse to start" has a meaning. Deliberately NOT in a service
 * provider: every artisan command boots the same providers, and db:bootstrap and
 * migrate are the two commands that run BEFORE the roles and the schema these checks
 * read even exist. A check wired into boot for every process would make a fresh clone
 * unable to reach the command that fixes it.
 *
 * Exit, not throw. An exception out of WorkerStarting is caught by the server and the
 * worker carries on; the gate's words are "exit 1", and a process that refuses to serve
 * has to actually stop so the orchestrator replaces or fails the deployment.
 */
final class RefuseUnsafeBoot
{
    public function __construct(private readonly Container $container) {}

    /** @param  object  $event  Laravel\Octane\Events\WorkerStarting */
    public function handle(object $event): void
    {
        $this->enforce(static function (int $code): never {
            exit($code);
        });
    }

    /**
     * The reasons, logged one per line, and then whatever "stop" means to the caller.
     *
     * $stop is injected so the test proves the LOGGING and the REASONS without the test
     * process exiting, while the one caller that matters passes exit(). What the test
     * cannot prove this way is the exit code itself, so that is proven separately by
     * running `php artisan boot:check` as a real process and reading $?.
     *
     * @param  callable(int): void  $stop
     */
    public function enforce(callable $stop): void
    {
        try {
            /** @var BootChecks $checks */
            $checks = $this->container->make(BootChecks::class);
            $failures = $checks->failures();
        } catch (Throwable $error) {
            // A boot check that cannot run is a boot that must not continue. The class
            // name only -- the message of an arbitrary throwable here could carry a DSN.
            $failures = ['boot checks could not be evaluated ('.$error::class.')'];
        }

        if ($failures === []) {
            return;
        }

        /** @var LoggerInterface $log */
        $log = $this->container->make(LoggerInterface::class);

        foreach ($failures as $failure) {
            // Through the application logger, so the redaction processor on every
            // channel applies to these lines too -- they name a database role, and the
            // one place a role name belongs is a log.
            $log->critical('boot refused: '.$failure);
        }

        $stop(1);
    }
}
