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
 * the proof where it belongs. Between them every line of the command is driven.
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
