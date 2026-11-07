<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;
use PDOException;
use Throwable;

/**
 * R-191's BOUND: one absorbed connect stall, in the SUITE ONLY, reported rather than hidden.
 *
 * ===================================================================================
 * WHAT R-191 ACTUALLY IS, which is not what its row said until 2026-11-07
 * ===================================================================================
 *
 * The row called it *"libpq reporting an OS-level `connect()` that did not complete"* on the
 * grounds that *"no connect_timeout is configured anywhere"*. The second clause is true of
 * this repository and the first does not follow: `pdo_pgsql` appends `connect_timeout` of its
 * own, from `PDO::ATTR_TIMEOUT`, **defaulting to 30**, whenever any driver option is passed --
 * and `Illuminate\Database\Connectors\Connector` always passes five. Measured 2026-11-07
 * against 192.0.2.1 (TEST-NET-1: packets dropped, not refused, which is this fault's shape):
 *
 *     ATTR_TIMEOUT = 3      failed in  3.01s   SQLSTATE[08006] ... failed: timeout expired
 *     Laravel's defaults    failed in 32.75s   SQLSTATE[08006] ... failed: timeout expired
 *
 * So the deadline was always 30 s, and this host's stall is 31-36 s: `AppPoolSaturator`
 * measured a worst connect of **32.430 s** and identified it as TCP SYN retransmission
 * (1+2+4+8+16 ~ 31 s, SYNs dropped by the WSL2/Docker forward), and `R-191` measured
 * **35,648 ms** on a bare TCP connect under CPU contention with **0 of 300 failing**. The
 * connects were SUCCEEDING. A deadline nobody chose was expiring first.
 *
 * That explains every property of the fault that was previously only described: random
 * identity (whichever `setUp` lands in a stall window), load correlation (the gate runs nine
 * steps before the suite), and why a bare `php artisan test` was green 0 of 2 and 2 of 2 --
 * fewer connects, less contention, no window wide enough.
 *
 * ===================================================================================
 * WHY A RETRY IS THE RIGHT BOUND, AND WHY IT IS HERE AND NOT IN THE APPLICATION
 * ===================================================================================
 *
 * The stall is TRANSIENT and its own measurements say so: median 162.6 ms, p95 320.1 ms, and
 * 0 of 300 outright failures. A second attempt a moment later is overwhelmingly likely to
 * land outside the window. `AppPoolSaturator`'s harness measured 1 outright failure in 864
 * attempts, which is the residual this retry covers and `DB_CONNECT_TIMEOUT=45` does not.
 *
 * ⚠ IT IS TEST INFRASTRUCTURE AND IS BOUND ONLY FROM `Tests\TestCase`. A retry in the
 * application would change production failure semantics on a path the limiter's own
 * fail-open posture and `DatabaseErrorMapper`'s 08006 mapping are both written against, and
 * it would do it on a host fault this machine has and AWS does not. The production question
 * -- whether a 30 s connect deadline with no retry is right for Aurora -- stays with
 * **Task 13** under `R-191`, now with a measured mechanism instead of a hypothesis.
 *
 * ===================================================================================
 * THE MATCH IS DELIBERATELY NARROW, AND THE NEGATIVE CONTROLS ARE WHAT PAY FOR IT
 * ===================================================================================
 *
 * Only `08006` AND the literal `timeout expired` is retried -- the same pair
 * `tests/known-flakes.json` matches this family by. Everything else is re-thrown on the
 * first attempt, which keeps three existing behaviours exactly as they are:
 *
 *   - `Connection refused` from a deliberately dead port (RouteSurfaceTest points pgsql at
 *     59997 and asserts the surface still answers) is immediate and stays immediate.
 *   - `too many connections for role` is also 08006 -- `DatabaseErrorMapper` records that --
 *     and is NOT retried, because `AppPoolSaturator`'s callers assert that refusal.
 *   - `permission denied for database` on a throwaway role, likewise 08006, is a result.
 *
 * A retry that swallowed those would turn tests that assert a refusal into tests that wait
 * for one. `ConnectRetryTest` drives all three as negative controls.
 *
 * ⚠ AND IT IS NOT SILENT, which is the condition that makes an absorbed failure acceptable
 * at all. Every absorbed stall appends a line to `storage/logs/r191-absorbed.log`, `bin/gate`
 * prints that file after the suite step whether the run is red or green, and a stall that
 * survives the retry is re-thrown with the attempt count and elapsed time appended to its
 * message -- so the next occurrence names itself, which is what `R-241` cost a day for
 * lacking. A host that is degrading shows up as a rising count in the gate log rather than
 * as nothing at all.
 */
class RetryingPostgresConnector extends PostgresConnector
{
    /**
     * SQLSTATE 08006 is also a saturated role limit and a refused database, so the state
     * alone is not this family. The pair below is the one `known-flakes.json` uses.
     */
    private const SQLSTATE = '08006';

    private const SIGNATURE = 'timeout expired';

    /**
     * One retry, not three.
     *
     * At 45 s a piece (phpunit.xml's DB_CONNECT_TIMEOUT), three attempts is 135 s inside one
     * test's setUp, which trades an attributable red for an unattributable timeout -- and a
     * suite that hangs with no output is the most expensive shape this project has paid for
     * (TestCase::SUITE_LOCK_KEY's docblock records the last one). Two attempts is the whole
     * of the measured need: the stall's p95 is 320 ms and its own measurement found 0 of 300
     * connects failing outright.
     */
    private const ATTEMPTS = 2;

