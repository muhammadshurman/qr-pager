<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;

/**
 * The studio's way back to the orders board. `QRPAGER-48` item 3, the mirror direction.
 *
 * ⚠⚠ IT REUSES `surfaces.staff_signin_url` RATHER THAN ADDING A KEY, and that is the point
 * being pinned here: the staff app has ONE entry point, and `PanelSignInRedirect` already
 * sends signed-OUT visitors to it. A second key would be a second thing to misconfigure and a
 * second validator to drift — the defect `can_access_studio` exists to prevent, one layer over.
 */
it('offers the orders board, from the key the signed-out redirect already uses', function (): void {
    config(['surfaces.staff_signin_url' => 'https://staff.example.test/staff']);

    $this->actingForTenant(role: TenantRole::Owner);

    $this->get('/studio')
        ->assertOk()
        ->assertSee('data-back-to-staff="true"', false)
        ->assertSee('https://staff.example.test/staff', false)
        ->assertSee(__('studio.page.back_to_staff'), false);
});

it('renders NO control at all when there is nowhere to send them', function (): void {
    // Unreachable in production -- BootChecks refuses to boot on an empty value -- but a dead
    // link in the chrome is worse than an absent one, so the branch is driven rather than
    // assumed. `assertDontSee` on the marker, not on the label: a label can legitimately
    // appear elsewhere, the marker cannot.
    config(['surfaces.staff_signin_url' => '']);

    $this->actingForTenant(role: TenantRole::Owner);

    $this->get('/studio')
        ->assertOk()
        ->assertDontSee('data-back-to-staff="true"', false);
});

it('escapes the configured target, so a hostile value cannot break out of the attribute', function (): void {
    // The value is boot-validated in production, so this is defence in depth rather than the
    // control -- but a URL interpolated into an attribute is exactly where an unescaped quote
    // would matter, and `§12`'s panel has no raw echo anywhere else either.
    config(['surfaces.staff_signin_url' => 'https://staff.example.test/"><script>alert(1)</script>']);

    $this->actingForTenant(role: TenantRole::Owner);

    $this->get('/studio')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});
