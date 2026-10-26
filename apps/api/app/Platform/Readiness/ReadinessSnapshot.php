<?php

declare(strict_types=1);

namespace App\Platform\Readiness;

use JsonException;

/**
 * What the readiness carrier measured, the last time it ran.
 *
 * Readiness reads this and nothing else -- no Postgres connection on the request path
 * (B6). The reason is measured, not stylistic: with qrpager_app at its CONNECTION LIMIT
 * of 40, a new app connection is refused 08006, so a readiness endpoint that opened one
 * would return 500 under the one condition it exists to report. That is R-012.
 */
final class ReadinessSnapshot
{
    public function __construct(
        public readonly bool $postgresUp,
        public readonly bool $redisUp,
        public readonly int $appBackends,
        public readonly int $appLimit,
        public readonly int $measuredAt,
    ) {}

    /** 0.0 when no limit is known, so an unknown denominator never reads as "saturated". */
    public function saturation(): float
    {
        return $this->appLimit > 0 ? round($this->appBackends / $this->appLimit, 4) : 0.0;
    }

    public function ageInSeconds(int $now): int
    {
        return max(0, $now - $this->measuredAt);
    }

    /**
     * Stale is NOT ready, and that reading is only sound because the carrier is a
     * process in the same task: a carrier that has stopped is a task that should be
     * replaced. A request-path sampled refresh would make "stale" mean "no traffic",
     * where reporting not-ready would kill an idle task.
     */
    public function isFresh(int $now, int $budgetSeconds): bool
    {
        return $this->ageInSeconds($now) <= $budgetSeconds;
    }

    /** @return array<string, bool|float|int> */
    public function toArray(): array
    {
        return [
            'postgres_up' => $this->postgresUp,
            'redis_up' => $this->redisUp,
            'app_backends' => $this->appBackends,
            'app_limit' => $this->appLimit,
            'saturation' => $this->saturation(),
            'measured_at' => $this->measuredAt,
        ];
    }

    /**
     * @throws JsonException
     */
    public function encode(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /** Anything unreadable is treated as absent rather than guessed at. */
    public static function decode(string $payload): ?self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['postgres_up', 'redis_up', 'app_backends', 'app_limit', 'measured_at'] as $key) {
            if (! array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return new self(
            (bool) $decoded['postgres_up'],
            (bool) $decoded['redis_up'],
            (int) $decoded['app_backends'],
            (int) $decoded['app_limit'],
            (int) $decoded['measured_at'],
        );
    }
}
