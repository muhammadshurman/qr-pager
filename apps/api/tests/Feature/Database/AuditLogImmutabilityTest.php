<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schema;

/*
 * Acceptance gate, clause 6: "audit_log immutable even for superuser".
 */

beforeEach(function (): void {
    $this->tenant = $this->makeTenant();

    // audit_log is the one table setUp cannot truncate -- the trigger refuses -- so
    // each test marks its own row and asserts on that rather than on a total.
    $this->probe = 'immutability.probe.'.Str::uuid7();

    withTenant($this->tenant, fn () => DB::table('audit_log')->insert([
        'tenant_id' => $this->tenant,
        'actor_type' => 'user',
        'action' => $this->probe,
    ]));
});

it('accepts an append from the app role', function (): void {
    expect(withTenant($this->tenant, fn () => DB::table('audit_log')->where('action', $this->probe)->count()))
        ->toBe(1);
});

it('refuses update, delete and truncate for every role, superuser included', function (string $connection, string $role): void {
    $db = DB::connection($connection);

    expect($this->sqlStateOf(fn () => $db->update('update audit_log set action = ?', ['tampered'])))
        ->toBe('42501', "{$role} was allowed to UPDATE audit_log");

    expect($this->sqlStateOf(fn () => $db->delete('delete from audit_log')))
        ->toBe('42501', "{$role} was allowed to DELETE from audit_log");

    expect($this->sqlStateOf(fn () => $db->unprepared('truncate audit_log')))
        ->toBe('42501', "{$role} was allowed to TRUNCATE audit_log");

    expect($this->asRoot()->table('audit_log')->where('action', $this->probe)->count())->toBe(1);
})->with([
    'app' => ['pgsql', 'qrpager_app'],
    'worker' => ['pgsql_worker', 'qrpager_worker'],
    'owner (the table owner)' => ['pgsql_owner', 'qrpager_owner'],
    'root (a real superuser)' => ['pgsql_root', 'qrpager_root'],
]);

it('refuses a statement that would match zero rows', function (): void {
    // FOR EACH STATEMENT, not FOR EACH ROW: a tamper attempt is refused before any
    // row is examined, so "delete where false" is refused too.
    expect($this->sqlStateOf(fn () => $this->asRoot()->delete('delete from audit_log where false')))
        ->toBe('42501');
});

it('refuses a superuser that disables triggers with session_replication_role', function (): void {
    $root = $this->asRoot();

    // This is what ENABLE ALWAYS is for. Verified on 16.14: with an ordinary
    // trigger, replica mode skips it and the DELETE succeeds.
    $state = $this->sqlStateOf(function () use ($root): void {
        $root->unprepared('set session_replication_role = replica; delete from audit_log;');
    });

    $root->unprepared('reset session_replication_role');

    expect($state)->toBe('42501')
        ->and($root->table('audit_log')->where('action', $this->probe)->count())->toBe(1);
});

it('keeps both triggers marked ENABLE ALWAYS, which is the whole mechanism', function (): void {
    $triggers = $this->asOwner()->select(
        "select tgname, tgenabled from pg_trigger
         where tgrelid = 'audit_log'::regclass and not tgisinternal order by tgname"
    );

    expect($triggers)->toHaveCount(2);

    foreach ($triggers as $trigger) {
        // 'A' = ALWAYS. 'O' (the default) would be skipped in replica mode.
        expect((string) $trigger->tgenabled)->toBe('A', "{$trigger->tgname} is not ENABLE ALWAYS");
    }
});

it('grants no role the privilege to mutate the log in the first place', function (): void {
    foreach (['qrpager_app', 'qrpager_worker'] as $role) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            expect(Schema::hasTablePrivilege($role, 'audit_log', $privilege))
                ->toBeFalse("{$role} holds {$privilege} on audit_log");
        }
    }
});

it('survives a tenant being deleted, because it carries no foreign keys', function (): void {
    $tenant = $this->makeTenant();

    withTenant($tenant, fn () => DB::table('audit_log')->insert([
        'tenant_id' => $tenant,
        'actor_type' => 'platform',
        'action' => 'tenant.deleted',
    ]));

    withoutTenantScope('task-1 test: delete a tenant', fn () => DB::connection('pgsql_owner')
        ->table('tenants')->where('id', $tenant)->delete());

    // An ON DELETE SET NULL would be an UPDATE, which the trigger blocks -- so a
    // foreign key here would make deleting a tenant impossible (PLAN.md §2.5).
    expect($this->asRoot()->table('audit_log')->where('tenant_id', $tenant)->count())->toBe(1);
});
