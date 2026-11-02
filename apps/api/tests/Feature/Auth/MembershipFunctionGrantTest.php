<?php

declare(strict_types=1);

/*
 * ================================================================================
 * `public.user_memberships(uuid)`'s ACL, OWNERSHIP, SECURITY MODE AND COLUMN SET
 * ================================================================================
 *
 * ⚠ **THIS FILE EXISTS BECAUSE 10b's MIGRATION CITED IT BY NAME AND IT DID NOT EXIST.**
 * `§11` SECURITY H-1: the docblock of
 * `2026_11_02_120000_publish_tenant_name_on_memberships.php` stated *"`MembershipFunctionGrantTest`
 * reads `pg_proc.proacl` back as an exact two-entry set, so a third grantee fails the suite
 * rather than passing quietly"*, and a grep for that string over the whole repository returned
 * exactly one hit: the citation itself. The ACL was CORRECT — the reviewer read it back from
 * the live cluster — so nothing was exposed. What was wrong is worse than a missing test: a
 * **false evidentiary claim**, which stops the next reader from looking.
 *
 * ================================================================================
 * WHY IT MATTERS FOR THIS FUNCTION SPECIFICALLY
 * ================================================================================
 *
 * This is `PROJECT_INSTRUCTIONS.md §4 › Tenancy`'s **second named cross-tenant read path**,
 * and 10b `DROP`s and re-creates it to add a fifth returned column. **A grant does not survive
 * its object**, and a `SECURITY DEFINER` function is `EXECUTE`-able by `PUBLIC` by default —
 * so an edit, a hand-run repair or a reworded `down()` that omits `REVOKE ALL … FROM PUBLIC`
 * would let every role on the cluster read every user's memberships in every tenant, under the
 * tenant bypass, with nothing going red.
 *
 * `PagerTokenLookupTest` has had exactly this control for the sibling function since Task 3.
 * This is that control, on the path that was missing it, plus the two properties the reviewer
 * asked for beyond the ACL: the security mode and the returned column SET.
 */

it('is executable by the app role and by nothing else', function (): void {
    $acl = $this->asRoot()->selectOne(
        "select coalesce(array_to_string(proacl, ','), '(default: PUBLIC)') as acl
           from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'user_memberships'",
    );

    $text = (string) $acl->acl;

    // An EXACT SET rather than a substring, which is `PagerTokenLookupTest`'s own correction:
    // asserting the absence of `=X/qrpager_owner,` fails on a correct grant, because that
    // substring is part of the owner's own legitimate entry.
    $entries = explode(',', $text);
    sort($entries);

    expect($entries)->toBe([
        'qrpager_app=X/qrpager_owner',
        'qrpager_owner=X/qrpager_owner',
    ])
        // ⚠ THE `REVOKE FROM PUBLIC` IS THE CONTROL AND THE GRANT IS ONLY WHAT IS LEFT. A
        // PUBLIC entry has an EMPTY grantee, so it appears as an entry beginning with `=`.
        // Without the revoke this function is callable by every role in the cluster.
        ->and($text)->not->toMatch('/(^|,)=/')
        // The worker has its own named cross-tenant path and this is not it.
        ->and($text)->not->toContain('qrpager_worker');
});

it('is SECURITY DEFINER, owned by the owner role, with search_path pinned', function (): void {
    // ⚠ ALL THREE, BECAUSE THEY ARE THE REST OF THE BOUND. The ACL says who may call it; these
    // say what it does when called. `SECURITY INVOKER` would make it useless (the caller's own
    // RLS applies and it returns nothing); an owner that was a superuser would make the bypass
    // unbounded; an unpinned `search_path` on a `SECURITY DEFINER` function is the classic
    // escalation — a schema the caller can write to, shadowing `public`.
    $row = $this->asRoot()->selectOne(
        "select p.prosecdef, r.rolname as owner, r.rolsuper, r.rolbypassrls,
                coalesce(array_to_string(p.proconfig, ','), '') as config
           from pg_proc p
           join pg_namespace n on n.oid = p.pronamespace
           join pg_roles r on r.oid = p.proowner
          where n.nspname = 'public' and p.proname = 'user_memberships'",
    );

    expect((bool) $row->prosecdef)->toBeTrue()
        ->and((string) $row->owner)->toBe('qrpager_owner')
        ->and((bool) $row->rolsuper)->toBeFalse()
        ->and((bool) $row->rolbypassrls)->toBeFalse()
        ->and((string) $row->config)->toBe('search_path=pg_catalog, pg_temp');
});

it('returns exactly the five named columns, and never a table row', function (): void {
    /*
     * ⚠ `§2.8` RULE 1 IS WHAT THIS PINS: *never `RETURNS SETOF <table>`* — column grants are
     * not applied to a function's result set, so a `SETOF tenants` here would hand
     * `qrpager_app` every column of that table, including whatever a later task adds to it,
     * and would silently undo `§2.7`.
     *
     * Read from the catalogue's own signature rather than by calling the function, so the
     * assertion holds even for an argument that returns no rows.
     */
    $row = $this->asRoot()->selectOne(
        "select pg_get_function_result(p.oid) as result
           from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'user_memberships'",
    );

    expect((string) $row->result)->toBe(
        'TABLE(tenant_id uuid, role tenant_role, tenant_status tenant_status, '
        .'tenant_slug character varying, tenant_name character varying)',
    );
});
