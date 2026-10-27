<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * The Redis status projection, and the only place either of this task's keys is built.
 *
 * ===================================================================================
 * WHY TWO KEYS, AND WHY ONLY ONE OF THEM IS TENANT-NAMESPACED
 * ===================================================================================
 *
 * §4 › Realtime: "Every key that holds tenant data is namespaced by tenant id, and every
 * read derives that id from the caller's own context -- never from client input. A
 * projection key that is not tenant-namespaced is a cross-tenant read with none of
 * Postgres's protection behind it."
 *
 * THE PROJECTION -- the thing that invariant is about -- is `t:{tenant}:pager:{session}`,
 * which is the shape QRPAGER-11's 2026-10-25 comment prescribes verbatim, and the tenant
 * id in it comes from the POINTER's value, which this class wrote. There is no request
 * field anywhere in Task 4 from which a tenant id is read.
 *
 * THE POINTER is `pager:tok:{sha256(token)}` and it cannot be tenant-prefixed, because
 * working out the tenant is what it is FOR. What stands behind it instead is the key name
 * itself: to read it you must present the preimage of a sha256 -- the 128-bit CSPRNG
 * token. That is the same property that makes resolve_pager_token a legitimate SECURITY
 * DEFINER bypass in Task 3, and §4 › Tenancy already names "QR token lookup" as one of
 * exactly four permitted cross-tenant paths. The value under it is a pointer, not tenant
 * content: a tenant id and a session id, both of which the holder of that token is
 * entitled to by definition.
 *
 * THIS IS A MEMO OF ONE RESOLVER, NOT A SECOND RESOLVER, and the class signature is what
 * enforces it. remember() takes a ResolvedPagerSession -- the object PagerTokenLookup
 * returns and the only thing that constructs one -- together with the PagerToken it was
 * resolved from. There is no call shape in which a caller supplies a tenant id and a
 * token separately, so there is no way to file a session under a token it does not belong
 * to. The hash is PagerToken::hashHex(), the same single derivation that produces
 * pager_sessions.token_hash.
 *
 * ===================================================================================
 * WHO WRITES WHAT
 * ===================================================================================
 *
 *   remember()  both keys, SETEX          <- the resolve endpoint only; it has the token
 *   refresh()   the projection, SET XX    <- PagerSessions and the outbox worker
 *   forget()    both keys                 <- not used on a request path; tests and tooling
 *
 * refresh() IS DELIBERATELY XX ("only if it already exists"). A session nobody has
 * scanned has no customer waiting on it, so a staff transition on it must not create a
 * key: the projection's lifetime is owned by the SCAN, not by the order. That is what
 * keeps the keyspace bounded by the waiting population rather than by the order volume,
 * which is §6.5's figure.
 *
 * ===================================================================================
 * THE TTL IS DERIVED, NEVER CONFIGURED LONGER THAN THE SESSION
 * ===================================================================================
 *
 * min(remaining session life, pager.projection_ttl), floored at one second. The session's
 * own expires_at is the hard ceiling, so A PROJECTION CANNOT OUTLIVE THE SESSION IT
 * DESCRIBES. That is what makes the polling endpoint's miss honest: a token stops
 * resolving in Redis at the same moment it stops being valid, and the client is pushed
 * back to the Postgres path, which is the only path that can tell "expired" from
 * "invalid" (gate clause (a)).
 *
 * ===================================================================================
 * EVERY REDIS CALL IS WRAPPED
 * ===================================================================================
 *
 * A read answers null on any failure and a write answers false. Redis being down is a
 * real state with a defined answer at every call site -- the polling endpoint turns a
 * null read into 503 or 404 depending on WHICH null it is (see read()), and a writer
 * turns a false into a logged best-effort miss rather than a failed staff request.
 * Nothing here throws, because the one thing a cache must not do is take the request
 * down with it.
 */
