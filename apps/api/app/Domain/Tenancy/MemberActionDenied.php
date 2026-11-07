<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use DomainException;

/**
 * A member-management action refused because of WHO is asking, not because of what exists.
 *
 * ================================================================================
 * IN `App\Domain`, AND THE PLACEMENT IS LOAD-BEARING — `PagerTransitionDenied`'s REASON
 * ================================================================================
 *
 * That class's docblock states it: `ProblemRenderer` maps the exception to a status, and the
 * renderer is in `App\Platform`, which **deptrac forbids from importing `App\Features`**. A
 * feature-layer exception could not be mapped there at all. `Domain` is the one layer
 * `Platform` may reach, so this is the only place it can live if the renderer is to know
 * about it.
 *
 * ================================================================================
 * WHY A 403 HERE WHEN EVERY OTHER MEMBER REFUSAL IS A 404
 * ================================================================================
 *
 * `MemberController` answers **404** for a member id this tenant cannot see, for a last-owner
 * demotion, and for an unknown email on `store()` — and that last one is deliberate and is
 * written down: *"a distinguishable one would make this endpoint an account-existence oracle
 * for the whole platform — `users` is global."*
 *
 * `QRPAGER-29 §5` names the trap of adding to that: *"A refusal must not be an oracle …  A new
 * seniority refusal must not undo that by answering distinguishably."*
 *
 * **These two refusals disclose nothing, and that is checkable rather than asserted.** Each
 * depends only on facts the caller already holds:
 *
 *   `Seniority`  compares the REQUESTED role with the CALLER'S OWN role. Both are the
 *                caller's: one they just sent, one they have been acting under all session.
 *                It deliberately does NOT look at the target's current role — a refusal that
 *                did would tell a manager *"that person is an owner"*, which is the oracle the
 *                trap is about.
 *   `SelfRole`   compares the TARGET MEMBERSHIP ID with the caller's own. A caller knows their
 *                own membership id by definition.
 *
 * So the ordering matters as much as the status, and `MemberDirectory` enforces it: on
 * `store()` the seniority check runs **before the email lookup**, so an unknown address and a
 * known one answer identically (403) when the requested role is too senior. A seniority check
 * placed after the lookup would have turned this exception into exactly the oracle `§4 ›
 * Security`'s single-message refusal rule exists to close.
 *
 * ================================================================================
 * THE MESSAGE NEVER REACHES A RESPONSE BODY
 * ================================================================================
 *
 * `ProblemType::Forbidden`'s title is a fixed phrase and the envelope carries no message
 * (`§4 › Edge & ops`). The text below is for the log and for a developer reading a stack
 * trace; the SCREEN's sentence is `staff.team.denied.*`, written in a restaurant manager's
 * words because `§12`'s hard half on this surface is a refusal a person can act on.
 */
final class MemberActionDenied extends DomainException
{
    private function __construct(string $message, public readonly string $clause)
    {
        parent::__construct($message);
    }

    /**
     * `§4 › Security`: *no actor may grant a role senior to its own*.
     *
     * Both roles are named in the message because both are the CALLER's own — see the class
     * docblock. Naming the target's role would be the oracle.
     */
    public static function seniority(TenantRole $actor, TenantRole $granted): self
    {
        return new self(
            "An actor holding [{$actor->value}] may not grant [{$granted->value}], which is senior to it. "
            .'Grantable from here: ['
            .implode(', ', array_map(
                static fn (TenantRole $r): string => $r->value,
                $actor->grantableRoles(),
            )).'].',
            'seniority',
        );
    }

    /**
     * `§4 › Security`: *…or change its own role at all*.
     *
     * ⚠ NO ROLE IS NAMED, and that is not an omission. The clause is absolute — it does not
     * depend on which role is being asked for or which is held — so naming one would suggest a
     * comparison that is not being made, and the next reader would look for a seniority bug
     * that does not exist.
     */
    public static function ownRole(): self
    {
        return new self(
            'An actor may not change its own role. A role change is somebody else\'s to make, '
            .'which is what stops a single compromised session from escalating itself.',
            'own-role',
        );
    }
}
