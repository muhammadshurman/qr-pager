<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use App\Platform\Readiness\ReadinessStore;
use App\Platform\Readiness\SaturationProbe;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * R-012's Task 2 half, and the measured proof the structural half (c8a7fac) left owed:
 *
 *   "40 app connections held, then /readyz ANSWERS and /healthz returns 200. Reverting
 *    to a readiness query gives 08006 and a 500."
 *
 * Plus B6 literally -- readiness opens no database connection on the request path -- and
 * §6.3's load-bearing choice: the probe runs on the WORKER connection, because a probe
 * that measures app-role saturation must not be subject to it.
 */

beforeEach(function (): void {
    app(ReadinessStore::class)->forget();
});

/**
 * Hold the app role at its CONNECTION LIMIT with raw PDO handles.
 *
 * Raw PDO rather than Laravel connections, so the manager is not holding them and
 * closing them is one unset rather than a purge of something the suite still needs.
 *
 * @return list<PDO>
 */
function holdTheAppPoolAtItsLimit(): array
{
    /** @var array<string, mixed> $config */
    $config = (array) config('database.connections.pgsql');
    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};sslmode={$config['sslmode']}";
    $held = [];

    // Up to the limit plus one: the one past it is expected to be refused, and that
    // refusal is the condition this whole row is about.
    for ($i = 0; $i < DatabaseRoles::connectionLimit('app') + 1; $i++) {
        try {
            $held[] = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
        } catch (PDOException) {
            break;
        }
    }

    return $held;
}

it('answers /readyz and /healthz with the app pool full — R-012', function (): void {
    $probe = app(SaturationProbe::class);

    // A fresh snapshot first, so readiness has something to read that is not stale.
    expect($probe->publish($probe->measure(time())))->toBeTrue();

    // The suite's own app connection would otherwise hold one of the forty.
    DB::purge('pgsql');

    $held = holdTheAppPoolAtItsLimit();

    try {
        expect(count($held))->toBeGreaterThanOrEqual(1, 'no app connection could be opened at all');

        // The condition the row exists for, asserted rather than assumed: a NEW app
        // connection is refused, and with 08006 -- not 53300, which is the SQLSTATE
        // anyone would guess for "too many connections" and which never fires for a
        // per-role CONNECTION LIMIT.
        $refusal = null;

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (Throwable $e) {
            $refusal = $this->sqlState($e);
        }

        expect($refusal)->toBe('08006', 'the app pool was not actually full');

        // THE CLAUSE. Both probes answer while the pool they describe is exhausted,
        // because neither opens a connection on the request path.
        $this->getJson('/healthz')->assertStatus(200)->assertJsonPath('status', 'alive');

        $readyz = $this->getJson('/readyz');

        // ANSWERING is the clause, and the body no longer carries the snapshot (R-031),
        // so what proves it answered from a real reading is probe_age_seconds rather
        // than the figures themselves.
        expect($readyz->status())->toBeIn([200, 503], 'readiness did not answer at all')
            ->and($readyz->json('probe_age_seconds'))->not->toBeNull()
            ->and($readyz->json())->not->toHaveKey('probe');
    } finally {
        $held = [];
        DB::purge('pgsql');
    }
});

it('measures saturation on the worker connection, which the app pool cannot starve', function (): void {
    DB::purge('pgsql');

    $held = holdTheAppPoolAtItsLimit();

    try {
        // §6.3's load-bearing choice. Measured in phase 1 and re-measured here: with
        // the app role at its limit the worker-role probe still returns the number. A
        // probe on the app connection would fail under exactly the condition it exists
        // to report, which is R-012 restated.
        $snapshot = app(SaturationProbe::class)->measure(time());

        expect($snapshot->postgresUp)->toBeTrue('the worker probe was starved by the app pool')
            ->and($snapshot->appLimit)->toBe(DatabaseRoles::connectionLimit('app'))
            ->and($snapshot->appBackends)->toBeGreaterThanOrEqual(count($held) - 1)
            ->and($snapshot->saturation())->toBeGreaterThanOrEqual(0.9);
    } finally {
        $held = [];
        DB::purge('pgsql');
    }
});