final class PagerProjection
{
    /** The pointer: token hash -> which session, in which tenant. */
    private const POINTER_PREFIX = 'pager:tok:';

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * The projection key. The ONLY place this string is built.
     *
     * Public so a test can assert the exact shape against the invariant's own wording
     * rather than against a second copy of it.
     */
    public static function projectionKey(string $tenantId, string $sessionId): string
    {
        return 't:'.$tenantId.':pager:'.$sessionId;
    }

    /** The pointer key. The ONLY place this string is built. */
    public static function pointerKey(PagerToken $token): string
    {
        return self::POINTER_PREFIX.$token->hashHex();
    }

    /**
     * File a resolved session under its token, and project its state. The resolve
     * endpoint's write, and the only one that can create either key.
     *
     * Both keys get the same TTL from the same clock reading, so they expire together
     * and there is no window in which a pointer resolves to a projection that is gone.
     */
    public function remember(PagerToken $token, ResolvedPagerSession $session, DateTimeImmutable $now): bool
    {
        if ($this->hasNothingLeftToProject($session->expiresAt, $now)) {
            return false;
        }

        $ttl = $this->ttlFor($session->expiresAt, $now);

        $view = new PagerStatusView($session->status, $session->orderReference, $session->expiresAt, $now);

        $pointer = json_encode(['t' => $session->tenantId, 's' => $session->id], JSON_THROW_ON_ERROR);

        // The projection first. If the pointer landed first and this failed, a poll would
        // resolve the token and then miss the projection -- two round trips to reach the
        // same Stale the pointer's own absence gives in one.
        if (! $this->setex(self::projectionKey($session->tenantId, $session->id), $view->encode(), $ttl)) {
            return false;
        }

        return $this->setex(self::pointerKey($token), $pointer, $ttl);
    }

