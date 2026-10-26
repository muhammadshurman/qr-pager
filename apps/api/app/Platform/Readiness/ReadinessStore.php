<?php

declare(strict_types=1);

namespace App\Platform\Readiness;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * The one Redis key readiness reads and the carrier writes.
 *
 * Redis directly rather than through the cache abstraction, for two reasons. The
 * suite runs with CACHE_STORE=array (phpunit.xml), so a cache-backed store would be
 * tested against an in-process array and never against Redis -- the "no mocks for
 * behaviour that belongs to the service" rule, broken quietly. And §4 › Realtime
 * already makes a Redis-only read the shape for the Task 4 polling endpoint.
 */
final class ReadinessStore
{
    public const KEY = 'qrpager:readiness';

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * Null means "nothing to read", which readiness treats as not ready. Redis being
     * down lands here too, by design: readiness has to reflect real Redis state, and
     * the gate says so.
     */
    public function read(): ?ReadinessSnapshot
    {
        try {
            /** @var mixed $payload */
            $payload = $this->redis->connection()->get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($payload) ? ReadinessSnapshot::decode($payload) : null;
    }

    /**
     * Written with a TTL a few multiples of the freshness budget: a carrier that dies
     * leaves a key that disappears rather than one that lingers and reads fresh.
     */
    public function write(ReadinessSnapshot $snapshot, int $ttlSeconds): bool
    {
        try {
            $this->redis->connection()->setex(self::KEY, max(1, $ttlSeconds), $snapshot->encode());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function forget(): void
    {
        try {
            $this->redis->connection()->del(self::KEY);
        } catch (Throwable) {
            // Nothing to clear is the same outcome as clearing it.
        }
    }
}