it('opens no database connection on either probe’s request path — B6', function (): void {
    $probe = app(SaturationProbe::class);
    $probe->publish($probe->measure(time()));

    // Every connection gone, and every statement on any of them recorded from here on.
    $this->purgeConnections();

    $statements = [];

    DB::listen(function (QueryExecuted $event) use (&$statements): void {
        $statements[] = $event->sql;
    });

    $this->getJson('/readyz');
    $this->getJson('/healthz');

    // listen() is the right tool here and not beforeExecuting: the question is what ran,
    // not what was about to, and nothing needs stopping.
    expect($statements)->toBe([], 'the probes touched the database: '.implode(' | ', $statements));
});

it('reports Postgres down when the worker role cannot reach it either', function (): void {
    config(['database.connections.pgsql_worker.port' => 1]);
    DB::purge('pgsql_worker');

    $snapshot = app(SaturationProbe::class)->measure(time());

    // "Postgres is down" and "the app role is full" are different answers, and
    // conflating them is the bug R-012 describes. The worker role being unable to
    // connect is the only reading that means the first one.
    expect($snapshot->postgresUp)->toBeFalse()
        ->and($snapshot->appBackends)->toBe(0);

    app(SaturationProbe::class)->publish($snapshot);

    $this->getJson('/readyz')->assertStatus(503)->assertJsonPath('reason', 'postgres is not reachable');

    // ...while liveness keeps answering, which is the half that stops a load balancer
    // killing a task that merely cannot see its dependencies.
    $this->getJson('/healthz')->assertStatus(200);
});

it('reports Redis down, and readiness refuses rather than guessing', function (): void {
    $probe = app(SaturationProbe::class);

    expect($probe->measure(time())->redisUp)->toBeTrue();

    // Everything Redis THROWS when it is unavailable -- measured in phase 1: ping,
    // Cache::get, RateLimiter::attempt and tooManyAttempts all raised RedisException.
    // So this is a try/catch and not a return value.
    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    expect(app(SaturationProbe::class)->measure(time())->redisUp)->toBeFalse();

    // And with Redis unreachable there is no key to read, so readiness is not ready
    // while liveness still answers.
    $this->getJson('/readyz')->assertStatus(503);
    $this->getJson('/healthz')->assertStatus(200);
});

it('treats a stale snapshot as not ready, because the carrier shares the task', function (): void {
    $probe = app(SaturationProbe::class);
    $budget = (int) config('shedding.readiness.freshness');

    $probe->publish($probe->measure(time() - $budget - 1));

    $this->getJson('/readyz')->assertStatus(503)
        ->assertJsonPath('reason', 'the readiness snapshot is stale');

    // Fresh again, same code path.
    $probe->publish($probe->measure(time()));

    $this->getJson('/readyz')->assertStatus(200)->assertJsonPath('ready', true);
});

it('is scheduled sub-minute, without overlapping, and needs no tick', function (): void {
    $events = app(Schedule::class)->events();

    $readiness = array_values(array_filter(
        $events,
        static fn (Event $event): bool => str_contains((string) $event->command, 'readiness:refresh'),
    ));

    expect($readiness)->toHaveCount(1);

    // '* * * * *' plus a 5-second repeat is what ->everyFiveSeconds() produces, and the
    // repeat interval is the part that makes it sub-minute: ScheduleRunCommand re-runs
    // repeatable events until the end of the minute, while schedule:work starts one
    // schedule:run per minute. Octane's ticks are Swoole-only and do not exist here.
    expect($readiness[0]->expression)->toBe('* * * * *')
        ->and($readiness[0]->repeatSeconds)->toBe((int) config('shedding.readiness.cadence'))
        // A probe that waits on a TCP timeout must not stack one run every five seconds,
        // each holding a connection out of the worker role's 20.
        ->and($readiness[0]->withoutOverlapping)->toBeTrue();
});
