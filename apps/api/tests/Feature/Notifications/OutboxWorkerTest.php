<?php

declare(strict_types=1);

use App\Domain\Outbox\OutboxStatus;
use App\Domain\Pager\PagerStatus;
use App\Features\Notifications\OutboxWorker;
use App\Features\Notifications\WebPushDispatcher;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
use Tests\Support\RecordingPushEdge;

/*
 * THE OUTBOX WORKER. C5 -- "the outbox queue worker sends Web Push, never the HTTP
 * request" -- and the first clause of this task's §3 gate: kill it mid-batch, nothing
 * lost, nothing duplicated.
 *
 * THE EDGE IS THE ONLY THING MOCKED, and it is injected at the container rather than
 * faked in our own code: everything between the claim and the request is real -- real
 * SKIP LOCKED against real Postgres, real tenant scopes, real policies, real encryption,
 * real VAPID signing. "No mocks for behaviour that belongs to the service" cuts the other
 * way too: the push service is not ours, so a recorded PSR-18 client is the honest edge.
 *
 * The kill-mid-batch proof is NOT here: a kill -9 needs a real process, so it lives in
 * bin/outbox-kill-proof and in OutboxCrashTest, which drives that script.
 */

/** A tenant with a ready session, one subscribed device, and its outbox row waiting. */
function readySessionWithDevice(string $tenantId, ?string &$sessionId = null, ?array &$device = null): string
{
    PushFixtures::configureVapid();

    $issued = app(PagerSessions::class)->create($tenantId, 'A-'.random_int(100, 999));
    $sessionId = $issued->session->id;
    $device = PushFixtures::subscription();

    // THE SCAN FIRST, because that is the real client flow and because /v1/pager/session is
    // the ONLY route that creates the Redis pointer. Subscribing resolves the token but
    // deliberately writes no projection -- one writer, one place -- so a helper that
    // skipped this step would leave the worker's refresh with nothing to refresh and would
    // be testing a path no customer takes.
    test()->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();

    test()->postJson('/v1/pager/subscription', [
        'token' => $issued->token->reveal(),
        'endpoint' => $device['endpoint'],
        'p256dh' => $device['p256dh'],
        'auth' => $device['auth'],
    ])->assertStatus(201);

    // markReady writes the outbox row, in the same transaction as the status change and
    // the audit row. That is Task 3's invariant; this task reads what it wrote.
    app(PagerSessions::class)->markReady($tenantId, $issued->session->id);

    return $issued->token->reveal();
}

function edge(array|int $statuses = 201): RecordingPushEdge
{
    $edge = new RecordingPushEdge($statuses);

    // app() and not test()->app: TestCase::$app is protected, and a global function in a
    // Pest file is not inside the test class -- the same constraint PagerTtlTest records
    // about TestCase::makeSession(). The container is the same one either way.
    app()->instance(WebPushDispatcher::EDGE, $edge);

    // And forget the dispatcher itself, because NotificationsServiceProvider resolves the
    // edge INSIDE the dispatcher's own factory: a dispatcher already in the container was
    // built with the real Guzzle client and would keep it.
    app()->forgetInstance(WebPushDispatcher::class);

    return $edge;
}

function outboxRow(string $tenantId, string $sessionId): object
{
    $row = withTenant($tenantId, fn () => DB::connection('pgsql_owner')->selectOne(
        'select status, attempts, sent_at, dead_at, last_error, available_at from outbox where pager_session_id = ?',
        [$sessionId],
    ), 'pgsql_owner');

    expect($row)->toBeObject('no outbox row for that session');

    return $row;
}

it('claims a pending row, sends it, and marks it sent exactly once', function (): void {
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    $pushEdge = edge(201);

    $first = app(OutboxWorker::class)->pass();

    expect($first)->toMatchArray(['claimed' => 1, 'sent' => 1, 'retried' => 0, 'dead' => 0])
        ->and($pushEdge->sentCount())->toBe(1)
        ->and($pushEdge->endpointsCalled())->toBe([$device['endpoint']]);

    $row = outboxRow($tenant, (string) $sessionId);
    expect($row->status)->toBe(OutboxStatus::Sent->value)
        ->and($row->sent_at)->not->toBeNull();

    // A SECOND PASS MUST DO NOTHING. The claim predicate is status = 'pending', so a sent
    // row is never claimed again -- which is half of "nothing duplicated" and the half
    // that survives any number of re-runs.
    $second = app(OutboxWorker::class)->pass();

    expect($second['claimed'])->toBe(0)
        ->and($pushEdge->sentCount())->toBe(1);
});

it('sends across tenants in one pass, which is the named cross-tenant path', function (): void {
    // §4 › Tenancy names "the outbox queue" as one of exactly four cross-tenant reads. The
    // claim runs with NO tenant context and must see both tenants' rows; everything after
    // it runs inside the claimed row's own scope.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    readySessionWithDevice($alpha, $alphaSession, $alphaDevice);
    readySessionWithDevice($beta, $betaSession, $betaDevice);

    $pushEdge = edge(201);

    $tally = app(OutboxWorker::class)->pass();

    expect($tally['claimed'])->toBe(2)
        ->and($tally['sent'])->toBe(2)
        ->and($pushEdge->endpointsCalled())->toContain($alphaDevice['endpoint'])
        ->and($pushEdge->endpointsCalled())->toContain($betaDevice['endpoint']);
});

