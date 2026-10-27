<?php

declare(strict_types=1);

/*
 * The gate's own words, as assertions.
 *
 *   NOTHING LOST        every seeded row is `sent`; none is pending, none is dead, none
 *                       has vanished.
 *   NOTHING DUPLICATED  every row carries exactly one sent_at, attempts never exceeds the
 *                       configured maximum, and the dedupe key is still unique -- which is
 *                       the database's own backstop behind the lease.
 */

use App\Domain\Outbox\OutboxStatus;
use Illuminate\Support\Facades\DB;

require __DIR__.'/outbox-proof-boot.php';

$expected = max(1, (int) ($argv[1] ?? 8));
$worker = DB::connection('pgsql_worker');
$failures = [];

$counts = [];
foreach ($worker->select('select status, count(*) as n from outbox group by status') as $row) {
    $counts[(string) $row->status] = (int) $row->n;
}

$sent = $counts[OutboxStatus::Sent->value] ?? 0;
$pending = $counts[OutboxStatus::Pending->value] ?? 0;
$dead = $counts[OutboxStatus::Dead->value] ?? 0;

// NOTHING LOST
if ($sent !== $expected) {
    $failures[] = "nothing lost: expected {$expected} sent row(s), found {$sent}";
}

if ($pending !== 0) {
    $failures[] = "nothing lost: {$pending} row(s) are still pending after the recovery pass";
}

if ($dead !== 0) {
    $failures[] = "nothing lost: {$dead} row(s) dead-lettered, which a blocked-then-killed edge must not cause";
}

// NOTHING DUPLICATED
$missingTimestamp = $worker->selectOne(
    "select count(*) as n from outbox where status = 'sent' and sent_at is null"
);

if ((int) $missingTimestamp->n !== 0) {
    $failures[] = "nothing duplicated: {$missingTimestamp->n} sent row(s) carry no sent_at";
}

$max = (int) config('webpush.worker.max_attempts');
$overAttempted = $worker->selectOne('select count(*) as n from outbox where attempts > ?', [$max]);

if ((int) $overAttempted->n !== 0) {
    $failures[] = "nothing duplicated: {$overAttempted->n} row(s) exceeded max_attempts ({$max})";
}

// The database's own backstop: one row per logical event, per tenant.
$collisions = $worker->selectOne(
    'select count(*) as n from (select tenant_id, dedupe_key from outbox group by tenant_id, dedupe_key having count(*) > 1) d'
);

if ((int) $collisions->n !== 0) {
    $failures[] = "nothing duplicated: {$collisions->n} duplicated (tenant_id, dedupe_key) pair(s)";
}

printf(
    'assertions: %d sent, %d pending, %d dead; max attempts %d of %d allowed%s',
    $sent, $pending, $dead,
    (int) ($worker->selectOne('select coalesce(max(attempts), 0) as n from outbox')->n),
    $max,
    PHP_EOL,
);

if ($failures !== []) {
    echo PHP_EOL, 'outbox-kill-proof: FAILED', PHP_EOL;

    foreach ($failures as $failure) {
        echo '  - ', $failure, PHP_EOL;
    }

    exit(1);
}

echo 'outbox-kill-proof: nothing lost, nothing duplicated.', PHP_EOL;

exit(0);
