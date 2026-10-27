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
    | The pass is a policy-limited keyed read -- `select id, tenant_id, status` -- then
    | one keyed UPDATE per row, per §4 › Postgres traps. (It said "`SELECT id`" until
    | 2026-10-27; the clause was amended to the shape the mechanism needs and this
    | sentence was left behind, which is R-069. The three columns are each load-bearing:
    | the keyed UPDATE needs the status it expects to find, and the audit row needs a
    | tenant to open a scope for.) This bounds the SELECT so one pass cannot hold a transaction
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

    /*
    |--------------------------------------------------------------------------
    | How long the Redis status projection lives
    |--------------------------------------------------------------------------
    |
    | A CEILING, not the value. PagerProjection writes
    | min(remaining session life, this), floored at one second -- so a session's own
    | expires_at always wins and A PROJECTION CANNOT OUTLIVE THE SESSION IT DESCRIBES.
    | That is what makes the polling endpoint's miss honest: a token stops resolving in
    | Redis at the same moment it stops being valid, and the client is pushed back to the
    | one path that can tell "expired" from "invalid".
    |
    | Two hours matches the session TTL, so in practice this ceiling only binds if the
    | TTL is ever raised -- which is exactly when a forgotten cache TTL would start
    | serving stale state. Set here so that raising one without the other is a visible
    | decision rather than a silent divergence.
    |
    */

    'projection_ttl' => 7200,

    /*
    |--------------------------------------------------------------------------
    | How often a waiting client should poll
    |--------------------------------------------------------------------------
    |
    | Advertised in every poll response as `poll_after_seconds`. The SERVER half of
    | §4 › Realtime's "client-side jittered interval + exponential backoff": the interval
    | is ours to publish and the jitter is the client's to apply, because jitter applied
    | here would be identical for every device that polled in the same second.
    |
    | Seven seconds is the figure §6.5's budget is computed against: at the C6 design
    | ceiling of 300 000 concurrent sessions that is ~42 900 polls per second, which is
    | what the two-Redis-reads-and-no-Postgres shape has to survive. Task 14 sets the real
    | number from load results (C6); moving it here moves the budget, which is why the
    | arithmetic is written down beside it.
    |
    */

    'poll_interval' => 7,

    /*
    |--------------------------------------------------------------------------
    | When a run of failed token resolutions is worth a log line
    |--------------------------------------------------------------------------
    |
    | R-063: failed resolutions are counted so an enumeration is observable. The counter
    | is unconditional; this is the point in one UTC hour at which it also says so once.
    |
    | ONCE, not once per request, and that is R-046 -- unthrottled per-request warning
    | logs on a high-volume failure path are themselves a defect, because an attacker's
    | loop then costs us a log line per attempt. TokenResolutionFailures fires on the
    | attempt that EQUALS this number and on no other.
    |
    | 50 in an hour is far above a customer mistyping or re-scanning a worn code, and far
    | below what a loop produces in a second.
    |
    */

    'token_failure_alert' => 50,

];
