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
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\Support\PushFixtures;
use Tests\Support\RecordingPushEdge;

/*
 * R-083 -- THE LEASE, AND WHO HOLDS IT.
 *
 * THE DEFECT, found independently by this task's QA and Security §11 reviews on
 * 2026-10-27 and recorded as one row because it is one root reached from two directions.
 * OutboxWorker::claim() leased every claimed row to `now() + lease_seconds` in one short
 * transaction and COMMITTED; pass() then walked the batch one row at a time, each row
 * costing up to one HTTP send per device. After that commit SKIP LOCKED protects nothing
 * -- only `available_at` does -- so any row the worker had not reached by `claim_time +
 * lease_seconds` was `pending AND available_at <= now()` again, a second worker claimed
 * it, and BOTH sent. QA reached it from the batch-wide lease; Security reached it from the
 * unbounded device count, which is the other way one row's processing grows past its own
 * lease. It falsified Task 4's own §3.1 row 4 invariant -- "nothing lost, nothing
 * duplicated" -- for the multi-worker configuration the design claims to support.
 *
 * THE FIX IS TWO THINGS, both of them Muhammad's ruling of 2026-10-27:
 *   (1) RE-LEASE PER ROW. OutboxWorker::reLease() re-takes the lease on one row,
 *       immediately before that row's send, keyed and guarded, and checks the affected-row
 *       count. Zero means another worker owns it: skip silently, send nothing.
 *   (2) A DEVICE CAP. PushSubscriptions::forSession() takes a LIMIT, so one row's
 *       processing is bounded and the lease can be sized to exceed it.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM OutboxWorkerTest. That file is about what one
 * worker does to a row. Every case here is about what happens when the row stops being
 * this worker's -- a different question, and one that needs a competing writer in the
 * middle of a pass.
 *
 * ⚠ EVERY CASE HERE WAS PROVEN RED WITHOUT ITS FIX, in the round that wrote it. The
 * two-process proof is the one QA asked for and the overnight run deliberately did not
 * write, because the design it had to be built around was Muhammad's to choose.
 */

/**
 * A ready session with `$devices` subscribed devices, and the edge installed.
 *
 * LOCAL TO THIS FILE rather than borrowed from OutboxWorkerTest or OutboxCrashTest, for
 * the reason OutboxCrashTest records: Pest shares the global function namespace across a
 * run, so calling another file's helper works when the whole suite runs and fails when
 * this file runs alone -- and a test that only passes in company is the shape
 * bin/concurrent-suite-proof exists because of.
 *
 * @return list<string> the endpoints registered, oldest first
 */
function leaseFixture(string $tenantId, int $devices = 1, ?string &$sessionId = null): array
{
    PushFixtures::configureVapid();

    $issued = app(PagerSessions::class)->create($tenantId, 'R-'.random_int(100, 999));
    $sessionId = $issued->session->id;

    $resolved = new ResolvedPagerSession(
        $issued->session->id,
        $tenantId,
        PagerStatus::Waiting,
        $issued->session->orderReference,
        new DateTimeImmutable('+2 hours'),
        null,
        null,
    );

    $endpoints = [];

    for ($i = 0; $i < $devices; $i++) {
        $device = PushFixtures::subscription();
        app(PushSubscriptions::class)->register($resolved, $device['endpoint'], $device['p256dh'], $device['auth']);
        $endpoints[] = $device['endpoint'];

        // created_at is what forSession() orders by and what the cap therefore keeps, and
        // Postgres resolves now() to the TRANSACTION's start -- so rows written in quick
        // succession can share a timestamp and the order becomes arbitrary. Nudged apart
        // as the owner, which is a harness placing a precondition rather than an
        // application path, so that "the cap keeps the oldest" is a property this test can
        // actually observe instead of a coin toss.
        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->update(
            'update push_subscriptions set created_at = now() - (interval \'1 second\' * ?) where endpoint = ?',
            [$devices - $i, $device['endpoint']],
        ), 'pgsql_owner');
    }

    app(PagerSessions::class)->markReady($tenantId, $issued->session->id);

    return $endpoints;
}

/** The edge, with an optional hook that runs INSIDE a send. */
function leaseEdge(array|int $statuses = 201, ?callable $onSend = null): RecordingPushEdge
{
    $edge = new RecordingPushEdge($statuses, false, $onSend);

    app()->instance(WebPushDispatcher::EDGE, $edge);
    app()->forgetInstance(WebPushDispatcher::class);

    return $edge;
}

/** One outbox row, read as the owner so no policy can hide it from the assertion. */
function leaseRow(string $tenantId, string $sessionId): object
{
    /** @var object $row */
    $row = withTenant($tenantId, fn () => DB::connection('pgsql_owner')->selectOne(
        'select id, status, attempts, sent_at, last_error, available_at from outbox where pager_session_id = ?',
        [$sessionId],
    ), 'pgsql_owner');

    return $row;
}

