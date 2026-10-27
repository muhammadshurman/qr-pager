<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Database\DatabaseRoles;
use PDO;
use PDOException;

/**
 * Hold the app role at its CONNECTION LIMIT with raw PDO handles.
 *
 * Raw PDO rather than Laravel connections, so the manager is not holding them and
 * releasing them is one unset rather than a purge of something the suite still needs.
 *
 * ONE SOURCE FOR THREE CALL SITES — `ReadinessCarrierTest` twice and `ShedLoadTest` once
 * — which is why this is a class and not a Pest helper: a global function is shared
 * across a run but lives in one file, so calling it from another works when the whole
 * suite runs and fails when that file runs alone (`OutboxCrashTest` records the same
 * constraint). `ShedLoadTest` also carried the limit as a hardcoded `41`, which would
 * have gone stale the first time `DatabaseRoles::connectionLimit('app')` moved.
 *
 * ===================================================================================
 * ⚠ THE HANDLES ARE OPENED WITH A PAUSE BETWEEN THEM, AND THAT IS LOAD-BEARING — R-092
 * ===================================================================================
 *
 * Opening forty-one connections in a tight loop made OTHER ROLES' connections fail, in
 * other test files, several minutes later in the run. Measured 2026-10-27 on
 * `/home/pixels/qr-pager`, 10 full-suite executions: 7 green, 3 red, each red a
 * different test, two of them `SQLSTATE[08006] ... connection to server at "127.0.0.1",
 * port 5432 failed: timeout expired` on `pgsql_owner` and `pgsql_root` inside
 * `TestCase::setUp`.
 *
 * POSTGRES NEVER SAW THOSE CONNECTIONS. Its log carries no FATAL and no error for them
 * — the only FATALs in an hour of running are this saturator's own expected
 * `too many connections for role "qrpager_app"` refusals. The connect never reached the
 * postmaster, so the fault is below it, in the loopback forward between the host's
 * 127.0.0.1:5432 and the container.
 *
 * THE RECONSTRUCTION, with the number that names it. A standalone harness that ran this
 * burst in a loop while a second process connected as owner and root measured a SLOWEST
 * CONNECT OF 32.430 SECONDS, and one outright failure in 864 attempts. 32 seconds is not
 * a queue; it is TCP SYN retransmission — 1 + 2 + 4 + 8 + 16 ≈ 31 s — which means the
 * SYNs were being DROPPED rather than refused. A dropped SYN is what an overflowing
 * forward path does, and the host's own listen backlog is 4096, so the drop is in the
 * Docker/WSL2 hop.
 *
 * THE SAME HARNESS WITH THIS PAUSE: zero failures in 1,152 attempts, slowest connect
 * 0.024 s. Same burst size, same held state, same refusal at the limit — only the RATE
 * changed, and the worst case fell by a factor of about 1,350.
 *
 * WHAT THIS DOES NOT WEAKEN. The property every caller is testing is the HELD STATE —
 * the app role sitting at its limit while something else is measured — and that is
 * unchanged: the same number of handles, held at once, with the one past the limit still
 * refused. The rate at which they were opened was never part of any clause; it was an
 * accident of a `for` loop.
 *
 * It costs about 120 ms per call and there are three calls in the suite.
 */
final class AppPoolSaturator
{
    /**
     * Microseconds between connection attempts. Three milliseconds is not a magic
     * number: it is the smallest value the reconstruction above was clean at, with the
     * tight loop (0 µs) failing and 3 ms giving 1,152 clean connects. If this flake ever
     * returns, RAISE THIS before changing anything else, and re-run the reconstruction
     * rather than guessing.
     */
    private const int STAGGER_MICROSECONDS = 3000;

    /**
     * Open one more connection than the app role is allowed, so the last is refused.
     *
     * @return list<PDO> the handles that were accepted; dropping them closes the pool
     */
    public static function hold(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('database.connections.pgsql');

        $dsn = "pgsql:host={$config['host']};port={$config['port']};"
            ."dbname={$config['database']};sslmode={$config['sslmode']}";

        $held = [];

        // Up to the limit PLUS ONE: the one past it is expected to be refused, and that
        // refusal is the condition R-012 is about.
        for ($i = 0; $i < DatabaseRoles::connectionLimit('app') + 1; $i++) {
            try {
                $held[] = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ]);
            } catch (PDOException) {
                break;
            }

            usleep(self::STAGGER_MICROSECONDS);
        }

        return $held;
    }
}
