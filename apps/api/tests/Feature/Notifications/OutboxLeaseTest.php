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
use Illuminate\Support\Str;
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
 * ⚠ WHICH CASES ARE REVERT-PROVED, EXACTLY -- this header said "EVERY CASE HERE WAS
 * PROVEN RED WITHOUT ITS FIX" and that was false for two of them (QA L-1, 2026-10-27).
 * `bin/lease-revert-proof` reverts the two halves of R-083 separately and the committed
 * transcript shows which cases move: three go red when the re-lease is removed, two when
 * the device cap is removed, and TWO ARE DELIBERATE CONTROLS THAT MUST NOT MOVE -- "does
 * not cap a session that is under the limit", which is what stops "the cap is enforced"
 * passing by refusing everything, and the lease arithmetic, which is a property of three
 * config numbers and of no code path. The cases added in the second round (the ownership
 * guard, the rollback, the eviction, the two uncovered arms) were each measured red
 * against their own fix when they were written.
 *
 * The two-process proof is the one QA asked for and the overnight run deliberately did
 * not write, because the design it had to be built around was Muhammad's to choose.
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

/**
 * Put devices on a session WITHOUT going through register(), so the per-session cap is
 * bypassed.
 *
 * Security `M-1`'s fix made register() evict the oldest beyond the cap, which means the
 * read-time LIMIT in forSession() can no longer be reached through the application at
 * all. It is still the backstop that bounds one row's processing inside its lease, and a
 * backstop nothing exercises is a backstop nobody has tested -- so these rows are written
 * as the OWNER, which is a harness placing a precondition rather than an application
 * path (§2.10's pattern), exactly as a session whose rows predate the eviction would look.
 *
 * @return list<string> the endpoints planted, oldest first
 */
function plantDevices(string $tenantId, string $sessionId, int $count): array
{
    $endpoints = [];

    for ($i = 0; $i < $count; $i++) {
        $device = PushFixtures::subscription();
        $endpoints[] = $device['endpoint'];

        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('push_subscriptions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'pager_session_id' => $sessionId,
            'endpoint' => $device['endpoint'],
            'p256dh' => $device['p256dh'],
            'auth' => $device['auth'],
            'created_at' => now()->subSeconds($count - $i),
        ]), 'pgsql_owner');
    }

    return $endpoints;
}

