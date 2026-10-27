<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/*
 * Hold a row lock on one pager session, in a process of its own, then let it go.
 *
 * Used by PagerRouteSurfaceTest for QA L-7: of §11's four failure paths (timeout, retry,
 * duplicate, crash mid-way), TIMEOUT was the one with no Task 3 test. The app role carries
 * lock_timeout = 2s (§2.1) and `select ... for update` in PagerSessions is the only
 * statement in the project that waits on another transaction, so this script creates the
 * one condition under which that wait is exercised.
 *
 * A SECOND PROCESS AND NOT A SECOND CONNECTION, for the reason
 * mark-ready-in-another-process.php already records: `for update` would block the only
 * thread there is, so one process cannot both hold the lock and contend for it. And not a
 * nested withTenant() on another connection either -- TenantContext refuses to reuse an
 * open scope across connections, which is R-015's lesson and a composition test of its own.
 *
 * The owner connection, because FORCE RLS binds the owner too: the scope is what makes the
 * row visible, and withTenant() is the only sanctioned way to open one (C2).
 *
 * A COMMITTED FILE AND NOT A HEREDOC the test writes at runtime -- R-043.
 *
 * Prints exactly two lines, which is the test's whole interface to it:
 *   locked    -- the lock is held; the test may now contend for it. Flushed, because the
 *                test BLOCKS on this line rather than sleeping and hoping.
 *   released  -- the transaction is over.
 *
 * argv: 1 = tenant uuid, 2 = session uuid, 3 = milliseconds to hold
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenant = (string) ($argv[1] ?? '');
$session = (string) ($argv[2] ?? '');
$holdMs = (int) ($argv[3] ?? 6000);

try {
    withTenant($tenant, function () use ($session, $holdMs): void {
        DB::connection('pgsql_owner')->selectOne(
            'select id from pager_sessions where id = ? for update',
            [$session],
        );

        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);

        usleep($holdMs * 1000);
    }, 'pgsql_owner');

    fwrite(STDOUT, "released\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}
