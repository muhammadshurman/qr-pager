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
    | Role bootstrap races when suites run in parallel (PROJECT_INSTRUCTIONS.md
    | §4 › Postgres traps), so the whole bootstrap holds this advisory lock.
    */
    'bootstrap_lock_key' => 4927301,

];