/** Every endpoint still stored for one session, newest first. */
function storedEndpoints(string $tenantId, string $sessionId): array
{
    return array_map(fn (object $r): string => (string) $r->endpoint, withTenant(
        $tenantId,
        fn (): array => DB::connection('pgsql_owner')->select(
            'select endpoint from push_subscriptions where pager_session_id = ? order by created_at desc, id desc',
            [$sessionId],
        ),
        'pgsql_owner',
    ));
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

it('evicts the OLDEST device past the cap, so a squatter cannot lock the customer out', function (): void {
    // SECURITY M-1, 2026-10-27, and it is the finding that made the cap dangerous rather
    // than merely blunt. R-083's cap kept the OLDEST `cap` devices, so anyone holding the
    // QR token could register five endpoints of their own BEFORE the customer's browser
    // subscribed and the customer's real device would sit outside the cap for the
    // session's whole two-hour life. The attacker's endpoints answer 200, so pruning
    // never reaches them; forget() needs an endpoint only its owner has. Five POSTs to
    // silence the one notification this product exists to send.
    //
    // NEWEST WINS, enforced at registration so the row is deleted rather than skipped.
    //
    // RED BEFORE THE FIX: the squatters are sent to and the customer's device is not.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    // `cap` squatters first, then the customer's own device last -- the attack's shape.
    $endpoints = leaseFixture($tenant, $cap + 1, $sessionId);
    $squatters = array_slice($endpoints, 0, $cap);
    $customer = $endpoints[$cap];

    // The table itself is bounded, not merely the read.
    $stored = storedEndpoints($tenant, (string) $sessionId);

    // NO MESSAGE ON toContain(). It is variadic -- `toContain($a, $b)` means "contains
    // BOTH" -- so a message passed here is read as a second needle, which is this
    // repository's own R-082/rule-7 defect in its positive form. Caught by this case
    // failing on the message string itself, which is the one way it announces itself.
    expect($stored)->toHaveCount($cap, 'registration did not evict past the cap')
        ->and($stored)->toContain($customer)
        ->and($stored)->not->toContain($squatters[0]);

    $edge = leaseEdge(201);
    app(OutboxWorker::class)->pass();

    expect($edge->sentCount())->toBe($cap)
        ->and($edge->endpointsCalled())->toContain($customer)
        ->and($edge->endpointsCalled())->not->toContain($squatters[0]);
});

it('sends to at most the configured number of devices for one session', function (): void {
    // SECURITY H-1 of the first round: nothing capped this. forSession() returned every
    // row and the worker sends to each sequentially, so the devices one session carried
    // WERE the round trips one outbox row cost -- and a token holder could register until
    // a single row's processing exceeded its own lease, which is R-083 from the device
    // side.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    $endpoints = leaseFixture($tenant, $cap + 2, $sessionId);
    $edge = leaseEdge(201);

    app(OutboxWorker::class)->pass();

    // The NEWEST cap, which is now true in both places: register() evicted the rest and
    // forSession() would have ordered the same way if they had survived.
    expect($edge->sentCount())->toBe($cap, 'the worker sent to more devices than the cap allows')
        ->and(array_values(array_diff(array_slice($endpoints, 2), $edge->endpointsCalled())))->toBe([]);
});

it('says so in the log when the read-time cap bites, because a device never buzzed is unreproducible', function (): void {
    // THE BACKSTOP, which register()'s eviction now hides from every application path --
    // so the rows are planted as the owner, which is what a session predating the
    // eviction looks like. A backstop nothing exercises is a backstop nobody has tested.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    leaseFixture($tenant, 1, $sessionId);
    $planted = plantDevices($tenant, (string) $sessionId, $cap + 1);

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
    expect(json_encode($capped[0], JSON_THROW_ON_ERROR))->not->toContain('push.example.test')
        ->and($planted)->toHaveCount($cap + 1);
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
        ->and(array_values(array_diff($endpoints, $edge->endpointsCalled())))->toBe([])
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

    // QA L-4, 2026-10-27: the bound is cap x REQUEST, not cap x (connect + request).
    // Guzzle's `timeout` is the TOTAL transfer time and already contains the connect
    // phase, so adding them double-counts. The old arithmetic erred in the safe direction
    // and was still not true, which is the only thing that matters about a sentence.
    $worstCase = $cap * $request;

    expect($cap)->toBeGreaterThan(0)
        ->and($connect)->toBeLessThanOrEqual($request, 'connect_timeout must sit inside the total timeout it is part of')
        ->and($worstCase)->toBeLessThan(
            $lease,
            "one row's worst case is {$worstCase}s against a {$lease}s lease: a single row can "
            .'outlive its own lease, so the re-lease degrades from "no duplicate" to "a skipped row"'
        );
});

// =====================================================================================
// 4. THE OWNERSHIP GUARD ON THE WRITES — QA H-1 / security M-2, the second round's High
// =====================================================================================
//
// The first round put the ownership token on the statement before the SEND and left it
// off the two that WRITE. Both reviewers of the fix diff found it independently, which
// is the second time that has happened on this task.

it('does not finish a row another worker has claimed away mid-send', function (): void {
    // THE WINDOW: re-lease succeeds, the sends run long, the lease lapses, a second
    // worker claims the row — and the first worker then marks it `sent`. The row reads
    // "one notification" while the thief is about to send the second.
    //
    // RED WITHOUT THE PREDICATE: status becomes `sent` under the thief's attempt count.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    leaseFixture($tenant, $cap, $sessionId);
    $row = leaseRow($tenant, (string) $sessionId);
    $stolen = 0;

    // Steal on the LAST send, so every device has been written to and only the finishing
    // write is left.
    $edge = leaseEdge(201, function (RequestInterface $request, int $call) use ($row, $cap, &$stolen): void {
        if ($call === $cap - 1) {
            $stolen = stealRow((string) $row->id);
        }
    });

    $tally = app(OutboxWorker::class)->pass();

    expect($stolen)->toBe(1, 'the simulated competing claim did not take the row')
        ->and($edge->sentCount())->toBe($cap)
        ->and($tally)->toMatchArray(['claimed' => 1, 'sent' => 0, 'skipped' => 1]);

    $after = leaseRow($tenant, (string) $sessionId);

    expect($after->status)->toBe(OutboxStatus::Pending->value, 'a worker finished a row it no longer held')
        ->and($after->sent_at)->toBeNull()
        ->and((int) $after->attempts)->toBe(2, 'the thief’s attempt count was overwritten');
});

it('does not back a row off after losing it, which would truncate the holder’s lease', function (): void {
    // QA H-1's sharpest edge, and the reason it is a High: backOff() writes
    // `available_at`, so a worker that no longer holds the row could land `now() + ~5s`
    // on top of the CURRENT holder's sixty-second lease, cut it short, and let a third
    // claim arrive while that holder was still sending. reLease()'s own docblock forbids
    // exactly that action -- it was forbidden at the one statement where it could not
    // happen and permitted at the one where it could.
    //
    // DRIVEN THROUGH THE RETRYABLE BRANCH, which reaches the same statement as the
    // pre-re-lease session-read branch: every device refuses with a 500, the row is
    // stolen during the last send, and backOff() then runs on a row this worker has
    // lost.
    //
    // RED WITHOUT THE PREDICATE: available_at lands ~5s out and last_error is stamped on
    // somebody else's row.
    $tenant = $this->makeTenant();
    $cap = (int) config('webpush.worker.max_devices_per_session');

    leaseFixture($tenant, $cap, $sessionId);
    $row = leaseRow($tenant, (string) $sessionId);
    $stolen = 0;

    $edge = leaseEdge(500, function (RequestInterface $request, int $call) use ($row, $cap, &$stolen): void {
        if ($call === $cap - 1) {
            $stolen = stealRow((string) $row->id, 60);
        }
    });

    $tally = app(OutboxWorker::class)->pass();

    expect($stolen)->toBe(1, 'the simulated competing claim did not take the row')
        ->and($edge->sentCount())->toBe($cap)
        ->and($tally)->toMatchArray(['claimed' => 1, 'retried' => 0, 'skipped' => 1]);

    $secondsOut = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        'select extract(epoch from (available_at - now())) as s from outbox where id = ?',
        [(string) $row->id],
    ), 'pgsql_owner');

    $after = leaseRow($tenant, (string) $sessionId);

    // The thief's lease is 60s; a back-off would have cut it to ~5s.
    expect((float) $secondsOut->s)->toBeGreaterThan(
        30.0,
        'a worker that lost the row truncated the holder’s lease to a back-off interval'
    )
        ->and($after->last_error)->toBeNull('an error was stamped on a row this worker did not hold')
        ->and($after->status)->toBe(OutboxStatus::Pending->value);
});

