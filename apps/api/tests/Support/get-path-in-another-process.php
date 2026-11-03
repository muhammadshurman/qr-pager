<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/*
 * ================================================================================
 * ONE GET, IN A PROCESS WHOSE `APP_ENV` THE PARENT CHOOSES. `QRPAGER-31`.
 * ================================================================================
 *
 * **WHY A SECOND PROCESS IS THE ONLY HONEST WAY TO ASSERT THIS.** `bootstrap/app.php`
 * decides which route files to load *while the application is booting*, so the route table a
 * running test sees was fixed before its first line ran. `RouteSurfaceTest`'s own comment said
 * so and drew the wrong conclusion from it — it read the `if` out of `bootstrap/app.php` as a
 * STRING and asserted the string. That is not a weaker version of this proof; it is a
 * different claim. A `grep` cannot tell you that a path 404s, and it goes green for any edit
 * that keeps the characters and changes the meaning — moving the `require` out of the `if`,
 * registering the route somewhere else, or (the case that actually mattered) leaving the
 * predicate exactly as written while the set of environments it admits is wrong.
 *
 * `tests/Support/*-in-another-process.php` is the established shape for this and
 * `FixtureScriptGuardTest` is the nearest precedent: it proves two fixture scripts refuse
 * outside `local`/`testing` by running them as real processes under an overridden `APP_ENV`,
 * on the stated grounds that *"an exit code cannot be asserted from a `grep`"*. A route
 * table cannot either.
 *
 * ================================================================================
 * WHAT IT PRINTS, AND WHY ONLY THAT
 * ================================================================================
 *
 * One line on stdout: the HTTP status integer, and nothing else. No body, no headers.
 *
 * That is a deliberate narrowing rather than laziness. This child boots the real kernel and
 * handles a real request with `APP_DEBUG` at whatever `.env` says, so its response body is
 * exactly the shape `ProblemRenderer` exists to keep out of logs and transcripts — measured
 * once at 862,104 bytes carrying SQL and a bound uuid. A status code answers the only
 * question the parent asks and carries nothing that could leak through a pipe into a CI log.
 *
 * Anything that goes wrong before a status exists goes to STDERR and exits non-zero, so the
 * parent's assertion fails loudly rather than comparing against an empty string.
 *
 * USAGE:  APP_ENV=production php tests/Support/get-path-in-another-process.php /passkey-harness
 */

require __DIR__.'/../../vendor/autoload.php';

$path = $argv[1] ?? '';

if ($path === '' || ! str_starts_with($path, '/')) {
    fwrite(STDERR, "usage: get-path-in-another-process.php /some/path\n");

    exit(1);
}

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

/*
 * ⚠ THE GUARD UNDER TEST IS READ AT BOOT, SO THE ASSERTION IS ABOUT THIS LINE HAVING ALREADY
 * HAPPENED. `bootstrap()` above is what ran `bootstrap/app.php`'s route closure under this
 * process's `APP_ENV`; by here the route table either holds the path or does not, and nothing
 * below can change that. Printing the environment the child actually resolved makes a
 * mis-passed variable a visible failure rather than a silently reversed proof — the defect
 * `FixtureScriptGuardTest` records against itself, where the child read `.env` and the parent
 * asserted against a different database.
 */
$environment = (string) config('app.env');

if ($environment !== (string) getenv('APP_ENV')) {
    fwrite(STDERR, "the child resolved APP_ENV '{$environment}', the parent asked for '".(string) getenv('APP_ENV')."'\n");

    exit(1);
}

/** @var HttpKernel $http */
$http = $app->make(HttpKernel::class);

$response = $http->handle(Request::create($path, 'GET'));

echo $response->getStatusCode(), "\n";

exit(0);
