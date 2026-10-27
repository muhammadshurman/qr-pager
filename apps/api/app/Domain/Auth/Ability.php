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