it('hands the attempt back when it skips, so lease contention cannot exhaust the retries', function (): void {
    // QA M-7 / security M-3. claim() charges an attempt unconditionally and backOff()
    // dead-letters at max_attempts, so a row claimed and skipped five times arrives at
    // the cap having never been sent to — and the first genuine transient failure
    // dead-letters it as "retries exhausted". That is a LOST notification, which is the
    // other half of the invariant this task ships on, and the re-lease makes repeated
    // skips the expected steady state under a slow edge.
    //
    // RED WITHOUT THE ROLLBACK: attempts climbs one per skip and the row dead-letters.
    $tenant = $this->makeTenant();
    $max = (int) config('webpush.worker.max_attempts');

    leaseFixture($tenant, 1, $sessionId);
    $row = leaseRow($tenant, (string) $sessionId);

    expect((int) $row->attempts)->toBe(0);

    // Skip the row max + 2 times, each time by lapsing the lease inside the send of a
    // decoy row — the real shape, not a hand-made state.
    for ($i = 0; $i < $max + 2; $i++) {
        leaseFixture($tenant, 1, $decoySession);
        $victim = leaseRow($tenant, (string) $sessionId);
        $decoy = leaseRow($tenant, (string) $decoySession);

        // THE DECOY MUST BE PROCESSED FIRST, because the lapse is staged inside ITS send
        // and a lapse during the victim's own send would arrive after the victim's
        // re-lease had already succeeded. claim() orders by `available_at, id`, so the
        // order is set here rather than hoped for.
        DB::connection('pgsql_root')->update(
            "update outbox set available_at = now() - interval '10 seconds' where id = ?",
            [(string) $decoy->id],
        );
        DB::connection('pgsql_root')->update(
            "update outbox set available_at = now() - interval '1 second' where id = ?",
            [(string) $victim->id],
        );

        leaseEdge(201, function (RequestInterface $request, int $call) use ($victim): void {
            if ($call === 0) {
                DB::connection('pgsql_root')->update(
                    "update outbox set available_at = now() - interval '1 second' where id = ?",
                    [(string) $victim->id],
                );
            }
        });

        app(OutboxWorker::class)->pass();

        expect(leaseRow($tenant, (string) $sessionId)->status)
            ->toBe(OutboxStatus::Pending->value, "the row died on skip #{$i}");
    }

    $final = leaseRow($tenant, (string) $sessionId);

    expect((int) $final->attempts)->toBe(0, 'skips burned the retry budget: attempts is '.$final->attempts)
        ->and($final->status)->toBe(OutboxStatus::Pending->value)
        ->and($final->last_error)->toBeNull();

    // AND IT IS STILL DELIVERABLE, which is the property the counter exists to protect.
    DB::connection('pgsql_root')->update(
        "update outbox set available_at = now() - interval '1 second' where id = ?",
        [(string) $row->id],
    );

    $edge = leaseEdge(201);
    app(OutboxWorker::class)->pass();

    expect($edge->sentCount())->toBeGreaterThanOrEqual(1)
        ->and(leaseRow($tenant, (string) $sessionId)->status)->toBe(OutboxStatus::Sent->value);
});