it('sends to every device a session has, not just the first', function (): void {
    // A customer with a phone and a tablet is not exotic, and finishing the row on the
    // first success would silently drop the notification for the second device.
    $tenant = $this->makeTenant();
    PushFixtures::configureVapid();

    $issued = app(PagerSessions::class)->create($tenant, 'A-310');

    $devices = [PushFixtures::subscription(), PushFixtures::subscription(), PushFixtures::subscription()];

    foreach ($devices as $device) {
        $this->postJson('/v1/pager/subscription', [
            'token' => $issued->token->reveal(),
            'endpoint' => $device['endpoint'],
            'p256dh' => $device['p256dh'],
            'auth' => $device['auth'],
        ])->assertStatus(201);
    }

    app(PagerSessions::class)->markReady($tenant, $issued->session->id);
    $pushEdge = edge(201);

    app(OutboxWorker::class)->pass();

    expect($pushEdge->sentCount())->toBe(3)
        ->and(array_diff(array_column($devices, 'endpoint'), $pushEdge->endpointsCalled()))->toBe([]);
});

it('prunes a subscription on 404 and on 410, on the first occurrence', function (): void {
    // THE GATE CLAUSE: "dead subscriptions pruned on 404/410". On the FIRST occurrence,
    // not after a retry budget -- a dead device never comes back, and leaving it makes
    // every later send cost more, which is the growth the clause exists to stop.
    foreach ([404, 410] as $status) {
        $tenant = $this->makeTenant();
        readySessionWithDevice($tenant, $sessionId, $device);
        $pushEdge = edge($status);

        $tally = app(OutboxWorker::class)->pass();

        expect($tally['pruned'])->toBe(1, "HTTP {$status} should prune")
            // The row finishes rather than retrying: there is no device left to retry to.
            ->and($tally['sent'])->toBe(1)
            ->and($pushEdge->sentCount())->toBe(1);

        $remaining = withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->selectOne('select count(*) as n from push_subscriptions where pager_session_id = ?', [$sessionId]), 'pgsql_owner');

        expect((int) $remaining->n)->toBe(0, "HTTP {$status} left the subscription in place");
    }
});

it('keeps a subscription on a 500, because a transient edge is not a dead device', function (): void {
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    edge(500);

    $tally = app(OutboxWorker::class)->pass();

    expect($tally)->toMatchArray(['retried' => 1, 'sent' => 0, 'pruned' => 0]);

    $remaining = withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->selectOne('select count(*) as n from push_subscriptions where pager_session_id = ?', [$sessionId]), 'pgsql_owner');

    expect((int) $remaining->n)->toBe(1);
});

it('backs off with a future available_at rather than spinning on a failure', function (): void {
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    edge(500);

    app(OutboxWorker::class)->pass();

    $row = outboxRow($tenant, (string) $sessionId);

    expect($row->status)->toBe(OutboxStatus::Pending->value)
        ->and($row->attempts)->toBe(1)
        ->and(strtotime((string) $row->available_at))->toBeGreaterThan(time());

    // And the next pass claims nothing, because the row is not due yet. That is what
    // stops a failing edge from being retried in a tight loop.
    expect(app(OutboxWorker::class)->pass()['claimed'])->toBe(0);
});

it('dead-letters after max_attempts, with a category and never a driver message', function (): void {
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    edge(500);

    $max = (int) config('webpush.worker.max_attempts');

    for ($attempt = 1; $attempt <= $max; $attempt++) {
        // Make the row due again, as the lease expiring would. Through the owner
        // connection so the test is placing a precondition rather than exercising a path.
        withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
            'update outbox set available_at = now() - interval \'1 second\' where pager_session_id = ? and status = \'pending\'',
            [$sessionId],
        ), 'pgsql_owner');

        app(OutboxWorker::class)->pass();
    }

    $row = outboxRow($tenant, (string) $sessionId);

    expect($row->status)->toBe(OutboxStatus::Dead->value)
        ->and($row->dead_at)->not->toBeNull()
        ->and($row->attempts)->toBe($max)
        // last_error is readable by anyone who can read the row, so §4 › Edge & ops keeps
        // SQL, bound parameters and secrets out of it. A category, not a driver message.
        ->and((string) $row->last_error)->toContain('retries exhausted')
        ->and((string) $row->last_error)->not->toContain($device['endpoint'])
        ->and((string) $row->last_error)->not->toContain('https://');
});

