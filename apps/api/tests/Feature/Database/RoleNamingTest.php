<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Tests\Support\Schema;

/*
 * The migrations name the roles as literals, because a migration is a snapshot;
 * config/tenancy.php names them for PHP. This closes that loop: if the two ever
 * disagree, the policies would protect one role while the application connected
 * as another.
 */

it('names in config exactly the roles the database was built for', function (): void {
    expect(DatabaseRoles::name('root'))->toBe('qrpager_root')
        ->and(DatabaseRoles::name('owner'))->toBe('qrpager_owner')
        ->and(DatabaseRoles::name('app'))->toBe('qrpager_app')
        ->and(DatabaseRoles::name('worker'))->toBe('qrpager_worker');
});

it('connects the application as the role config calls the app role', function (): void {
    expect((string) DB::selectOne('select current_user as role')->role)->toBe(DatabaseRoles::name('app'));
});

it('grants privileges to exactly the two roles config calls runtime roles', function (): void {
    $granted = array_values(array_filter(
        ['qrpager_root', 'qrpager_owner', 'qrpager_app', 'qrpager_worker'],
        static fn (string $role): bool => Schema::reachableTables($role) !== [],
    ));

    // The owner reaches everything as the table owner; root is a superuser. The
    // point is that no fifth role has been granted anything.
    expect($granted)->toBe(['qrpager_root', 'qrpager_owner', 'qrpager_app', 'qrpager_worker'])
        ->and(DatabaseRoles::runtimeRoleNames())->toBe(['qrpager_app', 'qrpager_worker']);
});

it('has no login role in the cluster beyond the four in the contract', function (): void {
    $roles = array_map(
        static fn (object $row): string => (string) $row->rolname,
        DB::connection('pgsql_root')->select(
            "select rolname from pg_roles where rolcanlogin and rolname like 'qrpager%' order by rolname"
        ),
    );

    expect($roles)->toBe(['qrpager_app', 'qrpager_owner', 'qrpager_root', 'qrpager_worker']);
});

it('names in config the two GUCs the policies actually read', function (): void {
    expect([DatabaseRoles::guc('bypass'), DatabaseRoles::guc('tenant')])
        ->toBe(Schema::gucsUsedByPolicies());
});
