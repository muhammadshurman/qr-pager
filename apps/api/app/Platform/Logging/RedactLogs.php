<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Illuminate\Log\Logger;

/**
 * The `tap` every channel in config/logging.php carries.
 *
 * `tap` rather than the `processors` config key, because `processors` is only read by
 * the `monolog` driver (LogManager::createMonologDriver) while `tap` is applied to
 * every channel the manager resolves -- verified in the Laravel 13 source. A `stack`
 * channel then inherits its children's processors, because createStackDriver collects
 * getProcessors() from each child it builds.
 *
 * Listed per channel rather than pushed globally from a provider, so it is visible in
 * config and a channel added without it is a diff a reviewer sees. The belt for that
 * brace is LogRedactionTest, which fails when any configured channel lacks this tap.
 */
final class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new RedactingProcessor);
        }
    }
}
