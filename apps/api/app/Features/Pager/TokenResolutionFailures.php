<?php

declare(strict_types=1);

namespace App\Features\Pager;

use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Count failed token resolutions, so an enumeration leaves a trace. R-063's second clause.
 *
 * WHAT R-063 ACTUALLY FOUND. PagerTokenLookup's docblock and QRPAGER-10 §6.4 both answered
 * §11's "what does an attacker's loop cost us per request" with "one regex". That is the
 * cost of a MALFORMED token. A real enumeration sends WELL-FORMED ones -- 22 random
 * base64url characters are free to generate -- and each costs a sha256, a plpgsql call
 * with two set_config statements, an index probe and a round trip. There was no limiter on
 * the path, no counter, and no audit row, so a sustained enumeration left no trace in
 * audit_log and no metric either. The limiter is config/ratelimit.php's `pager-token`
 * bucket; this is the trace.
 *
 * ===================================================================================
 * WHY A COUNTER AND NOT AN audit_log ROW -- the explicit decision R-063 asks for
 * ===================================================================================
 *
 * It is not a preference. Three reasons, and the first is structural:
 *
 *  1. IT IS NOT POSSIBLE WITHOUT A BYPASS. audit_log carries tenant_isolation_audit_log
 *     with WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid).
 *     A failed resolution has NO TENANT by definition -- that is what failed -- so there
 *     is no scope to open, the check is NULL rather than true, and the insert is refused
 *     42501. Measured, not reasoned about.
 *  2. IT WOULD BE A DENIAL-OF-SERVICE AMPLIFIER. §2.11 already calls audit_log "unbounded,
 *     and the one to watch"; no grant in §2.7 deletes from it and the append-only trigger
 *     refuses DELETE and TRUNCATE to a superuser. An unauthenticated loop that writes a
 *     permanent row per attempt is a worse outcome than the enumeration it records.
 *  3. §2.8 makes the function body the audit boundary and does not require one, so this
 *     is a choice made explicitly rather than inherited -- which is what the row asked
 *     for.
 *
 * ===================================================================================
 * THE KEY HOLDS NO TENANT DATA, WHICH IS WHY IT IS NOT TENANT-NAMESPACED
 * ===================================================================================
 *
 * `pager:tok:fail:{YYYYMMDDHH}` is a bare integer per UTC hour. A failed resolution
 * resolved no tenant, so there is nothing tenant-scoped to namespace by and nothing
 * tenant-scoped stored. §4 › Realtime's invariant is about keys that HOLD tenant data;
 * this one holds a count of attempts that belonged to nobody. Stated here and in §2.13
 * rather than left looking like the exception that was forgotten.
 *
 * It carries no IP and no token -- not even a hash of one. A per-IP breakdown is the rate
 * limiter's job and it already hashes the address (RateLimit::keysFor); a second,
 * longer-lived per-IP structure here would be personal data this feature does not need.
 *
 * ===================================================================================
 * THE LOG IS THRESHOLDED, AND THAT IS R-046
 * ===================================================================================
 *
 * R-046 is the finding that unthrottled per-request warning logs on a high-volume failure
 * path are themselves a defect -- "an attacker's loop costs us a log line per request" is
 * the same amplification as the audit row above, one layer up. So the COUNTER is
 * unconditional and cheap, and the WARNING fires only on the attempt that crosses the
 * threshold: once per window, however long the loop runs.
 */
final class TokenResolutionFailures
{
    private const PREFIX = 'pager:tok:fail:';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $log,
    ) {}

    /** The key for one UTC hour. The only place this string is built. */
    public static function keyFor(DateTimeImmutable $now): string
    {
        return self::PREFIX.$now->format('YmdH');
    }

    /**
     * Record one failed resolution. Never throws, never fails the request.
     *
     * A counter that takes the endpoint down when Redis is unavailable would be worse
     * than no counter: the request it is observing is already being refused.
     */
    public function record(DateTimeImmutable $now): void
    {
        $key = self::keyFor($now);
        $threshold = (int) config('pager.token_failure_alert');

        try {
            $connection = $this->redis->connection();

            /** @var mixed $count */
            $count = $connection->incr($key);

            // Two hours, so the window that is being filled and the one before it are both
            // readable by whoever comes to look, and nothing accumulates beyond that.
            // Set on every increment rather than only on the first: EXPIRE on an existing
            // key just moves the deadline, and a key whose TTL was lost to a failed first
            // call would otherwise live forever.
            $connection->expire($key, 7200);
        } catch (Throwable) {
            return;
        }

        // Exactly the crossing attempt, so one line per window however long the loop runs.
        if ($threshold > 0 && is_int($count) && $count === $threshold) {
            $this->log->warning('pager token resolution failures crossed the alert threshold', [
                'window' => $now->format('YmdH'),
                'failures' => $count,
            ]);
        }
    }

    /** What this window has seen. For an operator and for the tests that pin this. */
    public function count(DateTimeImmutable $now): int
    {
        try {
            /** @var mixed $value */
            $value = $this->redis->connection()->get(self::keyFor($now));
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
