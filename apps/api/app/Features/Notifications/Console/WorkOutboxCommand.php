<?php

declare(strict_types=1);

namespace App\Features\Notifications\Console;

use App\Features\Notifications\OutboxWorker;
use Illuminate\Console\Command;

/**
 * The outbox worker's entry point. `php artisan outbox:work`.
 *
 * TWO MODES, AND --once IS NOT ONLY FOR TESTS. `--once` runs a single pass and exits,
 * which is what a proof script drives, what a cron-style scheduler would call, and what
 * makes the kill proof possible at all. Without it, loops forever with a short sleep
 * between empty passes, which is how it runs as a long-lived task.
 *
 * SIGTERM IS HANDLED, because this process will be stopped by an orchestrator on every
 * deploy and §4 › Edge & ops asks for a graceful shutdown: stop reporting live, drain
 * in-flight work, exit 0. The loop checks the flag BETWEEN passes, so a signal never
 * interrupts a pass mid-row -- the row being processed finishes, its outcome is written,
 * and then the process exits. Killing it harder than that is still safe (that is the
 * gate's own clause and bin/outbox-kill-proof drives it), but a clean stop should not
 * have to rely on the crash path.
 *
 * NOTHING SCHEDULES IT, AND THAT IS DELIBERATE RATHER THAN FORGOTTEN. A long-running
 * worker is a process an orchestrator supervises, not a cron entry: scheduling it here
 * against infrastructure that does not exist yet is R-047's shape -- a carrier
 * "provisioned nowhere runnable" -- and R-058 is the same defect in Task 3's expiry job.
 * The ECS service definition is Task 13's, and this is recorded in PLAN.md §5 (R-077)
 * with that owner so it is a tracked gap rather than a silent one.
 *
 * In App\Features\Notifications\Console and not App\Platform\Console: deptrac forbids
 * Platform from importing Features, and this is feature logic with a console entry point
 * -- the same placement ExpirePagerSessionsCommand has.
 */
final class WorkOutboxCommand extends Command
{
    protected $signature = 'outbox:work
                            {--once : run a single pass and exit}
                            {--limit= : how many rows this pass may claim}';

    protected $description = 'Claim outbox rows and send the Web Push notifications they stand for';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $this->listenForTermination();

        $limit = $this->resolveLimit();
        $sleep = max(1, (int) config('webpush.worker.sleep_seconds'));

        do {
            $tally = $this->runPass($limit);

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            // Only when there was nothing to do. A full batch means there is probably
            // more waiting, so the next pass starts immediately -- a fixed sleep after a
            // full batch is how a backlog is made to drain at one batch per second.
            if ($tally['claimed'] === 0 && ! $this->shouldStop) {
                sleep($sleep);
            }
        } while (! $this->shouldStop);

        $this->info('outbox:work — stopping on signal, nothing in flight.');

        return self::SUCCESS;
    }

    /**
     * `skipped` is R-083's counter and it belongs on this line rather than only in a log:
     * it is the number of rows this pass claimed and then did NOT send because the lease
     * was no longer its own. Zero is the expected reading, so a non-zero one is the signal
     * that `lease_seconds` is too short for what a row now costs.
     *
     * @return array{claimed: int, sent: int, retried: int, dead: int, skipped: int, pruned: int}
     */
    private function runPass(?int $limit): array
    {
        $tally = $this->laravel->make(OutboxWorker::class)->pass($limit);

        if ($tally['claimed'] > 0) {
            $this->info(sprintf(
                'outbox:work — claimed %d, sent %d, retried %d, dead %d, skipped %d, pruned %d subscription(s).',
                $tally['claimed'], $tally['sent'], $tally['retried'], $tally['dead'],
                $tally['skipped'], $tally['pruned'],
            ));
        }

        return $tally;
    }

    /**
     * Stop after the current pass when the orchestrator asks.
     *
     * pcntl is not guaranteed to be present -- it is a CLI-only extension and the gate
     * must not depend on it -- so this degrades to "no graceful stop" rather than
     * refusing to run. The ungraceful path is safe by construction, which is the gate's
     * own clause.
     */
    private function listenForTermination(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }

    private function resolveLimit(): ?int
    {
        $option = $this->option('limit');

        return is_numeric($option) ? max(1, (int) $option) : null;
    }
}
