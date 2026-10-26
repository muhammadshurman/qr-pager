<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rate limiter, with the posture Redis actually requires.
 *
 * WHY THIS CLASS EXISTS RATHER THAN A ThrottleRequests MIDDLEWARE. Measured in phase 1
 * with REDIS_PORT pointed at a dead socket before boot: Redis::ping(),
 * Cache::store('redis')->get(), RateLimiter::attempt() and
 * RateLimiter::tooManyAttempts() ALL threw RedisException (phpredis 6.3.0). So "the API
 * keeps serving with Redis down" is false by default -- a throttle middleware turns a
 * Redis outage into a 500 on every request -- and B7's fail-open posture is code that
 * has to be written and tested.
 *
 * ON THE REDIS STORE EXPLICITLY, not on the default cache. The suite runs with
 * CACHE_STORE=array (phpunit.xml), so a limiter on the default store would be tested
 * against an in-process array and never against Redis: the one behaviour that matters
 * here belongs to Redis, and testing it against a fake would prove the fake. Same
 * reasoning as ReadinessStore.
 */
final class RequestLimiter
{
    private ?RateLimiter $limiter = null;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Consume one attempt against $key, and say whether the caller is over its limit.
     *
     * FAIL OPEN on any failure of the store, which includes Redis being unreachable.
     * Logged at warning rather than swallowed: an outage that silently removes rate
     * limiting is the residual B7 sends to §5, and it must at least be visible in the
     * log while it is happening.
     */
    public function tooManyAttempts(string $key, int $attempts, int $seconds): bool
    {
        try {
            if ($this->limiter()->tooManyAttempts($key, $attempts)) {
                return true;
            }

            $this->limiter()->hit($key, $seconds);

            return false;
        } catch (Throwable $failure) {
            if (! (bool) config('ratelimit.fail_open', true)) {
                return true;
            }

            // The class only, never the message: a connection failure's message carries
            // the host and port, and this line goes to the same log every other line
            // does.
            $this->log->warning('rate limiter unavailable, failing open', [
                'exception' => $failure::class,
            ]);

            return false;
        }
    }

    /** Seconds until $key is usable again, or 0 when that cannot be determined. */
    public function availableIn(string $key): int
    {
        try {
            return max(0, $this->limiter()->availableIn($key));
        } catch (Throwable) {
            return 0;
        }
    }

    public function clear(string $key): void
    {
        try {
            $this->limiter()->clear($key);
        } catch (Throwable) {
            // Nothing to clear is the same outcome as clearing it.
        }
    }

    private function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->cache->store('redis'));
    }
}
