<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
 * The slow in-flight request the SIGTERM drain proof needs, and NOTHING ELSE.
 *
 * This file is not part of the application. It is mounted into the drain container at
 * /app/routes/drain.php by compose and exists nowhere else -- there is a test asserting
 * routes/drain.php is absent from the repository, so it cannot arrive by being
 * committed.
 *
 * bootstrap/app.php loads it only when the file exists AND the environment is not
 * production, which is two conditions a reviewer can see in one place. The honest
 * accounting: this is production code that reads the filesystem for an optional route
 * file. The alternative was a sleep in a middleware behind a header, which is a
 * production code path a request can trigger -- strictly worse. The gate asks for the
 * drain to be "proven against the compiled app", so the proof has to run against this
 * image rather than against a double, and a request that outlives the signal is what
 * makes a drain observable at all.
 */

Route::get('/drain/slow', function (): JsonResponse {
    $started = microtime(true);

    // Long enough that SIGTERM lands while this is still running, short enough that a
    // stuck proof fails rather than hangs.
    usleep(3_000_000);

    return response()->json([
        'completed' => true,
        'held_seconds' => round(microtime(true) - $started, 3),
        'pid' => getmypid(),
    ]);
});
