<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Boot\BootChecks;
use Illuminate\Console\Command;

/**
 * The boot checks as a command, so "exit 1, reason logged" is something a person, a
 * container and CI can all run.
 *
 * The same BootChecks the WorkerStarting listener uses -- not a second copy of the
 * rules. It exists because an exit code is only provable by running a process: a test
 * that calls the listener can prove the reasons and the logging, and nothing but a real
 * process can prove the 1.
 */
final class CheckBootCommand extends Command
{
    protected $signature = 'boot:check {--connection= : the connection to verify, default database.default}';

    protected $description = 'Verify this process is safe to serve traffic (PLAN.md §3, row 2)';

    public function handle(BootChecks $checks): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $failures = $checks->failures($connection);

        if ($failures === []) {
            $this->info('boot checks passed: safe to serve traffic.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->getOutput()->writeln('<error>boot refused:</error> '.$failure);
            logger()->critical('boot refused: '.$failure);
        }

        return self::FAILURE;
    }
}
