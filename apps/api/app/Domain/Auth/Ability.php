<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Every capability a credential may carry. ONE SOURCE (PROJECT_INSTRUCTIONS.md §6.2,
 * QRPAGER-12 §10.2(d)).
 *
 * WHY AN ENUM AND NOT STRING LITERALS. Sanctum compares abilities as strings, so a
 * literal at a call site type-checks, passes review and silently never matches — a
 * misspelled ability is a permanently-denied capability, or worse, a check that was meant
 * to narrow and does not. The enum is what the code and the role matrix name; `->value`
 * is the only place the string exists.
 *
 * In App\Domain on purpose: no framework, no database, no config. These are part of the
 * contract, not environment configuration, for the same reason the role names and the
 * table names are — and Task 8 generates the TypeScript mirror from here (ADR-003), so
 * a hand-written copy on the other side would be the duplicated-enum defect §6.2 forbids.
 */
enum Ability: string
{
    /* ---------------------------------------------------------------- staff session */

    /** Read the active pager board. The KDS screen's whole job. */
    case PagerRead = 'pager:read';

    /** Open a pager session — the one customer-facing write a staff member makes. */
    case PagerCreate = 'pager:create';

    /** Move a session to ready, or re-notify one that already is. */
    case PagerNotify = 'pager:notify';

    /** Complete or cancel a session. */
    case PagerResolve = 'pager:resolve';

    /* ------------------------------------------------------------------- membership */

    /** List the tenant's staff. */
    case MembersRead = 'members:read';

    /** Invite, change a role, or remove a member. */
    case MembersWrite = 'members:write';

    /* ----------------------------------------------------------------- KDS devices */

    /** Issue or revoke a device token. Never held BY a device token — see below. */
    case DevicesWrite = 'devices:write';

    /* ------------------------------------------------------- the tenant's own identity */

    /**
     * Read the caller's own restaurant: its name, slug, timezone and status.
     *
     * ADDED AT TASK 10a, and it exists for a `§12` reason rather than a data one. The
     * staff shell has to say which restaurant the person is signed in to, and `§12`'s
     * zero-technical-background bar forbids an unexplained identifier on a screen —
     * `/v1/auth/me` carries `slug`, which is a DNS label (`e2e-0199…`) and not a
     * restaurant. So there is a route that reads `tenants` INSIDE the resolved tenant's
     * scope, where RLS leaves exactly one visible row.
     *
     * ⚠ WHY NOT WIDEN `public.user_memberships()` INSTEAD, which would have been one
     * column: that function IS `PROJECT_INSTRUCTIONS.md §4 › Tenancy`'s named cross-tenant
     * read path *"a user's own memberships"*. Adding display text to a cross-tenant
     * function is a migration, a `PLAN.md §2.8` change and a grant change, for data the
     * caller can read inside their own scope with ordinary row-level security. `§2.8`'s
     * named list stays at four.
     *
     * ⚠ AND IT IS DELIBERATELY NOT HELD BY `Principal::Device`, which is the entry a
     * reviewer should check. A KDS screen arguably wants to show the restaurant's name —
     * but `issuableToDevice()` below is a CLOSED LIST OF ONE by `QRPAGER-12 §5` (*"exactly
     * one tenant and one capability"*), and `RequestPrincipal::may()` requires BOTH the
     * matrix and the token's own abilities to allow. So granting it to `Device` in the
     * matrix without widening that list would be a cell that can never be exercised — a
     * vacuous grant reading as a capability — and widening the list is a change to an
     * approved design, which is a stop-and-report rather than a builder's call. Neither
     * was done: `Device` does not hold this, and the list is untouched.
     */
    case TenantRead = 'tenant:read';

    /**
     * The abilities a KDS device token may be issued with, and it is a closed list of
     * ONE.
     *
     * QRPAGER-12 §5: "A KDS device token lives on a shared screen in a kitchen. Scope it
     * to exactly one tenant and one capability". A kitchen screen reads the board; it does
     * not open sessions, manage staff or mint tokens. `DevicesWrite` is deliberately
     * absent, which is what makes "a device token cannot mint another device token"
     * structural rather than a policy check — and `PagerNotify` is absent because marking
     * ready is Task 10's staff action, not the screen's.
     *
     * @return list<self>
     */
    public static function issuableToDevice(): array
    {
        return [self::PagerRead];
    }

    /** Whether a device token may carry this ability at all. */
    public function issuableToDevices(): bool
    {
        return in_array($this, self::issuableToDevice(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
