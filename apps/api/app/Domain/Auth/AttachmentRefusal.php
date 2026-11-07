<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Why an existing address may not be attached to a tenant without that person's consent.
 *
 * `§4 › Security`, the clause added 2026-10-28 by `R-106`: *"an address that already belongs
 * to an account holding any membership, or to a platform admin, is refused outright rather
 * than invited"*. These are its two cases, and there are no others.
 *
 * ⚠ **THE VOCABULARY IS HERE AND THE RULE IS IN `App\Platform\Auth\UserAttachment`, because
 * before `R-233` the rule existed TWICE — once in `TenantProvisioning::refusalForOwnerEmail()`
 * and not at all on the members surface.** `§4 › Security` applies to both; the provisioning
 * form implemented it and `POST /v1/members` did not, which is the whole of `R-233`. One rule
 * with two callers cannot drift; two copies agree on the day they are written.
 *
 * In `App\Domain`, so no framework and no database — the reading that needs both is the
 * Platform reader's.
 */
enum AttachmentRefusal: string
{
    /**
     * The account holds a membership somewhere already.
     *
     * A second one strands it: `AuthController::login()` auto-selects a tenant only when
     * there is exactly one, so the victim resolves none.
     */
    case AlreadyAMember = 'already_a_member';

    /**
     * The account is a platform admin's.
     *
     * A platform admin holds no tenant ability by design (`§4 › Tenancy`), and giving one a
     * membership is the *"temporary audited membership"* that clause names and that
     * `QRPAGER-22` has not built.
     */
    case PlatformAdmin = 'platform_admin';
}
