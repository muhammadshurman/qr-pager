#!/usr/bin/env python3
"""Revert A for bin/provisioning-revert-proof: split the provisioning transaction.

WHY THIS IS A FILE AND NOT A HEREDOC. It was a heredoc inside the shell script, which is
how it came to be written with a regex that left `$ownerUserId` undefined -- six of the
eight reds it produced were ErrorExceptions, i.e. the harness breaking rather than the
guard being removed, and they were published as coverage. Task 6's own §11 security review
(L-9) measured that. A revert that has to be a faithful behavioural change is worth a file
with a name, where it can be read.

WHAT IT DOES. `TenantProvisioning::provision()` wraps three inserts in ONE `withTenant()`
call, which is one transaction: a failure at the second leaves none of them. This replaces
that single wrapper with four separate ones -- resolveOwner hoisted, then one per insert --
so each commits independently and a failure halfway leaves a `tenants` row behind. That is
the "partial tenant" state `§3` row 6 forbids, and the case that must notice is
`it leaves NO partial tenant when the membership insert fails halfway`.

Every variable stays defined, which is the property the regex version lost.
"""
import sys

MARKER = '// REVERTED BY bin/provisioning-revert-proof: one transaction -> four.'

REPLACEMENT = '''            ''' + MARKER + '''
            $ownerUserId = withTenant(
                $tenantId,
                fn (): string => $this->resolveOwner((string) $row->owner_email),
            );

            withTenant($tenantId, function () use ($tenantId, $row): void {
                DB::insert(
                    'insert into tenants (id, name, slug, status, timezone, created_at, updated_at)
                     values (?, ?, ?, ?::tenant_status, ?, now(), now())',
                    [$tenantId, $row->name, $row->slug, TenantStatus::Active->value, 'UTC'],
                );
            });

            withTenant($tenantId, function () use ($tenantId, $ownerUserId): void {
                DB::insert(
                    'insert into tenant_users (id, tenant_id, user_id, role, created_at, updated_at)
                     values (?, ?, ?, ?::tenant_role, now(), now())',
                    [(string) Str::uuid7(), $tenantId, $ownerUserId, TenantRole::Owner->value],
                );
            });

            withTenant($tenantId, function () use ($tenantId, $row): void {
                DB::insert(
                    'insert into audit_log
                        (tenant_id, actor_type, action, subject_type, subject_id, context)
                     values (?, ?::audit_actor_type, ?, ?, ?, ?::jsonb)',
                    [
                        $tenantId,
                        AuditActorType::Platform->value,
                        'tenant.provisioned',
                        'tenant',
                        $tenantId,
                        json_encode(['slug' => $row->slug], JSON_THROW_ON_ERROR),
                    ],
                );
            });'''

OPEN = '            $ownerUserId = withTenant($tenantId, function () use ($tenantId, $row): string {'
CLOSE = '            });'


def main() -> int:
    path = sys.argv[1]
    source = open(path, encoding='utf-8').read()

    if OPEN not in source:
        print('revert A: the single-transaction block was not found -- has provision() changed?',
              file=sys.stderr)
        return 1

    start = source.index(OPEN)
    end = source.index(CLOSE, start) + len(CLOSE)

    open(path, 'w', encoding='utf-8').write(source[:start] + REPLACEMENT + source[end:])
    print('revert A applied: one transaction -> four')
    return 0


if __name__ == '__main__':
    sys.exit(main())
