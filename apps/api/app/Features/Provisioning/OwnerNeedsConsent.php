<?php

declare(strict_types=1);

namespace App\Features\Provisioning;

use RuntimeException;

/**
 * The address given as the first owner belongs to an account that cannot be attached
 * without that person's consent.
 *
 * SECURITY M-2, and it is `R-106`'s consent half reached through the provisioning form
 * rather than through a staff screen. Two cases, both narrow and both measured:
 *
 *   - `ALREADY_A_MEMBER` — the account holds a membership somewhere, and a second one
 *     strands it: `AuthController::login()` auto-selects a tenant only when there is
 *     exactly one, so the victim resolves none and every `/v1` call answers 401 until a
 *     client implements tenant selection, which is Task 10's.
 *   - `PLATFORM_ADMIN` — a platform admin holds no tenant ability by design, and giving one
 *     a membership is the "temporary audited membership" the 2026-10-28 ruling explicitly
 *     does not build here.
 *
 * The REASON is carried as a constant rather than baked into the message, so the panel can
 * say something useful while the message itself stays free of any account detail: it reaches
 * a screen, and whether a given address already has an account is not a thing this form
 * should confirm to whoever is typing in it.
 */
final class OwnerNeedsConsent extends RuntimeException
{
    public const ALREADY_A_MEMBER = 'already_a_member';

    public const PLATFORM_ADMIN = 'platform_admin';

    public function __construct(public readonly string $reason)
    {
        // ONE MESSAGE FOR BOTH CASES, deliberately. Distinguishing them would let the form
        // be used to probe whether an address has an account and whether it is a platform
        // admin's -- the same enumeration argument that makes `AuthController::login()`
        // answer identically for "no such account" and "wrong password".
        parent::__construct(
            'That address cannot be made the owner of a new restaurant here. '
            .'It needs an invitation the person accepts themselves.'
        );
    }
}
