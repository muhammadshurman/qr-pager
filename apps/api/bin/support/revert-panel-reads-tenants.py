#!/usr/bin/env python3
"""Reverts B and C for bin/provisioning-revert-proof: make the panel read a tenant table.

TWO MODES, because the named invariant has two halves and only one of them had ever been
seen red (QA M-1, Task 6's §11 review):

  unscoped  A `select` against `tenants` with NO tenant context. The runtime scope guard
            (`ArmTenantScopeGuard` / `TenantScopeGuard`) refuses it BEFORE it is sent, so
            `DB::listen` never fires and the invariant case reds on its STATUS assertion
            instead -- a panel that queries a tenant table answers 500. That is still the
            invariant failing, but it is not the listener failing.

  scoped    The same read INSIDE a `withTenant()` scope. This is the case the guard
            ALLOWS, so the statement really is issued and the LISTENER is the only thing
            that can catch it. This is the half `§3.1` row 6 attributed the whole proof to
            while it had never been exercised.

The tenant id in the scoped mode is a fixed uuid that no test creates, so the query returns
nothing and changes no data; what matters is that the statement is issued at all.
"""
import sys

MARKER = '// REVERTED BY bin/provisioning-revert-proof'

ANCHOR = '    public function summary(): PlatformSummary\n    {\n'

UNSCOPED = (
    '        ' + MARKER + ': a panel query reaching a tenant\n'
    '        // table with NO scope. The runtime guard refuses it before it is sent, so the\n'
    '        // invariant case reds on its status assertion rather than on the listener.\n'
    '        DB::select(\'select count(*) from tenants\');\n\n'
)

SCOPED = (
    '        ' + MARKER + ': a panel query reaching a tenant\n'
    '        // table INSIDE a scope, which the runtime guard allows -- so the statement is\n'
    '        // really issued and ONLY the listener assertion can catch it (QA M-1).\n'
    '        withTenant(\n'
    '            \'00000000-0000-7000-8000-000000000000\',\n'
    '            fn () => DB::select(\'select count(*) from tenants\'),\n'
    '        );\n\n'
)


def main() -> int:
    path, mode = sys.argv[1], sys.argv[2]

    if mode not in ('unscoped', 'scoped'):
        print(f'unknown mode [{mode}]: expected unscoped or scoped', file=sys.stderr)
        return 1

    source = open(path, encoding='utf-8').read()

    if ANCHOR not in source:
        print('revert: summary() was not found -- has PlatformDashboard changed?', file=sys.stderr)
        return 1

    at = source.index(ANCHOR) + len(ANCHOR)
    inject = UNSCOPED if mode == 'unscoped' else SCOPED

    open(path, 'w', encoding='utf-8').write(source[:at] + inject + source[at:])
    print(f'revert applied: the dashboard reads `tenants` ({mode})')
    return 0


if __name__ == '__main__':
    sys.exit(main())
