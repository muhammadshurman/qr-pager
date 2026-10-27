<?php

declare(strict_types=1);

namespace App\Domain\Pager;

/**
 * What goes on the outbox, and the dedupe key that makes it idempotent.
 *
 * ONE SOURCE FOR BOTH, because they are one decision rather than two. `outbox.type` is
 * what Task 4's dispatcher switches on, and `outbox.dedupe_key` is what
 * `UNIQUE (tenant_id, dedupe_key)` enforces -- and §2.5 records that index as "the
 * mechanism for Task 3's idempotent markReady: one row per logical event, enforced by the
 * database under true concurrency, not by a read-then-write check". A type whose key shape
 * lived somewhere else could be given a key that collides with another type's, which is
 * the same bug as having no key at all.
 *
 * NOT in DatabaseEnums::map(). `outbox.type` is varchar(64), not a Postgres enum type
 * (§2.5), so there is no pg_enum to compare against and EnumContractTest correctly does
 * not police it. This is the most that can be done for a string column: one producer in
 * PHP.
 *
 * Which transitions produce an event is deliberate and is not "all of them":
 *
 *   - Ready and Renotified are the notification itself -- the buzz.
 *   - Cancelled notifies because a customer waiting for a pager that will never buzz is
 *     the one state worse than being told.
 *   - Completed does NOT: the customer is at the counter collecting, so they already
 *     know, and a push telling them what they are doing is noise.
 *   - Expired does NOT, and that one is settled by the schema rather than by taste: §2.7
 *     gives qrpager_worker no INSERT grant on outbox at all, so the expiry job could not
 *     write one if it wanted to. Recorded here so nobody adds it and then debugs 42501.
 *
 * In App\Domain: no framework, no database, which deptrac enforces.
 */
enum PagerEvent: string
{
    case Ready = 'pager.ready';
    case Renotify = 'pager.renotify';
    case Cancelled = 'pager.cancelled';

    /**
     * The dedupe key for one logical occurrence of this event on one session.
     *
     * Ready and Cancelled happen at most once per session, so their key carries the
     * session alone and a retry collides with itself -- which is exactly what is wanted:
     * the unique index is the backstop behind the row lock.
     *
     * Renotify is DIFFERENT and the attempt number is in the key on purpose. A renotify
     * IS a new notification, so two of them must produce two outbox rows; a key without
     * the count would make the second one a 23505 and silently swallow a buzz the staff
     * asked for. The count comes from the row's own `renotify_count` read under
     * `FOR UPDATE`, so two concurrent renotifies cannot compute the same one.
     *
     * Well within varchar(160): the longest is `session:` + 36 + `:renotify:` + a
     * smallint, 60 characters at most.
     */
    public function dedupeKey(string $sessionId, ?int $attempt = null): string
    {
        $key = 'session:'.$sessionId.':'.$this->suffix();

        return $attempt === null ? $key : $key.':'.$attempt;
    }

    /** True when dedupeKey() needs an attempt number to be unique. */
    public function isRepeatable(): bool
    {
        return $this === self::Renotify;
    }

    private function suffix(): string
    {
        return match ($this) {
            self::Ready => 'ready',
            self::Renotify => 'renotify',
            self::Cancelled => 'cancelled',
        };
    }
}
