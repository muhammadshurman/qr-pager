<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Middleware\ShedLoad;
use App\Platform\Readiness\PressureGauge;
use App\Platform\Readiness\ReadinessSnapshot;
use App\Platform\Readiness\ReadinessStore;
use App\Platform\Readiness\SaturationProbe;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * "A saturated DB pool returns a fast 503, and memory/CPU pressure returns 503 -- while
 * health and readiness keep answering" (PLAN.md §3, row 2).
 *
 * FAST means no Postgres. At 40/40 a new app connection is refused 08006, so a
 * middleware that measured the pool by connecting to it would fail under exactly the
 * condition it exists to detect -- R-012 one layer up. The figure comes from the key the
 * readiness carrier writes.
 */

beforeEach(function (): void {
    $this->actingForTenant();
    app(ReadinessStore::class)->forget();

    Route::middleware('api')->prefix('v1')->get('shed-probe', fn () => response()->json(['ok' => true]));
});

/** Publish a snapshot with the saturation this case needs. */
function publishSaturation(float $saturation, ?int $measuredAt = null): void
{
    $limit = 40;

    app(ReadinessStore::class)->write(new ReadinessSnapshot(
        postgresUp: true,
        redisUp: true,
        appBackends: (int) round($saturation * $limit),
        appLimit: $limit,
        measuredAt: $measuredAt ?? time(),
    ), 60);
}

/** A cgroup v2 tree on disk, so the gauge is driven against files and not against mocks. */
function fakeCgroup(array $files): string
{
    $root = sys_get_temp_dir().'/qrpager-cgroup-'.Str::random(10);

    @mkdir($root, 0o755, true);

    foreach ($files as $name => $contents) {
        file_put_contents($root.'/'.$name, $contents);
    }

    return $root;
}

it('returns a fast 503 when the pool is saturated, and touches no database', function (): void {
    publishSaturation(1.0);

    $this->purgeConnections();

    $statements = [];
    DB::listen(function (QueryExecuted $event) use (&$statements): void {
        $statements[] = $event->sql;
    });

    $response = $this->getJson('/v1/shed-probe');

    $response->assertStatus(503)->assertJsonPath('type', ProblemType::Unavailable->value);

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        // FAST: the refusal cost zero Postgres round trips. A middleware that opened a
        // connection to measure the pool would be refused 08006 at exactly this moment.
        ->and($statements)->toBe([], 'shedding touched the database: '.implode(' | ', $statements))
        // The figure is not in the body: "saturation 0.95" tells a caller how close this
        // task is to the edge, which is a capacity measurement of someone else's
        // infrastructure.
        ->and((string) $response->getContent())->not->toContain('saturation')
        ->not->toContain('0.9');
});

it('keeps both probes answering while /v1 is being shed', function (): void {
    publishSaturation(1.0);

    $this->getJson('/v1/shed-probe')->assertStatus(503);

    // The second half of the clause, and the reason the probe group is empty: a health
    // check that sheds with everything else makes the load balancer kill a task that is
    // merely busy, and the pressure moves to its neighbours. That is a cascade.
    $this->getJson('/healthz')->assertStatus(200)->assertJsonPath('status', 'alive');
    expect($this->getJson('/readyz')->status())->toBeIn([200, 503]);
});

it('serves normally below the threshold', function (): void {
    publishSaturation(0.5);

    $this->getJson('/v1/shed-probe')->assertStatus(200)->assertJsonPath('ok', true);
});

it('sheds exactly at the configured threshold and not before it', function (): void {
    $threshold = (float) config('shedding.pool_saturation');

    expect($threshold)->toBe(0.9);

    // 35/40 = 0.875, below. 36/40 = 0.9, at. The boundary is >=, so the second sheds.
    app(ReadinessStore::class)->write(new ReadinessSnapshot(true, true, 35, 40, time()), 60);
    $this->getJson('/v1/shed-probe')->assertStatus(200);

    app(ReadinessStore::class)->write(new ReadinessSnapshot(true, true, 36, 40, time()), 60);
    $this->getJson('/v1/shed-probe')->assertStatus(503);
});

it('does not shed on a missing or stale snapshot — it fails OPEN where readiness fails closed', function (): void {
    // THE ASYMMETRY, and the thing to understand about this middleware. Readiness
    // answers "should traffic come here", so not knowing must mean no. Shedding answers
    // "should I refuse this request", so not knowing must mean carry on -- otherwise
    // losing Redis turns into an outage, and the gate's own clause is that the API keeps
    // serving with Redis down.
    app(ReadinessStore::class)->forget();

    $this->getJson('/v1/shed-probe')->assertStatus(200);
    $this->getJson('/readyz')->assertStatus(503);

    // Stale and saturated: readiness refuses, shedding does not.
    publishSaturation(1.0, time() - (int) config('shedding.readiness.freshness') - 1);

    $this->getJson('/v1/shed-probe')->assertStatus(200);
    $this->getJson('/readyz')->assertStatus(503);
});

it('returns 503 under memory pressure, read from cgroup v2', function (): void {
    $root = fakeCgroup([
        'memory.max' => "1000\n",
        'memory.current' => "950\n",
        'cpu.max' => "max 100000\n",
        'cpu.stat' => "nr_periods 0\nnr_throttled 0\n",
    ]);

    app()->instance(PressureGauge::class, new PressureGauge($root));

    expect(app(PressureGauge::class)->memoryFraction())->toBe(0.95);

    publishSaturation(0.1);

    $this->getJson('/v1/shed-probe')->assertStatus(503)
        ->assertJsonPath('type', ProblemType::Unavailable->value);

    // ...and below the fraction it serves, from the same files.
    app()->instance(PressureGauge::class, new PressureGauge(fakeCgroup([
        'memory.max' => "1000\n",
        'memory.current' => "100\n",
        'cpu.stat' => "nr_periods 0\nnr_throttled 0\n",
    ])));

    $this->getJson('/v1/shed-probe')->assertStatus(200);
});

