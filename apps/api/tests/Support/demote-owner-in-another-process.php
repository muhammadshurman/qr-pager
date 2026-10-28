<?php

declare(strict_types=1);
use App\Domain\Tenancy\TenantRole;
use App\Features\Members\MemberDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

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
 * argv: 1 = tenant uuid, 2 = membership uuid to demote, 3 = acting user uuid
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
