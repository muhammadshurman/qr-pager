<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Regression test for a bug found while running this task's own suite: the
 * harness left a connection open per test and the run died at around test 100
 * with 08006, "remaining connection slots are reserved for roles with the
 * SUPERUSER attribute". Four roles plus the bootstrap connection is five per
 * test, against a default max_connections of 100.
 */

it('closes every database connection between tests', function (): void {
    $connections = ['pgsql', 'pgsql_owner', 'pgsql_worker', 'pgsql_root', 'pgsql_bootstrap'];

    foreach ($connections as $connection) {
        DB::connection($connection)->select('select 1');
    }

    expect(DB::getConnections())->toHaveCount(count($connections));

    // The exact routine tearDown runs. Without it this suite cannot finish.
    $this->purgeConnections();

    expect(DB::getConnections())->toBe([]);
});

it('holds only a handful of backends open at any point in the suite', function (): void {
    $backends = (int) DB::connection('pgsql_root')->selectOne(
        "select count(*) as c from pg_stat_activity where usename like 'qrpager\_%'"
    )->c;

    // Under the leak this climbed past 95. Anything in single digits means the
    // connections of previous tests are gone.
    expect($backends)->toBeLessThan(10);
});

it('fails closed on transport encryption rather than silently downgrading', function (): void {
    // F7 (security review of task-1): 'prefer' accepts an unencrypted session without
    // a word in any log, which would put the role passwords and every tenant row on
    // the wire in the clear. Local and CI opt out explicitly, so what matters is what
    // a deployment that sets nothing inherits.
    expect(config('database.connections.pgsql.sslmode'))->toBe('disable');

    $saved = [$_ENV['DB_SSLMODE'] ?? null, $_SERVER['DB_SSLMODE'] ?? null, getenv('DB_SSLMODE')];

    try {
        unset($_ENV['DB_SSLMODE'], $_SERVER['DB_SSLMODE']);
        putenv('DB_SSLMODE');

        $fresh = require base_path('config/database.php');

        foreach (['pgsql', 'pgsql_owner', 'pgsql_worker', 'pgsql_root', 'pgsql_bootstrap'] as $name) {
            expect($fresh['connections'][$name]['sslmode'])
                ->toBe('require', "{$name} would downgrade when DB_SSLMODE is unset");
        }
    } finally {
        [$env, $server, $raw] = $saved;

        if ($env !== null) {
            $_ENV['DB_SSLMODE'] = $env;
        }

        if ($server !== null) {
            $_SERVER['DB_SSLMODE'] = $server;
        }

        if (is_string($raw)) {
            putenv('DB_SSLMODE='.$raw);
        }
    }
});
