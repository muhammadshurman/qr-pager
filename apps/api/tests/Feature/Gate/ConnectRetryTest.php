<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Tests\Support\RetryingPostgresConnector;

/*
 * R-191's BOUND, PROVEN AGAINST A REAL libpq FAILURE RATHER THAN A HAND-WRITTEN STRING.
 *
 * `tests/Support/RetryingPostgresConnector.php` carries the mechanism and the measurements.
 * The short version: `pdo_pgsql` appends `connect_timeout` itself, defaulting to **30**,
 * whenever any driver option is passed, and `Illuminate\Database\Connectors\Connector` always
 * passes five -- so a 30-second deadline nobody in this repository chose has been in force on
 * every connect since Task 1, against a host whose SYN-retransmission stall was measured at
 * **32.430 s** (`AppPoolSaturator`) and **35,648 ms** (`R-191`, with 0 of 300 connects actually
 * failing). The connects were succeeding; the undocumented deadline expired first.
 *
 * ⚠ THE STALL IS PRODUCED HERE, NOT WAITED FOR, AND IT IS PRODUCED PORTABLY. A listening
 * socket that completes the TCP handshake and never answers the startup packet makes libpq
 * raise its own `SQLSTATE[08006] ... failed: timeout expired` in exactly `ATTR_TIMEOUT`
 * seconds. Measured 2026-11-07: 2.00 s at ATTR_TIMEOUT=2, on 127.0.0.1.
 *
 * That matters for two reasons. First, the message is the DRIVER's and not this test's, so the
 * matcher is proven against the real text -- which is the half most likely to be silently
 * wrong, and is how this family's `classify-reds` matcher went stale twice
 * (`known-flakes.json` › `matcher_history`). Second, it uses nothing but loopback, so it holds
 * in a fresh clone and in CI. The first draft pointed at 192.0.2.1 (TEST-NET-1) and measured
 * 32.75 s; that number is the evidence for the 30-second default and is recorded in
 * `config/database.php`, but it depends on how a host treats an unroutable address, so it is
 * NOT what the suite asserts on.
 */

/**
 * A listening socket that accepts and never speaks. Returns [server, port].
 *
 * No `accept()` call at all: the kernel's own backlog completes the handshake, which is what
 * makes libpq get past `connect()` and then block on the startup packet until its deadline.
 *
 * @return array{0: resource, 1: int}
 */
function silentPostgres(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    expect($server)->not->toBeFalse("could not open a silent listener: {$errstr} ({$errno})");

    /** @var resource $server */
    $name = (string) stream_socket_get_name($server, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);

    expect($port)->toBeGreaterThan(0, "could not read the listener's port from [{$name}]");

    return [$server, $port];
}

/**
 * The real pgsql config, pointed at a socket that will stall, with a short deadline.
 *
 * @return array<string, mixed>
 */
function stallingConfig(int $port, int $timeout = 2): array
{
    /** @var array<string, mixed> $config */
    $config = (array) config('database.connections.'.DatabaseRoles::connection('owner'));

    $config['host'] = '127.0.0.1';
    $config['port'] = (string) $port;
    $config['options'] = [PDO::ATTR_TIMEOUT => $timeout];

    return $config;
}

/**
 * Stalls for the first N attempts, then connects for real.
 *
 * `parent::attempt()` on both paths, so the REAL driver raises the stall and the REAL driver
 * makes the successful connection. Nothing here fakes a PDO or a message.
 */
final class StallsThenConnects extends RetryingPostgresConnector
{
    private int $seen = 0;

    /** @param  array<string, mixed>  $stalling */
    public function __construct(private readonly int $stalls, private readonly array $stalling) {}

    public function attempts(): int
    {
        return $this->seen;
    }

    /** @param  array<string, mixed>  $config */
    protected function attempt(array $config): PDO
    {
        $this->seen++;

        return parent::attempt($this->seen <= $this->stalls ? $this->stalling : $config);
    }
}

