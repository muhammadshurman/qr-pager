<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID
    |--------------------------------------------------------------------------
    |
    | The application server's identity to a push service (RFC 8292). The private
    | key signs the JWT every send carries; the public key is what a browser pins
    | the subscription to, so rotating the pair invalidates every subscription in
    | the database and is a migration, not a config change.
    |
    | NO VALUE HERE AND NONE IN .env.example, not even a test one. The names are
    | in .env.example with empty values and nothing else, and every test generates
    | its own pair at runtime with VAPID::createVapidKeys() -- which is why no
    | fixture key is needed and why gitleaks has nothing to find. A committed
    | "test" key is a committed key: it is indistinguishable from a real one in a
    | diff, and the next person to need a working local setup copies it.
    |
    | 'subject' is a mailto: or https: URI a push service can use to contact the
    | operator about an abusive sender. It is operational contact detail, not a
    | secret, and it is the one of the three that belongs in a deployment's
    | configuration rather than in its secret store.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', ''),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | What one send looks like on the wire
    |--------------------------------------------------------------------------
    |
    | 'ttl' is how long the push service holds an undelivered message for a device
    | that is offline. A pager notification is worthless once the customer has
    | walked away, so it is deliberately short -- far shorter than the library's
    | own default of four weeks. Measured against the product: a session's own TTL
    | is two hours, so holding a message longer than that would deliver a buzz for
    | an order that has already expired.
    |
    | 'urgency' is 'high' because this IS the alert. RFC 8030 §5.3 lets a push
    | service delay a low-urgency message to save the device's battery, which is
    | precisely the wrong trade for the one notification this product exists to
    | deliver.
    |
    | 'content_encoding' is aes128gcm (RFC 8291) and is stated here rather than
    | taken from the library. Measured 2026-10-27: Subscription::create() defaults
    | to 'aesgcm', which the library's own docblock calls "Not recommended.
    | Outdated historic encoding." A default taken on trust would have shipped the
    | legacy encoding to every device.
    |
    */

    'ttl' => 1800,
    'urgency' => 'high',
    'content_encoding' => 'aes128gcm',

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | A push edge that hangs must cost the worker a bounded amount of time, not a
    | pass. Both are seconds and both are passed to the PSR-18 client the
    | dispatcher is constructed with -- the library itself has no timeout setting,
    | which is why these live here and are applied where the client is built.
    |
    | The worker role carries idle_in_transaction_session_timeout = 60s (§2.1), and
    | the send deliberately happens with NO transaction open, so a slow edge cannot
    | reach that limit. These bound the pass's wall time instead.
    |
    */

    'timeout' => [
        'connect' => 3,
        'request' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | The outbox worker
    |--------------------------------------------------------------------------
    |
    | Starting points, not measurements -- Task 14 sets them from load results,
    | which is the same note config/ratelimit.php and config/shedding.php carry
    | (C6).
    |
    | 'batch_size' bounds one claim. The claim transaction is short by construction
    | (a SELECT and one keyed UPDATE per row, then COMMIT -- no HTTP inside it), so
    | this bounds how many rows one worker takes from its peers rather than how
    | long a transaction is held.
    |
    | 'lease_seconds' is how long a claimed row is invisible to another worker. It
    | has to exceed the worst case of ONE ROW'S processing -- a tenant scope, two
    | reads, and up to 'max_devices_per_session' sends at ('connect' + 'request')
    | seconds each -- or two workers could hold the same row. It also sets how long a
    | killed worker's rows wait before another pass picks them up, which is the
    | "nothing lost" property's recovery time, so it is a trade between that and
    | double-claim safety.
    |
    | ⚠ THAT SENTENCE WAS THE RIGHT BOUND FOR THE WRONG STATEMENT UNTIL 2026-10-27
    | (R-083). It said "one row's processing" while the lease was taken for the WHOLE
    | BATCH at claim time and the rows were then processed one at a time, so the bound
    | the sentence stated was never the bound the code had: the real precondition was
    | batch_size x one row's worst case, which at 100 x 11 s against a 60 s lease was
    | false by two orders of magnitude. The sentence is now true because the CODE
    | changed to match it -- OutboxWorker::reLease() re-takes the lease on each row
    | immediately before that row's send -- and not because the sentence was softened
    | to match the code, which is the defect this project named once already and did
    | not want twice.
    |
    | THE ARITHMETIC, so that moving any of the four numbers shows its own cost:
    |
    |     one row's worst case = max_devices_per_session x (connect + request)
    |                          = 5 x (3 + 8) = 55 s   <   lease_seconds = 60 s
    |
    | Raise 'max_devices_per_session' or either timeout without raising
    | 'lease_seconds' and a single row can outlive its own lease again -- which the
    | re-lease turns into a SKIPPED row rather than a duplicate send, so the failure
    | mode is a delayed notification instead of a double buzz. That is the degradation
    | this shape buys.
    |
    | 'max_attempts' is where a row stops being retried and becomes 'dead'. The
    | backoff below is exponential, so attempt 6 is already ~32 x base away; past
    | that a push edge is not transiently unwell, it is gone.
    |
    | 'backoff' is base * 2^attempts, capped, with jitter applied as a fraction of
    | the computed delay. The jitter is on the SERVER here, and that is not the
    | client-side jitter §4 › Realtime asks of a polling client: it is the retry
    | schedule's own. Without it, every row that failed during one edge outage
    | retries on the same tick, which is the outage's second wave.
    |
    */

    'worker' => [
        'batch_size' => 100,
        'lease_seconds' => 60,
        /*
         | HOW MANY DEVICES ONE SESSION MAY BE BUZZED ON -- a SAFETY BOUND, not the
         | product rule, and R-091 carries the difference with an owner.
         |
         | The second half of R-083's ruling. Nothing capped this before 2026-10-27:
         | PushSubscriptions::forSession() returned every row and the worker sent to all
         | of them sequentially, so one token holder could register devices until a
         | single row's processing exceeded its own lease -- the same duplicate-send
         | window as the batch-wide lease, reached from the device side, which is how
         | the Security review found it while QA found the other.
         |
         | 5 IS CHOSEN FROM THE LEASE AND THE TIMEOUTS, not from a guess about phones:
         | 5 x (3 + 8) = 55 s fits inside the 60 s lease with margin, and it is above
         | any plausible real session -- a customer with a phone, a tablet and a watch
         | is three, and the suite's own largest case is three. Task 14 sets the real
         | figure from load results and the product rule is Muhammad's (R-091), which
         | is also where "which devices are kept when the cap bites" is decided: the
         | query orders by created_at, so today it is the OLDEST, and that is a
         | consequence of a pre-existing ORDER BY rather than a decision anybody made.
         */
        'max_devices_per_session' => 5,
        'max_attempts' => 6,
        'backoff' => [
            'base_seconds' => 5,
            'cap_seconds' => 600,
            'jitter_fraction' => 0.2,
        ],
        'sleep_seconds' => 1,
    ],

];