/**
 * Take a row away from the worker that holds it, exactly as a second worker's claim does.
 *
 * THE SAME STATEMENT claim() runs -- `attempts = attempts + 1`, a fresh lease, keyed and
 * guarded by `status = 'pending'` -- so what this simulates is a real competing claim and
 * not a hand-made state no worker produces. It runs on the worker's OWN connection and
 * policy, which is what makes it a legitimate stand-in.
 */
function stealRow(string $outboxId, int $leaseSeconds = 60): int
{
    return DB::connection('pgsql_worker')->update(
        "update outbox
            set attempts = attempts + 1,
                available_at = now() + (interval '1 second' * ?),
                updated_at = now()
          where id = ? and status = 'pending'",
        [$leaseSeconds, $outboxId],
    );
}

// =====================================================================================
// 1. THE TWO-PROCESS PROOF -- the acceptance evidence R-083's closing gate names
// =====================================================================================

it('sends each notification exactly once across two concurrent worker processes', function (): void {
    // DRIVEN FROM THE SUITE so it cannot quietly rot, which is R-043's clause and the same
    // reason OutboxCrashTest drives bin/outbox-kill-proof. The script runs TWO real
    // `outbox:work --once` processes against one database with a one-second lease and a
    // slow edge, and counts requests AT THE EDGE -- because a duplicate send leaves no
    // trace in `outbox` at all: the losing worker's keyed, status-guarded finish() reports
    // 0 affected rows and writes nothing.
    //
    // RED BEFORE THE FIX, measured in the round that wrote it: 12 requests for 8 rows,
    // four endpoints buzzed twice, while the table still read "sent 8, pending 0, dead 0".
    $script = base_path('bin/outbox-lease-proof');

    expect(is_executable($script))->toBeTrue('bin/outbox-lease-proof is missing or not executable');

    $output = [];
    $status = 0;
    exec(escapeshellarg($script).' 8 2>&1', $output, $status);

    $transcript = implode(PHP_EOL, $output);

    expect($status)->toBe(0, "the two-process lease proof failed:\n".$transcript)
        ->and($transcript)->toContain('each of the 8 endpoints received exactly one request');
})->group('slow');

// =====================================================================================
// 2. THE ROW WHOSE LEASE WAS STOLEN MID-BATCH
// =====================================================================================

it('skips a row whose lease was stolen mid-batch instead of sending it', function (): void {
    // THE WINDOW, REACHED FROM INSIDE IT. Two rows are claimed in one pass; while the
    // first is being sent, a competing worker re-claims the second exactly as claim()
    // would. The worker must notice when it gets there and send nothing.
    //
    // RED WITHOUT reLease(): the edge receives TWO requests and the stolen row's customer
    // is buzzed twice -- once by us, once by whoever took it.
    $tenant = $this->makeTenant();

    leaseFixture($tenant, 1, $firstSession);
    leaseFixture($tenant, 1, $secondSession);

    $victim = leaseRow($tenant, (string) $secondSession);
    $stolen = 0;

    // The steal happens inside the FIRST send, which is the only moment at which the
    // second row is claimed, leased and not yet reached.
    $edge = leaseEdge(201, function (RequestInterface $request, int $call) use ($victim, &$stolen): void {
        if ($call === 0) {
            $stolen = stealRow((string) $victim->id);
        }
    });

    $tally = app(OutboxWorker::class)->pass();

    expect($stolen)->toBe(1, 'the simulated competing claim did not take the row, so nothing was stolen')
        ->and($tally)->toMatchArray(['claimed' => 2, 'sent' => 1, 'skipped' => 1, 'retried' => 0, 'dead' => 0])
        ->and($edge->sentCount())->toBe(1, 'the worker sent to a row it no longer held');

    // AND IT WROTE NOTHING TO THE ROW EITHER. A back-off here would move the thief's
    // `available_at` and shorten THEIR lease, which widens the very window this closes.
    $after = leaseRow($tenant, (string) $secondSession);

    expect($after->status)->toBe(OutboxStatus::Pending->value, 'the skipped row was finished by a worker that did not hold it')
        ->and((int) $after->attempts)->toBe(2, 'the thief’s attempt was overwritten')
        ->and($after->last_error)->toBeNull('the skipped row was marked with an error it did not cause')
        ->and($after->sent_at)->toBeNull();

    // The first row is untouched by any of it.
    expect(leaseRow($tenant, (string) $firstSession)->status)->toBe(OutboxStatus::Sent->value);
});

