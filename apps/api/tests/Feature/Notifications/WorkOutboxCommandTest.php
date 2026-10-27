<?php

declare(strict_types=1);

use App\Domain\Outbox\OutboxStatus;
use App\Domain\Pager\PagerDelivery;
use App\Features\Notifications\WebPushDispatcher;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushFixtures;
use Tests\Support\RecordingPushEdge;

/*
 * THE COMMAND ITSELF, which had no test of its own.
 *
 * bin/outbox-kill-proof drives it in a SUBPROCESS -- which is the only way to kill it --
 * and a subprocess is invisible to the coverage driver, so the file read 0/27 while being
 * the entry point of this task's first gate clause. That is the shape §4a keeps recording:
 * a control exercised somewhere a measurement cannot see it looks untested and, worse,
 * nobody can tell whether it is.
 *
 * So the pass-level behaviour is tested here, in process, and the crash behaviour stays in
 * the proof where it belongs.
 *
 * ⚠ AND NOT EVERY LINE IS DRIVEN, which this header claimed until 2026-10-27 (QA M-4). Every
 * case here and in bin/outbox-kill-proof passes --once, which returns before the continuous
 * loop body is reached, so the loop, its sleep, its shouldStop exit and the two pcntl signal
 * handlers are measurably uncovered -- the task's own coverage transcript reports
 * `WorkOutboxCommand … 64..71, 105..109 / 63.0%`. PLAN.md §4a stated the honest version in
 * the same breath, so the repository contradicted itself. The SIGTERM proof that would close
 * it is a §5 row with an owner.
 */

function pushEdge(array|int $statuses = 201): RecordingPushEdge
{
    PushFixtures::configureVapid();

    $edge = new RecordingPushEdge($statuses);
    app()->instance(WebPushDispatcher::EDGE, $edge);
    app()->forgetInstance(WebPushDispatcher::class);

    return $edge;
}

/** A ready session with one device, so one outbox row is waiting. */
function queuedNotification(string $tenantId): string
{
    $issued = app(PagerSessions::class)->create($tenantId, 'W-'.random_int(100, 999));
    $device = PushFixtures::subscription();

    test()->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();
    test()->postJson('/v1/pager/subscription', [
        'token' => $issued->token->reveal(),
        'endpoint' => $device['endpoint'], 'p256dh' => $device['p256dh'], 'auth' => $device['auth'],
    ])->assertStatus(201);

    app(PagerSessions::class)->markReady($tenantId, $issued->session->id);

    return $issued->session->id;
}

it('is registered, so `php artisan` is the record of what exists', function (): void {
    // bootstrap/app.php lists it explicitly rather than relying on a directory scan, which
    // does not cover app/Features -- the same reason ExpirePagerSessionsCommand is there.
    expect(array_keys(Artisan::all()))->toContain('outbox:work');
});

it('runs one pass and exits with --once', function (): void {
    $tenant = $this->makeTenant();
    $session = queuedNotification($tenant);
    $edge = pushEdge(201);

    $this->artisan('outbox:work', ['--once' => true])
        ->expectsOutputToContain('claimed 1, sent 1')
        ->assertExitCode(0);

    expect($edge->sentCount())->toBe(1);

    $row = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        'select status from outbox where pager_session_id = ?', [$session]), 'pgsql_owner');

    expect($row->status)->toBe(OutboxStatus::Sent->value);
});

it('says nothing when there is nothing to do, so an idle worker is quiet', function (): void {
    // A line per empty pass at one pass a second is a log nobody can read and a bill
    // nobody expected. The tally is printed only when a row was claimed.
    pushEdge(201);

    $this->artisan('outbox:work', ['--once' => true])
        ->doesntExpectOutputToContain('claimed')
        ->assertExitCode(0);
});

it('honours --limit, so one pass cannot take the whole queue', function (): void {
    $tenant = $this->makeTenant();

    for ($i = 0; $i < 3; $i++) {
        queuedNotification($tenant);
    }

    $edge = pushEdge(201);

    $this->artisan('outbox:work', ['--once' => true, '--limit' => 2])
        ->expectsOutputToContain('claimed 2')
        ->assertExitCode(0);

    expect($edge->sentCount())->toBe(2);

    // The third is still waiting, and a second pass takes it. Nothing was lost by the cap.
    $this->artisan('outbox:work', ['--once' => true, '--limit' => 2])
        ->expectsOutputToContain('claimed 1')
        ->assertExitCode(0);
});