// =====================================================================================
// 5. THE TWO ARMS THE SAFETY ARGUMENT RESTS ON — QA M-2
// =====================================================================================

it('retries rather than losing a row whose session cannot be read', function (): void {
    // The only database failure or timeout path inside the worker, and it had no test at
    // all — while the class docblock makes a correctness claim about it. Driven against
    // real Postgres with a real lock, not a mock.
    $tenant = $this->makeTenant();
    leaseFixture($tenant, 1, $sessionId);

    $blocker = DB::connection('pgsql_root');
    $blocker->beginTransaction();

    try {
        $blocker->statement('lock table pager_sessions in access exclusive mode');
        DB::connection('pgsql_worker')->statement("set lock_timeout = '250ms'");

        $edge = leaseEdge(201);
        $tally = app(OutboxWorker::class)->pass();

        expect($tally)->toMatchArray(['claimed' => 1, 'retried' => 1, 'sent' => 0, 'dead' => 0])
            ->and($edge->sentCount())->toBe(0, 'the worker sent despite not being able to read the session');

        $row = leaseRow($tenant, (string) $sessionId);

        expect($row->status)->toBe(OutboxStatus::Pending->value)
            // A CATEGORY, never driver text — §4 › Edge & ops keeps SQL and bound
            // parameters out of anything a caller can reach, and last_error is readable.
            ->and($row->last_error)->toContain('QueryException')
            ->and($row->last_error)->not->toContain('select order_reference');
    } finally {
        $blocker->rollBack();
        DB::connection('pgsql_worker')->statement('reset lock_timeout');
    }
});

it('dead-letters a row whose session no longer exists, rather than retrying forever', function (): void {
    // The other uncovered arm. outbox_session_fk is ON DELETE CASCADE, so the row would
    // normally go with the session — this drives the case where it does not.
    $tenant = $this->makeTenant();
    leaseFixture($tenant, 1, $sessionId);

    $row = leaseRow($tenant, (string) $sessionId);

    // Break the pointer as the owner rather than deleting the session, which would
    // cascade the outbox row away and leave nothing to assert.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        'update outbox set pager_session_id = null where id = ?',
        [(string) $row->id],
    ), 'pgsql_owner');

    $edge = leaseEdge(201);
    $tally = app(OutboxWorker::class)->pass();

    expect($tally)->toMatchArray(['claimed' => 1, 'dead' => 1, 'sent' => 0])
        ->and($edge->sentCount())->toBe(0);

    $after = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        'select status, last_error from outbox where id = ?',
        [(string) $row->id],
    ), 'pgsql_owner');

    expect($after->status)->toBe(OutboxStatus::Dead->value)
        ->and($after->last_error)->toBe('unroutable outbox type');
});
