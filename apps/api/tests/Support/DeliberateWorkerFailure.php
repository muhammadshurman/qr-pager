<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The failure the Octane boundary tests inject on purpose.
 *
 * Its own class so the worker's real exception handler can be told to ignore exactly
 * this and nothing else: a deliberate throw reported through the dev reporter prints
 * to stdout, and `php artisan test`'s last line is a gate that has to stay parseable.
 */
final class DeliberateWorkerFailure extends RuntimeException {}
