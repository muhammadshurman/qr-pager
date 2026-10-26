<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Readiness\SaturationProbe;
use Illuminate\Console\Command;

/**
 * The readiness carrier (§6.3): measure, write one Redis key, exit.
 *
 * Run by `php artisan schedule:work` on ->everyFiveSeconds(), from a second process --
 * locally a second terminal, on Fargate a second container IN THE SAME TASK DEFINITION.
 * That co-location is what makes staleness meaningful: a dead carrier is a task that
 * should be replaced, so "stale ⇒ not ready" is the correct reading rather than a
 * dangerous one. A request-path sampled refresh was rejected for the opposite reason --
 * under zero traffic the value goes stale and both readings are wrong, because "stale ⇒
 * not ready" would kill an idle task and "stale ⇒ ready" means readiness has stopped
 * reflecting real state.
 *
 * It needs pcntl in the image, which the base FrankenPHP image does not ship: Laravel's
 * trap() is how schedule:work drains on SIGTERM.
 *
 * Exits 0 even when it could not write. The scheduler runs it again in five seconds,
 * and a non-zero exit from a repeatable event is noise rather than signal -- the
 * SILENCE is the signal, because the key it writes carries measured_at and readiness
 * treats a stale value as not ready.
 */
final class RefreshReadinessCommand extends Command
{
    protected $signature = 'readiness:refresh';

    protected $description = 'Measure pool saturation and dependency health into the readiness key (PLAN.md §6.3)';

    public function handle(SaturationProbe $probe): int
    {
        $snapshot = $probe->measure(time());
        $written = $probe->publish($snapshot);

        $this->line(json_encode($snapshot->toArray() + ['written' => $written], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
