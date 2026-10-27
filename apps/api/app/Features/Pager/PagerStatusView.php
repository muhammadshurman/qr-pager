<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;

/**
 * What a waiting customer is told, and the only thing the Redis projection holds.
 *
 * THE STATUS VOCABULARY IS PagerStatus's AND NOTHING ELSE'S. The projection stores
 * $status->value and reads it back through PagerStatus::from(), so there is no second
 * copy of the state machine for the cache to drift from -- which is QRPAGER-11 §4.2's
 * clause in so many words. A stored value this enum cannot map is treated as a corrupt
 * projection and answered as a MISS, never as a new status: decode() returns null, the
 * caller answers Stale, and the client resolves again against Postgres.
 *
 * WHY THERE IS NO ready_at, which looks like an omission and is a decision. Every
 * writer of this view has to be able to supply every field without an extra statement,
 * or the projection means different things depending on who last wrote it. The service
 * learns a transition's own timestamp only by re-reading the row -- this project keeps
 * NO `RETURNING` anywhere (§4 › Postgres traps) -- so a ready_at here would be null
 * after markReady and populated after the worker's pass, which is a field that lies for
 * one worker interval. `asOf` is the honest timestamp instead: the moment the server
 * wrote this view. A client that wants the authoritative ready_at calls the resolve
 * endpoint, which reads Postgres and has it.
 *
 * WHAT IT DELIBERATELY DOES NOT CARRY: no tenant id, no session id, no restaurant name,
 * no token and no hash. The poll response is built from this, so anything in here is
 * something an attacker who has stolen a token can read -- and the holder of a 128-bit
 * token is the customer (Task 3 §5.4), so the bar is "what the customer needs", not
 * "what we happen to have". The tenant id in particular stays server-side: it is in the
 * KEY, which the client never sees.
 *
 * `order_reference` is here because the Order ready screen must show it (§4.1) and
 * because a customer with two orders needs to tell the notifications apart.
 */
final class PagerStatusView
{
    private const VERSION = 1;

    public function __construct(
        public readonly PagerStatus $status,
        public readonly string $orderReference,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $asOf,
    ) {}

    /**
     * The stored form. Versioned, because this is a cache that outlives a deploy.
     *
     * A rolling deploy has both versions of this class running against one Redis for as
     * long as the roll takes, so a shape change has to be survivable rather than
     * coordinated: decode() refuses a version it does not know and the caller answers
     * Stale, which costs one resolve per waiting customer and never a wrong answer.
     * Without the field the only options are "guess" and "fatal".
     */
    public function encode(): string
    {
        return json_encode([
            'v' => self::VERSION,
            's' => $this->status->value,
            'o' => $this->orderReference,
            'x' => $this->expiresAt->format(DateTimeInterface::RFC3339),
            'a' => $this->asOf->format(DateTimeInterface::RFC3339),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Null for anything this version cannot read, which the caller treats as a miss.
     *
     * Every failure mode lands on the same null: malformed JSON, a version from the
     * future, a missing member, a status this build does not have. None of them is an
     * exception, because the one thing a Redis-only endpoint must not do is turn a
     * cache problem into a 500 on a screen a customer is staring at while they wait.
     */
    public static function decode(string $payload): ?self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $status = is_string($decoded['s'] ?? null) ? PagerStatus::tryFrom($decoded['s']) : null;
        $reference = $decoded['o'] ?? null;
        $expires = self::time($decoded['x'] ?? null);
        $asOf = self::time($decoded['a'] ?? null);

        if ($status === null || ! is_string($reference) || $expires === null || $asOf === null) {
            return null;
        }

        return new self($status, $reference, $expires, $asOf);
    }

    /**
     * The wire shape of a poll response.
     *
     * `poll_after_seconds` is the SERVER half of §4 › Realtime's "client-side jittered
     * interval + exponential backoff": the interval is the server's to advertise and the
     * jitter is the client's to apply, because jitter applied here would be identical
     * for every device that happened to poll in the same second. The client is Task 9's
     * and this is what it reads.
     *
     * @return array{status: string, order_reference: string, expires_at: string, as_of: string, poll_after_seconds: int}
     */
    public function toArray(int $pollAfterSeconds): array
    {
        return [
            'status' => $this->status->value,
            'order_reference' => $this->orderReference,
            'expires_at' => $this->expiresAt->format(DateTimeInterface::RFC3339),
            'as_of' => $this->asOf->format(DateTimeInterface::RFC3339),
            'poll_after_seconds' => $pollAfterSeconds,
        ];
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);

        return $parsed === false ? null : $parsed;
    }
}
