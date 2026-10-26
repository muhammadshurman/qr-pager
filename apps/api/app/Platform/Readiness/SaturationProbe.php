<?php

declare(strict_types=1);

namespace App\Platform\Readiness;

use App\Platform\Database\DatabaseRoles;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Measures what readiness and load shedding both read, and writes it to one Redis key.
 *
 * THE LOAD-BEARING CHOICE IS THE CONNECTION. This runs on `pgsql_worker`, never on
 * `pgsql`, because a probe that measures app-role saturation must not be subject to it.
 * Measured in phase 1: with qrpager_app held at 40/40 and a new app connection refused
 * 08006, the same statement run as qrpager_worker still returned
 * {"app_backends":40,"app_limit":40,"saturation":1}. A probe on the app connection
 * fails under exactly the condition it exists to report -- which is R-012 restated, and
 * it is why the number is read from pg_stat_activity rather than by trying to connect.
 *
 * A non-superuser can read `usename` from pg_stat_activity, so this needs no
 * pg_read_all_stats grant and no §2 change. It costs one long-lived connection out of
 * the worker's separate 20-slot budget.
 *
 * WHY A SCHEDULED COMMAND AND NOT A TICK. Octane's Worker::handleTick() is called from
 * exactly one place in the package, bin/swoole-server, so ticks do not exist under
 * FrankenPHP -- measured, and it removed the obvious answer. The carrier is
 * `readiness:refresh` on ->everyFiveSeconds(), which is real sub-minute scheduling:
 * ScheduleRunCommand::repeatEvents() re-runs repeatable events until the end of the
 * minute while schedule:work starts one schedule:run per minute.
 */
final class SaturationProbe
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RedisFactory $redis,
        private readonly ReadinessStore $store,
    ) {}

    /** What the cluster and Redis say right now. Never throws: an unreachable dependency is a reading. */
    public function measure(int $now): ReadinessSnapshot
    {
        $limit = $this->appConnectionLimit();
        $backends = null;

        try {
            $row = $this->db->connection(DatabaseRoles::connection('worker'))->selectOne(
                'select count(*) as backends from pg_stat_activity where usename = ?',
                [DatabaseRoles::name('app')],
            );

            $backends = is_object($row) ? (int) ($row->backends ?? 0) : null;
        } catch (Throwable) {
            // Postgres unreachable FROM THE WORKER ROLE, which is the only reading that
            // means "Postgres is down" rather than "the app role is full". The two are
            // different answers and conflating them is the bug R-012 describes.
            $backends = null;
        }

        return new ReadinessSnapshot(
            postgresUp: $backends !== null,
            redisUp: $this->redisAnswers(),
            appBackends: $backends ?? 0,
            appLimit: $limit,
            measuredAt: $now,
        );
    }

    /**
     * Publish a snapshot. False when it could not be written, which readiness reads as
     * not ready -- a carrier that cannot reach Redis and a Redis nobody can reach are
     * the same answer to "should traffic come here".
     *
     * Separate from measure() so the command measures ONCE. The first version had
     * refresh() call measure() itself and the command call both, which took two
     * readings a few milliseconds apart and published the second while printing the
     * first -- a log line that disagrees with the key it describes.
     */
    public function publish(ReadinessSnapshot $snapshot): bool
    {
        return $this->store->write($snapshot, (int) config('shedding.readiness.ttl'));
    }

    /**
     * Redis, asked through the same client the application uses.
     *
     * Everything Redis THROWS when it is unavailable -- measured: with the port pointed
     * at a dead socket, ping, Cache::get, RateLimiter::attempt and tooManyAttempts all
     * raised RedisException. So "is Redis up" is a try/catch and not a return value,
     * and B7's fail-open posture is code rather than a property.
     */
    private function redisAnswers(): bool
    {
        try {
            $this->redis->connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The denominator, from config rather than from the cluster.
     *
     * It is the same number ALTER ROLE ... CONNECTION LIMIT was applied from
     * (DatabaseRoles::connectionLimit), so reading it back from pg_roles would be asking
     * the database to confirm what this process configured -- and that round trip is one
     * more thing to fail while the pool is already full. Task 1's DatabaseRolesTest
     * already pins the two against each other.
     */
    private function appConnectionLimit(): int
    {
        try {
            return DatabaseRoles::connectionLimit('app');
        } catch (Throwable) {
            return 0;
        }
    }
}
