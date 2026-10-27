<?php

declare(strict_types=1);
use App\Features\Pager\PagerSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/*
 * Mark one pager session ready, in a process of its own.
 *
 * Used by PagerIdempotencyTest to get TRUE concurrency for the §3 gate clause "markReady
 * is idempotent under concurrent calls -- one outbox row, one notification". Two
 * connections in one PHP process cannot produce it: `select ... for update` would block
 * the only thread there is, so the test would deadlock rather than demonstrate ordering.
 * Two processes contend on the row for real.
 *
 * A COMMITTED FILE AND NOT A HEREDOC the test writes at runtime. R-043 is a §5 row
 * because a gate clause named a demonstration whose script lived in a session scratchpad,
 * so it could not be re-run; a script generated into storage/ at runtime is the same
 * defect with extra steps -- nobody can read it without running the test first.
 *
 * Prints exactly one line, which is the test's whole interface to it:
 *   applied            -- this process performed the transition
 *   idempotent         -- it found the session already ready and wrote nothing
 *   error:<class>:<msg>-- anything else, so a failure is diagnosable rather than silent
 *
 * argv: 1 = tenant uuid, 2 = session uuid
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $state = $app->make(PagerSessions::class)
        ->markReady((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));

    fwrite(STDOUT, $state->changed ? "applied\n" : "idempotent\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}