beforeEach(function (): void {
    RetryingPostgresConnector::resetAbsorbed();
});

it('absorbs ONE real libpq connect stall and returns a working connection', function (): void {
    // THE HEADLINE CASE. Attempt one is a genuine 08006/timeout expired from the driver;
    // attempt two is the real database. What the suite gets back is a usable PDO, which is the
    // whole claim: a stall costs a retry rather than a re-run of the gate.
    [$server, $port] = silentPostgres();

    try {
        $connector = new StallsThenConnects(1, stallingConfig($port));

        /** @var array<string, mixed> $real */
        $real = (array) config('database.connections.'.DatabaseRoles::connection('owner'));

        $started = microtime(true);
        $pdo = $connector->connect($real);
        $elapsed = microtime(true) - $started;

        // Usable, not merely non-null: a connector that returned a broken handle would pass a
        // null check and fail every test after it with something unrelated.
        expect($pdo->query('select 1 as one')?->fetchColumn())->toEqual(1)
            ->and($connector->attempts())->toBe(2, 'the stall was not retried at all')
            ->and(RetryingPostgresConnector::absorbed())->toBe(1, 'the absorbed stall was not counted')
            // The deadline it waited out, plus the real connect. It must not have waited twice.
            ->and($elapsed)->toBeLessThan(10.0, 'the retry waited far longer than one deadline');
    } finally {
        fclose($server);
    }
});

