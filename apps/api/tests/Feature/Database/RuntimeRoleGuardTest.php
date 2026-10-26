<?php

declare(strict_types=1);

use App\Platform\Database\UnsafeDatabaseRoleException;

/*
 * Ticket §6: assertSafeRuntimeRole() exists and has a test proving it rejects owner.
 * Task 2 turns this into "exit 1, reason logged" at boot.
 */

it('accepts the app role, which is what the application connects as', function (): void {
    assertSafeRuntimeRole();

    expect(config('database.default'))->toBe('pgsql');
});

it('accepts the worker role', function (): void {
    assertSafeRuntimeRole('pgsql_worker');
})->throwsNoExceptions();

it('rejects the owner role', function (): void {
    expect(fn () => assertSafeRuntimeRole('pgsql_owner'))
        ->toThrow(UnsafeDatabaseRoleException::class);
});

it('says why it rejected the owner', function (): void {
    try {
        assertSafeRuntimeRole('pgsql_owner');
    } catch (UnsafeDatabaseRoleException $e) {
        expect($e->role)->toBe('qrpager_owner')
            ->and($e->reasons)->toContain('it is not one of the runtime roles (qrpager_app, qrpager_worker)')
            ->and($e->reasons)->toContain('it can create objects in schema public, which is DDL')
            ->and($e->getMessage())->toContain('Refusing to run as database role [qrpager_owner]');

        return;
    }

    $this->fail('the owner role was accepted');
});

it('rejects a superuser, and names the reason that actually matters', function (): void {
    try {
        assertSafeRuntimeRole('pgsql_root');
    } catch (UnsafeDatabaseRoleException $e) {
        // This is the Task 0 debt: the container superuser used to be called
        // qrpager_owner, and a superuser ignores FORCE RLS entirely.
        expect($e->reasons)
            ->toContain('it is a SUPERUSER, which ignores FORCE row level security entirely')
            ->and($e->reasons)->toContain('it holds BYPASSRLS');

        return;
    }

    $this->fail('a superuser was accepted as a runtime role');
});

it('would reject a role that could become the owner', function (): void {
    // Proven against the real thing rather than asserted: the role is granted
    // membership of the owner for the length of the test.
    $root = $this->asRoot();
    $root->unprepared('grant qrpager_owner to qrpager_app');

    try {
        expect(fn () => assertSafeRuntimeRole())->toThrow(
            UnsafeDatabaseRoleException::class,
            'it can become the table owner',
        );
    } finally {
        $root->unprepared('revoke qrpager_owner from qrpager_app');
    }

    assertSafeRuntimeRole();
});

it('would reject the runtime role if it were given BYPASSRLS', function (): void {
    // R-018: a throwaway role instead of the live qrpager_app, which this test used to
    // grant BYPASSRLS to cluster-wide. tenancy.roles.app is pointed at the throwaway so
    // assertSafe() still treats it as a RUNTIME role -- otherwise it would refuse for
    // two reasons at once and the test would pass without the attribute mattering.
    $live = config('tenancy.roles.app');

    $this->withThrowawayRole(['bypassrls'], function (string $connection, string $role): void {
        config(['tenancy.roles.app' => $role]);

        expect(fn () => assertSafeRuntimeRole($connection))->toThrow(
            UnsafeDatabaseRoleException::class,
            'it holds BYPASSRLS',
        );

        expect((bool) $this->asRoot()->selectOne(
            'select rolbypassrls from pg_roles where rolname = ?', ['qrpager_app'],
        )->rolbypassrls)->toBeFalse('the live app role was altered after all');
    });

    // Put the runtime-role list back before the last assertion: the callback repointed
    // tenancy.roles.app at the throwaway, so leaving it there would make this refuse
    // qrpager_app for "not one of the runtime roles" -- a green-looking failure for
    // entirely the wrong reason, which is what it did on the first run.
    config(['tenancy.roles.app' => $live]);

    // The real app role is still safe, and was never touched.
    assertSafeRuntimeRole();
});
