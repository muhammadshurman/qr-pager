<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;

/*
 * ================================================================================
 * ONE CONFIG VALUE, IN A PROCESS WHOSE ENVIRONMENT THE PARENT CHOOSES. TASK 12, R-234.
 * ================================================================================
 *
 * **WHY A SECOND PROCESS.** `config/ratelimit.php` decides whether to honour
 * `RATELIMIT_SCALE` from `env('APP_ENV')`, and that decision is made once, by the config
 * loader, before any test's first line runs. A running test can re-`require` the file (which
 * `RateLimitScaleTest::ratelimitConfig()` does, and that is a real proof of what the FILE
 * returns) but it cannot change what the booted APPLICATION resolved, and the clause the row
 * asks for is about a production process: *"auth-login stays at 10 with RATELIMIT_SCALE=20
 * and APP_ENV=production"*.
 *
 * `tests/Support/*-in-another-process.php` is the established shape, and
 * `get-path-in-another-process.php` is the nearest precedent — written for exactly this
 * reason at `QRPAGER-31`, where a case that read an `if` out of `bootstrap/app.php` as a
 * STRING was green while `staging` served a credential surface. The lesson generalises: a
 * guard keyed on an environment name is only proven by processes that carry names nobody
 * enumerated when the guard was written.
 *
 * ================================================================================
 * WHAT IT PRINTS, AND WHY ONLY THAT
 * ================================================================================
 *
 * One line on stdout: the value at the requested dotted config key, cast to a string. Scalars
 * and null only — a key that resolves to an array or an object exits non-zero rather than
 * printing something the parent would have to parse.
 *
 * ⚠ AND IT REFUSES A KEY THAT COULD CARRY A SECRET, which is Task 12's own named invariant
 * applied to its own instrument: this child boots the real application with the real `.env`,
 * so `database.connections.pgsql.password`, `app.key` and every mail or AWS credential are
 * one dotted string away from a CI log. The allowlist of readable PREFIXES is below. A test
 * that needs a value outside it is a test that should not be shipping that value through a
 * pipe.
 *
 * Anything that goes wrong before a value exists goes to STDERR and exits non-zero, so the
 * parent's assertion fails loudly rather than comparing against an empty string.
 *
 * USAGE:  APP_ENV=production RATELIMIT_SCALE=20 \
 *           php tests/Support/get-config-in-another-process.php ratelimit.buckets.auth-login.per_ip.attempts
 */

require __DIR__.'/../../vendor/autoload.php';

/**
 * Config prefixes this script will read out loud.
 *
 * Narrow on purpose and extended by a diff a reviewer sees, which is the same mechanism
 * `RouteSurfaceTest`'s allowlist is. `ratelimit` and `shedding` are bounds; `app.env` and
 * `app.debug` are what a caller asserts the child really resolved.
 */
const READABLE = [
    'ratelimit.',
    'shedding.',
    'app.env',
    'app.debug',
];

$key = $argv[1] ?? '';

if ($key === '') {
    fwrite(STDERR, "usage: get-config-in-another-process.php some.dotted.key\n");

    exit(1);
}

$readable = false;

foreach (READABLE as $prefix) {
    if ($key === rtrim($prefix, '.') || str_starts_with($key, $prefix)) {
        $readable = true;

        break;
    }
}

if (! $readable) {
    fwrite(STDERR, "[{$key}] is not on this script's readable prefixes: ".implode(', ', READABLE)."\n");

    exit(1);
}

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

/*
 * ⚠ THE CHILD SAYS WHICH ENVIRONMENT IT RESOLVED, and the parent asserts it. Without this a
 * mis-passed variable reverses the proof silently: the child reads `.env`, resolves `local`,
 * honours the scale, and the parent reads a number that proves the opposite of what it
 * claims. `FixtureScriptGuardTest` records that exact defect against itself.
 */
$environment = (string) config('app.env');
$asked = (string) getenv('APP_ENV');

if ($asked !== '' && $environment !== $asked) {
    fwrite(STDERR, "the child resolved APP_ENV '{$environment}', the parent asked for '{$asked}'\n");

    exit(1);
}

/** @var mixed $value */
$value = config($key);

if (is_array($value) || is_object($value)) {
    fwrite(STDERR, "[{$key}] resolves to a ".gettype($value).", and this script prints scalars only\n");

    exit(1);
}

echo $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value), "\n";

exit(0);