it('skips a row whose lease merely lapsed, because a lapsed lease is a theft waiting to happen', function (): void {
    // NOT YET STOLEN IS NOT STILL OURS. The predicate carries `available_at > now()` as
    // well as the attempt number, because between a lapse and a competing claim there is
    // a window in which the row looks unchanged and is claimable by anyone. Sending on it
    // is exactly the duplicate -- and the row is not lost: it is pending and due, so the
    // next pass takes it.
    $tenant = $this->makeTenant();

    leaseFixture($tenant, 1, $firstSession);
    leaseFixture($tenant, 1, $secondSession);

    $victim = leaseRow($tenant, (string) $secondSession);

    $edge = leaseEdge(201, function (RequestInterface $request, int $call) use ($victim): void {
        if ($call !== 0) {
            return;
        }

        // The clock moved, not the rule -- bin/support/outbox-proof-release.php's pattern.
        // attempts is deliberately NOT touched, so the row still carries OUR identity and
        // the only thing that changed is that the lease ran out.
        DB::connection('pgsql_root')->update(
            "update outbox set available_at = now() - interval '1 second' where id = ?",
            [(string) $victim->id],
        );
    });

    $tally = app(OutboxWorker::class)->pass();

    expect($tally)->toMatchArray(['claimed' => 2, 'sent' => 1, 'skipped' => 1])
        ->and($edge->sentCount())->toBe(1);

    // NOTHING LOST: it is pending, due, and the next pass sends it.
    $next = leaseEdge(201);

    expect(app(OutboxWorker::class)->pass())->toMatchArray(['claimed' => 1, 'sent' => 1, 'skipped' => 0])
        ->and($next->sentCount())->toBe(1)
        ->and(leaseRow($tenant, (string) $secondSession)->status)->toBe(OutboxStatus::Sent->value);
});

// =====================================================================================
// 3. THE DEVICE CAP
// =====================================================================================

it('sends to at most the configured number of devices for one session', function (): void {
    // SECURITY H-1: nothing capped this. forSession() returned every row and the worker
    // sends to each sequentially, so the devices one session carried WERE the round trips
    // one outbox row cost -- and a token holder could register until a single row's
    // processing exceeded its own lease, which is R-083 reached from the device side.
    //
    // RED WITHOUT THE LIMIT: all seven are sent to.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    $endpoints = leaseFixture($tenant, $cap + 2, $sessionId);
    $edge = leaseEdge(201);

    app(OutboxWorker::class)->pass();

    expect($edge->sentCount())->toBe($cap, 'the worker sent to more devices than the cap allows')
        // AND IT IS THE OLDEST THAT SURVIVE, which is a consequence of forSession()'s
        // pre-existing ORDER BY created_at rather than a product decision anybody made --
        // PLAN.md R-091 is where that gets decided, with an owner.
        ->and($edge->endpointsCalled())->toBe(array_slice($endpoints, 0, $cap));
});

it('says so in the log when the cap bites, because a device silently never buzzed is unreproducible', function (): void {
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    leaseFixture($tenant, $cap + 1, $sessionId);
    leaseEdge(201);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = ['level' => $message->level, 'text' => $message->message, 'context' => $message->context];
    });

    app(OutboxWorker::class)->pass();

    $capped = array_values(array_filter($lines, fn (array $l): bool => str_contains($l['text'], 'exceed the cap')));

    expect($capped)->toHaveCount(1, 'the cap bit and nothing said so')
        ->and($capped[0]['level'])->toBe('warning')
        ->and($capped[0]['context']['cap'])->toBe($cap)
        ->and($capped[0]['context']['pager_session_id'])->toBe((string) $sessionId);

    // AND NO ENDPOINT IN THE LOG LINE. An endpoint is a bearer capability for one device;
    // PushSubscriptions' own docblock keeps it out of logs and this is the newest line
    // that could have broken that.
    expect(json_encode($capped[0], JSON_THROW_ON_ERROR))->not->toContain('push.example.test');
});

it('does not cap a session that is under the limit, so the ordinary case is untouched', function (): void {
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    $endpoints = leaseFixture($tenant, $cap, $sessionId);
    $edge = leaseEdge(201);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message;
    });

    app(OutboxWorker::class)->pass();

    expect($edge->sentCount())->toBe($cap)
        ->and($edge->endpointsCalled())->toBe($endpoints)
        ->and(array_filter($lines, fn (string $l): bool => str_contains($l, 'exceed the cap')))
        ->toBe([], 'the cap warned about a session that is exactly at the limit');
});

it('keeps one row’s worst case inside the lease, which is the bound the fix rests on', function (): void {
    // THE ARITHMETIC IN config/webpush.php, ASSERTED. The re-lease moves the duplicate
    // window from "the whole batch" to "one row's processing"; that is only a bound if one
    // row's processing fits inside the lease. So the three numbers that decide it are
    // related here rather than only in a comment -- raising the cap or either timeout
    // without raising the lease has to fail something.
    $cap = (int) config('webpush.worker.max_devices_per_session');
    $lease = (int) config('webpush.worker.lease_seconds');
    $connect = (int) config('webpush.timeout.connect');
    $request = (int) config('webpush.timeout.request');

    $worstCase = $cap * ($connect + $request);

    expect($cap)->toBeGreaterThan(0)
        ->and($worstCase)->toBeLessThan(
            $lease,
            "one row's worst case is {$worstCase}s against a {$lease}s lease: a single row can "
            .'outlive its own lease, so the re-lease degrades from "no duplicate" to "a skipped row"'
        );
});