    /** Absorbed stalls in THIS process, for the report at the end of the run. */
    private static int $absorbed = 0;

    public static function absorbed(): int
    {
        return self::$absorbed;
    }

    public static function resetAbsorbed(): void
    {
        self::$absorbed = 0;
    }

    /**
     * The file `bin/gate` prints after the suite step.
     *
     * Under `storage/logs` with the gate's own transcripts, and git-ignored with them. One
     * file per run would need the run's identity, which a connector has no way to know; one
     * APPEND-ONLY file whose lines carry a UTC timestamp is readable without it, and the
     * gate prints only the lines from the current run's window.
     */
    public static function reportPath(): string
    {
        return storage_path('logs/r191-absorbed.log');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $startedAt = microtime(true);

            try {
                return $this->attempt($config);
            } catch (Throwable $error) {
                $elapsed = microtime(true) - $startedAt;

                if ($attempt >= self::ATTEMPTS || ! self::isConnectStall($error)) {
                    throw self::annotate($error, $attempt, $elapsed, self::deadline($config));
                }

                self::$absorbed++;
                self::record($config, $attempt, $elapsed, $error);
            }
        }
    }

    /**
     * The one real connect, isolated so a test can replace it without replacing the policy.
     *
     * `ConnectRetryTest` subclasses this to throw a captured PDOException a chosen number of
     * times and then succeed, which is the only way to exercise the ABSORBING path
     * deterministically: a real stall is a property of the host at a moment and cannot be
     * asked for. The matcher is exercised against a REAL libpq message separately, by
     * connecting to a blackholed address -- so neither half of this rests on a string
     * somebody typed.
     *
     * @param  array<string, mixed>  $config
     */
    protected function attempt(array $config): PDO
    {
        return parent::connect($config);
    }

    /**
     * The deadline THIS connect was actually given, not the one the config carries.
     *
     * A per-connect `options` override wins over the config (`Connector::getOptions()` does
     * `array_diff_key($this->options, $options) + $options`), and several tests pass one. An
     * annotation that reported the config value while a 1-second override was in force would
     * be a measurement of the wrong thing printed beside a real failure, which is worse than
     * printing nothing. Falls back to pdo_pgsql's own default of 30 -- the value this whole
     * row turned out to be about -- when neither names one.
     *
     * @param  array<string, mixed>  $config
     */
    private static function deadline(array $config): int
    {
        /** @var array<int, mixed> $options */
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        if (isset($options[PDO::ATTR_TIMEOUT]) && is_numeric($options[PDO::ATTR_TIMEOUT])) {
            return (int) $options[PDO::ATTR_TIMEOUT];
        }

        return 30;
    }

    private static function isConnectStall(Throwable $error): bool
    {
        if (! $error instanceof PDOException) {
            return false;
        }

        $message = $error->getMessage();

        return str_contains($message, self::SQLSTATE)
            && str_contains($message, self::SIGNATURE);
    }

    /**
     * Re-throw carrying what the next reader needs, rather than the bare driver message.
     *
     * ⚠ THE ORIGINAL MESSAGE IS KEPT INTACT AND THE ANNOTATION IS APPENDED, which is not
     * cosmetic: `bin/classify-reds` matches `R-191` by `SQLSTATE[08006]` and `timeout
     * expired` over the failure block, so a rewritten message would make an exhausted retry
     * read as a REAL RED -- the exact stale-matcher defect this family's own
     * `matcher_history` records twice.
     */
    private static function annotate(Throwable $error, int $attempt, float $elapsed, int $deadline): Throwable
    {
        if (! self::isConnectStall($error) || ! $error instanceof PDOException) {
            return $error;
        }

        $annotated = new PDOException(sprintf(
            '%s [R-191: %d connect attempt(s), the last taking %.2fs against a '.
            'DB_CONNECT_TIMEOUT of %ds. This is the connect path, not a statement. '.
            'See config/database.php and tests/Support/RetryingPostgresConnector.php.]',
            $error->getMessage(),
            $attempt,
            $elapsed,
            $deadline,
        ), (int) $error->getCode(), $error);

        $annotated->errorInfo = $error->errorInfo;

        return $annotated;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function record(array $config, int $attempt, float $elapsed, Throwable $error): void
    {
        $line = sprintf(
            "%s  absorbed a connect stall: role=%s database=%s host=%s:%s attempt=%d elapsed=%.2fs  %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            is_scalar($config['username'] ?? null) ? (string) $config['username'] : '?',
            is_scalar($config['database'] ?? null) ? (string) $config['database'] : '?',
            is_scalar($config['host'] ?? null) ? (string) $config['host'] : '?',
            is_scalar($config['port'] ?? null) ? (string) $config['port'] : '?',
            $attempt,
            $elapsed,
            str_replace("\n", ' | ', $error->getMessage()),
        );

        // Best effort, and deliberately so: a report that cannot be written must not turn an
        // ABSORBED stall into a failure. The counter above is the in-process truth and
        // ConnectRetryTest asserts against it rather than against the file.
        try {
            @file_put_contents(self::reportPath(), $line, FILE_APPEND);
        } catch (Throwable) {
            // nothing to do, and nothing worth failing a run for
        }
    }
}
