<?php

declare(strict_types=1);

use App\Domain\Outbox\OutboxStatus;
use App\Domain\Pager\PagerStatus;
use App\Features\Notifications\OutboxWorker;
use App\Features\Notifications\WebPushDispatcher;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\PushSubscriptions;
use App\Features\Pager\ResolvedPagerSession;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushFixtures;
use Tests\Support\RecordingPushEdge;

/*
 * THE GATE'S FIRST CLAUSE: "Kill the worker mid-batch -> nothing lost, nothing
 * duplicated."
 *
 * TWO CASES, AND THEY PROVE DIFFERENT HALVES.
 *
 * The first drives bin/outbox-kill-proof, which `kill -9`s a real `outbox:work` process
 * while it is blocked inside a send. That is the only way to get a process that STOPS
 * EXISTING -- a mocked interruption proves that PHP unwinds a stack, which nobody doubts.
 * The script lives in the repository because R-043 is a §5 row about exactly this: a gate
 * clause once named a demonstration whose script was in a session scratchpad, so nobody
 * could re-run it. Driving it from the suite as well means it cannot quietly rot.
 *
 * The second proves SKIP LOCKED from two real concurrent connections, which is the
 * property that makes "nothing duplicated" true while the first worker is still alive --
 * the kill proof covers what happens after it dies, and this covers what happens while it
 * does not.
 */

it('loses nothing and duplicates nothing when the worker is killed -9 mid-batch', function (): void {
    $script = base_path('bin/outbox-kill-proof');

    expect(is_executable($script))->toBeTrue('bin/outbox-kill-proof is missing or not executable');

    // Its own database, so this never touches the suite's -- the script exports
    // DB_DATABASE itself and bootstraps it. Named here as well so a reader of this test
    // knows where it went.
    $output = [];
    $status = 0;
    exec(escapeshellarg($script).' 6 2>&1', $output, $status);

    $transcript = implode(PHP_EOL, $output);

    expect($status)->toBe(0, "the kill proof failed:\n".$transcript)
        ->and($transcript)->toContain('worker killed -9 while blocked inside a send')
        ->and($transcript)->toContain('nothing lost, nothing duplicated');
})->group('slow');

it('never lets two workers hold the same outbox row, which is SKIP LOCKED', function (): void {
    // Measured through two REAL connections as qrpager_worker, with no tenant context --
    // the claim is §4 › Tenancy's named "outbox queue" path and must span tenants.
    //
    // Three statements, three different answers, and the contrast is the assertion:
    //   worker A, skip locked          -> the rows
    //   worker B, skip locked          -> NOTHING, while A holds them
    //   worker B, plain FOR UPDATE     -> blocks, and 55P03 when lock_timeout fires
    //
    // Without the third, "B saw nothing" could just mean the query was wrong.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $this->makeOutboxRow($alpha, $this->makeSession($alpha));
    $this->makeOutboxRow($beta, $this->makeSession($beta));

    $claim = "select id, tenant_id from outbox
               where status = 'pending' and available_at <= now()
               order by available_at, id
                 for update skip locked
               limit 10";

    // A second worker connection, from the first's own config -- never a second copy of
    // the DSN assembly, which is the duplicated-configuration shape QA H-1 cost us.
    config(['database.connections.pgsql_worker_second' => config('database.connections.pgsql_worker')]);

    $a = DB::connection('pgsql_worker');
    $b = DB::connection('pgsql_worker_second');

    $a->beginTransaction();

    try {
        $held = $a->select($claim);

        expect($held)->toHaveCount(2, 'the first worker did not claim both tenants’ rows')
            ->and(array_unique(array_map(fn ($r) => $r->tenant_id, $held)))->toHaveCount(2);

        $b->beginTransaction();

        try {
            expect($b->select($claim))->toBe([], 'a second worker claimed rows the first was holding');

            $b->statement('set local lock_timeout = 250');

            $blocked = $this->sqlStateOf(fn () => $b->select(str_replace('for update skip locked', 'for update', $claim)));

            expect($blocked)->toBe('55P03', 'a plain FOR UPDATE did not block, so SKIP LOCKED proved nothing');
        } finally {
            $b->rollBack();
        }
    } finally {
        $a->rollBack();
        DB::purge('pgsql_worker_second');
    }
});

