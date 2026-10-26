<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;

/*
 * config/tenancy.php is read in SQL-adjacent places, so a missing or malformed
 * entry has to fail loudly at the point of use rather than produce an empty
 * identifier in a statement.
 */

it('refuses an unknown role, connection or guc rather than returning nothing', function (): void {
    expect(fn () => DatabaseRoles::name('nobody'))
        ->toThrow(RuntimeException::class, 'No database role is configured for [nobody]')
        ->and(fn () => DatabaseRoles::connection('nowhere'))
        ->toThrow(RuntimeException::class, 'No database connection is configured for [nowhere]')
        ->and(fn () => DatabaseRoles::guc('nothing'))
        ->toThrow(RuntimeException::class, 'No GUC is configured for [nothing]');
});

it('refuses a role name that was blanked out in config', function (): void {
    config(['tenancy.roles.app' => '']);

    expect(fn () => DatabaseRoles::name('app'))->toThrow(RuntimeException::class);
});

it('falls back to a usable bypass value if config is malformed', function (): void {
    config(['tenancy.bypass_value' => null]);

    expect(DatabaseRoles::bypassValue())->toBe('on');
});

it('returns an empty set of timeouts rather than failing when config is malformed', function (): void {
    config(['tenancy.timeouts' => 'not-an-array']);

    expect(DatabaseRoles::timeouts())->toBe([]);
});

it('passes when all three roles exist', function (): void {
    DatabaseRoles::assertRolesExist(DB::connection('pgsql_owner'));
})->throwsNoExceptions();

it('tells the operator which command creates a missing role', function (): void {
    // The failure this prevents is a migration dying with "role does not exist"
    // and no indication of what to run.
    config(['tenancy.roles.worker' => 'qrpager_absent_role']);

    expect(fn () => DatabaseRoles::assertRolesExist(DB::connection('pgsql_owner')))
        ->toThrow(
            RuntimeException::class,
            "These database roles do not exist yet: qrpager_absent_role. Run 'php artisan db:bootstrap'",
        );
});
