<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Session settings (GUCs)
    |--------------------------------------------------------------------------
    |
    | These two names are locked by PROJECT_INSTRUCTIONS.md C1. Introducing a
    | third requires editing that file first. Both are always set
    | transaction-locally -- set_config(name, value, true) -- per C2.
    |
    */

    'guc' => [
        'tenant' => 'app.tenant_id',
        'bypass' => 'app.bypass_tenant',
    ],

    'bypass_value' => 'on',

    /*
    |--------------------------------------------------------------------------
    | Database roles
    |--------------------------------------------------------------------------
    |
    | Role names are part of the data contract (PLAN.md §2.1), not environment
    | configuration: the RLS policies name them literally, so PHP and SQL have
    | to agree. Only the credentials come from the environment.
    |
    */

    'roles' => [
        'root' => 'qrpager_root',
        'owner' => 'qrpager_owner',
        'app' => 'qrpager_app',
        'worker' => 'qrpager_worker',
    ],

    /*
    | The only roles the application itself may connect as. assertSafeRuntimeRole()
    | refuses to run as anything else.
    */
    'runtime_roles' => ['app', 'worker'],

    'connections' => [
        'root' => 'pgsql_root',
        'bootstrap' => 'pgsql_bootstrap',
        'owner' => 'pgsql_owner',
        'app' => 'pgsql',
        'worker' => 'pgsql_worker',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-role timeouts, applied with ALTER ROLE
    |--------------------------------------------------------------------------
    |
    | The owner has no statement timeout because a migration may legitimately
    | build an index for minutes, and its connection is never internet-facing.
    | It keeps a short lock_timeout so DDL fails fast instead of queueing behind
    | a long read and blocking every writer behind itself.
    |
    */

    'timeouts' => [
        'owner' => [
            'statement_timeout' => '0',
            'lock_timeout' => '3s',
            'idle_in_transaction_session_timeout' => '60s',
        ],
        'app' => [
            'statement_timeout' => '5s',
            'lock_timeout' => '2s',
            'idle_in_transaction_session_timeout' => '15s',
        ],
        'worker' => [
            'statement_timeout' => '15s',
            'lock_timeout' => '3s',
            'idle_in_transaction_session_timeout' => '60s',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ceilings on the values above
    |--------------------------------------------------------------------------
    |
    | R-014. Removing the wall-clock UPPER bound from the timeout firing proofs (F10)
    | was right -- an upper bound on elapsed time measures the host, not Postgres, and
    | it failed the gate at 27.6s -- but it left nothing at all to catch a timeout
    | configured far too high. Such a value is not a failing test; it is a suite that
    | HANGS until the harness kills it, because the firing proof waits the configured
    | duration out. The row also records that the SHOW-comparison test cannot catch it:
    | that test compares current_setting against the SAME config value, so it catches
    | role-vs-config drift only.
    |
    | So the ceiling is asserted where the value is WRITTEN -- db:bootstrap refuses to
    | apply one past it -- and since TestCase::setUp runs db:bootstrap, a far-too-high
    | value fails in the first test rather than hanging the one that waits for it.
    |
    | The numbers are a sanity bound, not a tuning target: generous enough that any
    | deliberate change to the values above still passes, tight enough that a unit slip
    | (5m where 5s was meant) does not. '0' means "no timeout" and is checked against
    | the ceiling for its own setting rather than being treated as infinite -- the owner
    | has statement_timeout = 0 deliberately (PLAN.md §2.1), so that pair is allowed by
    | name below.
    |
    */

    'timeout_ceilings' => [
        'statement_timeout' => '30s',
        'lock_timeout' => '10s',
        'idle_in_transaction_session_timeout' => '120s',
    ],

    /*
    | The one pair allowed to exceed its ceiling, by role and setting, because it is
    | the documented design rather than an oversight: a migration may legitimately
    | build an index for minutes and the owner connection is never internet-facing.
    | Named as a pair so "no statement timeout" cannot quietly become true of the app
    | role.
    */
    'timeout_ceiling_exemptions' => [
        'owner' => ['statement_timeout'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-role connection limits, applied with ALTER ROLE
    |--------------------------------------------------------------------------
    |
    | Postgres reserves slots for superusers only, so without these one role
    | exhausting max_connections starves the others -- and the worker is the only
    | sender of notifications (C5) while the owner is how migrations and incident
    | response get in. Sized against max_connections = 100 with three reserved for
    | the superuser, leaving headroom for the root and bootstrap connections.
    |
    */

    'connection_limits' => [
        'owner' => 10,
        'app' => 40,
        'worker' => 20,
    ],

    /*
    | Role bootstrap races when suites run in parallel (PROJECT_INSTRUCTIONS.md
    | §4 › Postgres traps), so the whole bootstrap holds this advisory lock.
    */
    'bootstrap_lock_key' => 4927301,

];
