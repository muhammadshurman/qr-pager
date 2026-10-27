<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;

/**
 * A problem document from the polling endpoint: the four usual members, plus the pacing.
 *
 * WHY A THIRD CLASS AND NOT AN OPTIONAL MEMBER ON ProblemDocument -- the same forced
 * reason ValidationProblemDocument exists, recorded in Problem::document(): Scramble marks
 * every property of a documented class `required`, measured both as a promoted constructor
 * parameter with a default and as a plain property with an initialiser. One class carrying
 * an optional `poll_after_seconds` would publish it as required on every 404 and 409 in
 * the API, most of which never carry it. Three honest schemas beat one that is wrong
 * everywhere but here.
 *
 * `poll_after_seconds` AND NOT `pollAfterSeconds`: the property name is the wire name,
 * because Scramble publishes the property and Problem::toArray() serialises it. It matches
 * the key the 200 response has carried since this endpoint was built, which is the whole
 * point of the member -- a client reads one name whatever the status.
 *
 * WHY A 404 CARRIES PACING AT ALL. `PLAN.md §3` row 4: "every polling response carries
 * `poll_after_seconds`". The 404 is `Stale` -- "this server no longer holds a live status,
 * open the session again" -- and a client that just got one is the client that most needs
 * telling when to come back; it is the single most likely answer during a Redis restart,
 * when every waiting device in every restaurant is about to ask again. QA finding M-6,
 * 2026-10-27, ruled on by Muhammad the same day: CARRY THE VALUE, DO NOT NARROW THE GATE.
 * Amending a §3 clause to match the code is the defect Task 3's security S-1 already
 * named, and it was not going to be this project's second.
 */
final class PacedProblemDocument extends ProblemDocument
{
    public function __construct(
        ProblemType $type,
        string $title,
        int $status,
        ?string $instance,
        public readonly int $poll_after_seconds,
    ) {
        parent::__construct($type, $title, $status, $instance);
    }
}