it('re-throws an exhausted stall carrying BOTH the driver message and what the reader needs', function (): void {
    // ⚠ THE DRIVER'S OWN TEXT IS KEPT, AND THAT IS NOT COSMETIC. `bin/classify-reds` attributes
    // this family by `SQLSTATE[08006]` and `timeout expired` over the failure block. A connector
    // that rewrote the message would turn an exhausted retry into a REAL RED -- the exact
    // stale-matcher defect `known-flakes.json` › `matcher_history` records twice, in the
    // direction that loses the attribution.
    [$server, $port] = silentPostgres();

    try {
        $connector = new StallsThenConnects(99, stallingConfig($port, 1));

        /** @var array<string, mixed> $real */
        $real = (array) config('database.connections.'.DatabaseRoles::connection('owner'));

        try {
            $connector->connect($real);
            $message = null;
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        expect($message)->not->toBeNull('a permanent stall was absorbed into a success')
            // What classify-reds reads.
            ->and($message)->toContain('SQLSTATE[08006]')
            ->and($message)->toContain('timeout expired')
            // What a person reads.
            ->and($message)->toContain('R-191')
            ->and($message)->toContain('connect attempt(s)')
            // Two attempts, not one and not three: ATTEMPTS is 2, and at a 45 s deadline a
            // third would spend 135 s inside one setUp.
            ->and($connector->attempts())->toBe(2)
            ->and(RetryingPostgresConnector::absorbed())->toBe(1);
    } finally {
        fclose($server);
    }
});

it('does NOT retry a refused connection, so the tests that point at a dead port stay fast', function (): void {
    // THE NEGATIVE CONTROL THAT PAYS FOR THE WIDTH OF THE MATCHER. `RouteSurfaceTest` points
    // pgsql at 59997 and asserts /healthz still answers; `ReadinessCarrierTest` and others do
    // the same. A connector that retried every connect failure would double the cost of each of
    // those and, worse, would make a deliberately-unreachable dependency look intermittent.
    //
    // 08006 is NOT the discriminator here and that is the point: a refusal is also a connection
    // failure. The literal `timeout expired` is what separates this family from every other
    // 08006, which is the same pair known-flakes.json matches on.
    /** @var array<string, mixed> $config */
    $config = (array) config('database.connections.'.DatabaseRoles::connection('owner'));
    $config['host'] = '127.0.0.1';
    $config['port'] = '59997';

    $started = microtime(true);

    try {
        (new RetryingPostgresConnector)->connect($config);
        $message = null;
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }

    $elapsed = microtime(true) - $started;

    expect($message)->not->toBeNull('a dead port connected')
        // One needle: Pest's `toContain()` takes further NEEDLES rather than a message, and the
        // negated two-argument form is silently always green.
        ->and(str_contains((string) $message, 'R-191'))
        ->toBeFalse('a refusal was annotated as a connect stall')
        ->and(RetryingPostgresConnector::absorbed())->toBe(0, 'a refusal was retried')
        ->and($elapsed)->toBeLessThan(5.0, 'a refused connect is no longer immediate');
});

it('does NOT retry the other 08006s this project deliberately produces', function (): void {
    // Two more real 08006s live in this suite and BOTH are results rather than faults:
    // `AppPoolSaturator`'s callers assert `too many connections for role`, and
    // `withThrowawayRole()` produces `permission denied for database`. Driven through the real
    // policy with the driver's real wording for each, because a matcher keyed on the SQLSTATE
    // alone would have turned both into waits.
    $states = [
        'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: '.
            'FATAL:  too many connections for role "qrpager_app"',
        'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: '.
            'FATAL:  permission denied for database "qrpager_test"',
    ];

    foreach ($states as $text) {
        RetryingPostgresConnector::resetAbsorbed();

        $connector = new class($text) extends RetryingPostgresConnector
        {
            public int $seen = 0;

            public function __construct(private readonly string $text) {}

            /** @param array<string, mixed> $config */
            protected function attempt(array $config): PDO
            {
                $this->seen++;

                throw new PDOException($this->text);
            }
        };

        try {
            $connector->connect([]);
        } catch (Throwable) {
            // the throw is the subject
        }

        expect($connector->seen)->toBe(1, "retried an 08006 that is a result: {$text}")
            ->and(RetryingPostgresConnector::absorbed())->toBe(0);
    }
});

it('is actually IN FORCE for the application, not merely present in the tree', function (): void {
    // ⚠ THE GUARD-GOES-QUIET CASE, and §4a records four instances of that shape. Everything
    // above tests the connector directly, so all of it would stay green with the container
    // binding deleted and the suite back to dying on a stall. This reads the binding the
    // framework will actually use, and then reads the connection that came out of it.
    expect(app('db.connector.pgsql'))->toBeInstanceOf(RetryingPostgresConnector::class);

    // And the real connections resolve through it: a bound connector that no connection uses
    // is the same defect one layer down.
    foreach (['app', 'owner', 'worker'] as $role) {
        $name = DatabaseRoles::connection($role);

        expect(config('database.connections.'.$name.'.driver'))
            ->toBe('pgsql', "connection [{$name}] is not on the driver the retrying connector is bound for");
    }
});

it('keeps the connect deadline EXPLICIT, and the suite’s above this host’s measured worst connect', function (): void {
    // The other half of the fix, and the half that is a documentation defect as much as a
    // behavioural one: the deadline was never absent, it was pdo_pgsql's undocumented 30.
    $configured = config('database.connections.'.DatabaseRoles::connection('owner').'.options.'.PDO::ATTR_TIMEOUT);

    expect($configured)->toBeInt('the connect deadline is implicit again: pdo_pgsql will apply its own 30');

    // 35,648 ms is R-191's own measured maximum on a bare TCP connect under CPU contention, and
    // 32,430 ms is AppPoolSaturator's. The suite's deadline must sit above both, or the retry
    // above is being asked to cover a gap a number could have closed.
    expect((int) $configured)->toBeGreaterThan(
        36,
        'the suite’s connect deadline is at or below this host’s measured worst connect (35,648 ms), '.
        'so a stall still reds the run on the first attempt',
    );

    // And production's own value is the one that was always in force, so this key changed no
    // behaviour: it made a property readable. Whether 30 s is right for Aurora is Task 13's.
    expect((int) env('DB_CONNECT_TIMEOUT'))->toBe(45, 'phpunit.xml no longer sets the suite’s deadline');
});
