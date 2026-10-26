<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Readiness
    |--------------------------------------------------------------------------
    |
    | The carrier (readiness:refresh) writes one Redis key; readiness reads it and
    | opens no database connection on the request path (B6). Measured: with
    | qrpager_app at its CONNECTION LIMIT of 40 a new app connection is refused
    | 08006, so a readiness endpoint that opened one would fail under exactly the
    | condition it exists to report (R-012).
    |
    | 'freshness' is the age at which a snapshot stops counting as ready. Three
    | times the carrier's cadence, so one missed run is tolerated and two are not.
    | That reading is sound only because the carrier runs in the same task as the
    | server: a carrier that has stopped is a task that should be replaced.
    |
    */

    'readiness' => [
        'cadence' => 5,
        'freshness' => 15,
        'ttl' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Load shedding thresholds
    |--------------------------------------------------------------------------
    |
    | Numbers live here and never in a class (D4). They are a starting point, not a
    | measurement: Task 14 sets them from load results (C6). The pool figure is a
    | fraction of config('tenancy.connection_limits.app'), which is what the probe
    | divides by.
    |
    */

    'pool_saturation' => 0.9,

    /*
    | Memory is read from the worker and from cgroup v2, not from the load average:
    | sys_getloadavg() reports the HOST inside a container, which under Fargate is
    | the instance and not the task -- measured. cgroup v2's memory.max reads the
    | literal string "max" where no limit is set, so an absolute ceiling is needed
    | as well or the fraction is unmeasurable on a developer machine.
    */
    'memory' => [
        'fraction' => 0.9,
        'ceiling_bytes' => 512 * 1024 * 1024,
    ],

    /*
    | CPU pressure is throttling, not utilisation: cpu.stat's nr_throttled is the
    | only signal that means "this task is being held back" rather than "this task
    | is busy", and §4 › Edge & ops is explicit that a busy task must not be shed.
    */
    'cpu' => [
        'throttled_periods_per_window' => 50,
        'window_seconds' => 10,
    ],

];
