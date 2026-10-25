<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Support\Facades\DB;

/**
 * assertSafeRuntimeRole() (PROJECT_INSTRUCTIONS.md §4 › Tenancy).
 *
 * A superuser ignores ENABLE and FORCE row level security entirely -- verified
 * on 16.14 -- so serving traffic as one silently removes tenant isolation while
 * every test still passes. Task 2 calls this at boot and exits 1 on failure.
 */
class RuntimeRoleGuard
{
    public function assertSafe(?string $connection = null): void
    {
        $connection ??= DatabaseRoles::connection('app');

        $state = DB::connection($connection)->selectOne(<<<'SQL'
            select current_user                                                   as role,
                   r.rolsuper                                                     as is_superuser,
                   r.rolbypassrls                                                  as can_bypass_rls,
                   r.rolcreaterole                                                 as can_create_role,
                   r.rolcreatedb                                                   as can_create_database,
                   has_schema_privilege(current_user, 'public', 'CREATE')          as can_create_objects,
                   -- MEMBER, not USAGE: the runtime roles are NOINHERIT, so a role
                   -- that can SET ROLE to the owner reports no inherited USAGE at all
                   -- while still being able to become it.
                   coalesce(
                       (select pg_has_role(current_user, o.oid, 'MEMBER')
                        from pg_roles o where o.rolname = ?),
                       false
                   )                                                              as can_become_owner
            from pg_roles r
            where r.rolname = current_user
        SQL, [DatabaseRoles::name('owner')]);

        /** @var object{role: string, is_superuser: bool, can_bypass_rls: bool, can_create_role: bool, can_create_database: bool, can_create_objects: bool, can_become_owner: bool} $state */
        $role = (string) $state->role;
        $allowed = DatabaseRoles::runtimeRoleNames();
        $reasons = [];

        if (! in_array($role, $allowed, true)) {
            $reasons[] = 'it is not one of the runtime roles ('.implode(', ', $allowed).')';
        }

        if ($state->is_superuser) {
            $reasons[] = 'it is a SUPERUSER, which ignores FORCE row level security entirely';
        }

        if ($state->can_bypass_rls) {
            $reasons[] = 'it holds BYPASSRLS';
        }

        if ($state->can_become_owner) {
            $reasons[] = 'it can become the table owner, which can turn policies off';
        }

        if ($state->can_create_objects) {
            $reasons[] = 'it can create objects in schema public, which is DDL';
        }

        if ($state->can_create_role) {
            $reasons[] = 'it holds CREATEROLE';
        }

        if ($state->can_create_database) {
            $reasons[] = 'it holds CREATEDB';
        }

        if ($reasons !== []) {
            throw new UnsafeDatabaseRoleException($role, $reasons);
        }
    }
}
