<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Gate clause added on the ticket: "the ALTER ROLE timeouts are verified to fire,
 * per role".
 *
 * The named failure modes are wrong role, wrong database scope, and silently
 * overridden by the connection -- all three of which SHOW on a fresh connection
 * catches. Firing is then proven per role with a real cancellation. lock_timeout
 * is used for the per-role firing proof because an advisory lock makes it cost
 * its own duration and nothing more: no table, no rows, no tenant context.
 */

it('has the configured timeouts in effect on a fresh connection of each role', function (string $roleKey, string $role): void {
    $connection = (string) config("tenancy.connections.{$roleKey}");

    DB::connection($connection)->disconnect();
    $db = DB::connection($connection);

    expect((string) $db->selectOne('select current_user as role')->role)->toBe($role);

    /** @var array<string, string> $expected */
    $expected = config("tenancy.timeouts.{$roleKey}") ?? [];

    foreach ($expected as $setting => $value) {
        // Compared as intervals, because Postgres reports back in its own units:
        // '60s' comes out as '1min'. A role that never received the setting reports
        // the server default instead, which is what this is really checking for.
        $row = $db->selectOne(
            'select current_setting(?::text) as actual, current_setting(?::text)::interval = ?::interval as matches',
            [$setting, $setting, $value],
        );

        expect((bool) $row->matches)
            ->toBeTrue("{$role} has {$setting} = {$row->actual}, expected {$value}");
    }
})->with([
    'owner' => ['owner', 'qrpager_owner'],
    'app' => ['app', 'qrpager_app'],
    'worker' => ['worker', 'qrpager_worker'],
]);

it('fires lock_timeout for each role, which proves ALTER ROLE took effect and not just SHOW', function (string $roleKey): void {
    $connection = config("tenancy.connections.{$roleKey}");
    $expected = (string) config("tenancy.timeouts.{$roleKey}.lock_timeout");
    $key = random_int(1, 2_000_000_000);

    // A second session holds the lock for longer than the timeout under test.
    $holder = DB::connection('pgsql_root');
    $holder->select('select pg_advisory_lock(?)', [$key]);

    try {
        $startedAt = microtime(true);
        $state = $this->sqlStateOf(fn () => DB::connection($connection)->select('select pg_advisory_lock(?)', [$key]));
        $waited = microtime(true) - $startedAt;

        expect($state)->toBe('55P03', "lock_timeout did not fire for {$roleKey}");

        // It waited roughly the configured time rather than failing instantly or
        // hanging: a timeout that does not fire is worse than none.
        $seconds = (float) rtrim($expected, 's');
        expect($waited)->toBeGreaterThan($seconds * 0.5)
            ->and($waited)->toBeLessThan($seconds + 3.0);
    } finally {
        $holder->select('select pg_advisory_unlock(?)', [$key]);
    }
})->with(['app', 'worker', 'owner']);

it('fires statement_timeout for the app role, which is what load shedding assumes', function (): void {
    $startedAt = microtime(true);
    $state = $this->sqlStateOf(fn () => DB::select('select pg_sleep(8)'));
    $waited = microtime(true) - $startedAt;

    expect($state)->toBe('57014')
        ->and($waited)->toBeLessThan(7.0);
});

it('leaves the owner without a statement timeout, deliberately', function (): void {
    // A migration may legitimately build an index for minutes and its connection is
    // never internet-facing (PLAN.md §2.1). The short lock_timeout above is what
    // keeps that from blocking everyone else.
    expect((string) DB::connection('pgsql_owner')->selectOne("select current_setting('statement_timeout') as v")->v)
        ->toBe('0');
});

it('records the timeouts against the role itself, not one database', function (): void {
    // ALTER ROLE ... SET (no IN DATABASE) is cluster-wide, so the test database and
    // production carry the same values -- "wrong database scope" is the trap here.
    $config = DB::connection('pgsql_root')->select(
        'select rolname, rolconfig from pg_roles where rolname = any(?::text[]) order by rolname',
        ['{qrpager_app,qrpager_owner,qrpager_worker}'],
    );

    expect($config)->toHaveCount(3);

    foreach ($config as $role) {
        expect((string) $role->rolconfig)->toContain('statement_timeout')
            ->and((string) $role->rolconfig)->toContain('lock_timeout')
            ->and((string) $role->rolconfig)->toContain('idle_in_transaction_session_timeout');
    }
});
