<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

$pgsql = static fn (string $username, string $password): array => [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'qrpager'),
    'username' => $username,
    'password' => $password,
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    // Fails closed. 'prefer' silently downgrades to an unencrypted session when the
    // server does not offer TLS, which would put the role passwords and every
    // tenant row on the wire in the clear with nothing in any log (F7, security
    // review of task-1). Local Postgres has no TLS, so .env.example and CI set
    // DB_SSLMODE=disable explicitly -- an opt-out that is visible in a diff.
    'sslmode' => env('DB_SSLMODE', 'require'),

    /*
     * ⚠ THE CONNECT DEADLINE, MADE EXPLICIT AT TASK 12 — AND IT WAS NEVER ABSENT, WHICH IS
     * `R-191`'s MECHANISM AND THE OPPOSITE OF WHAT THAT ROW SAID.
     *
     * The row's own `why_not_a_code_defect` read *"no connect_timeout is configured anywhere --
     * not in config/database.php, not in .env, not PGCONNECT_TIMEOUT, and there is no
     * pg_service.conf -- so the message is libpq reporting an OS-level connect() that did not
     * complete"*. The first half is true and the conclusion does not follow: `pdo_pgsql` appends
     * `connect_timeout` ITSELF, from `PDO::ATTR_TIMEOUT`, with a DEFAULT OF 30, whenever any
     * driver option is passed -- and `Illuminate\Database\Connectors\Connector` always passes
     * five (ATTR_CASE, ATTR_ERRMODE, ATTR_ORACLE_NULLS, ATTR_STRINGIFY_FETCHES,
     * ATTR_EMULATE_PREPARES). So a 30-second deadline nobody in this repository chose has been
     * in force on every connect since Task 1.
     *
     * MEASURED 2026-11-07, against 192.0.2.1 (TEST-NET-1, RFC 5737 -- packets are DROPPED
     * rather than refused, which is the shape of the fault this bounds):
     *
     *     ATTR_TIMEOUT = 3      failed in  3.01s  SQLSTATE[08006] ... failed: timeout expired
     *     Laravel's defaults    failed in 32.75s  SQLSTATE[08006] ... failed: timeout expired
     *
     * The second line is this file before this key existed, and `timeout expired` is verbatim
     * the string `tests/known-flakes.json` matches `R-191` by.
     *
     * WHY THAT NUMBER IS THE FAULT. `tests/Support/AppPoolSaturator.php` measured this host's
     * worst connect at **32.430 s** and read it correctly -- *"32 seconds is not a queue; it is
     * TCP SYN retransmission -- 1 + 2 + 4 + 8 + 16 ~ 31 s -- which means the SYNs were being
     * DROPPED"* -- and `R-191` measured **35,648 ms** on a bare TCP connect under CPU
     * contention, with **0 of 300 failed**. Both numbers sit JUST ABOVE 30. The connect was
     * succeeding; the undocumented deadline was expiring first. That is why the fault is
     * load-correlated, why its identity is random, and why it never reproduced under a bare
     * `php artisan test`.
     *
     * ⚠ THE VALUE HERE IS 30 ON PURPOSE: it is what has been in force all along, so this key
     * changes no behaviour anywhere and only makes the property readable. Whether 30 s is the
     * right deadline for a production connect -- a stalled connect holds a worker for its whole
     * length -- is a connection-pool question and stays with **Task 13** under `R-191`, with
     * these measurements. What Task 12 changes is the SUITE's value (phpunit.xml) and the
     * suite's behaviour on expiry (tests/Support/RetryingPostgresConnector.php).
     */
    'options' => [
        PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 30),
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // One role per connection (PLAN.md §2.1). 'pgsql' is the only connection the
        // application itself may use; the others belong to migrations, the queue worker
        // and the one-off role bootstrap.
        'pgsql' => $pgsql(env('DB_USERNAME', 'qrpager_app'), env('DB_PASSWORD', '')),

        'pgsql_owner' => $pgsql(env('DB_OWNER_USERNAME', 'qrpager_owner'), env('DB_OWNER_PASSWORD', '')),

        'pgsql_worker' => $pgsql(env('DB_WORKER_USERNAME', 'qrpager_worker'), env('DB_WORKER_PASSWORD', '')),

        'pgsql_root' => $pgsql(env('DB_ROOT_USERNAME', 'qrpager_root'), env('DB_ROOT_PASSWORD', '')),

        // Same superuser, pointed at the maintenance database, because CREATE DATABASE
        // cannot run from inside the database it is creating.
        'pgsql_bootstrap' => array_merge(
            $pgsql(env('DB_ROOT_USERNAME', 'qrpager_root'), env('DB_ROOT_PASSWORD', '')),
            ['database' => env('DB_MAINTENANCE_DATABASE', 'postgres'), 'url' => null],
        ),

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
