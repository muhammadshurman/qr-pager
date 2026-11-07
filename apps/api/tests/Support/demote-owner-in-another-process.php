<?php

declare(strict_types=1);
use App\Domain\Tenancy\TenantRole;
use App\Features\Members\MemberDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\StartBarrier;

/*
 * Demote one tenant owner to staff, in a process of its own.
 *
 * Used by MemberConcurrencyTest to get TRUE concurrency for the last-owner rule. Two
 * connections in one PHP process cannot produce it: the `select ... for update` the rule now
 * takes would block the only thread there is, so the test would deadlock rather than
 * demonstrate ordering. Two processes contend on the owner rows for real.
 *
 * A COMMITTED FILE AND NOT A HEREDOC the test writes at runtime, for the reason
 * `mark-ready-in-another-process.php` states: `R-043` is a `§5` row because a gate clause
 * named a demonstration whose script lived in a session scratchpad, and a script generated
 * into `storage/` at runtime is the same defect with extra steps.
 *
 * Prints exactly one line, which is the test's whole interface to it:
 *   demoted            -- this process performed the demotion
 *   refused            -- the last-owner rule refused it
 *   error:<class>:<msg>-- anything else, so a failure is diagnosable rather than silent
 *
 * ⚠ A START BARRIER, BECAUSE THE RACE WAS PROBABILISTIC WITHOUT ONE. Launching two
 * children and letting them run gave the parent no control over WHEN each reached its
 * transaction: each spends ~150 ms booting Laravel and then a sub-millisecond transaction,
 * so whether the two overlapped was luck. The `§11` review of the fix diff measured it --
 * against the UNLOCKED code the test went red in only 4 of 5 runs, so the only guard on a
 * tenant with zero owners (no in-product repair before Task 6) would have let the defect
 * through one run in five.
 *
 * So the boot happens BEFORE the race: the child writes `<barrier>.ready.<pid>`, spins until
 * the parent creates `<barrier>.go`, and only then calls `changeRole`. The parent waits for
 * both ready files before writing `go`. That takes Laravel's boot out of the window and
 * leaves the two transactions starting within microseconds of each other.
 *
 * argv: 1 = tenant uuid, 2 = membership uuid to demote, 3 = acting user uuid,
 *       4 = barrier path prefix (optional; no barrier when absent)
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$barrier = (string) ($argv[4] ?? '');

if ($barrier !== '') {
    // Booted and ready. The parent is watching for this file.
    @file_put_contents($barrier.'.ready.'.getmypid(), '1');

    // Spin rather than sleep: the whole point is to start within microseconds of the
    // sibling, and a sleep granularity of milliseconds is the window being removed. Bounded
    // so a lost parent cannot hang a test run forever.
    // ⚠ THE BOUND COMES FROM `Tests\Support\StartBarrier` AND IS LONGER THAN THE PARENT'S,
    // which is a correctness property rather than tidiness. It was a hard-coded 10s against a
    // parent that waited 30, so a child that booted fast and a sibling that booted slowly made
    // THIS process time out from the inside while the parent was still legitimately waiting --
    // and because the `.ready` file stays on disk, the parent then released a barrier nobody was
    // waiting on and read `error:BarrierTimeout` as the race's outcome. The parent must always be
    // the side that times out, because it is the side that can say why.
    $deadline = microtime(true) + StartBarrier::CHILD_SECONDS;

    while (! is_file($barrier.'.go')) {
        if (microtime(true) > $deadline) {
            fwrite(STDOUT, "error:BarrierTimeout:the parent never wrote the go file\n");
            exit(1);
        }

        usleep(50);
    }
}

try {
    $changed = $app->make(MemberDirectory::class)->changeRole(
        (string) ($argv[1] ?? ''),
        (string) ($argv[2] ?? ''),
        TenantRole::Staff,
        (string) ($argv[3] ?? ''),
        null,
    );

    fwrite(STDOUT, $changed ? "demoted\n" : "refused\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}
