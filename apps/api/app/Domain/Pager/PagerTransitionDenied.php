<?php

declare(strict_types=1);

namespace App\Domain\Pager;

use DomainException;

/**
 * A transition PagerStatus::allows() does not permit.
 *
 * IN App\Domain, and that placement is load-bearing rather than tidy. ProblemRenderer
 * maps this to a 409 so an illegal transition is a Conflict and not a 500, and
 * ProblemRenderer is in App\Platform -- which deptrac forbids from importing
 * App\Features. A feature-layer exception could not be mapped there at all. Domain is the
 * one layer Platform may reach, so this is the only place it can live if the renderer is
 * to know about it.
 *
 * THE MESSAGE NAMES ONLY STATUS VALUES. They are our own enum's cases, not driver text,
 * not a constraint name and not anything the caller sent, so it is safe for the log --
 * but it still never reaches a response body: ProblemType::Conflict's title is a fixed
 * phrase, which is the whole point of the envelope (§4 › Edge & ops, as amended for
 * response bodies on 2026-10-26).
 */
final class PagerTransitionDenied extends DomainException
{
    private function __construct(
        string $message,
        public readonly PagerStatus $from,
        public readonly ?PagerStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function between(PagerStatus $from, PagerStatus $to): self
    {
        $allowed = array_map(static fn (PagerStatus $s): string => $s->value, $from->allows());

        return new self(
            "A pager session in [{$from->value}] cannot move to [{$to->value}]. "
            .($allowed === []
                ? "[{$from->value}] is terminal, so the session accepts no further transition."
                : 'Allowed from here: ['.implode(', ', $allowed).'].'),
            $from,
            $to,
        );
    }

    /**
     * A renotify refused because the session is not `ready`.
     *
     * Its own constructor because renotify changes no status -- there is no `to` -- so
     * between() would have to invent one to describe it.
     */
    public static function cannotRenotify(PagerStatus $from): self
    {
        return new self(
            "A pager session in [{$from->value}] cannot be renotified. "
            .'Only a session that has been marked ready has a notification to repeat.',
            $from,
            null,
        );
    }

    /**
     * A re-issue refused because the order is already finished. Task 10d.
     *
     * Its own constructor for `cannotRenotify()`'s reason: a re-issue changes no status, so
     * there is no `to` and `between()` would have to invent one to describe it. The message
     * names the one thing a staff member needs to know — a new code for a finished order would
     * buzz nobody — and the SCREEN's own sentence (`staff.issue.reissue.finished`) says it in
     * a restaurant owner's words, because this text never reaches a response body.
     */
    public static function cannotReissue(PagerStatus $from): self
    {
        return new self(
            "A pager session in [{$from->value}] cannot be re-issued. "
            .'A new code for an order that is already finished, cancelled or expired would '
            .'notify nobody; issue a new pager instead.',
            $from,
            null,
        );
    }

    /** A renotify refused because the per-session cap is reached. */
    public static function renotifyLimitReached(PagerStatus $from, int $limit): self
    {
        return new self(
            "This pager session has already been renotified {$limit} times, which is the "
            .'configured limit. Each renotify is a push notification to a customer device '
            .'and an unbounded writer into the outbox.',
            $from,
            null,
        );
    }
}
