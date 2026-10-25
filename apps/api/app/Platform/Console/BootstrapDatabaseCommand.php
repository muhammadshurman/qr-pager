<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Database\RoleBootstrapper;
use Illuminate\Console\Command;

class BootstrapDatabaseCommand extends Command
{
    protected $signature = 'db:bootstrap';

    protected $description = 'Create the database and the owner/app/worker roles, and apply their timeouts';

    public function handle(RoleBootstrapper $bootstrapper): int
    {
        foreach ($bootstrapper->run() as $line) {
            $this->line('  '.$line);
        }

        $this->info('Database roles bootstrapped. Migrate with: php artisan migrate --database=pgsql_owner');

        return self::SUCCESS;
    }
}