it('measures this process against the ceiling when the cgroup has no limit', function (): void {
    // memory.max reads the literal string "max" where no limit is set -- measured on
    // this machine -- and in that case memory.current is the whole MACHINE's usage,
    // which is the sys_getloadavg trap again (6 GB against a 512 MB ceiling would shed
    // every request). So the fallback measures this PHP process instead.
    $gauge = new PressureGauge(fakeCgroup([
        'memory.max' => "max\n",
        'memory.current' => "6220210176\n",
        'cpu.stat' => "nr_periods 0\nnr_throttled 0\n",
    ]));

    config(['shedding.memory.ceiling_bytes' => 512 * 1024 * 1024]);

    $fraction = $gauge->memoryFraction();

    expect($fraction)->not->toBeNull()
        // This worker, not the machine: 6 GB / 512 MB would be 11.87.
        ->and($fraction)->toBeLessThan(1.0)
        ->and($fraction)->toBeGreaterThan(0.0);
});

it('reports 503 when the task is being throttled, and not merely when it is busy', function (): void {
    // nr_throttled is cumulative, so the signal is its DELTA. The first reading
    // establishes the baseline and answers false on purpose: a worker's first request
    // must not be shed because the counter has been climbing since boot.
    $root = fakeCgroup([
        'cpu.max' => "50000 100000\n",
        'cpu.stat' => "nr_periods 100\nnr_throttled 10\n",
    ]);

    $gauge = new PressureGauge($root);
    $now = time();

    expect($gauge->cpuIsThrottled($now))->toBeFalse('the first reading reported throttling with no baseline');

    // Past the budget, a full window later.
    $budget = (int) config('shedding.cpu.throttled_periods_per_window');
    $window = (int) config('shedding.cpu.window_seconds');

    file_put_contents($root.'/cpu.stat', "nr_periods 200\nnr_throttled ".(10 + $budget + 1)."\n");

    expect($gauge->cpuIsThrottled($now + $window))->toBeTrue();
});

it('does not report throttling for a task that is merely busy', function (): void {
    // §4 › Edge & ops is explicit: a busy task must not be shed. Utilisation climbing
    // with nr_throttled flat is exactly "busy", and it has to read as healthy.
    $root = fakeCgroup([
        'cpu.max' => "50000 100000\n",
        'cpu.stat' => "nr_periods 100\nnr_throttled 4\nusage_usec 1000\n",
    ]);

    $gauge = new PressureGauge($root);
    $now = time();
    $window = (int) config('shedding.cpu.window_seconds');

    $gauge->cpuIsThrottled($now);

    file_put_contents($root.'/cpu.stat', "nr_periods 300\nnr_throttled 4\nusage_usec 999999999\n");

    expect($gauge->cpuIsThrottled($now + $window))->toBeFalse();
});

it('measures nothing rather than guessing when cgroup v2 is not there', function (): void {
    $gauge = new PressureGauge(sys_get_temp_dir().'/qrpager-no-such-cgroup-'.Str::random(8));

    config(['shedding.memory.ceiling_bytes' => 0]);

    // Null is not 0.0: "cannot tell" must not read as "plenty of headroom".
    expect($gauge->memoryFraction())->toBeNull()
        ->and($gauge->cpuIsThrottled(time()))->toBeFalse()
        ->and($gauge->cpuIsUnlimited())->toBeTrue();
});

it('logs the reason it shed, where the body carries none', function (): void {
    Log::spy();

    publishSaturation(1.0);

    $this->getJson('/v1/shed-probe')->assertStatus(503);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'shedding load'
            && str_contains((string) ($context['reason'] ?? ''), 'pool saturation'),
    );
});

it('runs before the rate limiter, so the cheapest refusal is the first one', function (): void {
    /** @var Kernel $kernel */
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $group = $kernel->getMiddlewareGroups()['api'];

    $shed = array_search(ShedLoad::class, $group, true);
    $limit = array_search(RateLimit::class, $group, true);
    $tenant = array_search(RequireTenantScope::class, $group, true);

    expect($shed)->not->toBeFalse()
        ->and($limit)->not->toBeFalse()
        ->and($tenant)->not->toBeFalse()
        ->and($shed)->toBeLessThan($limit)
        ->and($limit)->toBeLessThan($tenant);

    // And none of them is on the probe group, which is what "while health and readiness
    // keep answering" means mechanically.
    expect($kernel->getMiddlewareGroups()['probe'])->toBe([]);
});

it('sheds a real saturated pool end to end', function (): void {
    // Not a published number this time: the carrier measures the live cluster with the
    // app role held at its limit, and the middleware reads what it wrote.
    DB::purge('pgsql');

    $held = [];
    $config = (array) config('database.connections.pgsql');
    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};sslmode={$config['sslmode']}";

    for ($i = 0; $i < 41; $i++) {
        try {
            $held[] = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException) {
            break;
        }
    }

    try {
        $probe = app(SaturationProbe::class);
        $snapshot = $probe->measure(time());
        $probe->publish($snapshot);

        expect($snapshot->saturation())->toBeGreaterThanOrEqual((float) config('shedding.pool_saturation'));

        $this->getJson('/v1/shed-probe')->assertStatus(503);
        $this->getJson('/healthz')->assertStatus(200);
    } finally {
        $held = [];
        DB::purge('pgsql');
    }
});