it('floors a nonsensical --limit at one rather than claiming nothing', function (): void {
    // A limit of 0 interpolated into `limit 0` would claim nothing every pass and the queue
    // would stop draining silently -- the worst failure shape available here, because the
    // worker would look healthy.
    $tenant = $this->makeTenant();
    queuedNotification($tenant);
    pushEdge(201);

    $this->artisan('outbox:work', ['--once' => true, '--limit' => 0])
        ->expectsOutputToContain('claimed 1')
        ->assertExitCode(0);
});

it('ignores a non-numeric --limit and falls back to the configured batch size', function (): void {
    $tenant = $this->makeTenant();
    queuedNotification($tenant);
    pushEdge(201);

    $this->artisan('outbox:work', ['--once' => true, '--limit' => 'lots'])
        ->expectsOutputToContain('claimed 1')
        ->assertExitCode(0);
});

it('reports pruning in the tally, so an operator sees subscriptions being dropped', function (): void {
    $tenant = $this->makeTenant();
    queuedNotification($tenant);
    pushEdge(410);

    $this->artisan('outbox:work', ['--once' => true])
        ->expectsOutputToContain('pruned 1 subscription(s)')
        ->assertExitCode(0);
});

it('reports a retry without failing the pass, because one bad edge is not a bad worker', function (): void {
    $tenant = $this->makeTenant();
    queuedNotification($tenant);
    pushEdge(500);

    $this->artisan('outbox:work', ['--once' => true])
        ->expectsOutputToContain('retried 1')
        ->assertExitCode(0);
});

it('knows which dispositions are worth another attempt', function (): void {
    // PagerDelivery::isRetryable() is the one line of that enum with no caller inside the
    // worker -- the worker matches on the case directly -- so it is pinned here rather than
    // left as an untested method somebody later trusts.
    expect(PagerDelivery::Retry->isRetryable())->toBeTrue()
        ->and(PagerDelivery::Delivered->isRetryable())->toBeFalse()
        ->and(PagerDelivery::Gone->isRetryable())->toBeFalse()
        ->and(PagerDelivery::Permanent->isRetryable())->toBeFalse();
});

it('reports skipped rows on the tally line, which is the only signal an operator gets', function (): void {
    // QA L-7, 2026-10-27. `skipped` is R-083's counter — the number of rows this pass
    // claimed and did NOT send because the lease had moved — and a non-zero reading is
    // the signal that `lease_seconds` is too short for what a row now costs. The
    // worker-level key was asserted; the OPERATOR-level one was not, and the skip's own
    // log line is `debug`, so this line is all an operator sees.
    $tenant = $this->makeTenant();
    $victim = queuedNotification($tenant);
    $decoy = queuedNotification($tenant);

    $victimRow = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        'select id from outbox where pager_session_id = ?', [$victim],
    ), 'pgsql_owner');
    $decoyRow = withTenant($tenant, fn () => DB::connection('pgsql_owner')->selectOne(
        'select id from outbox where pager_session_id = ?', [$decoy],
    ), 'pgsql_owner');

    // claim() orders by `available_at, id`, so the decoy is made to sort first and the
    // victim is stolen inside the decoy's send.
    DB::connection('pgsql_root')->update(
        "update outbox set available_at = now() - interval '10 seconds' where id = ?", [(string) $decoyRow->id],
    );
    DB::connection('pgsql_root')->update(
        "update outbox set available_at = now() - interval '1 second' where id = ?", [(string) $victimRow->id],
    );

    // configureVapid() first: without it the dispatcher cannot be built, every row backs
    // off, no send happens and the hook below never fires -- which is how this case
    // failed the first time it was run.
    PushFixtures::configureVapid();

    $edge = new RecordingPushEdge(201, false, function ($request, int $call) use ($victimRow): void {
        if ($call === 0) {
            DB::connection('pgsql_worker')->update(
                "update outbox
                    set attempts = attempts + 1, available_at = now() + interval '60 seconds', updated_at = now()
                  where id = ? and status = 'pending'",
                [(string) $victimRow->id],
            );
        }
    });

    app()->instance(WebPushDispatcher::EDGE, $edge);
    app()->forgetInstance(WebPushDispatcher::class);

    $this->artisan('outbox:work', ['--once' => true])
        ->expectsOutputToContain('skipped 1')
        ->assertExitCode(0);
});
