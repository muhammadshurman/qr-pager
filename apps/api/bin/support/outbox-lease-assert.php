<?php

declare(strict_types=1);

/*
 * "Each endpoint received exactly one request" -- R-083's closing gate, as an assertion.
 *
 * THE EDGE IS THE WITNESS, not the table. A duplicate send leaves NO trace in `outbox`:
 * the second worker's finish() is keyed and guarded by `status = 'pending'`, so whichever
 * worker arrives second reports 0 affected rows and writes nothing at all. Reading the
 * table would therefore show eight rows sent exactly once while sixteen notifications had
 * gone out. So the count that matters is the number of REQUESTS, which only the mocked
 * edge can give, and the two workers' logs are unioned here.
 *
 * IT ALSO CHECKS "NOTHING LOST", because a fix for duplication that drops a notification
 * instead is not a fix. Every seeded row must be `sent` and every endpoint must appear.
 */

use App\Domain\Outbox\OutboxStatus;
use Illuminate\Support\Facades\DB;

require __DIR__.'/outbox-proof-boot.php';

$expected = max(1, (int) ($argv[1] ?? 8));
$logs = array_slice($argv, 2);

if ($logs === []) {
    fwrite(STDERR, "outbox-lease-assert: usage: outbox-lease-assert.php <rows> <log> [<log>...]\n");

    exit(1);
}

$sent = [];

foreach ($logs as $log) {
    $lines = is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    foreach ($lines as $line) {
        $sent[] = trim((string) $line);
    }
}

$failures = [];
$byEndpoint = array_count_values($sent);
$duplicated = array_filter($byEndpoint, static fn (int $n): bool => $n > 1);

echo 'requests at the edge: '.count($sent).' across '.count($logs).' worker(s), '
    .count($byEndpoint).' distinct endpoint(s)'.PHP_EOL;

// NOTHING DUPLICATED -- the property R-083 falsified.
if ($duplicated !== []) {
    foreach ($duplicated as $endpoint => $n) {
        $failures[] = "nothing duplicated: {$n} requests reached ".substr((string) $endpoint, 0, 60).'…';
    }
}

// NOTHING LOST -- the other half, so that "send nothing" cannot pass this proof.
if (count($byEndpoint) !== $expected) {
    $failures[] = 'nothing lost: '.count($byEndpoint)." distinct endpoint(s) were sent to, expected {$expected}";
}

$worker = DB::connection('pgsql_worker');
$counts = [];

foreach ($worker->select('select status, count(*) as n from outbox group by status') as $row) {
    $counts[(string) $row->status] = (int) $row->n;
}

$sentRows = $counts[OutboxStatus::Sent->value] ?? 0;
$pending = $counts[OutboxStatus::Pending->value] ?? 0;
$dead = $counts[OutboxStatus::Dead->value] ?? 0;

echo "outbox: sent {$sentRows}, pending {$pending}, dead {$dead}".PHP_EOL;

if ($sentRows !== $expected) {
    $failures[] = "nothing lost: {$sentRows} row(s) are sent, expected {$expected}";
}

if ($pending !== 0 || $dead !== 0) {
    $failures[] = "nothing lost: {$pending} pending and {$dead} dead row(s) remain";
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL.'outbox-lease-proof: FAILED'.PHP_EOL);

    foreach ($failures as $failure) {
        fwrite(STDERR, '  - '.$failure.PHP_EOL);
    }

    exit(1);
}

echo PHP_EOL.'outbox-lease-proof: each of the '.$expected.' endpoints received exactly one request.'.PHP_EOL;

exit(0);
