<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * THE ROLE MATRIX — one array, read by both the authorisation layer and the test.
 *
 * ================================================================================
 * WHY THIS CLASS EXISTS AND WHY IT IS NOT IN THE TEST FILE
 * ================================================================================
 *
 * `PLAN.md §3` row 5's gate said "Full role-matrix test" and `§3.1` row 5 rejected it:
 * *"does not say what full means. Name the matrix — roles × actions ×
 * own-tenant/other-tenant — or it is unfalsifiable by anyone but its author."* The gate is
 * now amended to name it, and this is the named thing.
 *
 * It lives here, in `App\Domain`, and not in the test, because **a matrix that exists only
 * inside the assertion it feeds proves nothing about the code**: the test would be
 * checking that one copy of a list agrees with itself. The authorisation layer reads this
 * array to decide, and the matrix test reads the same array to assert — so a wrong cell is
 * a wrong decision *and* a wrong expectation, which is the only arrangement in which the
 * test can fail for the right reason.
 *
 * ================================================================================
 * WHAT "FULL" MEANS, and it is mechanical
 * ================================================================================
 *
 *   5 principals  ×  every /v1 route  ×  { own tenant, another tenant }
 *
 * - **Principals** are `Principal`'s five cases. `Device` is in the matrix because a KDS
 *   token is a principal; it is not in `tenant_role`, and `Principal`'s docblock says why.
 * - **Actions** are every `/v1` route that exists at this task. `RoleMatrixTest`'s first
 *   case iterates the router and fails if any `/v1` staff route has no `Ability` mapped to
 *   it, so "every route" is enforced rather than promised — a route added without a matrix
 *   row fails the suite. That is the same shape as the deny-by-default route test.
 *   (This named `MatrixCoverageTest`, which has never existed. Corrected at Task 5's QA
 *   review: a docblock pointing at an imaginary test is worse than one pointing at none,
 *   because a reader who greps for it concludes the guard is gone rather than misnamed.)
 * - **The other-tenant column is always refusal**, for every principal including
 *   `PlatformAdmin`, and it is in the matrix because that cell IS the invariant this task
 *   ships on (`§3.1` row 5: *a tenant-provisioning actor cannot read another tenant's
 *   data*). It is not expressed as data below, because expressing it as data would make it
 *   look like a choice: cross-tenant refusal is structural — the resolver never returns a
 *   tenant the authenticated user holds no membership in, so an "other tenant" request
 *   never reaches an authorisation check at all. The matrix test drives it through HTTP
 *   anyway, because `§11.2` requires IDOR to be proven by a test and not by reading code.
 *
 * ================================================================================
 * THE SHAPE OF THE GRANTS, and the one rule behind them
 * ================================================================================
 *
 * Abilities are **cumulative by seniority within a tenant** — staff ⊂ manager ⊂ owner —
 * and `Device` is **not** on that ladder: it holds exactly one ability and is a subset of
 * staff rather than a rung. A reviewer should check that `Device` can never be widened by
 * a change to the ladder, which is why it is listed explicitly rather than derived.
 *
 * `PlatformAdmin` holds **nothing here at all**, and that is the entry a reviewer should
 * look at hardest. It is not an oversight and not a TODO: a platform admin's powers are
 * platform-scoped (provisioning a tenant, reading the platform audit log) and Task 6 owns
 * that surface. Giving it any tenant ability here would be the tenant bypass `§5` forbids,
 * and would need a `PROJECT_INSTRUCTIONS.md §4` edit first.
 *
 * ⚠ THIS SAID "reading `audit_log` rows whose `tenant_id` is NULL" UNTIL 2026-10-28, and
 * that was never reachable by a serving process: `audit_log` is a tenant table under FORCE
 * RLS, a platform-scope row has `tenant_id` NULL, and `NULL = ctx` is never true, so the
 * row satisfies no policy in either direction. Measured at Task 6's Phase 0 — `42501` on
 * insert for `qrpager_app` in and out of context, 0 rows on read, and only
 * `qrpager_owner` with the bypass able to do either. Task 6's `platform_audit_log` is the
 * table this now names (`QRPAGER-13 §0` SR-3).
 */
final class RoleMatrix
{
    /**
     * Principal → the abilities it holds **inside a tenant it is a member of**.
     *
     * @var array<string, list<Ability>>
     */
    private const GRANTS = [
        // Reads the board and nothing else. One capability, per QRPAGER-12 §5.
        Principal::Device->value => [
            Ability::PagerRead,
        ],

        // The floor for a person: see the board, open a session, mark it ready, close it.
        // A staff member cannot manage other staff or issue device tokens.
        Principal::Staff->value => [
            Ability::PagerRead,
            Ability::PagerCreate,
            Ability::PagerNotify,
            Ability::PagerResolve,
        ],

        // Staff, plus the people and the screens.
        Principal::Manager->value => [
            Ability::PagerRead,
            Ability::PagerCreate,
            Ability::PagerNotify,
            Ability::PagerResolve,
            Ability::MembersRead,
            Ability::MembersWrite,
            Ability::DevicesWrite,
        ],

        // Everything a manager holds. The two are deliberately identical at this task:
        // what separates an owner from a manager is billing and tenant settings, and
        // neither exists before Task 6. Stated rather than left as an apparent omission,
        // because two principals with identical grants looks like a copy-paste error.
        Principal::Owner->value => [
            Ability::PagerRead,
            Ability::PagerCreate,
            Ability::PagerNotify,
            Ability::PagerResolve,
            Ability::MembersRead,
            Ability::MembersWrite,
            Ability::DevicesWrite,
        ],

        // Empty on purpose. See the class docblock.
        Principal::PlatformAdmin->value => [],
    ];

    /**
     * The abilities this principal holds inside a tenant it belongs to.
     *
     * @return list<Ability>
     */
    public static function abilitiesFor(Principal $principal): array
    {
        return self::GRANTS[$principal->value];
    }

    /** Whether this principal holds this ability inside a tenant it belongs to. */
    public static function allows(Principal $principal, Ability $ability): bool
    {
        return in_array($ability, self::GRANTS[$principal->value], true);
    }

    /**
     * The whole matrix, as the test reads it.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        $out = [];

        foreach (Principal::cases() as $principal) {
            $out[$principal->value] = array_map(
                static fn (Ability $a): string => $a->value,
                self::GRANTS[$principal->value],
            );
        }

        return $out;
    }
}
