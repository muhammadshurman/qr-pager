<?php

declare(strict_types=1);

namespace App\Platform\Readiness;

/**
 * Memory and CPU pressure, from cgroup v2 -- the only source that describes THIS TASK.
 *
 * WHY NOT sys_getloadavg(). It works, and it reports the HOST: under Fargate that is the
 * instance and not the task, so a neighbour's load would shed this task's traffic.
 * Measured and rejected in phase 1 (D4).
 *
 * WHY CPU PRESSURE IS THROTTLING AND NOT UTILISATION. cpu.stat's nr_throttled is the
 * only signal that means "this task is being held back" rather than "this task is
 * busy", and §4 › Edge & ops is explicit that a busy task must not be shed -- shedding a
 * busy task moves its traffic to its neighbours, which is a cascade and not shedding.
 * It is a cumulative counter, so what is read is the DELTA over a window.
 *
 * THE MEMORY FALLBACK IS WEAKER AND SAYS SO. memory.max reads the literal string "max"
 * where no limit is set, and in that case memory.current is the whole machine's usage --
 * which is the sys_getloadavg trap again. So with no cgroup limit this measures THIS PHP
 * PROCESS against the configured absolute ceiling instead. That is a narrower signal (one
 * worker, not the task) and it is the honest one available: the alternative is to report
 * a developer machine's 6 GB against a 512 MB ceiling and shed every request.
 */
final class PressureGauge
{
    private const ROOT = '/sys/fs/cgroup';

    /** @var array{int, int}|null  the last cpu.stat reading: [nr_throttled, unix time] */
    private ?array $lastThrottleReading = null;

    public function __construct(private readonly string $root = self::ROOT) {}

    /**
     * Memory in use as a fraction of the limit, or null when nothing can be measured.
     *
     * Null is not 0.0: "cannot tell" must not read as "plenty of headroom", and the
     * caller decides what to do with not knowing.
     */
    public function memoryFraction(): ?float
    {
        $limit = $this->readInt('memory.max');

        if ($limit !== null && $limit > 0) {
            $used = $this->readInt('memory.current');

            return $used === null ? null : round($used / $limit, 4);
        }

        // No cgroup limit, so no task boundary to measure against. This process against
        // the configured ceiling is what is left -- narrower, and not the host's number.
        $ceiling = (int) config('shedding.memory.ceiling_bytes');

        if ($ceiling <= 0) {
            return null;
        }

        return round(memory_get_usage(true) / $ceiling, 4);
    }

    /**
     * Has this task been throttled more than the configured budget in the last window?
     *
     * The first call establishes the baseline and answers false: a cumulative counter
     * says nothing until it has been read twice. That is deliberate -- a worker's first
     * request must not be shed because the counter has been climbing since boot.
     */
    public function cpuIsThrottled(int $now): bool
    {
        $throttled = $this->readCpuStat('nr_throttled');

        if ($throttled === null) {
            return false;
        }

        $previous = $this->lastThrottleReading;
        $window = max(1, (int) config('shedding.cpu.window_seconds'));
        $budget = max(0, (int) config('shedding.cpu.throttled_periods_per_window'));

        if ($previous === null || $now - $previous[1] >= $window) {
            $this->lastThrottleReading = [$throttled, $now];
        }

        if ($previous === null) {
            return false;
        }

        return $throttled - $previous[0] > $budget;
    }

    /** True when there is no CPU quota at all, so throttling is not a thing that can happen. */
    public function cpuIsUnlimited(): bool
    {
        $quota = trim((string) $this->read('cpu.max'));

        return $quota === '' || str_starts_with($quota, 'max');
    }

    private function readCpuStat(string $key): ?int
    {
        foreach (preg_split('/\R/', (string) $this->read('cpu.stat')) ?: [] as $line) {
            [$name, $value] = array_pad(explode(' ', trim($line), 2), 2, null);

            if ($name === $key && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function readInt(string $file): ?int
    {
        $value = trim((string) $this->read($file));

        return is_numeric($value) ? (int) $value : null;
    }

    private function read(string $file): ?string
    {
        $path = $this->root.'/'.$file;

        // No is_readable() guard before the read: on a container filesystem the two can
        // disagree, and a failed read is the same answer as an unreadable one.
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
