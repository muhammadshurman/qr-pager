<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where a scanned QR code lands
    |--------------------------------------------------------------------------
    |
    | The base URL the QR payload is built from. The token goes in the FRAGMENT of this
    | URL and never in its path or query (PagerToken::qrPayload explains why both the
    | logging clause and the CDN clause land on that one choice), so this value is the
    | part that is public, cacheable and identical for every scan.
    |
    | App-level for now, and per-TENANT later: custom domains with automatic TLS are
    | Task 7 (S2), and when they exist the landing host becomes a tenant attribute and
    | this becomes the fallback. Recorded here rather than discovered then.
    |
    */

    'landing_url' => env('PAGER_LANDING_URL', 'https://localhost/p'),

    /*
    |--------------------------------------------------------------------------
    | How many times one session may be renotified
    |--------------------------------------------------------------------------
    |
    | A BOUND, not a preference. Every renotify is a row in `outbox` -- the one table
    | §2.11 already calls "unbounded, and the one to watch", because no grant in §2.7
    | deletes a sent row -- and a push notification to a customer's phone. Without a cap,
    | a staff member holding the button is an unbounded writer into that table and an
    | unbounded notifier of one device, and §6.5 of this task's ticket has to answer
    | "what is bounded?" with something.
    |
    | Refused as a 409 Conflict, not a 422: the request is well-formed and the state is
    | what makes it impossible, which is the same answer an illegal transition gets.
    |
    | A starting point, like config/ratelimit.php's and config/shedding.php's numbers,
    | and for the same reason: Task 14 sets it from load and product results (C6).
    |
    | Deliberately NOT a database CHECK constraint. That would be stronger and it would
    | be a data-contract change to a shipped table (§2.5 has `renotify_count smallint
    | default 0 CHECK >= 0`) for a number Task 14 will re-measure. An application rule
    | with a test is the reversible form; the trade is in the ticket's out-of-scope table.
    |
    */

    'renotify_limit' => 5,

    /*
    |--------------------------------------------------------------------------
    | How many sessions one expiry pass may claim
    |--------------------------------------------------------------------------
    |
    | The pass is `SELECT id` (policy-limited) then one keyed UPDATE per row, per
    | §4 › Postgres traps. This bounds the SELECT so one pass cannot hold a transaction
    | open across an unbounded row count -- which matters more here than it looks,
    | because the worker role carries idle_in_transaction_session_timeout = 60s (§2.1)
    | and a pass that outran it would be killed mid-pass rather than finishing.
    |
    | Nothing is lost when a pass stops early: the rows it did commit are expired, the
    | rest keep their status, and the next pass finds them. That is the same
    | affected-row-count property that makes the job re-runnable.
    |
    */

    'expiry_batch' => 500,

];