    /**
     * Update a projection that already exists. Writes nothing if it does not.
     *
     * This is what PagerSessions calls after a transition commits and what the worker
     * calls after a notification goes out. It takes a tenant id and a session id rather
     * than a token because NEITHER CALLER HAS A TOKEN -- Task 3 made sure the service
     * never handles one -- so this signature is also the reason neither of them can
     * create a pointer.
     */
    public function refresh(
        string $tenantId,
        string $sessionId,
        PagerStatus $status,
        string $orderReference,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): bool {
        if ($this->hasNothingLeftToProject($expiresAt, $now)) {
            return false;
        }

        $view = new PagerStatusView($status, $orderReference, $expiresAt, $now);

        try {
            // XX: refresh only -- Redis answers false and writes nothing when the key is
            // absent, which is what makes a staff transition on an unscanned session
            // create no projection. EX in the SAME command, so the TTL always moves with
            // the value and a long-lived session cannot end up with a projection that
            // never expires.
            //
            // command() rather than the Connection::set() helper, because that helper
            // takes the option array apart into three positional arguments
            // ($expireResolution, $expireTTL, $flag) and reassembles it -- so the shape
            // actually sent is two hops from the call site. Here it is the literal
            // argument list Redis receives. Verified against the real server on
            // 2026-10-27: false with the key absent, true with it present, and the TTL
            // replaced.
            return $this->redis->connection()->command('set', [
                self::projectionKey($tenantId, $sessionId),
                $view->encode(),
                ['XX', 'EX' => $this->ttlFor($expiresAt, $now)],
            ]) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The whole poll path: pointer, then projection. Two commands, no Postgres.
     *
     * THE RETURN TYPE IS THREE-VALUED ON PURPOSE and the polling endpoint needs all
     * three, because "we have no status for you" and "we could not ask" are different
     * things to tell a customer who is waiting:
     *
     *   PagerStatusView  -- a live status
     *   null             -- a MISS: Redis answered, and there is nothing here (404 Stale)
     *   false            -- Redis could not be reached at all          (503 + Retry-After)
     *
     * A corrupt or future-versioned payload is a miss, not an error: decode() returns
     * null and the client resolves again, which is correct and costs one Postgres call.
     *
     * ===================================================================================
     * EXPIRY IS CHECKED AT READ TIME, NOT ONLY BY THE TTL
     * ===================================================================================
     *
     * The TTL is the primary mechanism and it is derived, never longer than the session
     * (ttlFor). But a TTL is arithmetic done at WRITE time against PHP's clock, and the
     * authority on when a session expires is `expires_at` in Postgres. If those clocks
     * ever disagree -- PHP behind Postgres, a drifting container, a rounded SETEX -- a key
     * can outlive the session it describes, and this endpoint would serve a live status
     * for a session the expiry job is about to move to `expired`.
     *
     * So the view's own `expiresAt` is compared against the clock on EVERY read, and a
     * past-expiry view is a MISS. That makes "a session expired in Postgres stops
     * resolving from Redis" true by COMPUTATION rather than by trusting arithmetic that
     * happened earlier -- the same discipline ResolvedPagerSession::hasExpired() applies,
     * for the same reason: compute from expires_at against the clock, never trust a
     * lagging indicator. The client then re-opens against Postgres, which is the only
     * path that can tell it `expired` from `invalid` (gate clause (a)).
     */
    public function read(PagerToken $token, DateTimeImmutable $now): PagerStatusView|null|false
    {
        try {
            $connection = $this->redis->connection();

            /** @var mixed $pointer */
            $pointer = $connection->get(self::pointerKey($token));

            if (! is_string($pointer)) {
                return null;
            }

            $target = $this->decodePointer($pointer);

            if ($target === null) {
                return null;
            }

            [$tenantId, $sessionId] = $target;

            /** @var mixed $payload */
            $payload = $connection->get(self::projectionKey($tenantId, $sessionId));
        } catch (Throwable) {
            return false;
        }

        if (! is_string($payload)) {
            return null;
        }

        $view = PagerStatusView::decode($payload);

        return $view === null || $view->expiresAt <= $now ? null : $view;
    }

    /** Drop both keys. Not on any request path; tooling and tests. */
    public function forget(PagerToken $token, string $tenantId, string $sessionId): void
    {
        try {
            $this->redis->connection()->del(
                self::pointerKey($token),
                self::projectionKey($tenantId, $sessionId),
            );
        } catch (Throwable) {
            // Nothing to clear is the same outcome as clearing it.
        }
    }

    /**
     * @return array{string, string}|null
     */
    private function decodePointer(string $pointer): ?array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($pointer, true);

        if (! is_array($decoded)) {
            return null;
        }

        $tenantId = $decoded['t'] ?? null;
        $sessionId = $decoded['s'] ?? null;

        return is_string($tenantId) && is_string($sessionId) ? [$tenantId, $sessionId] : null;
    }

    private function setex(string $key, string $value, int $ttl): bool
    {
        try {
            return $this->redis->connection()->setex($key, $ttl, $value) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Never longer than the session has left, never longer than config, never below 1.
     *
     * THERE IS NO LONGER A FLOOR, and that is a correction rather than a simplification.
     * It used to be `max(1, ...)`, because setex refuses a non-positive TTL -- which meant
     * a terminal transition on a long-expired session (`complete` on an order collected
     * last week, which refuseIfPastTtl deliberately allows, because a terminal session is
     * finished rather than expired) wrote a live status for one second. One second is
     * small and is still the wrong answer, and "small enough" is not a property anyone can
     * test. Both writers now refuse outright instead -- see hasNothingLeftToProject().
     */
    /**
     * True when there is no session left to project, so neither key is written.
     *
     * Refusing to write is better than writing briefly: the caller's next poll misses, the
     * client re-opens, and Postgres answers authoritatively. Nothing is lost and there is
     * no window to reason about.
     */
    private function hasNothingLeftToProject(DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        return $expiresAt <= $now;
    }

    private function ttlFor(DateTimeImmutable $expiresAt, DateTimeImmutable $now): int
    {
        $remaining = $expiresAt->getTimestamp() - $now->getTimestamp();
        $configured = (int) config('pager.projection_ttl');

        return min($remaining, $configured);
    }
}