/**
 * A ready session with one subscribed device, and the proof's own edge installed.
 *
 * LOCAL TO THIS FILE rather than borrowed from OutboxWorkerTest. Pest shares the global
 * function namespace across a run, so calling that file's helper would work when the whole
 * suite runs and fail when this file runs alone -- and a test that only passes in company
 * is the shape bin/concurrent-suite-proof exists because of.
 */
function leasedRowFixture(string $tenantId, array|int $edgeStatuses, ?string &$sessionId = null): void
{
    PushFixtures::configureVapid();

    $issued = app(PagerSessions::class)->create($tenantId, 'L-'.random_int(100, 999));
    $sessionId = $issued->session->id;
    $device = PushFixtures::subscription();

    $resolved = new ResolvedPagerSession(
        $issued->session->id,
        $tenantId,
        PagerStatus::Waiting,
        $issued->session->orderReference,
        new DateTimeImmutable('+2 hours'),
        null,
        null,
    );

    app(PushSubscriptions::class)->register($resolved, $device['endpoint'], $device['p256dh'], $device['auth']);
    app(PagerSessions::class)->markReady($tenantId, $issued->session->id);

    app()->instance(WebPushDispatcher::EDGE, new RecordingPushEdge($edgeStatuses));
    app()->forgetInstance(WebPushDispatcher::class);
}

it('leases a claimed row into the future, so a dead worker’s rows come back on their own', function (): void {
    // THE LEASE IS WHAT CARRIES "NOTHING LOST" PAST THE COMMIT OF THE CLAIM. A kill after
    // that commit leaves rows that are pending and not yet due; when the lease expires the
    // next pass finds them. There is nothing in memory, nothing to acknowledge, and
    // nothing that only existed in the dead process.
    //
    // Observed on a row that CANNOT finish -- the edge refuses with a 500 -- because a row
    // that succeeds has its lease replaced by `sent` in the same pass, so the lease is
    // never visible. An earlier version of this case watched a row with no devices, which
    // is sent immediately, and could therefore only assert the attempt count: its name
    // claimed more than it checked.
    $tenant = $this->makeTenant();
    leasedRowFixture($tenant, 500, $sessionId);

    $cap = (int) config('webpush.worker.backoff.cap_seconds');

    app(OutboxWorker::class)->pass();

    $row = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        "select status, attempts,
                available_at > now() as not_yet_due,
                available_at <= now() + (interval '1 second' * ?) as within_cap
           from outbox where pager_session_id = ?",
        [$cap, $sessionId],
    ), 'pgsql_owner');

    expect($row->status)->toBe(OutboxStatus::Pending->value, 'a failed row did not stay pending')
        ->and($row->attempts)->toBe(1, 'the claim did not charge an attempt')
        ->and($row->not_yet_due)->toBeTrue('the row is immediately due again, so a failing edge would be retried in a tight loop')
        ->and($row->within_cap)->toBeTrue('the row was pushed beyond the configured backoff cap');

    // And it IS claimable once the clock passes it, which is the recovery half of the
    // property -- a lease that never expired would lose the row rather than delay it.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        "update outbox set available_at = now() - interval '1 second' where pager_session_id = ?",
        [$sessionId],
    ), 'pgsql_owner');

    app()->instance(WebPushDispatcher::EDGE, new RecordingPushEdge(201));
    app()->forgetInstance(WebPushDispatcher::class);

    expect(app(OutboxWorker::class)->pass())->toMatchArray(['claimed' => 1, 'sent' => 1]);
});
