<?php

declare(strict_types=1);
use App\Features\Pager\PagerSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/*
 * Renotify one pager session, in a process of its own.
 *
 * The sibling of mark-ready-in-another-process.php, and it exists for QA L-3: PagerSessions
 * and PagerEvent::dedupeKey both claim that "two concurrent renotifies cannot compute the
 * same attempt number -- which is what keeps the dedupe keys distinct and lets both
 * notifications through", and nothing tested it. The two-process harness existed and was
 * pointed only at markReady.
 *
 * THE CLAIM MATTERS MORE THAN markReady's. If it is wrong the failure is a 23505 rendered
 * as a 409 on a buzz the staff deliberately asked for -- a LOST NOTIFICATION, which is the
 * one thing this product exists to prevent. markReady's failure mode is the opposite and
 * milder: a duplicate.
 *
 * Two connections in one PHP process cannot produce the contention: `select ... for update`
 * would block the only thread there is, so the test would deadlock rather than demonstrate
 * ordering.
 *
 * A COMMITTED FILE AND NOT A HEREDOC written at runtime, for R-043's reason -- a script
 * generated into storage/ is the same defect with extra steps.
 *
 * Prints exactly one line:
 *   renotified:<attempt> -- this process performed the renotify, with the count it wrote
 *   error:<class>:<msg>  -- anything else, so a failure is diagnosable rather than silent
 *
 * argv: 1 = tenant uuid, 2 = session uuid
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $state = $app->make(PagerSessions::class)
        ->renotify((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));

    fwrite(STDOUT, 'renotified:'.$state->renotifyCount."\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}
