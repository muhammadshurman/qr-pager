<?php

declare(strict_types=1);

namespace App\Domain\Pager;

/**
 * What one attempt to reach one device came to, and what the worker owes it.
 *
 * THE DISPATCHER'S VOCABULARY, NOT THE DATABASE'S, which is why it is deliberately not
 * a Postgres enum and deliberately not in DatabaseEnums::map(). `outbox.status` already
 * says what became of the ROW -- pending, sent, dead -- and that is the enum
 * EnumContractTest polices. This says what became of one SEND, and several sends can
 * sit behind one row because a session can have several devices. The two are different
 * questions and a single enum answering both would have to lie about one of them.
 *
 * It is the same call PagerEvent made for `outbox.type`, for the same reason, and
 * EnumContractTest correctly does not police either.
 *
 * FOUR CASES, AND THE SPLIT IS WHAT MATTERS. Gone is not a failure -- it is the push
 * service telling us the subscription is finished, which is information we asked for
 * and must act on. Retry and Permanent differ in whether another attempt could ever
 * work: a 500 from an edge is transient, a 400 from one means we built the request
 * wrong and sending it again is a loop.
 *
 * In App\Domain: no framework, no database, which deptrac enforces.
 */
enum PagerDelivery
{
    /** The push service accepted it. Nothing more is owed. */
    case Delivered;

    /**
     * 404 or 410: this subscription is finished and must be deleted.
     *
     * The two codes are not guessed here -- the dispatcher reads them from the library's
     * own MessageSentReport::isSubscriptionExpired(), which was measured on 2026-10-27
     * to be true for exactly 404 and 410 and false for 500, 429 and 413. That keeps the
     * pruning clause reading the library's definition rather than a second copy of it.
     */
    case Gone;

    /** Transient: back off and try this row again. A timeout lands here. */
    case Retry;

    /** Another attempt cannot work. The row dead-letters now rather than after six tries. */
    case Permanent;

    /** True when the row should be tried again rather than finished either way. */
    public function isRetryable(): bool
    {
        return $this === self::Retry;
    }
}