it('dead-letters a 400 immediately, because no retry fixes a request we built wrong', function (): void {
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    edge(400);

    $tally = app(OutboxWorker::class)->pass();

    expect($tally['sent'])->toBe(1)->and($tally['retried'])->toBe(0);

    // The row finishes rather than consuming six attempts and the backoff between them.
    expect(outboxRow($tenant, (string) $sessionId)->attempts)->toBe(1);
});

it('finishes a row whose session has no subscription, rather than retrying forever', function (): void {
    // The ticket's "the dispatcher must not assume a subscription exists". On iOS a
    // customer who has not installed to the Home Screen has none at all, which is the most
    // likely real case in this product. There is nobody to tell, and the customer still
    // sees the change by polling -- which is exactly what O2 made the fallback for.
    $tenant = $this->makeTenant();
    PushFixtures::configureVapid();

    $issued = app(PagerSessions::class)->create($tenant, 'A-320');
    app(PagerSessions::class)->markReady($tenant, $issued->session->id);
    $pushEdge = edge(201);

    $tally = app(OutboxWorker::class)->pass();

    expect($tally)->toMatchArray(['claimed' => 1, 'sent' => 1, 'retried' => 0])
        ->and($pushEdge->sentCount())->toBe(0);

    expect(outboxRow($tenant, $issued->session->id)->status)->toBe(OutboxStatus::Sent->value);
});

it('dead-letters an outbox type this build cannot route, instead of crashing the pass', function (): void {
    // outbox.type is varchar(64), not a Postgres enum (§2.5), so the column can hold a
    // string PagerEvent has no case for -- a row written by a newer version during a
    // rolling deploy, say. Retrying it forever is worse than ending it, and crashing the
    // pass on it is worse than both: one unroutable row would stop every other tenant's
    // notifications.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-330');

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->insert(
        'insert into outbox (id, tenant_id, pager_session_id, type, dedupe_key) values (?, ?, ?, ?, ?)',
        [(string) Str::uuid7(), $tenant, $issued->session->id, 'pager.from.the.future', 'session:'.$issued->session->id.':future'],
    ), 'pgsql_owner');

    edge(201);

    $tally = app(OutboxWorker::class)->pass();

    expect($tally)->toMatchArray(['claimed' => 1, 'dead' => 1, 'sent' => 0]);
});

it('refreshes the projection after a send, so a poll agrees with the notification', function (): void {
    $tenant = $this->makeTenant();
    $token = readySessionWithDevice($tenant, $sessionId, $device);
    edge(201);

    // markReady already refreshed it, so prove the WORKER's refresh lands by moving the
    // projection backwards first: a stale `waiting` that only the worker can correct.
    $stale = json_encode([
        'v' => 1, 's' => PagerStatus::Waiting->value, 'o' => 'A-000',
        'x' => (new DateTimeImmutable('+2 hours'))->format(DateTimeInterface::RFC3339),
        'a' => (new DateTimeImmutable('-1 minute'))->format(DateTimeInterface::RFC3339),
    ], JSON_THROW_ON_ERROR);

    app('redis')->connection()->setex(PagerProjection::projectionKey($tenant, (string) $sessionId), 600, $stale);

    test()->postJson('/v1/pager/status', ['token' => $token])->assertJsonPath('status', PagerStatus::Waiting->value);

    app(OutboxWorker::class)->pass();

    test()->postJson('/v1/pager/status', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('status', PagerStatus::Ready->value)
        // And the stale order_reference the fixture planted is gone too, so the refresh
        // rewrote the whole view from the session row rather than patching one field.
        ->assertJsonMissing(['order_reference' => 'A-000']);
});

it('does not revive a projection that has gone, because refresh is update-only', function (): void {
    // refresh() is XX. A projection whose TTL ran out belongs to a session whose TTL ran
    // out, so recreating one from the worker would publish a live status for a session
    // that is over. The customer's client re-opens instead, and Postgres tells it whether
    // the session expired or never existed -- which is the only path that can.
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);
    edge(201);

    app('redis')->connection()->del(PagerProjection::projectionKey($tenant, (string) $sessionId));

    app(OutboxWorker::class)->pass();

    expect(app('redis')->connection()->exists(PagerProjection::projectionKey($tenant, (string) $sessionId)))->toBe(0);
});

it('never reads a subscription without a tenant scope, which is measured not assumed', function (): void {
    // push_subscriptions has NO cross-tenant worker policy -- only the four §4 names do --
    // so the worker's scope is load-bearing. This is the measurement that makes the
    // worker's shape necessary rather than tidy, kept as a test so a policy change that
    // widened the worker's reach would fail here rather than silently work.
    $tenant = $this->makeTenant();
    readySessionWithDevice($tenant, $sessionId, $device);

    $unscoped = DB::connection('pgsql_worker')->selectOne('select count(*) as n from push_subscriptions');
    $scoped = withTenant($tenant, fn () => DB::connection('pgsql_worker')
        ->selectOne('select count(*) as n from push_subscriptions'), 'pgsql_worker');

    expect((int) $unscoped->n)->toBe(0, 'the worker can read push_subscriptions cross-tenant')
        ->and((int) $scoped->n)->toBe(1);
});
