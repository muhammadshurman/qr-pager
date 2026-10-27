<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Per-IP and per-tenant request limits
    |--------------------------------------------------------------------------
    |
    | Both, because they answer different questions. The per-IP limit is what stops one
    | client flooding the service; the per-TENANT limit is what stops one tenant's
    | traffic -- however many clients it comes from -- consuming the capacity of every
    | other tenant on the task (§4 › Realtime, D5). A limiter keyed only by IP is no
    | protection at all behind a NAT or a mobile carrier.
    |
    | Starting points, not measurements: Task 14 sets them from load results, which is
    | the same note config/shedding.php carries and for the same reason (C6).
    |
    */

    'buckets' => [

        'api' => [
            'per_ip' => ['attempts' => 120, 'seconds' => 60],
            'per_tenant' => ['attempts' => 600, 'seconds' => 60],
        ],

        /*
        | Creating a pager session is the one route in Task 3 that takes a body and the
        | one that does real work per call -- a CSPRNG draw, an insert and an audit row --
        | so it carries its own limit on top of the api group's. Sized well above a busy
        | counter's real rate (a session per order) and well below what a script could
        | use to fill `outbox` and `audit_log`, neither of which any grant in §2.7
        | deletes. Per tenant as well as per IP, because the per-IP half is no protection
        | at all when a tenant's staff are behind one NAT.
        |
        | The four transition routes stay on the api bucket: they act on a session that
        | already exists, and the renotify cap in config/pager.php is what bounds the
        | only one of them that can write repeatedly.
        */
        'pager-create' => [
            'per_ip' => ['attempts' => 60, 'seconds' => 60],
            'per_tenant' => ['attempts' => 240, 'seconds' => 60],
        ],

        /*
        | Unknown routes get their own, much tighter bucket. A 404 costs almost nothing
        | to serve and everything to scan with, so path enumeration is the traffic this
        | one exists for -- and the gate names it: "the rate limiter covers unknown
        | routes (404s)". There is no per-tenant limit here because an unknown path
        | carries no tenant: it is refused before anything resolves one.
        */
        'unknown' => [
            'per_ip' => ['attempts' => 30, 'seconds' => 60],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Posture when Redis is unavailable
    |--------------------------------------------------------------------------
    |
    | FAIL OPEN, approved as B7, and it is code rather than a property: measured in
    | phase 1, with the port pointed at a dead socket, RateLimiter::attempt() and
    | tooManyAttempts() both THREW RedisException. So "the API keeps serving with Redis
    | down" is false by default and has to be written.
    |
    | Open rather than closed, because the alternative is that losing Redis takes the
    | whole API down -- and the gate's own clause is "Redis is taken down and the API
    | keeps serving". The residual is real and is written down rather than waved at: an
    | attacker who can take Redis down has removed rate limiting. It goes to PLAN.md §5
    | with owner Task 12, which owns the SAST/DAST and dependency pass.
    |
    | Anything carrying TENANT DATA fails closed instead, which is why readiness answers
    | 503 when it cannot read its key rather than guessing: a wrong answer about one
    | tenant's data is worse than no answer at all. This value governs the limiter only.
    |
    */

    'fail_open' => true,

];
